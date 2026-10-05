<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the HospitalizationOrder aggregate
 * (`hospitalization_order`, migration 0010).
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface HospitalizationOrderRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists the orders of a hospitalization within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByHospitalization(int $hospitalizationId): array;
}
