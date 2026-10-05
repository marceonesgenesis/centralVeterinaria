<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\Bed;
use CentralVet\Domain\EncounterAccountItem;
use CentralVet\Domain\Exception\BedUnavailableException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Exception\PatientAlreadyHospitalizedException;
use CentralVet\Domain\Hospitalization;
use CentralVet\Domain\HospitalizationEvent;
use CentralVet\Domain\StockMovement;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for the Phase 6A hospitalization Domain (T-03): Bed,
 * Hospitalization, HospitalizationEvent, their exceptions and the new
 * account item type / stock movement reason. Pure Domain, no database.
 */
final class HospitalizationDomainTest
{
    private function admit(): Hospitalization
    {
        return Hospitalization::admit(
            tenantId: 1,
            systemUnitId: 1,
            patientId: 5,
            encounterId: 9,
            bedId: 3,
            responsibleSystemUserId: 10,
            admittedBySystemUserId: 10,
            reasonText: '  Fluidoterapia  ',
            expectedDischargeDate: null,
            dailyRateCents: 15000,
            admittedAt: new DateTimeImmutable('2026-10-01 10:00:00'),
        );
    }

    public function testBillableDaysIsAtLeastOneAndRoundsStartedDaysUp(): void
    {
        $hospitalization = $this->admit();

        Assert::same(1, $hospitalization->billableDays(new DateTimeImmutable('2026-10-01 10:30:00')));
        Assert::same(2, $hospitalization->billableDays(new DateTimeImmutable('2026-10-03 09:00:00')));
        Assert::same(1, $hospitalization->billableDays(new DateTimeImmutable('2026-10-02 10:00:00')));
        Assert::same(1, $hospitalization->billableDays(new DateTimeImmutable('2026-10-01 09:00:00')));
    }

    public function testAdmitTrimsReasonAndStartsAdmitted(): void
    {
        $hospitalization = $this->admit();

        Assert::same('Fluidoterapia', $hospitalization->reasonText());
        Assert::same(Hospitalization::STATUS_ADMITTED, $hospitalization->status());
        Assert::same(3, $hospitalization->bedId());
        Assert::same(15000, $hospitalization->dailyRateCents());
        Assert::null($hospitalization->dischargedAt());
    }

    public function testAdmitWithoutReasonIsRejected(): void
    {
        try {
            Hospitalization::admit(1, 1, 5, 9, 3, 10, 10, '   ', null, 0, new DateTimeImmutable());
        } catch (InvalidArgumentException $e) {
            Assert::same('reason_text is required', $e->getMessage());

            return;
        }

        Assert::true(false, 'Expected InvalidArgumentException');
    }

    public function testMoveToBedAfterDischargeThrowsInvalidStatusTransition(): void
    {
        $hospitalization = $this->admit();
        $hospitalization->assignId(42);
        $hospitalization->moveToBed(4);
        Assert::same(4, $hospitalization->bedId());

        $hospitalization->discharge(new DateTimeImmutable('2026-10-02 12:00:00'), 11, 'Alta clínica');
        Assert::same(Hospitalization::STATUS_DISCHARGED, $hospitalization->status());
        Assert::same('Alta clínica', $hospitalization->dischargeSummaryText());

        try {
            $hospitalization->moveToBed(5);
        } catch (InvalidStatusTransitionException $e) {
            Assert::same('Hospitalization 42 is not admitted', $e->getMessage());

            return;
        }

        Assert::true(false, 'Expected InvalidStatusTransitionException');
    }

    public function testDeactivateOccupiedBedThrowsBedUnavailable(): void
    {
        $bed = Bed::reconstitute([
            'id' => 7,
            'tenant_id' => 1,
            'system_unit_id' => 1,
            'code' => 'L-01',
            'name' => 'Leito 1',
            'daily_rate_cents' => 10000,
            'status' => Bed::STATUS_OCCUPIED,
            'current_hospitalization_id' => 42,
        ]);

        Assert::false($bed->isAvailable());

        try {
            $bed->deactivate();
        } catch (BedUnavailableException $e) {
            Assert::same('Bed 7 is occupied and cannot be deactivated', $e->getMessage());
            Assert::instanceOf(\DomainException::class, $e);

            return;
        }

        Assert::true(false, 'Expected BedUnavailableException');
    }

