<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the EncounterAccount aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface EncounterAccountRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Finds the account for an encounter within the current tenant.
     *
     * @return TEntity|null
     */
    public function findByEncounterId(int $encounterId): ?object;
}
