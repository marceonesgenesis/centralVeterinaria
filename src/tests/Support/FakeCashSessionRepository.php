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
 *
 * Etapa 3 (T-03): every read and save() is appended to $lockCalls by method
 * name, in call order, so CashSessionLockTest can prove a service took the
 * `...ForUpdate` read of the till before writing. The *ForUpdate methods
 * behave like their plain counterparts here (no transaction to check).
 */
final class FakeCashSessionRepository implements CashSessionRepositoryInterface
{
    /** @var array<int, CashSession> */
    private array $sessions = [];
    private int $nextId = 1;

    /**
     * Method names called on this fake, in order (seeding by the
     * constructor is not recorded; tests reset it to [] after their own
     * setup saves).
     *
     * @var list<string>
     */
    public array $lockCalls = [];

    public function __construct(private readonly int $tenantId, CashSession ...$seed)
    {
        foreach ($seed as $session) {
            $this->save($session);
        }

        $this->lockCalls = [];
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $this->lockCalls[] = 'findById';

        return $this->lookup((int) $id);
    }

    public function findByIdForUpdate(int $id): ?object
    {
        $this->lockCalls[] = 'findByIdForUpdate';

        return $this->lookup($id);
    }

    private function lookup(int $id): ?CashSession
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
        $this->lockCalls[] = 'findOpenBySystemUnit';

        return $this->lookupOpen($systemUnitId);
    }

    public function findOpenBySystemUnitForUpdate(int $systemUnitId): ?object
    {
        $this->lockCalls[] = 'findOpenBySystemUnitForUpdate';

        return $this->lookupOpen($systemUnitId);
    }

    private function lookupOpen(int $systemUnitId): ?CashSession
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

        $this->lockCalls[] = 'save';

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
