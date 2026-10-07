<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SurgeryChecklistRepositoryInterface;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\SurgeryChecklistItem;
use InvalidArgumentException;

/**
 * In-memory double for SurgeryChecklistRepositoryInterface (T-05). Append
 * only: save() of an item whose (surgery, phase, item code) already exists
 * mirrors the UNIQUE key of T-06 and throws
 * `Checklist phase "<phase>" is already confirmed for surgery <id>`.
 */
final class FakeSurgeryChecklistRepository implements SurgeryChecklistRepositoryInterface
{
    /** @var array<int, SurgeryChecklistItem> */
    private array $items = [];
    private int $nextId = 1;

    /** Number of successful save() calls. */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId)
    {
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

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryChecklistItem) {
            throw new InvalidArgumentException('FakeSurgeryChecklistRepository only stores SurgeryChecklistItem entities');
        }

        foreach ($this->items as $existing) {
            if (
                $existing->surgeryId() === $entity->surgeryId()
                && $existing->phase() === $entity->phase()
                && $existing->itemCode() === $entity->itemCode()
            ) {
                throw new InvalidStatusTransitionException(
                    "Checklist phase \"{$entity->phase()}\" is already confirmed for surgery {$entity->surgeryId()}",
                );
            }
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->items[$entity->id()] = $entity;
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof SurgeryChecklistItem && $entity->id() !== null) {
            unset($this->items[$entity->id()]);
        }
    }

    public function listBySurgery(int $surgeryId): array
    {
        $items = array_values(array_filter(
            $this->items,
            fn (SurgeryChecklistItem $i): bool => $i->tenantId() === $this->tenantId && $i->surgeryId() === $surgeryId,
        ));
        usort($items, static fn (SurgeryChecklistItem $a, SurgeryChecklistItem $b): int => $a->id() <=> $b->id());

        return $items;
    }
}
