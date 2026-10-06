<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Communication\WhatsAppLinkBuilder;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Contract\CommunicationPreferenceRepositoryInterface;
use CentralVet\Domain\Contract\MessageTemplateRepositoryInterface;
use CentralVet\Domain\Contract\OutboundMessageRepositoryInterface;
use CentralVet\Domain\Contract\PatientRepositoryInterface;
use CentralVet\Domain\Contract\SenderNamesQueryInterface;
use CentralVet\Domain\Contract\TutorRepositoryInterface;
use CentralVet\Domain\Exception\CommunicationConsentRequiredException;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\MessageTemplate;
use CentralVet\Domain\MessageTemplateRenderer;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Domain\Patient;
use CentralVet\Domain\Tutor;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Manual message cycle (Fase 7A): compose, render a template, mark a
 * WhatsApp message as sent by hand, discard, retry a failed e-mail, build
 * the wa.me link, read and list.
 *
 * Every operation authorizes first (`requiresUnitScope`, entity
 * `communication_message`): compose and list against the active unit,
 * the others against the persisted message's own unit. The service opens
 * no transaction and publishes nothing: the controller composes inside
 * `TTransaction` and calls `MessageQueuePublisher::publish` after commit.
 *
 * The LGPD legal basis comes from `MessagePurpose::legalBasisFor` and is
 * stored on the message; `CommunicationPreference::permitsSending` decides.
 * Status changes go only through the repository's conditional transitions;
 * when one returns false (a concurrent touch won) the service raises a
 * domain `InvalidStatusTransitionException`. Exception messages carry ids
 * only, never contact data or message text.
 */
final class MessageService
{
    private const ENTITY_TYPE = 'communication_message';
    private const BODY_MAX = 2000;
    private const LIST_LIMIT = 200;
    private const LIST_FILTERS = ['status', 'channel', 'purpose', 'tutor_id'];
    private const CANCEL_REASON = 'discarded';

    private readonly Closure $clock;

