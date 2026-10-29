<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the SurgeryTeamMember rows of a surgery.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface SurgeryTeamRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists the team members of a surgery, within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listBySurgery(int $surgeryId): array;

    /**
     * Replaces the whole team of a surgery by the given members (all with
     * that surgery id), within the current tenant.
     *
     * @param list<TEntity> $members
     */
    public function replaceForSurgery(int $surgeryId, array $members): void;
}
