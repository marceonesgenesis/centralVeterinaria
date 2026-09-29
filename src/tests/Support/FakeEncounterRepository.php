<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Encounter;
use InvalidArgumentException;

/**
 * In-memory double for EncounterRepositoryInterface (T-08): the `encounter`
 * table does not exist yet (migration T-01 not applied), so
 * EncounterServiceTest exercises EncounterService::start()/finish()/
 * autosave() against this instead of a real database. Tenant-scoped like the
 * real EncounterRepository (ADR 0002): findById() only ever returns an
 * encounter whose tenantId() matches this instance's own $tenantId.
 */
final class FakeEncounterRepository implements EncounterRepositoryInterface
{
    /** @var array<int, Encounter> */
    private array $encounters = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Encounter ...$seed)
    {
        foreach ($seed as $encounter) {
            $this->save($encounter);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $encounter = $this->encounters[(int) $id] ?? null;

        if ($encounter === null || $encounter->tenantId() !== $this->tenantId) {
            return null;
        }

        return $encounter;
    }

    /** @return list<Encounter> */
    public function listInProgressByPatient(int $patientId): array
    {
        return array_values(array_filter(
            $this->encounters,
            fn (Encounter $encounter): bool => $encounter->tenantId() === $this->tenantId
                && $encounter->patientId() === $patientId
                && $encounter->status() !== Encounter::STATUS_FINISHED,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Encounter) {
            throw new InvalidArgumentException('FakeEncounterRepository only stores Encounter entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->encounters[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Encounter && $entity->id() !== null) {
            unset($this->encounters[$entity->id()]);
        }
    }
}
