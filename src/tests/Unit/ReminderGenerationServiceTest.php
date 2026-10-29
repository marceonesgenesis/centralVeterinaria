<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\ReminderGenerationService;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\MessageTemplate;
use CentralVet\Domain\MessageTemplateDefaults;
use CentralVet\Domain\MessageTemplateRenderer;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Domain\ReminderCandidate;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCommunicationPreferenceRepository;
use CentralVet\Tests\Support\FakeMessageTemplateRepository;
use CentralVet\Tests\Support\FakeOutboundMessageRepository;
use CentralVet\Tests\Support\FakeReminderSourceQuery;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ReminderGenerationService (T-11): idempotent automatic reminders with the
 * LGPD rule of each purpose (legitimate interest unless opted out; consent
 * needs an opt-in) and the D-1 window in the tenant time zone.
 */
final class ReminderGenerationServiceTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const SYSTEM_USER_ID = 1;
    private const TIMEZONE = 'America/Fortaleza';
    private const EMAIL = 'f7a.teste@example.invalid';
    private const PHONE = '(85) 99999-0000';

    private FakeOutboundMessageRepository $messages;
    private FakeCommunicationPreferenceRepository $preferences;
    private FakeMessageTemplateRepository $templates;

    public function setUp(): void
    {
        $this->messages = new FakeOutboundMessageRepository(self::TENANT_ID);
        $this->preferences = new FakeCommunicationPreferenceRepository(self::TENANT_ID);
        $this->templates = new FakeMessageTemplateRepository(self::TENANT_ID);
    }

    public function testWindowsUseTheTenantTimezone(): void
    {
        $sources = new FakeReminderSourceQuery();
        $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        $calls = $sources->calls();
        Assert::count(3, $calls);

        Assert::same('appointmentsBetween', $calls[0][0]);
        Assert::same('2026-10-07 00:00:00 America/Fortaleza', $calls[0][1]->format('Y-m-d H:i:s e'));
        Assert::same('2026-10-08 00:00:00 America/Fortaleza', $calls[0][2]->format('Y-m-d H:i:s e'));

        Assert::same('vaccinesDueBetween', $calls[1][0]);
        Assert::same('2026-10-06', $calls[1][1]->format('Y-m-d'));
        Assert::same('2026-10-13', $calls[1][2]->format('Y-m-d'));

        Assert::same('openReceivablesCreatedBefore', $calls[2][0]);
        Assert::same('2026-09-29 10:00:00 America/Fortaleza', $calls[2][1]->format('Y-m-d H:i:s e'));
    }

    public function testDayOneWindowFollowsTheTenantDateNotUtc(): void
    {
        // 01:30 UTC on 10-07 is still 10-06 22:30 in Fortaleza: tomorrow is 10-07.
        $sources = new FakeReminderSourceQuery();
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-07 01:30:00', new DateTimeZone('UTC'));
        $this->service($sources, $clock)->generate(new DateTimeZone(self::TIMEZONE), 7);

        $calls = $sources->calls();
        Assert::same('2026-10-07 00:00:00 America/Fortaleza', $calls[0][1]->format('Y-m-d H:i:s e'));
        Assert::same('2026-10-08 00:00:00 America/Fortaleza', $calls[0][2]->format('Y-m-d H:i:s e'));
        Assert::same('2026-10-06', $calls[1][1]->format('Y-m-d'));
    }

    public function testConfirmationWithoutPreferenceGoesOutOnBothChannelsOnLegitimateInterest(): void
    {
        $sources = new FakeReminderSourceQuery(appointments: [
            self::candidate(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 11, 7),
        ]);

        $summary = $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        Assert::same(2, $summary->created());
        $byChannel = $this->byChannel();
        Assert::count(2, $byChannel);

        $email = $byChannel[CommunicationChannel::EMAIL];
        Assert::same(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, $email->legalBasis());
        Assert::same(OutboundMessage::ORIGIN_AUTOMATION, $email->origin());
        Assert::same(OutboundMessage::STATUS_QUEUED, $email->status());
        Assert::null($email->createdBySystemUserId());
        Assert::same(self::EMAIL, $email->recipient());
        Assert::same('appointment_confirmation:appointment:11:email', $email->dedupeKey());
        Assert::same(self::UNIT_ID, $email->systemUnitId());
        Assert::same(7, $email->tutorId());
        Assert::same(21, $email->patientId());

        $whatsapp = $byChannel[CommunicationChannel::WHATSAPP];
        Assert::same(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, $whatsapp->legalBasis());
        Assert::same('5585999990000', $whatsapp->recipient());
        Assert::same('appointment_confirmation:appointment:11:whatsapp', $whatsapp->dedupeKey());

        Assert::same([$email->id()], $summary->emailMessageIds());
    }

    public function testConfirmationOptedOutOnEmailGoesOnlyByWhatsapp(): void
    {
        $this->preferences->upsert(self::preference(7, CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_OUT));
        $sources = new FakeReminderSourceQuery(appointments: [
            self::candidate(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 11, 7),
        ]);

        $summary = $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        Assert::same(1, $summary->created());
        Assert::same(1, $summary->skippedOptedOut());
        Assert::same([], $summary->emailMessageIds());
        Assert::same([CommunicationChannel::WHATSAPP], array_keys($this->byChannel()));
    }

    public function testReturnReminderUsesLegitimateInterestAndRespectsOptOut(): void
    {
        $this->preferences->upsert(self::preference(8, CommunicationChannel::WHATSAPP, CommunicationPreference::STATUS_OPTED_OUT));
        $sources = new FakeReminderSourceQuery(appointments: [
            self::candidate(MessagePurpose::RETURN_REMINDER, OutboundMessage::SOURCE_APPOINTMENT, 12, 8),
        ]);

        $summary = $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        Assert::same(1, $summary->created());
        Assert::same(1, $summary->skippedOptedOut());
        $email = $this->byChannel()[CommunicationChannel::EMAIL];
        Assert::same(MessagePurpose::RETURN_REMINDER, $email->purpose());
        Assert::same(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, $email->legalBasis());
        Assert::same('return_reminder:appointment:12:email', $email->dedupeKey());
    }

    public function testVaccineWithoutPreferenceIsSkippedForMissingConsent(): void
    {
        $sources = new FakeReminderSourceQuery(vaccines: [
            self::candidate(MessagePurpose::VACCINE_DUE, OutboundMessage::SOURCE_VACCINATION, 31, 7),
        ]);

        $summary = $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        Assert::same(0, $summary->created());
        Assert::same(2, $summary->skippedNoConsent());
        Assert::count(0, $this->messages->all());
    }

    public function testVaccineWithEmailOptInGeneratesOnlyTheEmailOnConsent(): void
    {
        $this->preferences->upsert(self::preference(7, CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_IN));
        $sources = new FakeReminderSourceQuery(vaccines: [
            self::candidate(MessagePurpose::VACCINE_DUE, OutboundMessage::SOURCE_VACCINATION, 31, 7),
        ]);

        $summary = $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        Assert::same(1, $summary->created());
        Assert::same(1, $summary->skippedNoConsent());
        $byChannel = $this->byChannel();
        Assert::same([CommunicationChannel::EMAIL], array_keys($byChannel));
        Assert::same(MessagePurpose::LEGAL_BASIS_CONSENT, $byChannel[CommunicationChannel::EMAIL]->legalBasis());
        Assert::same('vaccine_due:vaccination:31:email', $byChannel[CommunicationChannel::EMAIL]->dedupeKey());
        Assert::same([$byChannel[CommunicationChannel::EMAIL]->id()], $summary->emailMessageIds());
    }

    public function testReceivableNeedsOptInAndOptOutBlocksIt(): void
    {
        $this->preferences->upsert(self::preference(7, CommunicationChannel::WHATSAPP, CommunicationPreference::STATUS_OPTED_IN));
        $this->preferences->upsert(self::preference(7, CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_OUT));
        $sources = new FakeReminderSourceQuery(receivables: [
            self::candidate(MessagePurpose::RECEIVABLE_OPEN, OutboundMessage::SOURCE_RECEIVABLE, 41, 7, patientId: null),
        ]);

        $summary = $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        Assert::same(1, $summary->created());
        Assert::same(1, $summary->skippedOptedOut());
        Assert::same(0, $summary->skippedNoConsent());
        $whatsapp = $this->byChannel()[CommunicationChannel::WHATSAPP];
        Assert::same(MessagePurpose::LEGAL_BASIS_CONSENT, $whatsapp->legalBasis());
        Assert::same('receivable_open:receivable:41:whatsapp', $whatsapp->dedupeKey());
        Assert::null($whatsapp->patientId());
        Assert::stringContains('R$ 150,00', $whatsapp->bodyText());
    }

    public function testMissingContactIsSkipped(): void
    {
        $sources = new FakeReminderSourceQuery(appointments: [
            self::candidate(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 11, 7, email: null, phone: '123'),
        ]);

        $summary = $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        Assert::same(0, $summary->created());
        Assert::same(2, $summary->skippedNoContact());
        Assert::count(0, $this->messages->all());
    }

    public function testSecondRunCreatesNoDuplicates(): void
    {
        $this->preferences->upsert(self::preference(7, CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_IN));
        $sources = new FakeReminderSourceQuery(
            appointments: [
                self::candidate(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 11, 7),
                self::candidate(MessagePurpose::RETURN_REMINDER, OutboundMessage::SOURCE_APPOINTMENT, 12, 8),
            ],
            vaccines: [self::candidate(MessagePurpose::VACCINE_DUE, OutboundMessage::SOURCE_VACCINATION, 31, 7)],
            receivables: [self::candidate(MessagePurpose::RECEIVABLE_OPEN, OutboundMessage::SOURCE_RECEIVABLE, 41, 7, patientId: null)],
        );
        $service = $this->service($sources);

        $first = $service->generate(new DateTimeZone(self::TIMEZONE), 7);
        $afterFirst = array_map(static fn (OutboundMessage $m): string => (string) $m->dedupeKey(), $this->messages->all());

        $second = $service->generate(new DateTimeZone(self::TIMEZONE), 7);
        $afterSecond = array_map(static fn (OutboundMessage $m): string => (string) $m->dedupeKey(), $this->messages->all());

        Assert::same(6, $first->created());
        Assert::same(0, $first->duplicates());
        Assert::same(0, $second->created());
        Assert::same($first->created(), $second->duplicates());
        Assert::same([], $second->emailMessageIds());
        Assert::same($afterFirst, $afterSecond);
        Assert::count(6, $afterSecond);
    }

    public function testWithoutActiveTemplateTheBodyComesFromTheDefaults(): void
    {
        $sources = new FakeReminderSourceQuery(appointments: [
            self::candidate(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 11, 7),
        ]);

        $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        $email = $this->byChannel()[CommunicationChannel::EMAIL];
        $default = MessageTemplateDefaults::for(MessagePurpose::APPOINTMENT_CONFIRMATION, CommunicationChannel::EMAIL);
        $variables = self::variables();
        Assert::same(MessageTemplateRenderer::render($default['body'], $variables), $email->bodyText());
        Assert::same(MessageTemplateRenderer::render((string) $default['subject'], $variables), $email->subject());
        Assert::null($email->templateId());
        Assert::stringContains('F7A teste Tutor', $email->bodyText());
        Assert::stringContains('07/10/2026', $email->bodyText());
    }

    public function testActiveTemplateIsRenderedAndLinked(): void
    {
        $template = MessageTemplate::create(
            self::TENANT_ID,
            MessagePurpose::APPOINTMENT_CONFIRMATION,
            CommunicationChannel::WHATSAPP,
            'F7A teste confirmação',
            null,
            'Oi {{tutor_name}}, {{patient_name}} amanhã às {{appointment_time}}.',
            self::SYSTEM_USER_ID,
        );
        $this->templates->save($template);
        $sources = new FakeReminderSourceQuery(appointments: [
            self::candidate(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 11, 7),
        ]);

        $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        $whatsapp = $this->byChannel()[CommunicationChannel::WHATSAPP];
        Assert::same('Oi F7A teste Tutor, F7A teste Rex amanhã às 09:30.', $whatsapp->bodyText());
        Assert::same($template->id(), $whatsapp->templateId());
    }

    public function testSummaryArrayCarriesOnlyCounts(): void
    {
        $sources = new FakeReminderSourceQuery(appointments: [
            self::candidate(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 11, 7),
        ]);

        $summary = $this->service($sources)->generate(new DateTimeZone(self::TIMEZONE), 7);

        Assert::same([
            'created' => 2,
            'duplicates' => 0,
            'skipped_no_consent' => 0,
            'skipped_no_contact' => 0,
            'skipped_opted_out' => 0,
        ], $summary->toArray());
    }

    private function service(FakeReminderSourceQuery $sources, ?\Closure $clock = null): ReminderGenerationService
    {
        return new ReminderGenerationService(
            $sources,
            $this->preferences,
            $this->templates,
            $this->messages,
            TenantContext::authenticated(self::TENANT_ID, self::SYSTEM_USER_ID),
            $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-06 10:00:00', new DateTimeZone(self::TIMEZONE)),
        );
    }

    /** @return array<string, OutboundMessage> */
    private function byChannel(): array
    {
        $map = [];
        foreach ($this->messages->all() as $message) {
            $map[$message->channel()] = $message;
        }
        ksort($map);

        return $map;
    }

    /** @return array<string, string> */
    private static function variables(): array
    {
        return [
            'tutor_name' => 'F7A teste Tutor',
            'patient_name' => 'F7A teste Rex',
            'unit_name' => 'Unidade Centro',
            'clinic_name' => 'Clínica F7A',
            'appointment_date' => '07/10/2026',
            'appointment_time' => '09:30',
            'vaccine_name' => 'V10',
            'due_date' => '10/10/2026',
            'amount_due' => 'R$ 150,00',
        ];
    }

    private static function candidate(
        string $purpose,
        string $sourceType,
        int $sourceId,
        int $tutorId,
        ?int $patientId = 21,
        ?string $email = self::EMAIL,
        string $phone = self::PHONE,
    ): ReminderCandidate {
        return new ReminderCandidate($purpose, $sourceType, $sourceId, self::UNIT_ID, $tutorId, $patientId, $email, $phone, self::variables());
    }

    private static function preference(int $tutorId, string $channel, string $status): CommunicationPreference
    {
        return CommunicationPreference::record(
            self::TENANT_ID,
            $tutorId,
            $channel,
            $status,
            'in_person',
            self::SYSTEM_USER_ID,
            new DateTimeImmutable('2026-10-01 09:00:00'),
        );
    }
}
