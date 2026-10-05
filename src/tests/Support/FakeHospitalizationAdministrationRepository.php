<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\HospitalizationAdministrationRepositoryInterface;
use CentralVet\Domain\HospitalizationAdministration;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for HospitalizationAdministrationRepositoryInterface (T-06), modelled on
 * FakeEncounterAccountRepository: save() assigns incremental ids (seeded
 * entities that already have one keep it), and every read only sees
 * entities whose tenantId() matches this instance's $tenantId.
 * listBoardRows() has no in-memory equivalent of the flowboard JOIN: it
 * returns exactly the rows given to seedBoardRows(), without any filter.
 */
final class FakeHospitalizationAdministrationRepository implements HospitalizationAdministrationRepositoryInterface
{
    /** @var array<int, HospitalizationAdministration> */
    private array $administrations = [];
    private int $nextId = 1;

    /** Number of save() calls after construction (seed not counted). */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId, HospitalizationAdministration ...$seed)
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
        $item = $this->administrations[(int) $id] ?? null;

        if ($item === null || $item->tenantId() !== $this->tenantId) {
            return null;
        }

        return $item;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof HospitalizationAdministration) {
            throw new InvalidArgumentException('FakeHospitalizationAdministrationRepository only stores HospitalizationAdministration entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->administrations[$entity->id()] = $entity;
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof HospitalizationAdministration && $entity->id() !== null) {
            unset($this->administrations[$entity->id()]);
        }
    }

    /** @return list<HospitalizationAdministration> */
    private function ownTenant(): array
    {
        return array_values(array_filter(
            $this->administrations,
            fn (HospitalizationAdministration $item): bool => $item->tenantId() === $this->tenantId,
        ));
    }

    /** @var list<array<string, mixed>> */
    private array $boardRows = [];

    public function listByHospitalization(int $hospitalizationId): array
    {
        return $this->sorted(static fn (HospitalizationAdministration $a): bool => $a->hospitalizationId() === $hospitalizationId);
    }

    public function listPendingByOrder(int $orderId): array
    {
        return $this->sorted(static fn (HospitalizationAdministration $a): bool => $a->orderId() === $orderId
            && $a->status() === HospitalizationAdministration::STATUS_PENDING);
    }

    public function listPendingByHospitalization(int $hospitalizationId): array
    {
        return $this->sorted(static fn (HospitalizationAdministration $a): bool => $a->hospitalizationId() === $hospitalizationId
            && $a->status() === HospitalizationAdministration::STATUS_PENDING);
    }

    /** @param list<array<string, mixed>> $rows */
    public function seedBoardRows(array $rows): void
    {
        $this->boardRows = array_values($rows);
    }

    public function listBoardRows(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->boardRows;
    }

    /** @return list<HospitalizationAdministration> scheduled_at ASC, id ASC */
    private function sorted(callable $filter): array
    {
        $items = array_values(array_filter($this->ownTenant(), $filter));
        usort($items, static fn (HospitalizationAdministration $a, HospitalizationAdministration $b): int => [$a->scheduledAt(), $a->id()] <=> [$b->scheduledAt(), $b->id()]);

        return $items;
    }
}
