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
     * Loads the receivable with SELECT ... FOR UPDATE (T-08), so concurrent
     * payments/cancellations of the same receivable serialize. Must run
     * inside an open transaction.
     *
     * @throws \LogicException 'findByIdForUpdate() requires an open transaction'
     * @return TEntity|null
     */
    public function findByIdForUpdate(int $id): ?object;

    /**
     * Finds the receivable of a sale within the current tenant (T-08).
     *
     * @return TEntity|null
     */
    public function findBySaleId(int $saleId): ?object;

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
