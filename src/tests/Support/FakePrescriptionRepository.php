<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\PrescriptionRepositoryInterface;
use CentralVet\Domain\Prescription;
use InvalidArgumentException;

/**
 * In-memory double for PrescriptionRepositoryInterface (T-11): the
 * `prescription`/`prescription_item` tables do not exist yet (migration T-01
 * not applied), so PrescriptionServiceTest exercises PrescriptionService
 * against this instead of a real database. Tenant-scoped like the real
 * PrescriptionRepository (ADR 0002): findById() and both listBy*() methods
 * only ever return a prescription whose tenantId() matches this instance's
 * own $tenantId.
 */
final class FakePrescriptionRepository implements PrescriptionRepositoryInterface
{
    /** @var array<int, Prescription> */
    private array $prescriptions = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Prescription ...$seed)
    {
        foreach ($seed as $prescription) {
            $this->save($prescription);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $prescription = $this->prescriptions[(int) $id] ?? null;

        if ($prescription === null || $prescription->tenantId() !== $this->tenantId) {
            return null;
        }

        return $prescription;
    }

    /** @return list<Prescription> */
    public function listByPatient(int $patientId): array
    {
        return array_values(array_filter(
            $this->prescriptions,
            fn (Prescription $prescription): bool => $prescription->tenantId() === $this->tenantId
                && $prescription->patientId() === $patientId,
        ));
    }

    /** @return list<Prescription> */
    public function listByEncounter(int $encounterId): array
    {
        return array_values(array_filter(
            $this->prescriptions,
            fn (Prescription $prescription): bool => $prescription->tenantId() === $this->tenantId
                && $prescription->encounterId() === $encounterId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Prescription) {
            throw new InvalidArgumentException('FakePrescriptionRepository only stores Prescription entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->prescriptions[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Prescription && $entity->id() !== null) {
            unset($this->prescriptions[$entity->id()]);
        }
    }
}
