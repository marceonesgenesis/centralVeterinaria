<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the procedure catalog item input aggregate (the products/supplies
 * consumed by a procedure catalog item).
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface ProcedureCatalogItemInputRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists the inputs configured for a procedure catalog item within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByProcedureCatalogItem(int $procedureCatalogItemId): array;
}
