<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One confirmed item of a surgery checklist phase (domain entity for the
 * `surgery_checklist` table, migration 20261005_0011_phase6b_surgery).
 * Append-only; unique per (surgery, phase, item code).
 */
final class SurgeryChecklistItem
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $surgeryId,
        private readonly string $phase,
        private readonly string $itemCode,
        private readonly int $checkedBySystemUserId,
        private readonly DateTimeImmutable $checkedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function check(
        int $tenantId,
        int $surgeryId,
        string $phase,
        string $itemCode,
        int $checkedBySystemUserId,
        DateTimeImmutable $checkedAt,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($surgeryId <= 0) {
            throw new InvalidArgumentException('surgery_id must be positive');
        }

        SurgeryChecklist::assertItemOfPhase($phase, $itemCode);

        if ($checkedBySystemUserId <= 0) {
            throw new InvalidArgumentException('checked_by_system_user_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            surgeryId: $surgeryId,
            phase: $phase,
            itemCode: $itemCode,
            checkedBySystemUserId: $checkedBySystemUserId,
            checkedAt: $checkedAt,
        );
    }

    /**
     * Rebuilds an existing item from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `surgery_checklist` table.
     */
    public static function reconstitute(array $row): self
    {
        $phase = (string) $row['phase'];
        $itemCode = (string) $row['item_code'];
        SurgeryChecklist::assertItemOfPhase($phase, $itemCode);

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            surgeryId: (int) $row['surgery_id'],
            phase: $phase,
            itemCode: $itemCode,
            checkedBySystemUserId: (int) $row['checked_by_system_user_id'],
            checkedAt: new DateTimeImmutable((string) $row['checked_at']),
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('SurgeryChecklistItem already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function surgeryId(): int
    {
        return $this->surgeryId;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function itemCode(): string
    {
        return $this->itemCode;
    }

    public function checkedBySystemUserId(): int
    {
        return $this->checkedBySystemUserId;
    }

    public function checkedAt(): DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
