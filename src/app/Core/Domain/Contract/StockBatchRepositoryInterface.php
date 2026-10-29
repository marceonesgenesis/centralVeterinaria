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

    /**
     * Same filter and order as listByProductOrderedByExpiry(), but the rows
     * are read with SELECT ... FOR UPDATE: a concurrent consumer of the same
     * product waits for this transaction instead of reading a stale balance.
     *
     * @throws \LogicException('lockByProductOrderedByExpiry() requires an open transaction')
     *         when called outside a transaction.
     * @return list<TEntity>
     */
    public function lockByProductOrderedByExpiry(int $productId, int $systemUnitId): array;

    /**
     * Conditional decrement within the current tenant: subtracts $quantity
     * only while the batch still holds at least $quantity units. Returns
     * true only when exactly one row was changed.
     */
    public function decreaseQuantity(int $batchId, int $quantity): bool;

    /** Adds $quantity units back to the batch within the current tenant. */
    public function increaseQuantity(int $batchId, int $quantity): void;
}
