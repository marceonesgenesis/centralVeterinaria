<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the EncounterAccountItem aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface EncounterAccountItemRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists items belonging to an account within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByAccount(int $accountId): array;
}
