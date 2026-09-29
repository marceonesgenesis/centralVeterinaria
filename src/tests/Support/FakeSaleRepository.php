<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SaleRepositoryInterface;
use CentralVet\Domain\Sale;
use InvalidArgumentException;

/**
 * In-memory double for SaleRepositoryInterface (T-06): the `sale` table does
 * not exist yet (migration T-01 not applied), so SaleServiceTest exercises
 * SaleService::create()/findById() against this instead of a real database.
 * Tenant-scoped like the real SaleRepository (ADR 0002): findById()/
 * listByTutor() only ever return a sale whose tenantId() matches this
 * instance's own $tenantId.
 *
 * remove() actually deletes from the in-memory store (not a soft
 * cancel/delete), which is what lets SaleServiceTest prove
 * SaleService::create()'s compensating delete on InsufficientStockException
 * (see its own class docblock) really removes the Sale row it just wrote,
 * not merely marks it cancelled.
 */
final class FakeSaleRepository implements SaleRepositoryInterface
{
    /** @var array<int, Sale> */
    private array $sales = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Sale ...$seed)
    {
        foreach ($seed as $sale) {
            $this->save($sale);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $sale = $this->sales[(int) $id] ?? null;

        if ($sale === null || $sale->tenantId() !== $this->tenantId) {
            return null;
        }

        return $sale;
    }

    /** @return list<Sale> */
    public function listByTutor(int $tutorId): array
    {
        return array_values(array_filter(
            $this->sales,
            fn (Sale $sale): bool => $sale->tenantId() === $this->tenantId && $sale->tutorId() === $tutorId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Sale) {
            throw new InvalidArgumentException('FakeSaleRepository only stores Sale entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->sales[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Sale && $entity->id() !== null) {
            unset($this->sales[$entity->id()]);
        }
    }
}
