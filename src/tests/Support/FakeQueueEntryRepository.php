<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\QueueEntryRepositoryInterface;
use CentralVet\Domain\QueueEntry;
use InvalidArgumentException;

/**
 * In-memory double for QueueEntryRepositoryInterface (T-16): the
 * `queue_entry` table does not exist yet (migration T-01 not applied), so
 * QueueEntryServiceTest exercises QueueEntryService::checkIn()/
 * advanceStatus() against this instead of a real database. Tenant-scoped
 * like the real QueueEntryRepository (ADR 0002): findById() and
 * listActiveByUnit() only ever return entries whose tenantId() matches this
 * instance's own $tenantId.
 */
final class FakeQueueEntryRepository implements QueueEntryRepositoryInterface
{
    /** @var array<int, QueueEntry> */
    private array $entries = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, QueueEntry ...$seed)
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

    /** @return list<QueueEntry> */
    public function listActiveByUnit(int $systemUnitId): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (QueueEntry $entry): bool => $entry->tenantId() === $this->tenantId
                && $entry->systemUnitId() === $systemUnitId
                && $entry->status() !== QueueEntry::STATUS_ATENDIDO,
        ));
    }

    public function findByAppointment(int $appointmentId): ?object
    {
        foreach ($this->entries as $entry) {
            if ($entry->tenantId() === $this->tenantId && $entry->appointmentId() === $appointmentId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<int> $appointmentIds
     * @return list<int>
     */
    public function listAppointmentIdsInQueue(array $appointmentIds): array
    {
        $wanted = array_map('intval', $appointmentIds);
        $found = [];

        foreach ($this->entries as $entry) {
            $appointmentId = $entry->appointmentId();

            if ($entry->tenantId() === $this->tenantId && $appointmentId !== null && in_array($appointmentId, $wanted, true)) {
                $found[$appointmentId] = $appointmentId;
            }
        }

        return array_values($found);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof QueueEntry) {
            throw new InvalidArgumentException('FakeQueueEntryRepository only stores QueueEntry entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->entries[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof QueueEntry && $entity->id() !== null) {
            unset($this->entries[$entity->id()]);
        }
    }
}
