<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

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
}
