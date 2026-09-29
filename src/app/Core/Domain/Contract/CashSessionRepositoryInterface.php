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
}
