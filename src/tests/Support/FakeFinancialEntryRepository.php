<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\FinancialEntryRepositoryInterface;
use CentralVet\Domain\FinancialEntry;
use InvalidArgumentException;

/**
 * In-memory double for FinancialEntryRepositoryInterface (T-13): the
 * `financial_entry` table does not exist yet (migration T-01 not applied),
 * so PaymentServiceTest exercises PaymentService::register() (which calls
 * FinancialEntryService::record() internally) against this instead of a
 * real database. Tenant-scoped like the real FinancialEntryRepository
 * (ADR 0002): findById()/listBySystemUnitAndPeriod() only ever return
 * entries whose tenantId() matches this instance's own $tenantId.
 */
final class FakeFinancialEntryRepository implements FinancialEntryRepositoryInterface
{
    /** @var array<int, FinancialEntry> */
    private array $entries = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, FinancialEntry ...$seed)
    {
        foreach ($seed as $entry) {
            $this->save($entry);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $entry = $this->entries[(int) $id] ?? null;

        if ($entry === null || $entry->tenantId() !== $this->tenantId) {
            return null;
        }

        return $entry;
    }

    /** @return list<FinancialEntry> */
    public function listBySystemUnitAndPeriod(int $systemUnitId, string $from, string $to): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (FinancialEntry $entry): bool => $entry->tenantId() === $this->tenantId
                && $entry->systemUnitId() === $systemUnitId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof FinancialEntry) {
            throw new InvalidArgumentException('FakeFinancialEntryRepository only stores FinancialEntry entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->entries[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof FinancialEntry && $entity->id() !== null) {
            unset($this->entries[$entity->id()]);
        }
    }
}
