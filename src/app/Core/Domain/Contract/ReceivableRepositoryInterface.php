<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Receivable aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface ReceivableRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Finds the receivable for an encounter account within the current tenant.
     *
     * @return TEntity|null
     */
    public function findByEncounterAccountId(int $accountId): ?object;

    /**
     * Lists every receivable still owed (status in ['open',
     * 'partially_paid']) for the current tenant. Scoped only by tenant_id —
     * unlike PayableRepositoryInterface::listOpenBySystemUnit(), Receivable
     * carries no system_unit_id column of its own (see plan.md's
     * Decisões de arquitetura).
     *
     * @return list<TEntity>
     */
    public function listOpen(): array;
}
