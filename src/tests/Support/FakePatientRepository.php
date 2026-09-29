<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\PatientRepositoryInterface;
use CentralVet\Domain\Patient;
use InvalidArgumentException;

/**
 * In-memory double for PatientRepositoryInterface (T-16), same rationale as
 * FakeTutorRepository: the `patient` table does not exist yet (migration
 * T-01 not applied), so Application-layer unit tests exercise PatientService
 * against this instead of a real database. Tenant-scoped like the real
 * PatientRepository (ADR 0002): findById()/findByTutor()/search() only ever
 * return patients whose tenantId matches this instance's own $tenantId.
 */
final class FakePatientRepository implements PatientRepositoryInterface
{
    /** @var array<int, Patient> */
    private array $patients = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Patient ...$seed)
    {
        foreach ($seed as $patient) {
            $this->save($patient);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $patient = $this->patients[(int) $id] ?? null;

        if ($patient === null || $patient->tenantId !== $this->tenantId) {
            return null;
        }

        return $patient;
    }

    /** @return list<Patient> */
    public function findByTutor(int $tutorId): array
    {
        return array_values(array_filter(
            $this->patients,
            fn (Patient $patient): bool => $patient->tenantId === $this->tenantId && $patient->tutorId === $tutorId,
        ));
    }

    /** @return list<Patient> */
    public function search(string $term): array
    {
        $needle = mb_strtolower($term);

        return array_values(array_filter(
            $this->patients,
            fn (Patient $patient): bool => $patient->tenantId === $this->tenantId
                && str_contains(mb_strtolower($patient->name), $needle),
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Patient) {
            throw new InvalidArgumentException('FakePatientRepository only stores Patient entities');
        }

        $saved = $entity->id !== null ? $entity : $entity->withId($this->nextId++);
        $this->patients[$saved->id] = $saved;

        return $saved;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Patient && $entity->id !== null) {
            unset($this->patients[$entity->id]);
        }
    }
}
