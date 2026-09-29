<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A completed (or later cancelled) point-of-sale transaction (domain entity
 * for the `sale` table): who it was sold to/for (tutor_id, optional
 * patient_id), where (system_unit_id) and by whom
 * (system_user_id), plus the exact total charged
 * (total_amount_cents). Its line items — a mix of `product` and `procedure`
 * entries, each with its own price/description captured at sale time — are
 * the separate {@see SaleItem} aggregate.
 *
 * total_amount_cents is never recomputed by this class: it is supplied by
 * the caller (CentralVet\Application\SaleService::create()) as the exact sum
 * of every SaleItem::totalCents() it is about to persist alongside this Sale,
 * so the two can never drift (T-06's acceptance criterion).
 *
 * Same mutable-id / assignId() shape as CentralVet\Domain\Product and
 * CentralVet\Domain\ProcedureCatalogItem (create() for a brand-new instance,
 * reconstitute() for repositories hydrating a row).
 *
 * PENDING / DO NOT WIRE YET: `sale` is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class Sale
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    private const STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $tutorId,
        private readonly ?int $patientId,
        private readonly ?int $encounterId,
        private readonly int $systemUserId,
        private string $status,
        private readonly int $totalAmountCents,
        private readonly DateTimeImmutable $soldAt,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        int $systemUnitId,
        int $tutorId,
        ?int $patientId,
        ?int $encounterId,
        int $systemUserId,
        int $totalAmountCents,
        DateTimeImmutable $soldAt,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        if ($tutorId <= 0) {
            throw new InvalidArgumentException('tutor_id must be positive');
        }

        if ($patientId !== null && $patientId <= 0) {
            throw new InvalidArgumentException('patient_id must be positive when informed');
        }

        if ($encounterId !== null && $encounterId <= 0) {
            throw new InvalidArgumentException('encounter_id must be positive when informed');
        }

        if ($systemUserId <= 0) {
            throw new InvalidArgumentException('system_user_id must be positive');
        }

        if ($totalAmountCents < 0) {
            throw new InvalidArgumentException('total_amount_cents cannot be negative');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            tutorId: $tutorId,
            patientId: $patientId,
            encounterId: $encounterId,
            systemUserId: $systemUserId,
            status: self::STATUS_COMPLETED,
            totalAmountCents: $totalAmountCents,
            soldAt: $soldAt,
        );
    }

    /**
     * Rebuilds an existing sale from persisted data. Repositories use this
     * to hydrate rows; application code should use create() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $systemUnitId,
        int $tutorId,
        ?int $patientId,
        ?int $encounterId,
        int $systemUserId,
        string $status,
        int $totalAmountCents,
        DateTimeImmutable $soldAt,
        ?DateTimeImmutable $createdAt,
    ): self {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status');
        }

        return new self(
            $id,
            $tenantId,
            $systemUnitId,
            $tutorId,
            $patientId,
            $encounterId,
            $systemUserId,
            $status,
            $totalAmountCents,
            $soldAt,
            $createdAt,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Sale already has an id');
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

    public function systemUnitId(): int
    {
        return $this->systemUnitId;
    }

    public function tutorId(): int
    {
        return $this->tutorId;
    }

    public function patientId(): ?int
    {
        return $this->patientId;
    }

    public function encounterId(): ?int
    {
        return $this->encounterId;
    }

    public function systemUserId(): int
    {
        return $this->systemUserId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function totalAmountCents(): int
    {
        return $this->totalAmountCents;
    }

    public function soldAt(): DateTimeImmutable
    {
        return $this->soldAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
    }
}
