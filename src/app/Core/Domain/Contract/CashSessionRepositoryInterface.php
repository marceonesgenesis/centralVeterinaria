<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the CashSession aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface CashSessionRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Finds the open cash session for a unit within the current tenant, if any.
     *
     * @return TEntity|null
     */
    public function findOpenBySystemUnit(int $systemUnitId): ?object;

    /**
     * Same as findById(), with `SELECT ... FOR UPDATE` (Etapa 3, T-03): the
     * row stays locked until the caller's transaction ends, so closing the
     * till and moving money through it serialize.
     *
     * @return TEntity|null
     *
     * @throws \LogicException 'findByIdForUpdate requires an open transaction'
     */
    public function findByIdForUpdate(int $id): ?object;

    /**
     * Same as findOpenBySystemUnit(), with `SELECT ... FOR UPDATE` (T-03).
     *
     * @return TEntity|null
     *
     * @throws \LogicException 'findOpenBySystemUnitForUpdate requires an open transaction'
     */
    public function findOpenBySystemUnitForUpdate(int $systemUnitId): ?object;
}
