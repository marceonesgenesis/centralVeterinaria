<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the StockBatch aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface StockBatchRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists a product's stock batches in a unit, ordered by expiry date (earliest first),
     * within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByProductOrderedByExpiry(int $productId, int $systemUnitId): array;
}
