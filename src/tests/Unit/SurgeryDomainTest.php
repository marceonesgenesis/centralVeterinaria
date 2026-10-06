<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\EncounterAccountItem;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Exception\SurgeryRoomUnavailableException;
use CentralVet\Domain\StockMovement;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryRoom;
use CentralVet\Domain\SurgeryTeamMember;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Unit tests for the Phase 6B surgery Domain (T-02): SurgeryRoom, Surgery,
 * SurgeryTeamMember, SurgeryRoomUnavailableException and the new account
 * item types / stock movement reason. Pure Domain, no database.
 */
final class SurgeryDomainTest
{
    private function schedule(
        string $start = '2026-10-10 08:00:00',
        string $end = '2026-10-10 10:00:00',
        ?string $notes = null,
        string $procedureName = 'Orquiectomia',
        int $priceCents = 80000,
    ): Surgery {
        return Surgery::schedule(
            tenantId: 1,
            systemUnitId: 1,
            patientId: 5,
            encounterId: 9,
            roomId: 2,
            procedureCatalogItemId: 4,
            procedureName: $procedureName,
            procedurePriceCents: $priceCents,
            surgeonSystemUserId: 10,
            scheduledBySystemUserId: 11,
            scheduledStartAt: new DateTimeImmutable($start),
            scheduledEndAt: new DateTimeImmutable($end),
            notesText: $notes,
        );
    }

