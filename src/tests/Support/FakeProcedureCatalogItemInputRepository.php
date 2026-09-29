<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ProcedureCatalogItemInputRepositoryInterface;
use CentralVet\Domain\ProcedureCatalogItemInput;
use InvalidArgumentException;

/**
 * In-memory double for ProcedureCatalogItemInputRepositoryInterface (T-04):
 * the `procedure_catalog_item_input` table does not exist yet (migration
 * T-01 not applied), so ProcedureExecutionServiceTest exercises
 * ProcedureCatalogService (a ProcedureExecutionService collaborator, T-05)
 * against this instead of a real database. Tenant-scoped like the real
 * ProcedureCatalogItemInputRepository (ADR 0002).
 *
 * listByProcedureCatalogItem() returns inputs in insertion order (the order
 * ProcedureCatalogService::addInput() was called), matching the real
 * repository's own documented "registration order" guarantee, which
 * ProcedureExecutionService::execute() relies on for a deterministic
 * per-input StockService::consume() call sequence.
 */
final class FakeProcedureCatalogItemInputRepository implements ProcedureCatalogItemInputRepositoryInterface
{
    /** @var array<int, ProcedureCatalogItemInput> */
    private array $inputs = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, ProcedureCatalogItemInput ...$seed)
    {
        foreach ($seed as $input) {
            $this->save($input);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $input = $this->inputs[(int) $id] ?? null;

        if ($input === null || $input->tenantId() !== $this->tenantId) {
            return null;
        }

        return $input;
    }

    /** @return list<ProcedureCatalogItemInput> */
    public function listByProcedureCatalogItem(int $procedureCatalogItemId): array
    {
        return array_values(array_filter(
            $this->inputs,
            fn (ProcedureCatalogItemInput $input): bool => $input->tenantId() === $this->tenantId
                && $input->procedureCatalogItemId() === $procedureCatalogItemId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ProcedureCatalogItemInput) {
            throw new InvalidArgumentException('FakeProcedureCatalogItemInputRepository only stores ProcedureCatalogItemInput entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->inputs[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof ProcedureCatalogItemInput && $entity->id() !== null) {
            unset($this->inputs[$entity->id()]);
        }
    }
}
