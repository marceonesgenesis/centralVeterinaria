<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the procedure catalog item aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface ProcedureCatalogRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists active procedure catalog items within the current tenant.
     *
     * @return list<TEntity>
     */
    public function findActive(): array;
}
