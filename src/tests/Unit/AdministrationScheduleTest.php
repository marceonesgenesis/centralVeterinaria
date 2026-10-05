<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\AdministrationSchedule;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\HospitalizationAdministration;
use CentralVet\Domain\HospitalizationOrder;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Fase 6A, T-04: administration schedule generation, timeliness
 * classification (30-min tolerance) and the administration/order invariants.
 */
final class AdministrationScheduleTest
{
    private static function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    private static function messageOf(callable $callback): ?string
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function testGenerateStepsByFrequencyWithExclusiveEnd(): void
    {
        $times = AdministrationSchedule::generate(self::at('2026-10-01 08:00'), self::at('2026-10-04 08:00'), 8);

        Assert::count(9, $times);
        Assert::same('2026-10-01 08:00:00', $times[0]->format('Y-m-d H:i:s'));
        Assert::same('2026-10-01 16:00:00', $times[1]->format('Y-m-d H:i:s'));
        Assert::same('2026-10-04 00:00:00', $times[8]->format('Y-m-d H:i:s'));
    }

    public function testGenerateRejectsInvalidPeriodAndFrequency(): void
    {
        Assert::same(30, AdministrationSchedule::MAX_PERIOD_DAYS);
        Assert::same(
            'Prescription period cannot exceed 30 days',
            self::messageOf(fn () => AdministrationSchedule::generate(self::at('2026-10-01 08:00'), self::at('2026-11-01 08:00'), 8)),
        );
        Assert::count(30, AdministrationSchedule::generate(self::at('2026-10-01 08:00'), self::at('2026-10-31 08:00'), 24));
        Assert::same(
            'ends_at must be after starts_at',
            self::messageOf(fn () => AdministrationSchedule::generate(self::at('2026-10-01 08:00'), self::at('2026-10-01 08:00'), 8)),
        );
        Assert::same(
            'frequency_hours must be between 1 and 168',
            self::messageOf(fn () => AdministrationSchedule::generate(self::at('2026-10-01 08:00'), self::at('2026-10-02 08:00'), 0)),
        );
        Assert::same(
            'frequency_hours must be between 1 and 168',
            self::messageOf(fn () => AdministrationSchedule::generate(self::at('2026-10-01 08:00'), self::at('2026-10-02 08:00'), 169)),
        );
        Assert::throws(InvalidArgumentException::class, fn () => AdministrationSchedule::generate(self::at('2026-10-02 08:00'), self::at('2026-10-01 08:00'), 8));
    }

    public function testClassifyAppliesThirtyMinuteTolerance(): void
    {
        $scheduled = self::at('2026-10-01 10:00');

        Assert::same('upcoming', HospitalizationAdministration::classify('pending', $scheduled, null, self::at('2026-10-01 09:59')));
        Assert::same('due', HospitalizationAdministration::classify('pending', $scheduled, null, self::at('2026-10-01 10:00')));
        Assert::same('due', HospitalizationAdministration::classify('pending', $scheduled, null, self::at('2026-10-01 10:30')));
        Assert::same('late', HospitalizationAdministration::classify('pending', $scheduled, null, self::at('2026-10-01 10:31')));
        Assert::same('done', HospitalizationAdministration::classify('done', $scheduled, self::at('2026-10-01 10:30'), self::at('2026-10-01 11:00')));
        Assert::same('done_late', HospitalizationAdministration::classify('done', $scheduled, self::at('2026-10-01 10:45'), self::at('2026-10-01 11:00')));
        Assert::same('skipped', HospitalizationAdministration::classify('skipped', $scheduled, self::at('2026-10-01 10:05'), self::at('2026-10-01 11:00')));
        Assert::same('cancelled', HospitalizationAdministration::classify('cancelled', $scheduled, null, self::at('2026-10-01 11:00')));
        Assert::same(30, HospitalizationAdministration::LATE_TOLERANCE_MINUTES);
    }

    public function testMarkDoneTwiceIsRejected(): void
    {
        $administration = HospitalizationAdministration::schedule(1, 10, 20, self::at('2026-10-01 10:00'));
        $administration->assignId(42);
        Assert::same('pending', $administration->status());

        $administration->markDone(self::at('2026-10-01 10:10'), 7, '');
        Assert::same('done', $administration->status());
        Assert::same(7, $administration->performedBySystemUserId());
        Assert::same('2026-10-01 10:10:00', $administration->performedAt()?->format('Y-m-d H:i:s'));

        Assert::throws(
            InvalidStatusTransitionException::class,
            fn () => $administration->markDone(self::at('2026-10-01 10:20'), 7, ''),
        );
        Assert::same('Administration 42 is not pending', self::messageOf(fn () => $administration->markDone(self::at('2026-10-01 10:20'), 7, '')));
    }

    public function testMarkSkippedRequiresNotesAndCancelLeavesPending(): void
    {
        $administration = HospitalizationAdministration::schedule(1, 10, 20, self::at('2026-10-01 10:00'));
        $administration->assignId(43);

        Assert::same('notes_text is required', self::messageOf(fn () => $administration->markSkipped(self::at('2026-10-01 10:10'), 7, '  ')));
        $administration->markSkipped(self::at('2026-10-01 10:10'), 7, 'Paciente em jejum');
        Assert::same('skipped', $administration->status());
        Assert::same('Paciente em jejum', $administration->notesText());

        $other = HospitalizationAdministration::schedule(1, 10, 20, self::at('2026-10-01 18:00'));
        $other->assignId(44);
        $other->cancel();
        Assert::same('cancelled', $other->status());
        Assert::same('Administration 44 is not pending', self::messageOf(fn () => $other->cancel()));
    }

    public function testPrescribeValidatesProductQuantityAndSuspends(): void
    {
        Assert::same(
            'quantity_per_administration is required when a product is selected',
            self::messageOf(fn () => HospitalizationOrder::prescribe(
                1, 10, HospitalizationOrder::TYPE_MEDICATION, 'Dipirona', 5, null, '25 mg/kg', 'iv', 8,
                self::at('2026-10-01 08:00'), self::at('2026-10-04 08:00'), 7,
            )),
        );

        $order = HospitalizationOrder::prescribe(
            1, 10, HospitalizationOrder::TYPE_MEDICATION, 'Dipirona', 5, 1, '25 mg/kg', 'iv', 8,
            self::at('2026-10-01 08:00'), self::at('2026-10-04 08:00'), 7,
        );
        Assert::same(HospitalizationOrder::STATUS_ACTIVE, $order->status());
        Assert::same(5, $order->productId());
        Assert::same(1, $order->quantityPerAdministration());
        Assert::same(8, $order->frequencyHours());
        Assert::true(in_array('inhalation', HospitalizationOrder::ROUTES, true));

        $order->suspend(self::at('2026-10-02 08:00'));
        Assert::same(HospitalizationOrder::STATUS_SUSPENDED, $order->status());
        Assert::throws(InvalidStatusTransitionException::class, fn () => $order->suspend(self::at('2026-10-02 09:00')));
    }
}
