<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Queue\QueueInterface;
use CentralVet\Queue\QueueMessage;
use LogicException;

/**
 * Write-only double for QueueInterface (T-06): push() records the job and
 * returns a sequential id; pushed() lists what was published. Consuming
 * (pop/ack/fail) is the RedisQueue's job and is not simulated here.
 */
final class FakeQueue implements QueueInterface
{
    /** @var list<array{queue: string, payload: array<mixed>, tenantId: ?int, maxAttempts: int}> */
    private array $pushed = [];

    public function push(
        string $queue,
        array $payload,
        ?int $tenantId = null,
        ?string $correlationId = null,
        int $delaySeconds = 0,
        int $maxAttempts = 5,
    ): string {
        $this->pushed[] = [
            'queue' => $queue,
            'payload' => $payload,
            'tenantId' => $tenantId,
            'maxAttempts' => $maxAttempts,
        ];

        return 'fake-job-' . count($this->pushed);
    }

    /** @return list<array{queue: string, payload: array<mixed>, tenantId: ?int, maxAttempts: int}> */
    public function pushed(): array
    {
        return $this->pushed;
    }

    public function pop(string $queue, int $timeoutSeconds = 5): ?QueueMessage
    {
        return null;
    }

    public function ack(QueueMessage $message): void
    {
        throw new LogicException('FakeQueue does not consume messages');
    }

    public function fail(QueueMessage $message, ?string $reason = null): void
    {
        throw new LogicException('FakeQueue does not consume messages');
    }

    public function recoverDue(string $queue): int
    {
        return 0;
    }

    public function deadLetterCount(string $queue): int
    {
        return 0;
    }
}
