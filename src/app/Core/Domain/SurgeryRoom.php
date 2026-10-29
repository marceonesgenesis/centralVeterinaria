<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An operating room of one system unit (domain entity for the
 * `surgery_room` table, migration 20261005_0011_phase6b_surgery).
 *
 * Room booking is not stored here: conflicts are checked by the scheduling
 * service under `SurgeryRoomRepositoryInterface::lockForScheduling()` plus
 * `SurgeryRepositoryInterface::hasOverlapInRoom()`. This entity only owns
 * the catalog data (code, name) and the active/inactive switch.
 */
final class SurgeryRoom
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    private const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly string $code,
        private string $name,
        private string $status,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(int $tenantId, int $systemUnitId, string $code, string $name): self
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        $code = trim($code);
        $codeLength = mb_strlen($code);

        if ($codeLength < 1 || $codeLength > 30) {
            throw new InvalidArgumentException('code must be between 1 and 30 characters');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            code: $code,
            name: self::normalizeName($name),
            status: self::STATUS_ACTIVE,
        );
    }

    /**
     * Rebuilds an existing room from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `surgery_room` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown surgery room status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            status: $status,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Surgery room already has an id');
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

    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
    }

    public function deactivate(): void
    {
        $this->status = self::STATUS_INACTIVE;
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

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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
        $length = mb_strlen($name);

        if ($length < 1 || $length > 120) {
            throw new InvalidArgumentException('name must be between 1 and 120 characters');
        }

        return $name;
    }
}