    public function testBedCreateValidatesAndToggles(): void
    {
        $bed = Bed::create(1, 1, '  L-02 ', 'Leito 2', 0);
        Assert::same('L-02', $bed->code());
        Assert::true($bed->isAvailable());
        Assert::null($bed->currentHospitalizationId());

        $bed->deactivate();
        Assert::same(Bed::STATUS_INACTIVE, $bed->status());
        $bed->activate();
        Assert::same(Bed::STATUS_AVAILABLE, $bed->status());

        Assert::throws(InvalidArgumentException::class, fn () => Bed::create(1, 1, '', 'x', 0));
        Assert::throws(InvalidArgumentException::class, fn () => Bed::create(1, 1, str_repeat('a', 31), 'x', 0));
        Assert::throws(InvalidArgumentException::class, fn () => Bed::create(1, 1, 'A', '', 0));
        Assert::throws(InvalidArgumentException::class, fn () => Bed::create(1, 1, 'A', 'x', -1));
    }

    public function testEventValidation(): void
    {
        $at = new DateTimeImmutable('2026-10-01 12:00:00');

        $vitals = HospitalizationEvent::record(1, 42, HospitalizationEvent::TYPE_VITALS, 10, $at, '', temperatureC: 38.5);
        Assert::same(38.5, $vitals->temperatureC());

        $this->assertMessage('notes_text is required', fn () => HospitalizationEvent::record(1, 42, HospitalizationEvent::TYPE_EVOLUTION, 10, $at, '  '));
        $this->assertMessage('At least one vital sign is required', fn () => HospitalizationEvent::record(1, 42, HospitalizationEvent::TYPE_VITALS, 10, $at, ''));
        $this->assertMessage('pain_score must be between 0 and 10', fn () => HospitalizationEvent::record(1, 42, HospitalizationEvent::TYPE_VITALS, 10, $at, '', painScore: 11));
        Assert::throws(InvalidArgumentException::class, fn () => HospitalizationEvent::record(1, 42, HospitalizationEvent::TYPE_TRANSFER, 10, $at, '', fromBedId: 3));

        $transfer = HospitalizationEvent::record(1, 42, HospitalizationEvent::TYPE_TRANSFER, 10, $at, '', fromBedId: 3, toBedId: 4);
        Assert::same(4, $transfer->toBedId());
    }

    public function testExceptionFactoriesAndNewAccountTypeAndStockReason(): void
    {
        Assert::same('Bed 3 is not available', BedUnavailableException::forBed(3)->getMessage());
        Assert::same(
            'Patient 5 already has an active hospitalization',
            PatientAlreadyHospitalizedException::forPatient(5)->getMessage(),
        );
        Assert::instanceOf(\DomainException::class, PatientAlreadyHospitalizedException::forPatient(5));

        $item = EncounterAccountItem::create(1, 2, 'hospitalization_stay', 7, 'Diárias de internação', 30000);
        Assert::same(EncounterAccountItem::TYPE_HOSPITALIZATION_STAY, $item->sourceType());
        Assert::same(7, $item->sourceId());

        EncounterAccountItem::create(1, 2, EncounterAccountItem::TYPE_HOSPITALIZATION_ADMINISTRATION, 8, 'Dipirona', 500);
        Assert::throws(
            InvalidArgumentException::class,
            fn () => EncounterAccountItem::create(1, 2, EncounterAccountItem::TYPE_HOSPITALIZATION_STAY, null, 'x', 1),
        );

        $movement = StockMovement::record(
            1, 1, 1, 1, StockMovement::TYPE_OUT, 2, StockMovement::REASON_HOSPITALIZATION_CONSUMPTION, 'hospitalization', 42, 10,
        );
        Assert::same('hospitalization_consumption', $movement->reason());
    }

    private function assertMessage(string $expected, callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            Assert::same($expected, $e->getMessage());

            return;
        }

        Assert::true(false, "Expected InvalidArgumentException '{$expected}'");
    }
}
