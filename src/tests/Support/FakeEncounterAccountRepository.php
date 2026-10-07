<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\EncounterAccountRepositoryInterface;
use CentralVet\Domain\EncounterAccount;
use InvalidArgumentException;

/**
 * In-memory double for EncounterAccountRepositoryInterface (T-13): the
 * `encounter_account` table does not exist yet (migration T-01 not
 * applied), so EncounterAccountServiceTest exercises
 * EncounterAccountService against this instead of a real database.
 * Tenant-scoped like the real EncounterAccountRepository (ADR 0002):
 * findById()/findByEncounterId() only ever return an account whose
 * tenantId() matches this instance's own $tenantId.
 */
final class FakeEncounterAccountRepository implements EncounterAccountRepositoryInterface
{
    /** @var array<int, EncounterAccount> */
    private array $accounts = [];
    private int $nextId = 1;

    /**
     * T-59: number of save() calls made after construction (seed accounts
     * are not counted). Public and writable so a test can zero it right
     * before the call under test and assert nothing was persisted.
     */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId, EncounterAccount ...$seed)
    {
        foreach ($seed as $account) {
            $this->save($account);
        }

        $this->saveCount = 0;
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

    public function findByEncounterId(int $encounterId): ?object
    {
        foreach ($this->accounts as $account) {
            if ($account->tenantId() === $this->tenantId && $account->encounterId() === $encounterId) {
                return $account;
            }
        }

        return null;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof EncounterAccount) {
            throw new InvalidArgumentException('FakeEncounterAccountRepository only stores EncounterAccount entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->accounts[$entity->id()] = $entity;
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof EncounterAccount && $entity->id() !== null) {
            unset($this->accounts[$entity->id()]);
        }
    }
}
