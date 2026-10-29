<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

/**
 * Persistence boundary for PaymentReversal (Etapa 2, T-08). Append-only:
 * a reversal is written once and never updated.
 *
 * @template TEntity of object
 * @extends RepositoryInterface<TEntity>
 */
interface PaymentReversalRepositoryInterface extends RepositoryInterface
{
    /** Whether $paymentId already has a reversal in the current tenant. */
    public function existsForPayment(int $paymentId): bool;

    /**
     * Reversals of the payments of $receivableId in the current tenant,
     * oldest first.
     *
     * @return list<TEntity>
     */
    public function listByReceivable(int $receivableId): array;
}
