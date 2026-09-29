<?php

declare(strict_types=1);

namespace CentralVet\Queue;

interface QueueInterface
{
    /**
     * Enqueues a job and returns its generated id.
     *
     * $tenantId is optional metadata carried on the message (nullable for
     * system-level jobs); the queue channel itself is environment-scoped and
     * shared, mirroring the project's "shared storage + tenant_id column"
     * convention rather than one physical queue per tenant.
     */
    public function push(
        string $queue,
        array $payload,
        ?int $tenantId = null,
        ?string $correlationId = null,
        int $delaySeconds = 0,
        int $maxAttempts = 5,
    ): string;

    /** Blocks up to $timeoutSeconds waiting for a message; null when none arrived. */
    public function pop(string $queue, int $timeoutSeconds = 5): ?QueueMessage;

    /** Marks a message as successfully processed, removing it permanently. */
    public function ack(QueueMessage $message): void;

    /**
     * Marks a message as failed. Re-schedules it with exponential backoff
     * while attempts remain, otherwise moves it to the dead-letter queue.
     */
    public function fail(QueueMessage $message, ?string $reason = null): void;

    /** Moves due delayed/retry jobs back onto the pending list; returns how many moved. */
    public function recoverDue(string $queue): int;

    public function deadLetterCount(string $queue): int;
}
