<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the exam catalog item aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface ExamCatalogRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists active exam catalog items within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listActive(): array;
}
