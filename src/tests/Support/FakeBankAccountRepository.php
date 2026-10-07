<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\BankAccount;
use CentralVet\Domain\Contract\BankAccountRepositoryInterface;
use InvalidArgumentException;

/**
 * In-memory double for BankAccountRepositoryInterface (rodada 2, T-15).
 * Tenant-scoped like the real BankAccountRepository (ADR 0002): findById()/
 * findByName()/listBySystemUnit() only return accounts of this instance's
 * $tenantId. Seeded accounts may already carry an id (reconstitute()).
 */
final class FakeBankAccountRepository implements BankAccountRepositoryInterface
{
    /** @var array<int, BankAccount> */
    private array $accounts = [];
    private int $nextId = 1;
    public int $inserts = 0;

    public function __construct(private readonly int $tenantId, BankAccount ...$seed)
    {
        foreach ($seed as $account) {
            $this->save($account);
        }
        $this->inserts = 0;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $account = $this->accounts[(int) $id] ?? null;

        if ($account === null || $account->tenantId() !== $this->tenantId) {
            return null;
        }

        return $account;
    }

    public function findByName(int $systemUnitId, string $name): ?object
    {
        foreach ($this->listBySystemUnit($systemUnitId) as $account) {
            if (mb_strtolower($account->name()) === mb_strtolower(trim($name))) {
                return $account;
            }
        }

        return null;
    }

    /** @return list<BankAccount> */
    public function listBySystemUnit(int $systemUnitId): array
    {
        return array_values(array_filter(
            $this->accounts,
            fn (BankAccount $a): bool => $a->tenantId() === $this->tenantId && $a->systemUnitId() === $systemUnitId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof BankAccount) {
            throw new InvalidArgumentException('FakeBankAccountRepository only stores BankAccount entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
            $this->inserts++;
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->accounts[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof BankAccount && $entity->id() !== null) {
            unset($this->accounts[$entity->id()]);
        }
    }

    public function count(): int
    {
        return count($this->accounts);
    }
}
