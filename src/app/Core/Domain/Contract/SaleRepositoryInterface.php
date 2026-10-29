<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\Sale;
use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Sale aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface SaleRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists sales for a tutor within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByTutor(int $tutorId): array;

    /**
     * Row-locking read (`SELECT ... FOR UPDATE`) of a sale of the current
     * tenant, held until the caller's transaction ends (Etapa 2 / T-09).
     *
     * @throws \LogicException('findByIdForUpdate() requires an open transaction')
     *         when called outside a transaction.
     */
    public function findByIdForUpdate(int $id): ?Sale;
}
