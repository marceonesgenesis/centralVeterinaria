<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ReminderSourceQueryInterface;
use CentralVet\Domain\ReminderCandidate;
use DateTimeImmutable;

/**
 * Double for ReminderSourceQueryInterface (T-06): each method returns the
 * list given in the constructor (no date filtering, the caller's window is
 * what the tests assert) and records the call as `[<method>, ...args]`.
 */
final class FakeReminderSourceQuery implements ReminderSourceQueryInterface
{
    /** @var list<array{0: string, 1: DateTimeImmutable, 2?: DateTimeImmutable}> */
    private array $calls = [];

    /**
     * @param list<ReminderCandidate> $appointments
     * @param list<ReminderCandidate> $vaccines
     * @param list<ReminderCandidate> $receivables
     */
    public function __construct(
        private readonly array $appointments = [],
        private readonly array $vaccines = [],
        private readonly array $receivables = [],
    ) {
    }

    public function appointmentsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls[] = ['appointmentsBetween', $from, $to];

        return $this->appointments;
    }

    public function vaccinesDueBetween(DateTimeImmutable $fromDate, DateTimeImmutable $toDate): array
    {
        $this->calls[] = ['vaccinesDueBetween', $fromDate, $toDate];

        return $this->vaccines;
    }

    public function openReceivablesCreatedBefore(DateTimeImmutable $before): array
    {
        $this->calls[] = ['openReceivablesCreatedBefore', $before];

        return $this->receivables;
    }

    /** @return list<array{0: string, 1: DateTimeImmutable, 2?: DateTimeImmutable}> */
    public function calls(): array
    {
        return $this->calls;
    }
}
