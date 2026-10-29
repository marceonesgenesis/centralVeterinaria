<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Billing\Contract\BillingEventRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * In-memory double for BillingEventRepositoryInterface (T-09) with the same
 * deduplication by event_id as BillingEventRepository. $events is public so
 * tests can read status, attempts, last_error and processed_at.
 */
final class FakeBillingEventRepository implements BillingEventRepositoryInterface
{
    private const DONE_STATUSES = ['processed', 'ignored'];

    /**
     * @var array<int, array{event_id: string, event_type: string, event_created: int, livemode: bool,
     *     payload: string, status: string, attempts: int, last_error: ?string, processed_at: ?DateTimeImmutable}>
     */
    public array $events = [];

    private int $nextId = 1;

    public function claim(string $eventId, string $eventType, int $eventCreated, bool $livemode, string $payload): ?int
    {
        foreach ($this->events as $id => $event) {
            if ($event['event_id'] !== $eventId) {
                continue;
            }

            if (in_array($event['status'], self::DONE_STATUSES, true)) {
                return null;
            }

            $this->events[$id]['attempts']++;

            return $id;
        }

        $id = $this->nextId++;
        $this->events[$id] = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'event_created' => $eventCreated,
            'livemode' => $livemode,
            'payload' => $payload,
            'status' => 'received',
            'attempts' => 1,
            'last_error' => null,
            'processed_at' => null,
        ];

        return $id;
    }

    public function markDone(int $id, string $status, DateTimeImmutable $at): void
    {
        if (!in_array($status, self::DONE_STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Billing event cannot be marked done as "%s"', $status));
        }

        $this->assertExists($id);
        $this->events[$id]['status'] = $status;
        $this->events[$id]['processed_at'] = $at;
    }

    public function markFailed(int $id, string $error): void
    {
        $this->assertExists($id);
        $this->events[$id]['status'] = 'failed';
        $this->events[$id]['last_error'] = mb_substr($error, 0, 190);
    }

    public function latestEventCreated(): ?int
    {
        if ($this->events === []) {
            return null;
        }

        return max(array_column($this->events, 'event_created'));
    }

    private function assertExists(int $id): void
    {
        if (!isset($this->events[$id])) {
            throw new RuntimeException(sprintf('Billing event %d not found', $id));
        }
    }
}
