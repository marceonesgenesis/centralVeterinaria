<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A bank account of the tenant at a system unit (domain entity for the
 * `bank_account` table, migration 0007, rodada 2 T-15). The balance is
 * informed by hand (signed, in cents) and is NOT reconciled with
 * financial_entry (decision recorded in the task's notes.md); every change
 * of balance_cents stamps balance_updated_at.
 */
final class BankAccount
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private string $name,
        private ?string $bankName,
        private int $balanceCents,
        private ?DateTimeImmutable $balanceUpdatedAt,
        private bool $active,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        int $systemUnitId,
        string $name,
        ?string $bankName,
        int $balanceCents,
        DateTimeImmutable $now,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            name: self::normalizeName($name),
            bankName: self::normalizeBankName($bankName),
            balanceCents: $balanceCents,
            balanceUpdatedAt: $now,
            active: true,
        );
    }

    /**
     * Rebuilds an existing account from a persisted row (column names of
     * `bank_account`). Repositories use this to hydrate rows; application
     * code should use create() instead.
     *
     * @param array<string, mixed> $row
     */
    public static function reconstitute(array $row): self
    {
        $date = static fn (mixed $value): ?DateTimeImmutable => $value === null || $value === ''
            ? null
            : ($value instanceof DateTimeImmutable ? $value : new DateTimeImmutable((string) $value));

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            name: self::normalizeName((string) ($row['name'] ?? '')),
            bankName: self::normalizeBankName(isset($row['bank_name']) ? (string) $row['bank_name'] : null),
            balanceCents: (int) ($row['balance_cents'] ?? 0),
            balanceUpdatedAt: $date($row['balance_updated_at'] ?? null),
            active: (bool) (int) ($row['active'] ?? 1),
            createdAt: $date($row['created_at'] ?? null),
            updatedAt: $date($row['updated_at'] ?? null),
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('BankAccount already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    public function rename(string $name): void
    {
        $this->name = self::normalizeName($name);
    }

    public function changeBankName(?string $bankName): void
    {
        $this->bankName = self::normalizeBankName($bankName);
    }

    /**
     * Informs a new balance. balance_updated_at is stamped only when the
     * value actually changes, so re-saving the same balance keeps the date
     * of the last real change.
     */
    public function changeBalance(int $balanceCents, DateTimeImmutable $now): void
    {
        if ($balanceCents === $this->balanceCents) {
            return;
        }

        $this->balanceCents = $balanceCents;
        $this->balanceUpdatedAt = $now;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
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

    public function name(): string
    {
        return $this->name;
    }

    public function bankName(): ?string
    {
        return $this->bankName;
    }

    /** Signed balance in cents (negative = overdrawn). */
    public function balanceCents(): int
    {
        return $this->balanceCents;
    }

    public function balanceUpdatedAt(): ?DateTimeImmutable
    {
        return $this->balanceUpdatedAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }

        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('name must have at most 120 characters');
        }

        return $name;
    }

    private static function normalizeBankName(?string $bankName): ?string
    {
        $bankName = $bankName === null ? null : trim($bankName);

        if ($bankName === null || $bankName === '') {
            return null;
        }

        if (mb_strlen($bankName) > 120) {
            throw new InvalidArgumentException('bank_name must have at most 120 characters');
        }

        return $bankName;
    }
}
