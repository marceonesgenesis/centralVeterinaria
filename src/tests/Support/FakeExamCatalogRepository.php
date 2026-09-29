<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ExamCatalogRepositoryInterface;
use CentralVet\Domain\ExamCatalogItem;
use InvalidArgumentException;

/**
 * In-memory double for ExamCatalogRepositoryInterface (T-13): the
 * `exam_catalog_item` table exists in the schema but EncounterAccountService
 * consumes this contract directly (see that class's own docblock — no
 * findById() shortcut through ExamCatalogService), so
 * EncounterAccountServiceTest exercises it against this instead of a real
 * database. Tenant-scoped like the real ExamCatalogRepository (ADR 0002):
 * findById()/listActive() only ever return items whose tenantId() matches
 * this instance's own $tenantId.
 */
final class FakeExamCatalogRepository implements ExamCatalogRepositoryInterface
{
    /** @var array<int, ExamCatalogItem> */
    private array $items = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, ExamCatalogItem ...$seed)
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

    /** @return list<ExamCatalogItem> */
    public function listActive(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (ExamCatalogItem $item): bool => $item->tenantId() === $this->tenantId && $item->active(),
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ExamCatalogItem) {
            throw new InvalidArgumentException('FakeExamCatalogRepository only stores ExamCatalogItem entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->items[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof ExamCatalogItem && $entity->id() !== null) {
            unset($this->items[$entity->id()]);
        }
    }
}
