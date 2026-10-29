<?php

declare(strict_types=1);

namespace CentralVet\Operations\Contract;

use DateTimeImmutable;

/**
 * Last successful run of each operational job (`ops_heartbeat`, migration
 * 0019). The detail is a short, non-personal summary (counts, file names).
 */
interface HeartbeatRepositoryInterface
{
    public const JOB_BILLING_RECONCILE = 'billing-reconcile';
    public const JOB_BACKUP = 'backup';
    public const JOB_OPS_MONITOR = 'ops-monitor';
    public const JOB_BILLING_EVENT_RETENTION = 'billing-event-retention';

    /** Records a successful run of $job at $at (upsert: one row per job). */
    public function record(string $job, DateTimeImmutable $at, string $detail): void;

    /** Time of the last successful run of $job, or null when it never ran. */
    public function lastSuccess(string $job): ?DateTimeImmutable;
}
