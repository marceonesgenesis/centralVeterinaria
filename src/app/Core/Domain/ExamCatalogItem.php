<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Tenant-scoped catalog of billable exams (domain aggregate for the
 * `exam_catalog_item` table): a named exam, with an optional partner
 * (outsourced lab) and a price, that can be requested against a patient
 * inside an encounter ({@see ExamRequest}).
 *
 * PENDING / DO NOT WIRE YET: `exam_catalog_item` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * Plain PHP entity, mutable like `CentralVet\Domain\QueueEntry` (not an
 * immutable value object): `active` can be toggled without rebuilding the
 * whole aggregate. No Adianti dependency (ADR 0001).
 */
final class ExamCatalogItem
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly string $name,
        private readonly ?string $partnerName,
        private readonly int $priceCents,
        private bool $active,
        private readonly ?\DateTimeImmutable $createdAt = null,
        private readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        string $name,
        ?string $partnerName,
        int $priceCents,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('name must not be empty');
        }

        if ($priceCents < 0) {
            throw new InvalidArgumentException('price_cents must not be negative');
        }

        $partnerName = $partnerName !== null ? trim($partnerName) : null;
        $partnerName = $partnerName === '' ? null : $partnerName;

        return new self(
            id: null,
            tenantId: $tenantId,
            name: $name,
            partnerName: $partnerName,
            priceCents: $priceCents,
            active: true,
        );
    }

    /**
     * Rebuilds an existing catalog item from persisted data. Repositories
     * use this to hydrate rows; application code should use create() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        string $name,
        ?string $partnerName,
        int $priceCents,
        bool $active,
        ?\DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $tenantId,
            $name,
            $partnerName,
            $priceCents,
            $active,
            $createdAt,
            $updatedAt,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('ExamCatalogItem already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function partnerName(): ?string
    {
        return $this->partnerName;
    }

    public function priceCents(): int
    {
        return $this->priceCents;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function createdAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
