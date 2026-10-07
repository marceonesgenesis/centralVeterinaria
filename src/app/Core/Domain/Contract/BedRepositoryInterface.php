<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Bed aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface BedRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists every bed of a unit (any status), ordered by code, within the
     * current tenant.
     *
     * @return list<TEntity>
     */
    public function listByUnit(int $systemUnitId): array;

    /**
     * Finds a bed by its code inside a unit, within the current tenant.
     *
     * @return TEntity|null
     */
    public function findByCode(int $systemUnitId, string $code): ?object;

    /**
     * Atomically marks an `available` bed as `occupied` by the given
     * hospitalization (conditional UPDATE with `status = 'available'` in the
     * WHERE). Returns false when no row changed (bed taken, inactive or
     * from another tenant).
     */
    public function occupy(int $bedId, int $hospitalizationId): bool;

    /**
     * Atomically returns an `occupied` bed to `available`, only when it is
     * held by the given hospitalization. Returns false when no row changed.
     */
    public function release(int $bedId, int $hospitalizationId): bool;
}
