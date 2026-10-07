<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SurgeryEventRepositoryInterface;
use CentralVet\Domain\SurgeryEvent;
use InvalidArgumentException;
use LogicException;

/**
 * In-memory double for SurgeryEventRepositoryInterface (T-05): append-only
 * timeline (remove() throws LogicException), listBySurgery() most recent
 * first (recorded_at DESC, id DESC), reads limited to this tenant.
 */
final class FakeSurgeryEventRepository implements SurgeryEventRepositoryInterface
{
    /** @var array<int, SurgeryEvent> */
    private array $events = [];
    private int $nextId = 1;

    /** Number of save() calls. */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId)
    {
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $event = $this->events[(int) $id] ?? null;

        if ($event === null || $event->tenantId() !== $this->tenantId) {
            return null;
        }

        return $event;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryEvent) {
            throw new InvalidArgumentException('FakeSurgeryEventRepository only stores SurgeryEvent entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->events[$entity->id()] = $entity;
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        throw new LogicException('Surgery events are append-only');
    }

    public function listBySurgery(int $surgeryId): array
    {
        $events = array_values(array_filter(
            $this->events,
            fn (SurgeryEvent $e): bool => $e->tenantId() === $this->tenantId && $e->surgeryId() === $surgeryId,
        ));
        usort($events, static fn (SurgeryEvent $a, SurgeryEvent $b): int => [$b->recordedAt(), $b->id()] <=> [$a->recordedAt(), $a->id()]);

        return $events;
    }
}
