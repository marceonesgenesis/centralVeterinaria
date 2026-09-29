<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Payment aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface PaymentRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists payments for a receivable within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByReceivable(int $receivableId): array;

    /**
     * Lists payments recorded in a cash session within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByCashSession(int $cashSessionId): array;
}
