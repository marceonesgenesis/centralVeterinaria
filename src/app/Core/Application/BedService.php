<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Bed;
use CentralVet\Domain\Contract\BedRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Hospitalization bed catalog of a system unit (T-08): create, rename/
 * re-price, activate/deactivate, read and list beds.
 *
 * New beds always go to the caller's active unit
 * (`TenantContext::requireUnitId()`); the unit is never taken from input.
 * Every operation on an existing bed loads it first (tenant-scoped
 * repository) and authorizes against the persisted bed's own
 * system_unit_id, so a user of unit A cannot touch a bed of unit B by id.
 * Occupancy is not handled here: it belongs to
 * `BedRepositoryInterface::occupy()`/`release()` (admission/discharge).
 */
final class BedService
{
    private const ENTITY_TYPE = 'bed';

    public function __construct(
        private readonly BedRepositoryInterface $beds,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the code is already used by a
     *         bed of the active unit, or for invalid code/name/rate.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function create(string $code, string $name, int $dailyRateCents, string $action): Bed
    {
        $unitId = $this->context->requireUnitId();
        $this->authorize($action, $unitId, null);

        $bed = Bed::create($this->context->tenantId(), $unitId, $code, $name, $dailyRateCents);

        if ($this->beds->findByCode($unitId, $bed->code()) !== null) {
            throw new InvalidArgumentException("A bed with code \"{$bed->code()}\" already exists in this unit");
        }

        $this->beds->save($bed);

        return $bed;
    }

    public function update(int $bedId, string $name, int $dailyRateCents, string $action): Bed
    {
        $bed = $this->requireBed($bedId, $action);
        $bed->rename($name);
        $bed->changeDailyRate($dailyRateCents);
        $this->beds->save($bed);

        return $bed;
    }

    /** @throws \CentralVet\Domain\Exception\BedUnavailableException when the bed is occupied. */
    public function deactivate(int $bedId, string $action): Bed
    {
        $bed = $this->requireBed($bedId, $action);
        $bed->deactivate();
        $this->beds->save($bed);

        return $bed;
    }

    public function activate(int $bedId, string $action): Bed
    {
        $bed = $this->requireBed($bedId, $action);
        $bed->activate();
        $this->beds->save($bed);

        return $bed;
    }

    public function get(int $bedId, string $action): Bed
    {
        return $this->requireBed($bedId, $action);
    }

    /** @return list<Bed> every bed of the active unit (any status), by code. */
    public function listForCurrentUnit(string $action): array
    {
        $unitId = $this->context->requireUnitId();
        $this->authorize($action, $unitId, null);

        return $this->beds->listByUnit($unitId);
    }

    /** @return list<Bed> only the `available` beds of the given unit, by code. */
    public function listAvailableForUnit(int $systemUnitId, string $action): array
    {
        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        $this->authorize($action, $systemUnitId, null);

        return array_values(array_filter(
            $this->beds->listByUnit($systemUnitId),
            static fn (Bed $bed): bool => $bed->isAvailable(),
        ));
    }

    /**
     * Loads a bed of the authenticated tenant and authorizes $action against
     * its persisted system_unit_id.
     *
     * @throws CrossTenantReferenceException when the id does not resolve
     *         within the tenant (missing and foreign are indistinguishable).
     */
    private function requireBed(int $bedId, string $action): Bed
    {
        $bed = $bedId > 0 ? $this->beds->findById($bedId) : null;

        if (!$bed instanceof Bed) {
            throw new CrossTenantReferenceException("Bed {$bedId} not found for this tenant");
        }

        $this->authorize($action, $bed->systemUnitId(), $bedId);

        return $bed;
    }

    private function authorize(string $action, int $unitId, ?int $bedId): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $unitId,
            entityType: self::ENTITY_TYPE,
            entityId: $bedId,
        ))->assertAllowed();
    }
}
