<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A till session for a system unit (domain entity for the `cash_session`
 * table, T-01): opened by a system user with a starting balance, later
 * closed by a system user with a counted closing balance. Exactly one
 * `status='open'` session may exist per system unit at a time — that
 * exclusivity is enforced by CentralVet\Application\CashSessionService::open()
 * via CashSessionRepositoryInterface::findOpenBySystemUnit() (throwing
 * CashSessionAlreadyOpenException before any write), not by this entity,
 * since checking "is there another open session" requires a repository
 * lookup this entity has no access to.
 *
 * close() is the entity's own invariant: it is the ONLY way status changes,
 * and it refuses to run a second time on an already-closed session (mirrors
 * Encounter::finish()/QueueEntry::advance(), both one-way transitions
 * guarded by InvalidStatusTransitionException).
 */
final class CashSession
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $openedBySystemUserId,
        private readonly int $openingBalanceCents,
        private ?int $closedBySystemUserId,
        private ?int $closingBalanceCents,
        private string $status,
        private readonly DateTimeImmutable $openedAt,
        private ?DateTimeImmutable $closedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function open(
        int $tenantId,
        int $systemUnitId,
        int $openingBalanceCents,
        int $openedBySystemUserId,
        DateTimeImmutable $openedAt,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        if ($openedBySystemUserId <= 0) {
            throw new InvalidArgumentException('opened_by_system_user_id must be positive');
        }

        if ($openingBalanceCents < 0) {
            throw new InvalidArgumentException('opening_balance_cents cannot be negative');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            openedBySystemUserId: $openedBySystemUserId,
            openingBalanceCents: $openingBalanceCents,
            closedBySystemUserId: null,
            closingBalanceCents: null,
            status: self::STATUS_OPEN,
            openedAt: $openedAt,
            closedAt: null,
        );
    }

    /**
     * Rebuilds an existing session from persisted data. Repositories use
     * this to hydrate rows; application code should use open() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $systemUnitId,
        int $openedBySystemUserId,
        int $openingBalanceCents,
        ?int $closedBySystemUserId,
        ?int $closingBalanceCents,
        string $status,
        DateTimeImmutable $openedAt,
        ?DateTimeImmutable $closedAt,
        ?DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $tenantId,
            $systemUnitId,
            $openedBySystemUserId,
            $openingBalanceCents,
            $closedBySystemUserId,
            $closingBalanceCents,
            $status,
            $openedAt,
            $closedAt,
            $createdAt,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Cash session already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Closes the session: `status` moves to `closed`, `closing_balance_cents`
     * and `closed_by_system_user_id` are recorded and `closed_at` is
     * stamped. One-way — there is no operation in this plan that reopens a
     * closed session.
     *
     * @throws InvalidStatusTransitionException when the session is not
     *         `status='open'`. Nothing is mutated when this is thrown, so a
     *         session already closed keeps its original
     *         closing_balance_cents/closed_at.
     */
    public function close(int $closingBalanceCents, int $closedBySystemUserId, DateTimeImmutable $now): void
    {
        if ($this->status !== self::STATUS_OPEN) {
            throw new InvalidStatusTransitionException(
                sprintf(
                    'Cash session %s cannot be closed: current status is "%s", not "%s"',
                    $this->id !== null ? (string) $this->id : '(new)',
                    $this->status,
                    self::STATUS_OPEN,
                )
            );
        }

        if ($closingBalanceCents < 0) {
            throw new InvalidArgumentException('closing_balance_cents cannot be negative');
        }

        if ($closedBySystemUserId <= 0) {
            throw new InvalidArgumentException('closed_by_system_user_id must be positive');
        }

        $this->status = self::STATUS_CLOSED;
        $this->closingBalanceCents = $closingBalanceCents;
        $this->closedBySystemUserId = $closedBySystemUserId;
        $this->closedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function systemUnitId(): int
    {
        return $this->systemUnitId;
    }

    public function openedBySystemUserId(): int
    {
        return $this->openedBySystemUserId;
    }

    public function openingBalanceCents(): int
    {
        return $this->openingBalanceCents;
    }

    public function closedBySystemUserId(): ?int
    {
        return $this->closedBySystemUserId;
    }

    public function closingBalanceCents(): ?int
    {
        return $this->closingBalanceCents;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function openedAt(): DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
