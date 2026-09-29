<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ReceivableRepositoryInterface;
use CentralVet\Domain\Receivable;
use InvalidArgumentException;

/**
 * In-memory double for ReceivableRepositoryInterface (T-13): the
 * `receivable` table does not exist yet (migration T-01 not applied), so
 * EncounterAccountServiceTest/PaymentServiceTest exercise
 * EncounterAccountService::close()/PaymentService::register() against this
 * instead of a real database. Tenant-scoped like the real
 * ReceivableRepository (ADR 0002): findById()/findByEncounterAccountId()
 * only ever return a receivable whose tenantId() matches this instance's
 * own $tenantId.
 */
final class FakeReceivableRepository implements ReceivableRepositoryInterface
{
    /** @var array<int, Receivable> */
    private array $receivables = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Receivable ...$seed)
    {
        foreach ($seed as $receivable) {
            $this->save($receivable);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $receivable = $this->receivables[(int) $id] ?? null;

        if ($receivable === null || $receivable->tenantId() !== $this->tenantId) {
            return null;
        }

        return $receivable;
    }

    public function findByEncounterAccountId(int $accountId): ?object
    {
        foreach ($this->receivables as $receivable) {
            if ($receivable->tenantId() === $this->tenantId && $receivable->encounterAccountId() === $accountId) {
                return $receivable;
            }
        }

        return null;
    }

    /** @return list<Receivable> */
    public function listOpen(): array
    {
        return array_values(array_filter(
            $this->receivables,
            fn (Receivable $receivable): bool => $receivable->tenantId() === $this->tenantId
                && in_array($receivable->status(), [Receivable::STATUS_OPEN, Receivable::STATUS_PARTIALLY_PAID], true)
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Receivable) {
            throw new InvalidArgumentException('FakeReceivableRepository only stores Receivable entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->receivables[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Receivable && $entity->id() !== null) {
            unset($this->receivables[$entity->id()]);
        }
    }
}
