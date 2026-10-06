<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;
use DateTimeImmutable;

/**
 * Persistence boundary for the Surgery aggregate.
 *
 * `save()` of an existing surgery only writes while the status in the
 * database still equals the expected status: the last status saved by this
 * same entity instance (tracked per instance), else
 * `Surgery::loadedStatus()`, else `scheduled`. Otherwise it throws
 * `InvalidStatusTransitionException` with
 * `Surgery <id> changed status concurrently`. The same instance can be saved
 * repeatedly; another instance holding an older status is refused.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface SurgeryRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists the surgeries of a unit whose scheduled start falls on the given
     * day (any status), ordered by `scheduled_start_at, id`, within the
     * current tenant.
     *
     * @return list<TEntity>
     */
    public function listByUnitAndDay(int $systemUnitId, DateTimeImmutable $day): array;

    /**
     * True when the room holds another surgery in status
     * `scheduled`/`pre_op`/`in_progress` whose period overlaps
     * (`scheduled_start_at < $endAt AND scheduled_end_at > $startAt`),
     * ignoring `$exceptSurgeryId`, within the current tenant.
     */
    public function hasOverlapInRoom(
        int $roomId,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        ?int $exceptSurgeryId,
    ): bool;

    /**
     * Locks the surgery row (`SELECT status ... FOR UPDATE`) and returns its
     * current status; null when it does not exist in the tenant.
     */
    public function lockStatus(int $surgeryId): ?string;
}
