<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\VaccineProtocolRepositoryInterface;
use CentralVet\Domain\VaccineProtocol;
use InvalidArgumentException;

/**
 * In-memory double for VaccineProtocolRepositoryInterface (T-11): the
 * `vaccine_protocol` table does not exist yet (migration T-01 not applied),
 * so VaccinationServiceTest exercises VaccinationService::apply()'s
 * next_dose_at calculation against this instead of a real database.
 * Tenant-scoped like the real VaccineProtocolRepository (ADR 0002):
 * findById() and listByVaccineCatalogItem() only ever return a row whose
 * tenantId() matches this instance's own $tenantId.
 */
final class FakeVaccineProtocolRepository implements VaccineProtocolRepositoryInterface
{
    /** @var array<int, VaccineProtocol> */
    private array $protocols = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, VaccineProtocol ...$seed)
    {
        foreach ($seed as $protocol) {
            $this->save($protocol);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $protocol = $this->protocols[(int) $id] ?? null;

        if ($protocol === null || $protocol->tenantId() !== $this->tenantId) {
            return null;
        }

        return $protocol;
    }

    /** @return list<VaccineProtocol> */
    public function listByVaccineCatalogItem(int $vaccineCatalogItemId): array
    {
        return array_values(array_filter(
            $this->protocols,
            fn (VaccineProtocol $protocol): bool => $protocol->tenantId() === $this->tenantId
                && $protocol->vaccineCatalogItemId() === $vaccineCatalogItemId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof VaccineProtocol) {
            throw new InvalidArgumentException('FakeVaccineProtocolRepository only stores VaccineProtocol entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->protocols[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof VaccineProtocol && $entity->id() !== null) {
            unset($this->protocols[$entity->id()]);
        }
    }
}
