<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\PayableRepositoryInterface;
use CentralVet\Domain\Payable;
use InvalidArgumentException;

/**
 * In-memory double for PayableRepositoryInterface (fase 10, T-18).
 * Tenant-scoped like the real PayableRepository (ADR 0002): findById()/
 * listOpenBySystemUnit()/listBySystemUnitAndStatus() only return payables
 * of this instance's $tenantId.
 */
final class FakePayableRepository implements PayableRepositoryInterface
{
    /** @var array<int, Payable> */
    private array $payables = [];
    private int $nextId = 1;
    public int $inserts = 0;

    public function __construct(private readonly int $tenantId, Payable ...$seed)
    {
        foreach ($seed as $payable) {
            $this->save($payable);
        }
        $this->inserts = 0;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $payable = $this->payables[(int) $id] ?? null;

        if ($payable === null || $payable->tenantId() !== $this->tenantId) {
            return null;
        }

        return $payable;
    }

    /** @return list<Payable> */
    public function listOpenBySystemUnit(int $systemUnitId): array
    {
        return array_values(array_filter(
            $this->payables,
            fn (Payable $p): bool => $p->tenantId() === $this->tenantId
                && $p->systemUnitId() === $systemUnitId
                && $p->status() === Payable::STATUS_OPEN,
        ));
    }

    /** @return list<Payable> */
    public function listBySystemUnitAndStatus(int $systemUnitId, ?string $status): array
    {
        return array_values(array_filter(
            $this->payables,
            fn (Payable $p): bool => $p->tenantId() === $this->tenantId
                && $p->systemUnitId() === $systemUnitId
                && ($status === null || $p->status() === $status),
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Payable) {
            throw new InvalidArgumentException('FakePayableRepository only stores Payable entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
            $this->inserts++;
        }

        $this->payables[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Payable && $entity->id() !== null) {
            unset($this->payables[$entity->id()]);
        }
    }

    public function count(): int
    {
        return count($this->payables);
    }
}
