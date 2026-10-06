<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\SurgeryRoomRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\SurgeryRoom;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Operating room catalog of a system unit (T-07): create, rename,
 * activate/deactivate, read and list rooms. Mirrors BedService.
 *
 * New rooms always go to the caller's active unit
 * (`TenantContext::requireUnitId()`); the unit is never taken from input.
 * Every operation on an existing room loads it first (tenant-scoped
 * repository) and authorizes against the persisted room's own
 * system_unit_id, so a user of unit A cannot touch a room of unit B by id.
 * Room booking (overlap) is not handled here: it belongs to the scheduling
 * service under `SurgeryRoomRepositoryInterface::lockForScheduling()`.
 */
final class SurgeryRoomService
{
    private const ENTITY_TYPE = 'surgery_room';

    public function __construct(
        private readonly SurgeryRoomRepositoryInterface $rooms,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the code is already used by a
     *         room of the active unit, or for invalid code/name.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function create(string $code, string $name, string $action): SurgeryRoom
    {
        $unitId = $this->context->requireUnitId();
        $this->authorize($action, $unitId, null);

        $room = SurgeryRoom::create($this->context->tenantId(), $unitId, $code, $name);

        if ($this->rooms->findByCode($unitId, $room->code()) !== null) {
            throw new InvalidArgumentException("A surgery room with code \"{$room->code()}\" already exists in this unit");
        }

        $this->rooms->save($room);

        return $room;
    }

    public function update(int $roomId, string $name, string $action): SurgeryRoom
    {
        $room = $this->requireRoom($roomId, $action);
        $room->rename($name);
        $this->rooms->save($room);

        return $room;
    }

    public function deactivate(int $roomId, string $action): SurgeryRoom
    {
        $room = $this->requireRoom($roomId, $action);
        $room->deactivate();
        $this->rooms->save($room);

        return $room;
    }

    public function activate(int $roomId, string $action): SurgeryRoom
    {
        $room = $this->requireRoom($roomId, $action);
        $room->activate();
        $this->rooms->save($room);

        return $room;
    }

    public function get(int $roomId, string $action): SurgeryRoom
    {
        return $this->requireRoom($roomId, $action);
    }

    /** @return list<SurgeryRoom> every room of the active unit (any status), by code. */
    public function listForCurrentUnit(string $action): array
    {
        $unitId = $this->context->requireUnitId();
        $this->authorize($action, $unitId, null);

        return $this->rooms->listByUnit($unitId);
    }

    /** @return list<SurgeryRoom> only the active rooms of the given unit, by code. */
    public function listActiveForUnit(int $systemUnitId, string $action): array
    {
        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        $this->authorize($action, $systemUnitId, null);

        return array_values(array_filter(
            $this->rooms->listByUnit($systemUnitId),
            static fn (SurgeryRoom $room): bool => $room->isActive(),
        ));
    }

    /**
     * Loads a room of the authenticated tenant and authorizes $action
     * against its persisted system_unit_id.
     *
     * @throws CrossTenantReferenceException when the id does not resolve
     *         within the tenant (missing and foreign are indistinguishable).
     */
    private function requireRoom(int $roomId, string $action): SurgeryRoom
    {
        $room = $roomId > 0 ? $this->rooms->findById($roomId) : null;

        if (!$room instanceof SurgeryRoom) {
            throw new CrossTenantReferenceException("room_id {$roomId} was not found for the authenticated tenant");
        }

        $this->authorize($action, $room->systemUnitId(), $roomId);

        return $room;
    }

    private function authorize(string $action, int $unitId, ?int $roomId): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $unitId,
            entityType: self::ENTITY_TYPE,
            entityId: $roomId,
        ))->assertAllowed();
    }
}
