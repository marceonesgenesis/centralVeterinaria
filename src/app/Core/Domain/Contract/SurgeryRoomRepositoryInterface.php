<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the SurgeryRoom aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface SurgeryRoomRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists every room of a unit (any status), ordered by code, within the
     * current tenant.
     *
     * @return list<TEntity>
     */
    public function listByUnit(int $systemUnitId): array;

    /**
     * Finds a room by its code inside a unit, within the current tenant.
     *
     * @return TEntity|null
     */
    public function findByCode(int $systemUnitId, string $code): ?object;

    /**
     * Locks the room row of the current tenant (`SELECT ... FOR UPDATE`) so
     * the overlap check and the insert of a surgery in that room are
     * serialized. Returns false when the room does not exist in the tenant.
     */
    public function lockForScheduling(int $roomId): bool;
}
