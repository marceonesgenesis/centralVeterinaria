<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Communication\WhatsAppLinkBuilder;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Contract\CommunicationPreferenceRepositoryInterface;
use CentralVet\Domain\Contract\MessageTemplateRepositoryInterface;
use CentralVet\Domain\Contract\OutboundMessageRepositoryInterface;
use CentralVet\Domain\Contract\ReminderSourceQueryInterface;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\MessageTemplateDefaults;
use CentralVet\Domain\MessageTemplateRenderer;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Domain\ReminderCandidate;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Generates the automatic reminders of the Fase 7A (appointment
 * confirmation, return reminder, vaccine due, open receivable) as `queued`
 * messages, once per source and channel (`dedupe_key` + `insertIfNew`).
 *
 * Runs as the system actor of the scheduler: it does not authorize and does
 * not open a transaction. Windows in the tenant time zone: appointments of
 * tomorrow (00:00 to 24:00), vaccines due from today to today + 7 days,
 * receivables created before now - `$receivableReminderDays` days.
 *
 * LGPD: the legal basis comes from {@see MessagePurpose::legalBasisFor()};
 * an opt-out always blocks, `consent` purposes need an opt-in on the
 * channel, and the final gate is {@see CommunicationPreference::permitsSending()}.
 * WhatsApp messages stay `queued` for manual sending; only the e-mail ids
 * are returned for the queue. Contacts never leave this method except as
 * the message recipient.
 */
final class ReminderGenerationService
{
    private const VACCINE_WINDOW_DAYS = 7;

    private readonly Closure $clock;

    public function __construct(
        private readonly ReminderSourceQueryInterface $sources,
        private readonly CommunicationPreferenceRepositoryInterface $preferences,
        private readonly MessageTemplateRepositoryInterface $templates,
        private readonly OutboundMessageRepositoryInterface $messages,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function generate(DateTimeZone $timezone, int $receivableReminderDays): ReminderRunSummary
    {
        $now = ($this->clock)()->setTimezone($timezone);
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');

        $candidates = array_merge(
            $this->sources->appointmentsBetween($tomorrow, $tomorrow->modify('+1 day')),
            $this->sources->vaccinesDueBetween($today, $today->modify('+' . self::VACCINE_WINDOW_DAYS . ' days')),
            $this->sources->openReceivablesCreatedBefore($now->modify('-' . max(0, $receivableReminderDays) . ' days')),
        );

        $counters = ['created' => 0, 'duplicates' => 0, 'no_consent' => 0, 'no_contact' => 0, 'opted_out' => 0];
        $emailIds = [];
        $preferencesByTutor = [];
        $templateCache = [];

        foreach ($candidates as $candidate) {
            $legalBasis = MessagePurpose::legalBasisFor($candidate->purpose());
            $preferencesByTutor[$candidate->tutorId()] ??= $this->preferences->findForTutor($candidate->tutorId());

            foreach (CommunicationChannel::all() as $channel) {
                $preference = $preferencesByTutor[$candidate->tutorId()][$channel] ?? null;

                if ($preference !== null && !$preference->isOptedIn()) {
                    $counters['opted_out']++;
                    continue;
                }

                if ($legalBasis === MessagePurpose::LEGAL_BASIS_CONSENT && $preference === null) {
                    $counters['no_consent']++;
                    continue;
                }

                $recipient = self::recipientFor($candidate, $channel);

                if ($recipient === null) {
                    $counters['no_contact']++;
                    continue;
                }

                if (!CommunicationPreference::permitsSending($preference, $legalBasis)) {
                    $counters['no_consent']++;
                    continue;
                }

                $templateCache[$candidate->purpose() . ':' . $channel] ??= $this->templateFor($candidate->purpose(), $channel);
                $template = $templateCache[$candidate->purpose() . ':' . $channel];
                $variables = $candidate->variables();

                $message = OutboundMessage::compose(
                    tenantId: $this->context->tenantId(),
                    systemUnitId: $candidate->systemUnitId(),
                    tutorId: $candidate->tutorId(),
                    patientId: $candidate->patientId(),
                    templateId: $template['template_id'],
                    purpose: $candidate->purpose(),
                    channel: $channel,
                    origin: OutboundMessage::ORIGIN_AUTOMATION,
                    legalBasis: $legalBasis,
                    sourceType: $candidate->sourceType(),
                    sourceId: $candidate->sourceId(),
                    dedupeKey: OutboundMessage::buildDedupeKey($candidate->purpose(), $candidate->sourceType(), $candidate->sourceId(), $channel),
                    recipient: $recipient,
                    subject: $template['subject'] === null ? null : MessageTemplateRenderer::render($template['subject'], $variables),
                    bodyText: MessageTemplateRenderer::render($template['body'], $variables),
                    createdBySystemUserId: null,
                );

                $inserted = $this->messages->insertIfNew($message);

                if ($inserted === null) {
                    $counters['duplicates']++;
                    continue;
                }

                $counters['created']++;

                if ($channel === CommunicationChannel::EMAIL && $inserted->id() !== null) {
                    $emailIds[] = $inserted->id();
                }
            }
        }

        return new ReminderRunSummary(
            $counters['created'],
            $counters['duplicates'],
            $counters['no_consent'],
            $counters['no_contact'],
            $counters['opted_out'],
            $emailIds,
        );
    }

    private static function recipientFor(ReminderCandidate $candidate, string $channel): ?string
    {
        if ($channel === CommunicationChannel::EMAIL) {
            $email = trim((string) $candidate->tutorEmail());

            return $email === '' ? null : $email;
        }

        return WhatsAppLinkBuilder::normalizePhone($candidate->tutorPhone());
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
