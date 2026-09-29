<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ProcedureCatalogRepositoryInterface;
use CentralVet\Domain\ProcedureCatalogItem;
use InvalidArgumentException;

/**
 * In-memory double for ProcedureCatalogRepositoryInterface (T-04): the
 * `procedure_catalog_item` table does not exist yet (migration T-01 not
 * applied), so ProcedureExecutionServiceTest/SaleServiceTest exercise their
 * services against this instead of a real database. Tenant-scoped like the
 * real ProcedureCatalogRepository (ADR 0002): findById()/findActive() only
 * ever return an item whose tenantId() matches this instance's own
 * $tenantId.
 */
final class FakeProcedureCatalogRepository implements ProcedureCatalogRepositoryInterface
{
    /** @var array<int, ProcedureCatalogItem> */
    private array $items = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, ProcedureCatalogItem ...$seed)
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

    /** @return list<ProcedureCatalogItem> */
    public function findActive(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (ProcedureCatalogItem $item): bool => $item->tenantId() === $this->tenantId && $item->active(),
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ProcedureCatalogItem) {
            throw new InvalidArgumentException('FakeProcedureCatalogRepository only stores ProcedureCatalogItem entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->items[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof ProcedureCatalogItem && $entity->id() !== null) {
            unset($this->items[$entity->id()]);
        }
    }
}
