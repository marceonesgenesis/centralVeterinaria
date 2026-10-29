<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\ReminderCandidate;
use DateTimeImmutable;

/**
 * Read boundary of the automatic reminders (Fase 7A): lists the sources that
 * may generate a reminder, within the current tenant, as
 * {@see ReminderCandidate} (contact in memory only).
 */
interface ReminderSourceQueryInterface
{
    /**
     * Appointments scheduled between `$from` and `$to`: purpose `return_reminder`
     * for a return appointment, `appointment_confirmation` for the others.
     *
     * @return list<ReminderCandidate>
     */
    public function appointmentsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Vaccine next doses due between the two dates (inclusive).
     *
     * @return list<ReminderCandidate>
     */
    public function vaccinesDueBetween(DateTimeImmutable $fromDate, DateTimeImmutable $toDate): array;

    /**
     * Open receivables created before `$before`.
     *
     * @return list<ReminderCandidate>
     */
    public function openReceivablesCreatedBefore(DateTimeImmutable $before): array;
}
