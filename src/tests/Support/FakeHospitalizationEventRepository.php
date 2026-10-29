<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\HospitalizationEventRepositoryInterface;
use CentralVet\Domain\HospitalizationEvent;
use InvalidArgumentException;

/**
 * In-memory double for HospitalizationEventRepositoryInterface (T-06), modelled on
 * FakeEncounterAccountRepository: save() assigns incremental ids (seeded
 * entities that already have one keep it), and every read only sees
 * entities whose tenantId() matches this instance's $tenantId.
 */
final class FakeHospitalizationEventRepository implements HospitalizationEventRepositoryInterface
{
    /** @var array<int, HospitalizationEvent> */
    private array $events = [];
    private int $nextId = 1;

    /** Number of save() calls after construction (seed not counted). */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId, HospitalizationEvent ...$seed)
    {
        foreach ($seed as $item) {
            $this->save($item);
        }

        $this->saveCount = 0;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $item = $this->events[(int) $id] ?? null;

        if ($item === null || $item->tenantId() !== $this->tenantId) {
            return null;
        }

        return $item;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof HospitalizationEvent) {
            throw new InvalidArgumentException('FakeHospitalizationEventRepository only stores HospitalizationEvent entities');
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
        if ($entity instanceof HospitalizationEvent && $entity->id() !== null) {
            unset($this->events[$entity->id()]);
        }
    }

    /** @return list<HospitalizationEvent> */
    private function ownTenant(): array
    {
        return array_values(array_filter(
            $this->events,
            fn (HospitalizationEvent $item): bool => $item->tenantId() === $this->tenantId,
        ));
    }

    public function listByHospitalization(int $hospitalizationId): array
    {
        $events = array_values(array_filter(
            $this->ownTenant(),
            static fn (HospitalizationEvent $event): bool => $event->hospitalizationId() === $hospitalizationId,
        ));
        usort($events, static fn (HospitalizationEvent $a, HospitalizationEvent $b): int => [$b->recordedAt(), $b->id()] <=> [$a->recordedAt(), $a->id()]);

        return $events;
    }
}
