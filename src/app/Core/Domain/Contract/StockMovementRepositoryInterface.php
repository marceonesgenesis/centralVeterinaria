<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the StockMovement aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface StockMovementRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists stock movements for a product within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByProduct(int $productId): array;

    /**
     * Lists the movements of one reference (e.g. 'sale', 42) within the
     * current tenant, in id order.
     *
     * @return list<TEntity>
     */
    public function listByReference(string $referenceType, int $referenceId): array;
}
