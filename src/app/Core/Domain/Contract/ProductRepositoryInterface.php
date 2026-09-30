<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Product aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface ProductRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists active products within the current tenant.
     *
     * @return list<TEntity>
     */
    public function findActive(): array;

    /**
     * Finds a product by its name within the current tenant.
     *
     * @return TEntity|null
     */
    public function findByName(string $name): ?object;

    /**
     * Finds a product by its code within the current tenant.
     *
     * @return TEntity|null
     */
    public function findByCode(string $code): ?object;
}
