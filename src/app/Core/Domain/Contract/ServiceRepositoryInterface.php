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

    /**
     * Lists active and inactive services within the current tenant, ordered by name.
     *
     * @return list<TEntity>
     */
    public function listAll(): array;

    /**
     * Whether any appointment of the current tenant references the service
     * (appointment_service_fk is RESTRICT, so such a service cannot be deleted).
     */
    public function hasAppointments(int $serviceId): bool;
}
