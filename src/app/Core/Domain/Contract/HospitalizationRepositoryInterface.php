<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Hospitalization aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface HospitalizationRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Finds the patient's hospitalization in status `admitted`, within the
     * current tenant.
     *
     * @return TEntity|null
     */
    public function findActiveByPatient(int $patientId): ?object;

    /**
     * Lists the hospitalizations in status `admitted` of a unit, within the
     * current tenant.
     *
     * @return list<TEntity>
     */
    public function listActiveByUnit(int $systemUnitId): array;
}