    /** @return array<string, mixed> */
    private function row(string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'tenant_id' => 1,
            'system_unit_id' => 1,
            'patient_id' => 5,
            'encounter_id' => 9,
            'room_id' => 2,
            'procedure_catalog_item_id' => 4,
            'procedure_name' => 'Orquiectomia',
            'procedure_price_cents' => 80000,
            'surgeon_system_user_id' => 10,
            'scheduled_by_system_user_id' => 11,
            'scheduled_start_at' => '2026-10-10 08:00:00.000000',
            'scheduled_end_at' => '2026-10-10 10:00:00.000000',
            'status' => $status,
            'notes_text' => null,
            'consent_signer_name' => 'Maria Tutora',
            'consent_text' => 'Autorizo o procedimento.',
            'consent_recorded_at' => '2026-10-10 07:30:00.000000',
            'consent_recorded_by_system_user_id' => 11,
            'started_at' => null,
            'completed_at' => null,
            'completed_by_system_user_id' => null,
            'cancelled_at' => null,
            'cancelled_by_system_user_id' => null,
            'cancellation_reason_text' => null,
            'followup_appointment_id' => null,
            'created_at' => '2026-10-05 10:00:00.000000',
            'updated_at' => '2026-10-05 10:00:00.000000',
        ], $overrides);
    }

    private static function expectMessage(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Assert::instanceOf($class, $e, 'Unexpected exception: ' . $e->getMessage());
            Assert::same($message, $e->getMessage());

            return;
        }

        Assert::true(false, "Expected {$class} with message \"{$message}\"");
    }

    public function testStartRequiresPreOpAndConsent(): void
    {
        $surgery = $this->schedule();
        $surgery->assignId(42);
        $at = new DateTimeImmutable('2026-10-10 08:05:00');

        Assert::same(Surgery::STATUS_SCHEDULED, $surgery->status());
        Assert::null($surgery->loadedStatus());
        Assert::true($surgery->isOpenForPreOp());
        Assert::false($surgery->hasConsent());

        self::expectMessage(InvalidStatusTransitionException::class, 'Surgery 42 is not in pre-op', fn () => $surgery->start($at));

        $surgery->startPreOp();
        Assert::same(Surgery::STATUS_PRE_OP, $surgery->status());
        self::expectMessage(InvalidStatusTransitionException::class, 'Surgery 42 is not scheduled', fn () => $surgery->startPreOp());
        self::expectMessage(InvalidStatusTransitionException::class, 'Surgery 42 has no recorded consent', fn () => $surgery->start($at));

        $surgery->recordConsent('  Maria Tutora ', 'Autorizo.', 11, new DateTimeImmutable('2026-10-10 07:50:00'));
        $surgery->recordConsent('João Tutor', 'Autorizo o procedimento.', 11, new DateTimeImmutable('2026-10-10 07:55:00'));
        Assert::true($surgery->hasConsent());
        Assert::same('João Tutor', $surgery->consentSignerName());
        Assert::same('Autorizo o procedimento.', $surgery->consentText());

        $surgery->start($at);
        Assert::same(Surgery::STATUS_IN_PROGRESS, $surgery->status());
        Assert::same($at, $surgery->startedAt());
        Assert::false($surgery->isOpenForPreOp());

        self::expectMessage(
            InvalidStatusTransitionException::class,
            'Surgery 42 cannot be cancelled in its current status',
            fn () => $surgery->cancel($at, 11, 'Desistência'),
        );
        self::expectMessage(
            InvalidStatusTransitionException::class,
            'Surgery 42 is not open for pre-operative changes',
            fn () => $surgery->recordConsent('X', 'Y', 11, $at),
        );
    }

    public function testLoadedStatusDoesNotFollowTransitions(): void
    {
        $surgery = Surgery::reconstitute($this->row(Surgery::STATUS_PRE_OP));

        Assert::same(Surgery::STATUS_PRE_OP, $surgery->loadedStatus());
        $surgery->start(new DateTimeImmutable('2026-10-10 08:10:00'));
        Assert::same(Surgery::STATUS_IN_PROGRESS, $surgery->status());
        Assert::same(Surgery::STATUS_PRE_OP, $surgery->loadedStatus());
    }

    public function testCompleteAndFollowUp(): void
    {
        $surgery = Surgery::reconstitute($this->row(Surgery::STATUS_IN_PROGRESS, ['started_at' => '2026-10-10 08:10:00']));
        $at = new DateTimeImmutable('2026-10-10 09:40:00');

        self::expectMessage(InvalidStatusTransitionException::class, 'Surgery 42 is not completed', fn () => $surgery->linkFollowUp(77));

        $surgery->complete($at, 10);
        Assert::same(Surgery::STATUS_COMPLETED, $surgery->status());
        Assert::same($at, $surgery->completedAt());
        self::expectMessage(InvalidStatusTransitionException::class, 'Surgery 42 is not in progress', fn () => $surgery->complete($at, 10));

        $surgery->linkFollowUp(77);
        Assert::same(77, $surgery->followupAppointmentId());
        self::expectMessage(
            InvalidStatusTransitionException::class,
            'Surgery 42 already has a follow-up appointment',
            fn () => $surgery->linkFollowUp(78),
        );
    }

    public function testCancelRequiresReason(): void
    {
        $surgery = Surgery::reconstitute($this->row(Surgery::STATUS_SCHEDULED));
        $at = new DateTimeImmutable('2026-10-09 18:00:00');

        self::expectMessage(InvalidArgumentException::class, 'cancellation_reason_text is required', fn () => $surgery->cancel($at, 11, '   '));

        $surgery->cancel($at, 11, ' Tutor desmarcou ');
        Assert::same(Surgery::STATUS_CANCELLED, $surgery->status());
        Assert::same('Tutor desmarcou', $surgery->cancellationReasonText());
        Assert::same($at, $surgery->cancelledAt());
    }

    public function testScheduleValidatesInput(): void
    {
        self::expectMessage(
            InvalidArgumentException::class,
            'scheduled_end_at must be after scheduled_start_at',
            fn () => $this->schedule('2026-10-10 10:00:00', '2026-10-10 08:00:00'),
        );
        self::expectMessage(
            InvalidArgumentException::class,
            'scheduled_end_at must be after scheduled_start_at',
            fn () => $this->schedule('2026-10-10 08:00:00', '2026-10-10 08:00:00'),
        );
        self::expectMessage(
            InvalidArgumentException::class,
            'Surgery duration cannot exceed 24 hours',
            fn () => $this->schedule('2026-10-10 08:00:00', '2026-10-11 08:00:01'),
        );
        self::expectMessage(
            InvalidArgumentException::class,
            'notes_text must have at most 500 characters',
            fn () => $this->schedule(notes: str_repeat('a', 501)),
        );
        self::expectMessage(InvalidArgumentException::class, 'procedure_name is required', fn () => $this->schedule(procedureName: '  '));
        self::expectMessage(
            InvalidArgumentException::class,
            'procedure_price_cents must be zero or positive',
            fn () => $this->schedule(priceCents: -1),
        );

        $surgery = $this->schedule('2026-10-10 08:00:00', '2026-10-11 08:00:00', '  ');
        Assert::null($surgery->notesText());
        Assert::same('Orquiectomia', $surgery->procedureName());
        Assert::same(80000, $surgery->procedurePriceCents());
    }

    public function testConsentValidation(): void
    {
        $surgery = $this->schedule();
        $at = new DateTimeImmutable('2026-10-10 07:00:00');

        self::expectMessage(InvalidArgumentException::class, 'consent_signer_name is required', fn () => $surgery->recordConsent(' ', 'x', 11, $at));
        self::expectMessage(
            InvalidArgumentException::class,
            'consent_signer_name must have at most 190 characters',
            fn () => $surgery->recordConsent(str_repeat('a', 191), 'x', 11, $at),
        );
        self::expectMessage(InvalidArgumentException::class, 'consent_text is required', fn () => $surgery->recordConsent('Maria', '  ', 11, $at));
        Assert::false($surgery->hasConsent());
    }

    public function testRoomAndTeamMember(): void
    {
        $room = SurgeryRoom::create(1, 1, ' SC-01 ', ' Sala 1 ');
        Assert::same('SC-01', $room->code());
        Assert::same('Sala 1', $room->name());
        Assert::true($room->isActive());
        $room->deactivate();
        Assert::same(SurgeryRoom::STATUS_INACTIVE, $room->status());
        $room->activate();
        Assert::same(SurgeryRoom::STATUS_ACTIVE, $room->status());
        Assert::throws(InvalidArgumentException::class, fn () => SurgeryRoom::create(1, 1, str_repeat('a', 31), 'x'));
        Assert::throws(InvalidArgumentException::class, fn () => SurgeryRoom::create(1, 1, 'A', str_repeat('a', 121)));

        $member = SurgeryTeamMember::create(1, 42, 10, SurgeryTeamMember::ROLE_ANESTHETIST);
        Assert::same(42, $member->surgeryId());
        Assert::same(10, $member->systemUserId());
        Assert::same('anesthetist', $member->role());
        Assert::count(4, SurgeryTeamMember::ROLES);
        self::expectMessage(InvalidArgumentException::class, 'Unknown team role "nurse"', fn () => SurgeryTeamMember::create(1, 42, 10, 'nurse'));

        $booked = SurgeryRoomUnavailableException::booked(3);
        Assert::instanceOf(\DomainException::class, $booked);
        Assert::same('Surgery room 3 is already booked for this period', $booked->getMessage());
        Assert::same('Surgery room 3 is not active', SurgeryRoomUnavailableException::inactive(3)->getMessage());
    }

    public function testSurgeryAccountTypesAndStockReason(): void
    {
        $item = EncounterAccountItem::create(1, 3, 'surgery_material', 7, 'Fio de sutura', 1500);
        Assert::same(EncounterAccountItem::TYPE_SURGERY_MATERIAL, $item->sourceType());
        Assert::same(7, $item->sourceId());
        Assert::same('surgery_procedure', EncounterAccountItem::TYPE_SURGERY_PROCEDURE);
        Assert::throws(
            InvalidArgumentException::class,
            fn () => EncounterAccountItem::create(1, 3, EncounterAccountItem::TYPE_SURGERY_PROCEDURE, null, 'Cirurgia', 1),
        );
        Assert::same('surgery_consumption', StockMovement::REASON_SURGERY_CONSUMPTION);
    }
}
