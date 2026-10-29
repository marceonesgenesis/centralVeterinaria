<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Bed;
use CentralVet\Domain\Contract\BedRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for BedRepositoryInterface (T-06), modelled on
 * FakeEncounterAccountRepository: save() assigns incremental ids (seeded
 * entities that already have one keep it), and every read only sees
 * entities whose tenantId() matches this instance's $tenantId.
 * occupy()/release() mirror the conditional UPDATE of the PDO repository:
 * occupy only succeeds on an `available` bed of this tenant, release only
 * when the bed is `occupied` by the given hospitalization. Bed itself has
 * no occupancy mutator (T-03), so the stored entity is replaced by a
 * Bed::reconstitute() copy with the new status.
 */
final class FakeBedRepository implements BedRepositoryInterface
{
    /** @var array<int, Bed> */
    private array $beds = [];
    private int $nextId = 1;

    /** Number of save() calls after construction (seed not counted). */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId, Bed ...$seed)
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
        $item = $this->beds[(int) $id] ?? null;

        if ($item === null || $item->tenantId() !== $this->tenantId) {
            return null;
        }

        return $item;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Bed) {
            throw new InvalidArgumentException('FakeBedRepository only stores Bed entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->beds[$entity->id()] = $entity;
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Bed && $entity->id() !== null) {
            unset($this->beds[$entity->id()]);
        }
    }

    /** @return list<Bed> */
    private function ownTenant(): array
    {
        return array_values(array_filter(
            $this->beds,
            fn (Bed $item): bool => $item->tenantId() === $this->tenantId,
        ));
    }

    public function listByUnit(int $systemUnitId): array
    {
        $beds = array_values(array_filter(
            $this->ownTenant(),
            static fn (Bed $bed): bool => $bed->systemUnitId() === $systemUnitId,
        ));
        usort($beds, static fn (Bed $a, Bed $b): int => strcmp($a->code(), $b->code()));

        return $beds;
    }

    public function findByCode(int $systemUnitId, string $code): ?object
    {
        foreach ($this->ownTenant() as $bed) {
            if ($bed->systemUnitId() === $systemUnitId && $bed->code() === $code) {
                return $bed;
            }
        }

        return null;
    }

    public function occupy(int $bedId, int $hospitalizationId): bool
    {
        $bed = $this->findById($bedId);

        if ($bed === null || $bed->status() !== Bed::STATUS_AVAILABLE) {
            return false;
        }

        $this->replace($bed, Bed::STATUS_OCCUPIED, $hospitalizationId);

        return true;
    }

    public function release(int $bedId, int $hospitalizationId): bool
    {
        $bed = $this->findById($bedId);

        if (
            $bed === null
            || $bed->status() !== Bed::STATUS_OCCUPIED
            || $bed->currentHospitalizationId() !== $hospitalizationId
        ) {
            return false;
        }

        $this->replace($bed, Bed::STATUS_AVAILABLE, null);

        return true;
    }

    private function replace(Bed $bed, string $status, ?int $hospitalizationId): void
    {
        $this->beds[(int) $bed->id()] = Bed::reconstitute([
            'id' => $bed->id(),
            'tenant_id' => $bed->tenantId(),
            'system_unit_id' => $bed->systemUnitId(),
            'code' => $bed->code(),
            'name' => $bed->name(),
            'daily_rate_cents' => $bed->dailyRateCents(),
            'status' => $status,
            'current_hospitalization_id' => $hospitalizationId,
            'created_at' => $bed->createdAt()?->format('Y-m-d H:i:s.u'),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }
}
