<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the ExamRequest aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface ExamRequestRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists exam requests for a patient within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByPatient(int $patientId): array;

    /**
     * Lists exam requests for an encounter within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByEncounter(int $encounterId): array;

    /**
     * Lists exam requests still awaiting a result (status "requested") within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listPending(): array;
}
