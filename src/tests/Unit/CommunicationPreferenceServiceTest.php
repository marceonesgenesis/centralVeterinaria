<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\CommunicationPreferenceService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Domain\Tutor;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCommunicationPreferenceRepository;
use CentralVet\Tests\Support\FakeOutboundMessageRepository;
use CentralVet\Tests\Support\FakeTutorRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for CommunicationPreferenceService (T-09): channel map with
 * `not_recorded`, recording with tenant-scoped tutor, consent source
 * validation, denied policy without writing and audit metadata without
 * contact data; an opt-out cancels the tutor's queued messages of that
 * channel in the tenant (T-25).
 */
final class CommunicationPreferenceServiceTest
{
    private const ACTION = 'test::communication_preference';
    private const TENANT_ID = 1;
    private const USER_ID = 7;
    private const NOW = '2026-10-06 09:30:00';

    private FakeOutboundMessageRepository $messages;

    /** @return array{0: CommunicationPreferenceService, 1: FakeCommunicationPreferenceRepository, 2: FakeAuthorizationPolicy} */
    private function build(bool $allowed = true, CommunicationPreference ...$seed): array
    {
        $this->messages ??= new FakeOutboundMessageRepository(self::TENANT_ID);
        $preferences = new FakeCommunicationPreferenceRepository(self::TENANT_ID, ...$seed);
        $tutors = new FakeTutorRepository(
            self::TENANT_ID,
            Tutor::register(self::TENANT_ID, 'F7A teste Tutor', '85999998888', null, 'f7a.teste@example.invalid'),
        );
        $policy = new FakeAuthorizationPolicy(allowed: $allowed);
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID);
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW);

        return [new CommunicationPreferenceService($preferences, $tutors, $policy, $context, $clock, $this->messages), $preferences, $policy];
    }

    private static function preference(string $channel, string $status): CommunicationPreference
    {
        return CommunicationPreference::record(
            self::TENANT_ID,
            1,
            $channel,
            $status,
            'in_person',
            self::USER_ID,
            new DateTimeImmutable('2026-10-01 10:00:00'),
        );
    }

    public function testPreferencesForTutorWithoutRowsAreNotRecorded(): void
    {
        [$service, , $policy] = $this->build();

        Assert::same(['email' => 'not_recorded', 'whatsapp' => 'not_recorded'], $service->preferencesFor(1, self::ACTION));
        Assert::count(1, $policy->requests);
        Assert::same('communication_preference', $policy->requests[0]->entityType());
        Assert::same(1, $policy->requests[0]->entityId());
        Assert::false($policy->requests[0]->requiresUnitScope());
    }

    public function testPreferencesForReflectsRecordedChannels(): void
    {
        [$service] = $this->build(true, self::preference('whatsapp', CommunicationPreference::STATUS_OPTED_OUT));

        Assert::same(['email' => 'not_recorded', 'whatsapp' => 'opted_out'], $service->preferencesFor(1, self::ACTION));
    }

    public function testPreferencesForUnknownTutorThrows(): void
    {
        [$service] = $this->build();

        try {
            $service->preferencesFor(99, self::ACTION);
            Assert::true(false, 'Expected CrossTenantReferenceException');
        } catch (CrossTenantReferenceException $e) {
            Assert::same('tutor_id 99 was not found for the authenticated tenant', $e->getMessage());
        }
    }

    public function testRecordStoresPreferenceWithAuthorAndClock(): void
    {
        [$service, $preferences] = $this->build();

        $preference = $service->record(1, 'email', 'opted_in', 'written', self::ACTION);

        Assert::same(1, $preferences->upsertCount);
        Assert::same('opted_in', $preference->status());
        Assert::same('written', $preference->consentSource());
        Assert::same(self::USER_ID, $preference->changedBySystemUserId());
        Assert::same(self::NOW, $preference->changedAt()->format('Y-m-d H:i:s'));
        Assert::same(['email' => 'opted_in', 'whatsapp' => 'not_recorded'], $service->preferencesFor(1, self::ACTION));
    }

    public function testRecordWithUnknownConsentSourceIsRejected(): void
    {
        [$service, $preferences] = $this->build();

        Assert::throws(InvalidArgumentException::class, fn () => $service->record(1, 'email', 'opted_in', 'carrier_pigeon', self::ACTION));
        Assert::same(0, $preferences->upsertCount);
    }

    public function testRecordWithUnknownChannelOrStatusIsRejected(): void
    {
        [$service, $preferences] = $this->build();

        Assert::throws(InvalidArgumentException::class, fn () => $service->record(1, 'sms', 'opted_in', 'phone', self::ACTION));
        Assert::throws(InvalidArgumentException::class, fn () => $service->record(1, 'email', 'maybe', 'phone', self::ACTION));
        Assert::same(0, $preferences->upsertCount);
    }

    public function testRecordForUnknownTutorThrowsWithoutWriting(): void
    {
        [$service, $preferences] = $this->build();

        Assert::throws(CrossTenantReferenceException::class, fn () => $service->record(42, 'email', 'opted_in', 'phone', self::ACTION));
        Assert::same(0, $preferences->upsertCount);
    }

    public function testRecordDeniedDoesNotWrite(): void
    {
        [$service, $preferences] = $this->build(false);

        Assert::throws(AuthorizationDenied::class, fn () => $service->record(1, 'email', 'opted_in', 'phone', self::ACTION));
        Assert::same(0, $preferences->upsertCount);
    }

    public function testRecordAuditMetadataHasNoContactData(): void
    {
        [$service, , $policy] = $this->build(true, self::preference('whatsapp', CommunicationPreference::STATUS_OPTED_IN));

        $service->record(1, 'whatsapp', 'opted_out', 'phone', self::ACTION);

        $request = $policy->requests[0];
        Assert::same('communication_preference', $request->entityType());
        Assert::same(1, $request->entityId());
        Assert::false($request->requiresUnitScope());
        Assert::same([
            'channel' => 'whatsapp',
            'status_before' => 'opted_in',
            'status_after' => 'opted_out',
            'consent_source' => 'phone',
        ], $request->metadata());

        $flat = json_encode($request->metadata(), JSON_THROW_ON_ERROR);
        Assert::false(str_contains($flat, '@'), 'Audit metadata must not carry an e-mail');
        Assert::same(0, preg_match('/\d/', $flat), 'Audit metadata must not carry phone digits');
    }

    public function testRecordFirstChoiceAuditsNotRecordedAsBefore(): void
    {
        [$service, , $policy] = $this->build();

        $service->record(1, 'email', 'opted_in', 'online', self::ACTION);

        Assert::same('not_recorded', $policy->requests[0]->metadata()['status_before']);
    }

    public function testOptOutCancelsQueuedMessagesOfTheTutorAndChannelInTheTenant(): void
    {
        $this->messages = new FakeOutboundMessageRepository(self::TENANT_ID);
        $legitimate = self::message(self::TENANT_ID, 1, 'whatsapp', MessagePurpose::APPOINTMENT_CONFIRMATION, 5);
        $consent = self::message(self::TENANT_ID, 1, 'whatsapp', MessagePurpose::CUSTOM, 6);
        $email = self::message(self::TENANT_ID, 1, 'email', MessagePurpose::APPOINTMENT_CONFIRMATION, 5);
        $otherTutor = self::message(self::TENANT_ID, 2, 'whatsapp', MessagePurpose::APPOINTMENT_CONFIRMATION, 5);
        $otherTenant = self::message(self::TENANT_ID + 1, 1, 'whatsapp', MessagePurpose::APPOINTMENT_CONFIRMATION, 5);
        $alreadySent = self::message(self::TENANT_ID, 1, 'whatsapp', MessagePurpose::APPOINTMENT_CONFIRMATION, 5);
        $this->messages->seed($legitimate, $consent, $email, $otherTutor, $otherTenant, $alreadySent);
        $this->messages->simulateConcurrentTransition((int) $alreadySent->id(), OutboundMessage::STATUS_MANUAL);

        [$service] = $this->build(true, self::preference('whatsapp', CommunicationPreference::STATUS_OPTED_IN));
        $service->record(1, 'whatsapp', 'opted_out', 'phone', self::ACTION);

        $status = [];
        foreach ($this->messages->all() as $message) {
            $status[(int) $message->id()] = $message;
        }

        foreach ([$legitimate, $consent] as $cancelled) {
            $stored = $status[(int) $cancelled->id()];
            Assert::same(OutboundMessage::STATUS_CANCELLED, $stored->status(), 'queued WhatsApp of the tutor is cancelled (both legal bases)');
            Assert::same('opted_out', $stored->lastErrorCode());
            Assert::same(self::USER_ID, $stored->cancelledBySystemUserId());
            Assert::same(self::NOW, $stored->cancelledAt()?->format('Y-m-d H:i:s'));
        }

        Assert::same(OutboundMessage::STATUS_QUEUED, $status[(int) $email->id()]->status(), 'other channel untouched');
        Assert::same(OutboundMessage::STATUS_QUEUED, $status[(int) $otherTutor->id()]->status(), 'other tutor untouched');
        Assert::same(OutboundMessage::STATUS_QUEUED, $status[(int) $otherTenant->id()]->status(), 'other tenant untouched');
        Assert::same(OutboundMessage::STATUS_MANUAL, $status[(int) $alreadySent->id()]->status(), 'only queued messages are cancelled');
    }

    public function testOptInCancelsNothing(): void
    {
        $this->messages = new FakeOutboundMessageRepository(self::TENANT_ID);
        $queued = self::message(self::TENANT_ID, 1, 'whatsapp', MessagePurpose::APPOINTMENT_CONFIRMATION, 5);
        $this->messages->seed($queued);

        [$service] = $this->build();
        $service->record(1, 'whatsapp', 'opted_in', 'phone', self::ACTION);

        Assert::same(OutboundMessage::STATUS_QUEUED, $this->messages->all()[0]->status());
    }

    public function testDeniedOptOutCancelsNothing(): void
    {
        $this->messages = new FakeOutboundMessageRepository(self::TENANT_ID);
        $queued = self::message(self::TENANT_ID, 1, 'whatsapp', MessagePurpose::APPOINTMENT_CONFIRMATION, 5);
        $this->messages->seed($queued);

        [$service] = $this->build(false);
        Assert::throws(AuthorizationDenied::class, fn () => $service->record(1, 'whatsapp', 'opted_out', 'phone', self::ACTION));

        Assert::same(OutboundMessage::STATUS_QUEUED, $this->messages->all()[0]->status());
    }

    private static function message(int $tenantId, int $tutorId, string $channel, string $purpose, int $unitId): OutboundMessage
    {
        return OutboundMessage::compose(
            tenantId: $tenantId,
            systemUnitId: $unitId,
            tutorId: $tutorId,
            patientId: null,
            templateId: null,
            purpose: $purpose,
            channel: $channel,
            origin: OutboundMessage::ORIGIN_MANUAL,
            legalBasis: MessagePurpose::legalBasisFor($purpose),
            sourceType: null,
            sourceId: null,
            dedupeKey: null,
            recipient: $channel === 'email' ? 'f7a.teste@example.invalid' : '5585999990000',
            subject: $channel === 'email' ? 'F7A teste' : null,
            bodyText: 'F7A teste corpo',
            createdBySystemUserId: self::USER_ID,
        );
    }
}
