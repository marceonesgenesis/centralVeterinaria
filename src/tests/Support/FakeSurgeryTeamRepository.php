<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SurgeryTeamRepositoryInterface;
use CentralVet\Domain\SurgeryTeamMember;
use InvalidArgumentException;

/**
 * In-memory double for SurgeryTeamRepositoryInterface (T-05): save() assigns
 * incremental ids, reads only see members of this instance's tenant and
 * replaceForSurgery() drops the surgery's current team before saving the
 * new members (each must belong to that surgery).
 */
final class FakeSurgeryTeamRepository implements SurgeryTeamRepositoryInterface
{
    /** @var array<int, SurgeryTeamMember> */
    private array $members = [];
    private int $nextId = 1;

    /** Number of save() calls (replaceForSurgery() saves each member). */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId)
    {
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $member = $this->members[(int) $id] ?? null;

        if ($member === null || $member->tenantId() !== $this->tenantId) {
            return null;
        }

        return $member;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryTeamMember) {
            throw new InvalidArgumentException('FakeSurgeryTeamRepository only stores SurgeryTeamMember entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->members[$entity->id()] = $entity;
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof SurgeryTeamMember && $entity->id() !== null) {
            unset($this->members[$entity->id()]);
        }
    }

    public function listBySurgery(int $surgeryId): array
    {
        $members = array_values(array_filter(
            $this->members,
            fn (SurgeryTeamMember $m): bool => $m->tenantId() === $this->tenantId && $m->surgeryId() === $surgeryId,
        ));
        usort($members, static fn (SurgeryTeamMember $a, SurgeryTeamMember $b): int => $a->id() <=> $b->id());

        return $members;
    }

    public function replaceForSurgery(int $surgeryId, array $members): void
    {
        foreach ($members as $member) {
            if (!$member instanceof SurgeryTeamMember || $member->surgeryId() !== $surgeryId) {
                throw new InvalidArgumentException("Team members must belong to surgery {$surgeryId}");
            }
        }

        foreach ($this->listBySurgery($surgeryId) as $current) {
            $this->remove($current);
        }

        foreach ($members as $member) {
            $this->save($member);
        }
    }
}
