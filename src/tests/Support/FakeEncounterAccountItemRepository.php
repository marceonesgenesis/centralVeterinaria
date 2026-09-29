<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\EncounterAccountItemRepositoryInterface;
use CentralVet\Domain\EncounterAccountItem;
use InvalidArgumentException;

/**
 * In-memory double for EncounterAccountItemRepositoryInterface (T-13): the
 * `encounter_account_item` table does not exist yet (migration T-01 not
 * applied), so EncounterAccountServiceTest exercises
 * EncounterAccountService::syncAutomaticItems()/addManualItem() against
 * this instead of a real database. Tenant-scoped like the real
 * EncounterAccountItemRepository (ADR 0002): findById()/listByAccount()
 * only ever return items whose tenantId() matches this instance's own
 * $tenantId.
 */
final class FakeEncounterAccountItemRepository implements EncounterAccountItemRepositoryInterface
{
    /** @var array<int, EncounterAccountItem> */
    private array $items = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, EncounterAccountItem ...$seed)
    {
        foreach ($seed as $item) {
            $this->save($item);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $item = $this->items[(int) $id] ?? null;

        if ($item === null || $item->tenantId() !== $this->tenantId) {
            return null;
        }

        return $item;
    }

    /** @return list<EncounterAccountItem> */
    public function listByAccount(int $accountId): array
    {
        return array_values(array_filter(
            $this->items,
            fn (EncounterAccountItem $item): bool => $item->tenantId() === $this->tenantId
                && $item->accountId() === $accountId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof EncounterAccountItem) {
            throw new InvalidArgumentException('FakeEncounterAccountItemRepository only stores EncounterAccountItem entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->items[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof EncounterAccountItem && $entity->id() !== null) {
            unset($this->items[$entity->id()]);
        }
    }
}
