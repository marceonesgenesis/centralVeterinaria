<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\CashSession;
use CentralVet\Domain\Contract\CashSessionRepositoryInterface;
use InvalidArgumentException;

/**
 * In-memory double for CashSessionRepositoryInterface (T-13): the
 * `cash_session` table does not exist yet (migration T-01 not applied), so
 * CashSessionServiceTest/PaymentServiceTest exercise
 * CashSessionService::open()/close()/PaymentService::register() against
 * this instead of a real database. Tenant-scoped like the real
 * CashSessionRepository (ADR 0002): findById()/findOpenBySystemUnit() only
 * ever return a session whose tenantId() matches this instance's own
 * $tenantId.
 */
final class FakeCashSessionRepository implements CashSessionRepositoryInterface
{
    /** @var array<int, CashSession> */
    private array $sessions = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, CashSession ...$seed)
    {
        foreach ($seed as $session) {
            $this->save($session);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $session = $this->sessions[(int) $id] ?? null;

        if ($session === null || $session->tenantId() !== $this->tenantId) {
            return null;
        }

        return $session;
    }

    /**
     * Test-only accessor (not part of CashSessionRepositoryInterface): the
     * total number of sessions currently stored, used by
     * CashSessionServiceTest to prove open() never writes a second row when
     * it throws CashSessionAlreadyOpenException.
     */
    public function count(): int
    {
        return count($this->sessions);
    }

    public function findOpenBySystemUnit(int $systemUnitId): ?object
    {
        foreach ($this->sessions as $session) {
            if (
                $session->tenantId() === $this->tenantId
                && $session->systemUnitId() === $systemUnitId
                && $session->isOpen()
            ) {
                return $session;
            }
        }

        return null;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof CashSession) {
            throw new InvalidArgumentException('FakeCashSessionRepository only stores CashSession entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->sessions[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof CashSession && $entity->id() !== null) {
            unset($this->sessions[$entity->id()]);
        }
    }
}
