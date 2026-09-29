<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single execution of a procedure catalog item for a patient inside an
 * encounter (domain entity for the `procedure_execution` table): who
 * performed it, when, and an optional free-text note. The stock consumption
 * it triggers (one StockService::consume() call per
 * ProcedureCatalogItemInput of the referenced procedure catalog item) is an
 * Application concern driven by ProcedureExecutionService::execute(), not
 * modeled here — this entity itself does not know about Product or stock,
 * mirroring how Vaccination does not know about VaccineCatalogItem's stock
 * counter.
 *
 * PENDING / DO NOT WIRE YET: `procedure_execution` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class ProcedureExecution
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $encounterId,
        private readonly int $patientId,
        private readonly int $procedureCatalogItemId,
        private readonly int $professionalSystemUserId,
        private readonly ?string $notesText,
        private readonly DateTimeImmutable $executedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function record(
        int $tenantId,
        int $encounterId,
        int $patientId,
        int $procedureCatalogItemId,
        int $professionalSystemUserId,
        ?string $notesText,
        DateTimeImmutable $executedAt,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($encounterId <= 0) {
            throw new InvalidArgumentException('encounter_id must be positive');
        }

        if ($patientId <= 0) {
            throw new InvalidArgumentException('patient_id must be positive');
        }

        if ($procedureCatalogItemId <= 0) {
            throw new InvalidArgumentException('procedure_catalog_item_id must be positive');
        }

        if ($professionalSystemUserId <= 0) {
            throw new InvalidArgumentException('professional_system_user_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            encounterId: $encounterId,
            patientId: $patientId,
            procedureCatalogItemId: $procedureCatalogItemId,
            professionalSystemUserId: $professionalSystemUserId,
            notesText: $notesText !== null && trim($notesText) !== '' ? $notesText : null,
            executedAt: $executedAt,
        );
    }

    /**
     * Rebuilds an existing procedure execution from persisted data.
     * Repositories use this to hydrate rows; application code should use
     * record() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `procedure_execution` table.
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            encounterId: (int) $row['encounter_id'],
            patientId: (int) $row['patient_id'],
            procedureCatalogItemId: (int) $row['procedure_catalog_item_id'],
            professionalSystemUserId: (int) $row['professional_system_user_id'],
            notesText: $row['notes_text'] !== null ? (string) $row['notes_text'] : null,
            executedAt: new DateTimeImmutable((string) $row['executed_at']),
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('ProcedureExecution already has an id');
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

    public function encounterId(): int
    {
        return $this->encounterId;
    }

    public function patientId(): int
    {
        return $this->patientId;
    }

    public function procedureCatalogItemId(): int
    {
        return $this->procedureCatalogItemId;
    }

    public function professionalSystemUserId(): int
    {
        return $this->professionalSystemUserId;
    }

    public function notesText(): ?string
    {
        return $this->notesText;
    }

    public function executedAt(): DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
