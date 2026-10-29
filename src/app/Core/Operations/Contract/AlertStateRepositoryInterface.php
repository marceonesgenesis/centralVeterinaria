<?php

declare(strict_types=1);

namespace CentralVet\Operations\Contract;

use DateTimeImmutable;

/**
 * Deduplication state of operator alerts (`ops_alert_state`, migration
 * 0019): when each alert key was last e-mailed and how many times.
 */
interface AlertStateRepositoryInterface
{
    /** Last time $alertKey was sent, or null when it never was. */
    public function lastSentAt(string $alertKey): ?DateTimeImmutable;

    /** Stores $at as the last send of $alertKey and counts one more send. */
    public function markSent(string $alertKey, string $subject, DateTimeImmutable $at): void;
}
