<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\HospitalizationOrderRepositoryInterface;
use CentralVet\Domain\HospitalizationOrder;
use InvalidArgumentException;

/**
 * In-memory double for HospitalizationOrderRepositoryInterface (T-06), modelled on
 * FakeEncounterAccountRepository: save() assigns incremental ids (seeded
 * entities that already have one keep it), and every read only sees
 * entities whose tenantId() matches this instance's $tenantId.
 */
final class FakeHospitalizationOrderRepository implements HospitalizationOrderRepositoryInterface
{
    /** @var array<int, HospitalizationOrder> */
    private array $orders = [];
    private int $nextId = 1;

    /** Number of save() calls after construction (seed not counted). */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId, HospitalizationOrder ...$seed)
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
        $item = $this->orders[(int) $id] ?? null;

        if ($item === null || $item->tenantId() !== $this->tenantId) {
            return null;
        }

        return $item;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof HospitalizationOrder) {
            throw new InvalidArgumentException('FakeHospitalizationOrderRepository only stores HospitalizationOrder entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->orders[$entity->id()] = $entity;
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof HospitalizationOrder && $entity->id() !== null) {
            unset($this->orders[$entity->id()]);
        }
    }

    /** @return list<HospitalizationOrder> */
    private function ownTenant(): array
    {
        return array_values(array_filter(
            $this->orders,
            fn (HospitalizationOrder $item): bool => $item->tenantId() === $this->tenantId,
        ));
    }

    public function listByHospitalization(int $hospitalizationId): array
    {
        return array_values(array_filter(
            $this->ownTenant(),
            static fn (HospitalizationOrder $order): bool => $order->hospitalizationId() === $hospitalizationId,
        ));
    }
}
