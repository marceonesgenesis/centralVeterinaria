<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Communication\WhatsAppLinkBuilder;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Contract\CommunicationPreferenceRepositoryInterface;
use CentralVet\Domain\Contract\DocumentSourceQueryInterface;
use CentralVet\Domain\Contract\MessageTemplateRepositoryInterface;
use CentralVet\Domain\Contract\OutboundMessageRepositoryInterface;
use CentralVet\Domain\Contract\SenderNamesQueryInterface;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\MessageTemplateDefaults;
use CentralVet\Domain\MessageTemplateRenderer;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Tenancy\TenantContext;

/**
 * Queues the `document_ready` notice of a generated document (Fase 7B),
 * once per channel (`dedupe_key` `document_ready:document:<id>:<channel>`
 * + `insertIfNew`, with no `source_type`/`source_id`).
 *
 * The purpose's legal basis is `consent`: an opt-out always blocks and a
 * channel without an explicit opt-in is skipped, with the final gate in
 * {@see CommunicationPreference::permitsSending()}, in the same order as
 * {@see ReminderGenerationService}. Without the unit or clinic name no
 * message is created. System actor: no authorization, no transaction.
 * The text has no link and no attachment; WhatsApp stays `queued` for the
 * manual flow of the Fase 7A. Contacts never leave this class except as the
 * message recipient.
 */
final class DocumentReadyNotifier
{
    public function __construct(
        private readonly CommunicationPreferenceRepositoryInterface $preferences,
        private readonly MessageTemplateRepositoryInterface $templates,
        private readonly OutboundMessageRepositoryInterface $messages,
        private readonly DocumentSourceQueryInterface $sources,
        private readonly SenderNamesQueryInterface $names,
        private readonly TenantContext $context,
    ) {
    }

    /** @return list<int> ids of the `email` messages created */
    public function notify(GeneratedDocument $document): array
    {
        $documentId = $document->id();

        if ($documentId === null) {
            return [];
        }

        $names = $this->names->namesForUnit($document->systemUnitId());

        if ($names['unit_name'] === null || $names['clinic_name'] === null) {
            return [];
        }

        $contact = $this->sources->tutorContact($document->tutorId());

        if ($contact === null) {
            return [];
        }

        $purpose = MessagePurpose::DOCUMENT_READY;
        $legalBasis = MessagePurpose::legalBasisFor($purpose);
        $preferences = $this->preferences->findForTutor($document->tutorId());
        $patient = $this->sources->patientSummary($document->patientId());
        $variables = [
            'tutor_name' => $contact['tutor_name'],
            'patient_name' => $patient['patient_name'] ?? null,
            'unit_name' => $names['unit_name'],
            'clinic_name' => $names['clinic_name'],
        ];
        $emailIds = [];

        foreach (CommunicationChannel::all() as $channel) {
            $preference = $preferences[$channel] ?? null;

            if ($preference !== null && !$preference->isOptedIn()) {
                continue;
            }

            if ($legalBasis === MessagePurpose::LEGAL_BASIS_CONSENT && $preference === null) {
                continue;
            }

            $recipient = self::recipientFor($contact, $channel);

            if ($recipient === null || !CommunicationPreference::permitsSending($preference, $legalBasis)) {
                continue;
            }

            $template = $this->templateFor($purpose, $channel);

            $message = OutboundMessage::compose(
                tenantId: $this->context->tenantId(),
                systemUnitId: $document->systemUnitId(),
                tutorId: $document->tutorId(),
                patientId: $document->patientId(),
                templateId: $template['template_id'],
                purpose: $purpose,
                channel: $channel,
                origin: OutboundMessage::ORIGIN_AUTOMATION,
                legalBasis: $legalBasis,
                sourceType: null,
                sourceId: null,
                dedupeKey: OutboundMessage::buildDedupeKey($purpose, 'document', $documentId, $channel),
                recipient: $recipient,
                subject: $template['subject'] === null ? null : MessageTemplateRenderer::render($template['subject'], $variables),
                bodyText: MessageTemplateRenderer::render($template['body'], $variables),
                createdBySystemUserId: null,
            );

            $inserted = $this->messages->insertIfNew($message);

            if ($inserted !== null && $channel === CommunicationChannel::EMAIL && $inserted->id() !== null) {
                $emailIds[] = $inserted->id();
            }
        }

        return $emailIds;
    }

    /** @param array{tutor_name: string, email: ?string, phone: string} $contact */
    private static function recipientFor(array $contact, string $channel): ?string
    {
        if ($channel === CommunicationChannel::EMAIL) {
            $email = trim((string) $contact['email']);

            return $email === '' ? null : $email;
        }

        return WhatsAppLinkBuilder::normalizePhone($contact['phone']);
    }

    /** @return array{template_id: ?int, subject: ?string, body: string} */
    private function templateFor(string $purpose, string $channel): array
    {
        $template = $this->templates->findActiveFor($purpose, $channel);

        if ($template !== null) {
            $subject = $template->subject();

            if ($channel === CommunicationChannel::EMAIL && ($subject === null || trim($subject) === '')) {
                $subject = MessageTemplateDefaults::for($purpose, $channel)['subject'];
            }

            return ['template_id' => $template->id(), 'subject' => $subject, 'body' => $template->bodyText()];
        }

        $default = MessageTemplateDefaults::for($purpose, $channel);

        return ['template_id' => null, 'subject' => $default['subject'], 'body' => $default['body']];
    }
}
