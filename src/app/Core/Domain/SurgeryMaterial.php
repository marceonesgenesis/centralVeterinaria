<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A stock product used during a surgery (domain entity for the
 * `surgery_material` table, migration 20261005_0011_phase6b_surgery).
 * Recorded while the surgery is in progress and removable until its
 * completion; stock and billing happen only at completion.
 */
final class SurgeryMaterial
{
    private const QUANTITY_MIN = 1;
    private const QUANTITY_MAX = 9999;

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $surgeryId,
        private readonly int $productId,
        private readonly int $quantity,
        private readonly int $recordedBySystemUserId,
        private readonly DateTimeImmutable $recordedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function record(
        int $tenantId,
        int $surgeryId,
        int $productId,
        int $quantity,
        int $recordedBySystemUserId,
        DateTimeImmutable $recordedAt,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($surgeryId <= 0) {
            throw new InvalidArgumentException('surgery_id must be positive');
        }

        if ($productId <= 0) {
            throw new InvalidArgumentException('product_id must be positive');
        }

        if ($quantity < self::QUANTITY_MIN || $quantity > self::QUANTITY_MAX) {
            throw new InvalidArgumentException('quantity must be between 1 and 9999');
        }

        if ($recordedBySystemUserId <= 0) {
            throw new InvalidArgumentException('recorded_by_system_user_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            surgeryId: $surgeryId,
            productId: $productId,
            quantity: $quantity,
            recordedBySystemUserId: $recordedBySystemUserId,
            recordedAt: $recordedAt,
        );
    }

    /**
     * Rebuilds an existing material from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `surgery_material` table.
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            surgeryId: (int) $row['surgery_id'],
            productId: (int) $row['product_id'],
            quantity: (int) $row['quantity'],
            recordedBySystemUserId: (int) $row['recorded_by_system_user_id'],
            recordedAt: new DateTimeImmutable((string) $row['recorded_at']),
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('SurgeryMaterial already has an id');
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

    public function productId(): int
    {
        return $this->productId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function recordedBySystemUserId(): int
    {
        return $this->recordedBySystemUserId;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
