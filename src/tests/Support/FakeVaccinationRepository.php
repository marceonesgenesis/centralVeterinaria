<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\VaccinationRepositoryInterface;
use CentralVet\Domain\Vaccination;
use InvalidArgumentException;

/**
 * In-memory double for VaccinationRepositoryInterface (T-11): the
 * `vaccination` table does not exist yet (migration T-01 not applied), so
 * VaccinationServiceTest exercises VaccinationService::apply() against this
 * instead of a real database. Tenant-scoped like the real
 * VaccinationRepository (ADR 0002): findById() and both listBy*() methods
 * only ever return a vaccination whose tenantId() matches this instance's
 * own $tenantId.
 */
final class FakeVaccinationRepository implements VaccinationRepositoryInterface
{
    /** @var array<int, Vaccination> */
    private array $vaccinations = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Vaccination ...$seed)
    {
        foreach ($seed as $vaccination) {
            $this->save($vaccination);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $vaccination = $this->vaccinations[(int) $id] ?? null;

        if ($vaccination === null || $vaccination->tenantId() !== $this->tenantId) {
            return null;
        }

        return $vaccination;
    }

    /** @return list<Vaccination> */
    public function listByPatient(int $patientId): array
    {
        return array_values(array_filter(
            $this->vaccinations,
            fn (Vaccination $vaccination): bool => $vaccination->tenantId() === $this->tenantId
                && $vaccination->patientId() === $patientId,
        ));
    }

    /** @return list<Vaccination> */
    public function listByEncounter(int $encounterId): array
    {
        return array_values(array_filter(
            $this->vaccinations,
            fn (Vaccination $vaccination): bool => $vaccination->tenantId() === $this->tenantId
                && $vaccination->encounterId() === $encounterId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Vaccination) {
            throw new InvalidArgumentException('FakeVaccinationRepository only stores Vaccination entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->vaccinations[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Vaccination && $entity->id() !== null) {
            unset($this->vaccinations[$entity->id()]);
        }
    }
}
