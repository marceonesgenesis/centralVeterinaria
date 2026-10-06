<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\HospitalizationRepositoryInterface;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Hospitalization;
use InvalidArgumentException;

/**
 * In-memory double for HospitalizationRepositoryInterface (T-06), modelled on
 * FakeEncounterAccountRepository: save() assigns incremental ids (seeded
 * entities that already have one keep it), and every read only sees
 * entities whose tenantId() matches this instance's $tenantId.
 */
final class FakeHospitalizationRepository implements HospitalizationRepositoryInterface
{
    /** @var array<int, Hospitalization> */
    private array $hospitalizations = [];
    private int $nextId = 1;

    /**
     * Status each id had at its last save(), mirroring the PDO repository's
     * `AND status = 'admitted'` guard (entities are shared by reference, so
     * the stored object cannot tell what was persisted).
     *
     * @var array<int, string>
     */
    private array $persistedStatus = [];

    /** Number of save() calls after construction (seed not counted). */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId, Hospitalization ...$seed)
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
        $item = $this->hospitalizations[(int) $id] ?? null;

        if ($item === null || $item->tenantId() !== $this->tenantId) {
            return null;
        }

        return $item;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Hospitalization) {
            throw new InvalidArgumentException('FakeHospitalizationRepository only stores Hospitalization entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $previous = $this->persistedStatus[$entity->id()] ?? null;

            if ($previous !== null && $previous !== Hospitalization::STATUS_ADMITTED) {
                throw new InvalidStatusTransitionException("Hospitalization {$entity->id()} is not admitted");
            }

            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->hospitalizations[$entity->id()] = $entity;
        $this->persistedStatus[$entity->id()] = $entity->status();
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Hospitalization && $entity->id() !== null) {
            unset($this->hospitalizations[$entity->id()]);
        }
    }

    /** @return list<Hospitalization> */
    private function ownTenant(): array
    {
        return array_values(array_filter(
            $this->hospitalizations,
            fn (Hospitalization $item): bool => $item->tenantId() === $this->tenantId,
        ));
    }

    public function findActiveByPatient(int $patientId): ?object
    {
        foreach ($this->ownTenant() as $hospitalization) {
            if (
                $hospitalization->patientId() === $patientId
                && $hospitalization->status() === Hospitalization::STATUS_ADMITTED
            ) {
                return $hospitalization;
            }
        }

        return null;
    }

    public function listActiveByUnit(int $systemUnitId): array
    {
        return array_values(array_filter(
            $this->ownTenant(),
            static fn (Hospitalization $h): bool => $h->systemUnitId() === $systemUnitId
                && $h->status() === Hospitalization::STATUS_ADMITTED,
        ));
    }
}
