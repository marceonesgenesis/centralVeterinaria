<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the QueueEntry aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface QueueEntryRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists active queue entries (not yet finished) for a unit within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listActiveByUnit(int $systemUnitId): array;

    /**
     * Finds the queue entry linked to a given appointment within the current tenant, if any.
     *
     * @return TEntity|null
     */
    public function findByAppointment(int $appointmentId): ?object;
}
