<?php

declare(strict_types=1);

namespace CentralVet\Queue;

/**
 * Immutable envelope for a message popped from a queue. Carries the raw
 * JSON exactly as stored in the processing list, required to ack/fail it
 * with an exact-match LREM.
 */
final class QueueMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly array $payload,
        public readonly ?int $tenantId,
        public readonly string $correlationId,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        private readonly string $rawEnvelope,
    ) {
    }

    public function rawEnvelope(): string
    {
        return $this->rawEnvelope;
    }
}
