<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SurgeryMaterialRepositoryInterface;
use CentralVet\Domain\SurgeryMaterial;
use InvalidArgumentException;

/**
 * In-memory double for SurgeryMaterialRepositoryInterface (T-05): save()
 * assigns incremental ids (seeded entities that already have one keep it),
 * remove()/delete() delete (delete() returns the rows deleted, 0 when
 * already gone or of another tenant, like the PDO rowCount), listBySurgery() orders by recorded_at, id, and reads
 * only see materials of this instance's tenant.
 */
final class FakeSurgeryMaterialRepository implements SurgeryMaterialRepositoryInterface
{
    /** @var array<int, SurgeryMaterial> */
    private array $materials = [];
    private int $nextId = 1;

    /** Number of save() calls after construction (seed not counted). */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId, SurgeryMaterial ...$seed)
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
        $material = $this->materials[(int) $id] ?? null;

        if ($material === null || $material->tenantId() !== $this->tenantId) {
            return null;
        }

        return $material;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryMaterial) {
            throw new InvalidArgumentException('FakeSurgeryMaterialRepository only stores SurgeryMaterial entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->materials[$entity->id()] = $entity;
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof SurgeryMaterial) {
            $this->delete($entity);
        }
    }

    /** Like the PDO DELETE: tenant + id + surgery_id, returns the rows deleted. */
    public function delete(SurgeryMaterial $material): int
    {
        $id = $material->id();
        $stored = $id !== null ? ($this->materials[$id] ?? null) : null;

        if (
            $stored === null
            || $stored->tenantId() !== $this->tenantId
            || $stored->surgeryId() !== $material->surgeryId()
        ) {
            return 0;
        }

        unset($this->materials[$id]);

        return 1;
    }

    /** Test-only: the stored material with this id, whatever its tenant. */
    public function storedMaterialOfAnyTenant(int $id): ?SurgeryMaterial
    {
        return $this->materials[$id] ?? null;
    }

    public function listBySurgery(int $surgeryId): array
    {
        $materials = array_values(array_filter(
            $this->materials,
            fn (SurgeryMaterial $m): bool => $m->tenantId() === $this->tenantId && $m->surgeryId() === $surgeryId,
        ));
        usort($materials, static fn (SurgeryMaterial $a, SurgeryMaterial $b): int => [$a->recordedAt(), $a->id()] <=> [$b->recordedAt(), $b->id()]);

        return $materials;
    }
}
