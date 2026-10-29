<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pure calculation of the administration times of an internal
 * (hospitalization) prescription: every `frequencyHours` from `startsAt`,
 * with `endsAt` exclusive. The whole schedule is generated when the order is
 * prescribed (Fase 6A decision: `ends_at` mandatory, at most 30 days; no
 * background job).
 *
 * No Adianti dependency (ADR 0001).
 */
final class AdministrationSchedule
{
    public const MAX_PERIOD_DAYS = 30;
    public const MIN_FREQUENCY_HOURS = 1;
    public const MAX_FREQUENCY_HOURS = 168;

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException when the period is empty/inverted,
     *         longer than {@see self::MAX_PERIOD_DAYS} days or the frequency
     *         is outside 1..168 hours.
     */
    public static function assertValid(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, int $frequencyHours): void
    {
        if ($endsAt <= $startsAt) {
            throw new InvalidArgumentException('ends_at must be after starts_at');
        }

        if ($startsAt->modify('+' . self::MAX_PERIOD_DAYS . ' days') < $endsAt) {
            throw new InvalidArgumentException('Prescription period cannot exceed 30 days');
        }

        if ($frequencyHours < self::MIN_FREQUENCY_HOURS || $frequencyHours > self::MAX_FREQUENCY_HOURS) {
            throw new InvalidArgumentException('frequency_hours must be between 1 and 168');
        }
    }

    /**
     * @return list<DateTimeImmutable> times from `startsAt` (inclusive) to
     *         `endsAt` (exclusive), stepping `frequencyHours`.
     */
    public static function generate(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, int $frequencyHours): array
    {
        self::assertValid($startsAt, $endsAt, $frequencyHours);

        $step = new DateInterval('PT' . $frequencyHours . 'H');
        $times = [];

        for ($current = $startsAt; $current < $endsAt; $current = $current->add($step)) {
            $times[] = $current;
        }

        return $times;
    }
}
