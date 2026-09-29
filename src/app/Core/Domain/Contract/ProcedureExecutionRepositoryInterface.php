<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the ProcedureExecution aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface ProcedureExecutionRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists procedure executions for an encounter within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByEncounter(int $encounterId): array;
}
