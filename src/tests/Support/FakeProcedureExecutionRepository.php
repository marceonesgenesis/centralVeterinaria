<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ProcedureExecutionRepositoryInterface;
use CentralVet\Domain\ProcedureExecution;
use InvalidArgumentException;

/**
 * In-memory double for ProcedureExecutionRepositoryInterface (T-05): the
 * `procedure_execution` table does not exist yet (migration T-01 not
 * applied), so ProcedureExecutionServiceTest exercises
 * ProcedureExecutionService::execute()/listByEncounter() against this
 * instead of a real database. Tenant-scoped like the real
 * ProcedureExecutionRepository (ADR 0002): findById()/listByEncounter()
 * only ever return an execution whose tenantId() matches this instance's
 * own $tenantId.
 */
final class FakeProcedureExecutionRepository implements ProcedureExecutionRepositoryInterface
{
    /** @var array<int, ProcedureExecution> */
    private array $executions = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, ProcedureExecution ...$seed)
    {
        foreach ($seed as $execution) {
            $this->save($execution);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $execution = $this->executions[(int) $id] ?? null;

        if ($execution === null || $execution->tenantId() !== $this->tenantId) {
            return null;
        }

        return $execution;
    }

    /** @return list<ProcedureExecution> */
    public function listByEncounter(int $encounterId): array
    {
        return array_values(array_filter(
            $this->executions,
            fn (ProcedureExecution $execution): bool => $execution->tenantId() === $this->tenantId
                && $execution->encounterId() === $encounterId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ProcedureExecution) {
            throw new InvalidArgumentException('FakeProcedureExecutionRepository only stores ProcedureExecution entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->executions[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof ProcedureExecution && $entity->id() !== null) {
            unset($this->executions[$entity->id()]);
        }
    }
}