    public function __construct(
        private readonly OutboundMessageRepositoryInterface $messages,
        private readonly MessageTemplateRepositoryInterface $templates,
        private readonly CommunicationPreferenceRepositoryInterface $preferences,
        private readonly TutorRepositoryInterface $tutors,
        private readonly PatientRepositoryInterface $patients,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
        private readonly ?SenderNamesQueryInterface $senderNames = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @param array{tutor_id: int|string, patient_id?: int|string|null, channel: string, purpose: string, template_id?: int|string|null, subject?: string|null, body_text: string} $data
     *
     * @throws CommunicationConsentRequiredException when the tutor's preference forbids the message.
     * @throws CrossTenantReferenceException for an unknown tutor, patient or template.
     * @throws InvalidArgumentException for missing contact or invalid fields.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function compose(array $data, string $action): OutboundMessage
    {
        $unitId = $this->context->requireUnitId();
        $this->authorize($action, $unitId, null);

        $channel = (string) ($data['channel'] ?? '');
        $purpose = (string) ($data['purpose'] ?? '');
        CommunicationChannel::assertValid($channel);
        $legalBasis = MessagePurpose::legalBasisFor($purpose);

        $tutor = $this->requireTutor(self::positiveIntOrNull($data['tutor_id'] ?? null) ?? 0);
        $patientId = self::positiveIntOrNull($data['patient_id'] ?? null);

        if ($patientId !== null) {
            $this->requirePatientOfTutor($patientId, (int) $tutor->id);
        }

        $templateId = self::positiveIntOrNull($data['template_id'] ?? null);

        if ($templateId !== null) {
            $template = $this->requireTemplate($templateId);

            if ($template->purpose() !== $purpose || $template->channel() !== $channel) {
                throw new InvalidArgumentException("Template {$templateId} does not match the message purpose and channel");
            }
        }

        $preference = $this->preferences->findForTutor((int) $tutor->id)[$channel] ?? null;

        if (!CommunicationPreference::permitsSending($preference, $legalBasis)) {
            throw $preference !== null && !$preference->isOptedIn()
                ? CommunicationConsentRequiredException::optedOut((int) $tutor->id, $channel)
                : CommunicationConsentRequiredException::notOptedIn((int) $tutor->id, $channel);
        }

        $recipient = $this->recipientFor($tutor, $channel);

        $body = (string) ($data['body_text'] ?? '');
        $bodyLength = mb_strlen(trim($body));

        if ($bodyLength < 1 || mb_strlen($body) > self::BODY_MAX) {
            throw new InvalidArgumentException('body must be between 1 and 2000 characters');
        }

        $subject = isset($data['subject']) ? (string) $data['subject'] : null;

        if (self::hasPlaceholder($body)
            || ($channel === CommunicationChannel::EMAIL && $subject !== null && self::hasPlaceholder($subject))) {
            throw new InvalidArgumentException('Replace the template placeholders before sending the message');
        }

        $message = OutboundMessage::compose(
            tenantId: $this->context->tenantId(),
            systemUnitId: $unitId,
            tutorId: (int) $tutor->id,
            patientId: $patientId,
            templateId: $templateId,
            purpose: $purpose,
            channel: $channel,
            origin: OutboundMessage::ORIGIN_MANUAL,
            legalBasis: $legalBasis,
            sourceType: null,
            sourceId: null,
            dedupeKey: null,
            recipient: $recipient,
            subject: $subject,
            bodyText: $body,
            createdBySystemUserId: $this->context->userId(),
        );

        // A null dedupe key never collides, so insertIfNew always stores a manual message.
        return $this->messages->insertIfNew($message)
            ?? throw new \LogicException('Manual message without dedupe key was not stored');
    }

    /**
     * Renders a template for a manual message: `tutor_name`, `patient_name`
     * (when a patient is given), `unit_name` (active unit) and `clinic_name`
     * (tenant) from the sender-names query. A manual message has no
     * appointment, vaccine or receivable, so every placeholder without a
     * value stays visible as `{{name}}` for the attendant to replace;
     * `compose` refuses a text that still has one, so nothing goes out with
     * a blank where a date or a name should be.
     *
     * @return array{subject: ?string, body: string}
     */
    public function renderTemplate(int $templateId, int $tutorId, ?int $patientId, string $action): array
    {
        $unitId = $this->context->requireUnitId();
        $this->authorize($action, $unitId, null);

        $template = $this->requireTemplate($templateId);
        $tutor = $this->requireTutor($tutorId);
        $patient = $patientId !== null ? $this->requirePatientOfTutor($patientId, (int) $tutor->id) : null;

        $names = $this->senderNames?->namesForUnit($unitId) ?? [];

        $variables = [
            'tutor_name' => $tutor->fullName,
            'patient_name' => $patient?->name,
            'unit_name' => $names['unit_name'] ?? null,
            'clinic_name' => $names['clinic_name'] ?? null,
        ];

        foreach (MessageTemplateRenderer::PLACEHOLDERS as $placeholder) {
            $value = $variables[$placeholder] ?? null;

            if ($value === null || trim((string) $value) === '') {
                $variables[$placeholder] = '{{' . $placeholder . '}}';
            }
        }

        return [
            'subject' => $template->subject() !== null ? MessageTemplateRenderer::render($template->subject(), $variables) : null,
            'body' => MessageTemplateRenderer::render($template->bodyText(), $variables),
        ];
    }

    public function markManualSent(int $messageId, string $action): void
    {
        $this->requireMessage($messageId, $action);

        if (!$this->messages->markManualSent($messageId, $this->context->userId(), $this->now())) {
            throw new InvalidStatusTransitionException("Message {$messageId} is no longer awaiting manual send");
        }
    }

    public function cancel(int $messageId, string $action): void
    {
        $this->requireMessage($messageId, $action);

        if (!$this->messages->cancel($messageId, $this->context->userId(), self::CANCEL_REASON, $this->now())) {
            throw new InvalidStatusTransitionException("Message {$messageId} is no longer queued");
        }
    }

    /** Moves a `failed` message back to `queued`; the caller publishes it after commit. */
    public function retry(int $messageId, string $action): OutboundMessage
    {
        $this->requireMessage($messageId, $action);

        if (!$this->messages->requeue($messageId)) {
            throw new InvalidStatusTransitionException("Message {$messageId} has not failed");
        }

        return $this->loadMessage($messageId);
    }

    /** wa.me link with the message text, only for a `queued` WhatsApp message. */
    public function whatsAppLink(int $messageId, string $action): string
    {
        $message = $this->requireMessage($messageId, $action);

        if ($message->channel() !== CommunicationChannel::WHATSAPP) {
            throw new InvalidArgumentException("Message {$messageId} is not a WhatsApp message");
        }

        if ($message->status() !== OutboundMessage::STATUS_QUEUED) {
            throw new InvalidStatusTransitionException("Message {$messageId} is no longer awaiting manual send");
        }

        return WhatsAppLinkBuilder::build($message->recipient(), $message->bodyText());
    }

    public function find(int $messageId, string $action): OutboundMessage
    {
        return $this->requireMessage($messageId, $action);
    }

    /**
     * Messages of the active unit, newest first (at most 200). Accepted
     * filters: `status`, `channel`, `purpose`, `tutor_id`; empty values and
     * other keys are ignored.
     *
     * @param array<string, mixed> $filters
     * @return list<OutboundMessage>
     */
    public function listForUnit(array $filters, string $action): array
    {
        $unitId = $this->context->requireUnitId();
        $this->authorize($action, $unitId, null);

        $clean = [];

        foreach (self::LIST_FILTERS as $key) {
            $value = $filters[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'tutor_id') {
                $tutorId = self::positiveIntOrNull($value);

                if ($tutorId !== null) {
                    $clean[$key] = $tutorId;
                }

                continue;
            }

            $clean[$key] = (string) $value;
        }

        return $this->messages->listForUnit($unitId, $clean, self::LIST_LIMIT);
    }

    private function requireMessage(int $messageId, string $action): OutboundMessage
    {
        $message = $this->loadMessage($messageId);
        $this->authorize($action, $message->systemUnitId(), $messageId);

        return $message;
    }

    private function loadMessage(int $messageId): OutboundMessage
    {
        $message = $messageId > 0 ? $this->messages->findById($messageId) : null;

        if (!$message instanceof OutboundMessage) {
            throw new CrossTenantReferenceException("message_id {$messageId} was not found for the authenticated tenant");
        }

        return $message;
    }

    private function requireTutor(int $tutorId): Tutor
    {
        $tutor = $tutorId > 0 ? $this->tutors->findById($tutorId) : null;

        if (!$tutor instanceof Tutor || $tutor->id === null) {
            throw new CrossTenantReferenceException("tutor_id {$tutorId} was not found for the authenticated tenant");
        }

        return $tutor;
    }

    private function requirePatientOfTutor(int $patientId, int $tutorId): Patient
    {
        $patient = $patientId > 0 ? $this->patients->findById($patientId) : null;

        if (!$patient instanceof Patient || $patient->tutorId !== $tutorId) {
            throw new CrossTenantReferenceException("patient_id {$patientId} was not found for tutor_id {$tutorId}");
        }

        return $patient;
    }

    private function requireTemplate(int $templateId): MessageTemplate
    {
        $template = $templateId > 0 ? $this->templates->findById($templateId) : null;

        if (!$template instanceof MessageTemplate) {
            throw new CrossTenantReferenceException("template_id {$templateId} was not found for the authenticated tenant");
        }

        return $template;
    }

    private function recipientFor(Tutor $tutor, string $channel): string
    {
        if ($channel === CommunicationChannel::EMAIL) {
            $email = trim((string) $tutor->email);

            if ($email === '') {
                throw new InvalidArgumentException("Tutor {$tutor->id} has no e-mail address");
            }

            return $email;
        }

        $phone = WhatsAppLinkBuilder::normalizePhone($tutor->phone);

        if ($phone === null) {
            throw new InvalidArgumentException('Invalid phone number for WhatsApp');
        }

        return $phone;
    }

    /** True when the text still has a `{{placeholder}}` of the closed list. */
    private static function hasPlaceholder(string $text): bool
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $matches);

        return array_intersect($matches[1], MessageTemplateRenderer::PLACEHOLDERS) !== [];
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        if ($int === false || $int <= 0) {
            throw new InvalidArgumentException('Identifiers must be positive integers');
        }

        return $int;
    }

    private function authorize(string $action, int $unitId, ?int $messageId): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $unitId,
            entityType: self::ENTITY_TYPE,
            entityId: $messageId,
        ))->assertAllowed();
    }
}
