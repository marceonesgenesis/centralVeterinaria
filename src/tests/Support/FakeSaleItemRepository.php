<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SaleItemRepositoryInterface;
use CentralVet\Domain\SaleItem;
use InvalidArgumentException;

/**
 * In-memory double for SaleItemRepositoryInterface (T-06): the `sale_item`
 * table does not exist yet (migration T-01 not applied), so
 * SaleServiceTest exercises SaleService::create() against this instead of a
 * real database. Tenant-scoped like the real SaleItemRepository (ADR 0002).
 *
 * remove() actually deletes from the in-memory store, letting
 * SaleServiceTest prove SaleService::create()'s compensating delete on
 * InsufficientStockException removes every SaleItem it just wrote, alongside
 * FakeSaleRepository::remove() for the parent Sale row.
 */
final class FakeSaleItemRepository implements SaleItemRepositoryInterface
{
    /** @var array<int, SaleItem> */
    private array $items = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, SaleItem ...$seed)
    {
        foreach ($seed as $item) {
            $this->save($item);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $item = $this->items[(int) $id] ?? null;

        if ($item === null || $item->tenantId() !== $this->tenantId) {
            return null;
        }

        return $item;
    }

    /** @return list<SaleItem> */
    public function listBySale(int $saleId): array
    {
        return array_values(array_filter(
            $this->items,
            fn (SaleItem $item): bool => $item->tenantId() === $this->tenantId && $item->saleId() === $saleId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SaleItem) {
            throw new InvalidArgumentException('FakeSaleItemRepository only stores SaleItem entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->items[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof SaleItem && $entity->id() !== null) {
            unset($this->items[$entity->id()]);
        }
    }
}
