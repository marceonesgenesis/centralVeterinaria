<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\StockMovementRepositoryInterface;
use CentralVet\Domain\StockMovement;
use InvalidArgumentException;

/**
 * In-memory double for StockMovementRepositoryInterface (T-03): the
 * `stock_movement` table does not exist yet (migration T-01 not applied), so
 * StockServiceTest/ProcedureExecutionServiceTest/SaleServiceTest exercise
 * their services against this instead of a real database. Tenant-scoped
 * like the real StockMovementRepository (ADR 0002): findById()/
 * listByProduct() only ever return a movement whose tenantId() matches this
 * instance's own $tenantId. Append-only in spirit (mirrors the real ledger
 * table), but save()/remove() still work like every other fake so tests can
 * assert on what was actually written.
 */
final class FakeStockMovementRepository implements StockMovementRepositoryInterface
{
    /** @var array<int, StockMovement> */
    private array $movements = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, StockMovement ...$seed)
    {
        foreach ($seed as $movement) {
            $this->save($movement);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $movement = $this->movements[(int) $id] ?? null;

        if ($movement === null || $movement->tenantId() !== $this->tenantId) {
            return null;
        }

        return $movement;
    }

    /** @return list<StockMovement> */
    public function listByProduct(int $productId): array
    {
        return array_values(array_filter(
            $this->movements,
            fn (StockMovement $movement): bool => $movement->tenantId() === $this->tenantId
                && $movement->productId() === $productId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof StockMovement) {
            throw new InvalidArgumentException('FakeStockMovementRepository only stores StockMovement entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->movements[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof StockMovement && $entity->id() !== null) {
            unset($this->movements[$entity->id()]);
        }
    }
}
