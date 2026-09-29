<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the VaccineProtocol aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface VaccineProtocolRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists the dose schedule of a vaccine catalog item, ordered by dose number, within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByVaccineCatalogItem(int $vaccineCatalogItemId): array;
}
