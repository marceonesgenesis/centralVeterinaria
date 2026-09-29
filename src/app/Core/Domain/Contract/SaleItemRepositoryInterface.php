<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the SaleItem aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface SaleItemRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists items belonging to a sale within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listBySale(int $saleId): array;
}
