<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the vaccine catalog item aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface VaccineCatalogRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists active vaccine catalog items within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listActive(): array;
}
