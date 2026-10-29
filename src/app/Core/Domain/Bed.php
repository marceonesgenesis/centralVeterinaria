<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\BedUnavailableException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A hospitalization bed of one system unit (domain entity for the `bed`
 * table, migration 20261005_0010_phase6a_hospitalization).
 *
 * Occupancy (`available` <-> `occupied` plus current_hospitalization_id) is
 * NOT changed through this entity: it is flipped atomically by
 * `BedRepositoryInterface::occupy()`/`release()` (conditional UPDATE), so
 * two tablets admitting into the same bed cannot both win. This entity only
 * owns the catalog data (code, name, daily rate) and the active/inactive
 * switch.
 */
final class Bed
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_INACTIVE = 'inactive';

    private const STATUSES = [self::STATUS_AVAILABLE, self::STATUS_OCCUPIED, self::STATUS_INACTIVE];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly string $code,
        private string $name,
        private int $dailyRateCents,
        private string $status,
        private readonly ?int $currentHospitalizationId,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(int $tenantId, int $systemUnitId, string $code, string $name, int $dailyRateCents): self
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
            dailyRateCents: self::normalizeRate($dailyRateCents),
            status: self::STATUS_AVAILABLE,
            currentHospitalizationId: null,
        );
    }

    /**
     * Rebuilds an existing bed from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `bed` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown bed status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            dailyRateCents: (int) $row['daily_rate_cents'],
            status: $status,
            currentHospitalizationId: isset($row['current_hospitalization_id'])
                ? (int) $row['current_hospitalization_id']
                : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Bed already has an id');
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

    public function changeDailyRate(int $dailyRateCents): void
    {
        $this->dailyRateCents = self::normalizeRate($dailyRateCents);
    }

    /** Takes the bed out of use; an occupied bed must be released first. */
    public function deactivate(): void
    {
        if ($this->status === self::STATUS_OCCUPIED) {
            throw BedUnavailableException::occupied((int) $this->id);
        }

        $this->status = self::STATUS_INACTIVE;
    }

    /** Puts an inactive bed back in use; no-op for an available or occupied bed. */
    public function activate(): void
    {
        if ($this->status === self::STATUS_INACTIVE) {
            $this->status = self::STATUS_AVAILABLE;
        }
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

    public function dailyRateCents(): int
    {
        return $this->dailyRateCents;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function currentHospitalizationId(): ?int
    {
        return $this->currentHospitalizationId;
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
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

    private static function normalizeRate(int $dailyRateCents): int
    {
        if ($dailyRateCents < 0) {
            throw new InvalidArgumentException('daily_rate_cents cannot be negative');
        }

        return $dailyRateCents;
    }
}
