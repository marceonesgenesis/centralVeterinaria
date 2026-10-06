<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for SurgeryChecklistItem (append-only, one row per
 * surgery, phase and item code).
 *
 * `save` of an item that already exists for the same surgery, phase and
 * item code throws
 * {@see \CentralVet\Domain\Exception\InvalidStatusTransitionException}
 * with `Checklist phase "<phase>" is already confirmed for surgery <id>`.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface SurgeryChecklistRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists a surgery's checked items, within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listBySurgery(int $surgeryId): array;
}
