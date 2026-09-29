<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Service (catalog) aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface ServiceRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Finds a service by its unique name within the current tenant.
     *
     * @return TEntity|null
     */
    public function findByName(string $name): ?object;

    /**
     * Lists active services within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listActive(): array;
}
