<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\VaccineCatalogRepositoryInterface;
use CentralVet\Domain\VaccineCatalogItem;
use InvalidArgumentException;

/**
 * In-memory double for VaccineCatalogRepositoryInterface (T-11): the
 * `vaccine_catalog_item` table does not exist yet (migration T-01 not
 * applied), so VaccinationServiceTest exercises VaccinationService::apply()'s
 * stock decrement against this instead of a real database. Tenant-scoped
 * like the real VaccineCatalogRepository (ADR 0002): findById() and
 * listActive() only ever return an item whose tenantId() matches this
 * instance's own $tenantId.
 */
final class FakeVaccineCatalogRepository implements VaccineCatalogRepositoryInterface
{
    /** @var array<int, VaccineCatalogItem> */
    private array $items = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, VaccineCatalogItem ...$seed)
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

    /** @return list<VaccineCatalogItem> */
    public function listActive(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (VaccineCatalogItem $item): bool => $item->tenantId() === $this->tenantId && $item->active(),
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof VaccineCatalogItem) {
            throw new InvalidArgumentException('FakeVaccineCatalogRepository only stores VaccineCatalogItem entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->items[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof VaccineCatalogItem && $entity->id() !== null) {
            unset($this->items[$entity->id()]);
        }
    }
}
