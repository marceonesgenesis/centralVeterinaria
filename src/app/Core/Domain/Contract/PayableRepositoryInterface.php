<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Payable aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface PayableRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists open payables for a unit within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listOpenBySystemUnit(int $systemUnitId): array;
}
