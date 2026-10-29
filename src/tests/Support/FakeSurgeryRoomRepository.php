<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SurgeryRoomRepositoryInterface;
use CentralVet\Domain\SurgeryRoom;
use InvalidArgumentException;

/**
 * In-memory double for SurgeryRoomRepositoryInterface (T-05), modelled on
 * FakeBedRepository: save() assigns incremental ids (seeded entities that
 * already have one keep it) and every read only sees rooms of this
 * instance's tenant. lockForScheduling() counts its calls in $lockCalls and
 * returns false for a room outside the tenant, like the PDO `FOR UPDATE`.
 */
final class FakeSurgeryRoomRepository implements SurgeryRoomRepositoryInterface
{
    /** @var array<int, SurgeryRoom> */
    private array $rooms = [];
    private int $nextId = 1;

    /** Number of save() calls after construction (seed not counted). */
    public int $saveCount = 0;

    /** Number of lockForScheduling() calls. */
    public int $lockCalls = 0;

    public function __construct(private readonly int $tenantId, SurgeryRoom ...$seed)
    {
        foreach ($seed as $item) {
            $this->save($item);
        }

        $this->saveCount = 0;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $room = $this->rooms[(int) $id] ?? null;

        if ($room === null || $room->tenantId() !== $this->tenantId) {
            return null;
        }

        return $room;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryRoom) {
            throw new InvalidArgumentException('FakeSurgeryRoomRepository only stores SurgeryRoom entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->rooms[$entity->id()] = $entity;
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof SurgeryRoom && $entity->id() !== null) {
            unset($this->rooms[$entity->id()]);
        }
    }

    /** @return list<SurgeryRoom> */
    private function ownTenant(): array
    {
        return array_values(array_filter(
            $this->rooms,
            fn (SurgeryRoom $room): bool => $room->tenantId() === $this->tenantId,
        ));
    }

    public function listByUnit(int $systemUnitId): array
    {
        $rooms = array_values(array_filter(
            $this->ownTenant(),
            static fn (SurgeryRoom $room): bool => $room->systemUnitId() === $systemUnitId,
        ));
        usort($rooms, static fn (SurgeryRoom $a, SurgeryRoom $b): int => strcmp($a->code(), $b->code()));

        return $rooms;
    }

    public function findByCode(int $systemUnitId, string $code): ?object
    {
        foreach ($this->ownTenant() as $room) {
            if ($room->systemUnitId() === $systemUnitId && $room->code() === $code) {
                return $room;
            }
        }

        return null;
    }

    public function lockForScheduling(int $roomId): bool
    {
        $this->lockCalls++;

        return $this->findById($roomId) !== null;
    }
}
