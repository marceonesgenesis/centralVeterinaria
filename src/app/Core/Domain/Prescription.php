<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Prescription aggregate root (domain entity for the `prescription` table),
 * with its medication lines ({@see PrescriptionItem}) as the aggregate's
 * child collection (`prescription_item`, free text only — no medication
 * catalog in this phase, per the migration's own docblock).
 *
 * PENDING / DO NOT WIRE YET: `prescription`/`prescription_item` are created
 * by the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * Status mirrors the `prescription_status_ck` CHECK constraint in the
 * migration: `draft` on creation, `issued` once the professional finalizes
 * it (issue() is out of scope for T-03's create()/findById() pair and is
 * left for a later task to add once the printable/PDF flow — T-06 — needs
 * it).
 *
 * Plain PHP entity, mutable like `CentralVet\Domain\Encounter` (not an
 * immutable value object like `CentralVet\Domain\Patient`). No Adianti
 * dependency (ADR 0001).
 */
final class Prescription
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';

    private const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ISSUED,
    ];

    /** @param list<PrescriptionItem> $items */
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $encounterId,
        private readonly int $patientId,
        private readonly int $professionalSystemUserId,
        private readonly ?string $orientationText,
        private string $status,
        private array $items,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
        private readonly ?DateTimeImmutable $validUntil = null,
    ) {
    }

    /**
     * Creates a new prescription with its medication lines. Always starts
     * `draft`, mirroring `Encounter::start()` always beginning `in_progress`.
     *
     * @param list<PrescriptionItem> $items must contain at least one item;
     *        every item must already belong to the same tenant as
     *        $tenantId (PrescriptionService is responsible for building
     *        them with PrescriptionItem::create() using that same tenant
     *        id before calling this).
     * @param DateTimeImmutable|null $validUntil optional validity date
     *        (`prescription.valid_until`, date only; rodada 2, T-13). Whether
     *        it may be in the past is PrescriptionService's rule, not this
     *        entity's: reconstitute() must still load old prescriptions.
     */
    public static function create(
        int $tenantId,
        int $encounterId,
        int $patientId,
        int $professionalSystemUserId,
        ?string $orientationText,
        array $items,
        ?DateTimeImmutable $validUntil = null,
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

        if ($professionalSystemUserId <= 0) {
            throw new InvalidArgumentException('professional_system_user_id must be positive');
        }

        if ($items === []) {
            throw new InvalidArgumentException('A prescription must have at least one item');
        }

        foreach ($items as $item) {
            if (!$item instanceof PrescriptionItem) {
                throw new InvalidArgumentException('Every item must be a PrescriptionItem instance');
            }

            if ($item->tenantId() !== $tenantId) {
                throw new InvalidArgumentException('Every item must belong to the same tenant as the prescription');
            }
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            encounterId: $encounterId,
            patientId: $patientId,
            professionalSystemUserId: $professionalSystemUserId,
            orientationText: $orientationText !== null && trim($orientationText) !== '' ? $orientationText : null,
            status: self::STATUS_DRAFT,
            items: array_values($items),
            validUntil: $validUntil?->setTime(0, 0),
        );
    }

    /**
     * Rebuilds an existing prescription (header only) from persisted data.
     * Repositories use this to hydrate rows; application code should use
     * create() instead. Items are attached separately via
     * {@see self::withItems()} once PrescriptionRepository has loaded them
     * from `prescription_item`, mirroring how EncounterRepository hydrates
     * its own single-table aggregate directly from the row but keeps the
     * child-collection assembly (here: a second query) in the Repository,
     * not the entity.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `prescription` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown prescription status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            encounterId: (int) $row['encounter_id'],
            patientId: (int) $row['patient_id'],
            professionalSystemUserId: (int) $row['professional_system_user_id'],
            orientationText: $row['orientation_text'] !== null ? (string) $row['orientation_text'] : null,
            status: $status,
            items: [],
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable((string) $row['updated_at'])
                : null,
            validUntil: isset($row['valid_until']) && $row['valid_until'] !== null
                ? new DateTimeImmutable((string) $row['valid_until'])
                : null,
        );
    }

    /**
     * Returns a copy of this prescription with its item collection replaced,
     * used by PrescriptionRepository after loading `prescription_item` rows
     * separately from the `prescription` header row (see reconstitute()'s
     * docblock).
     *
     * @param list<PrescriptionItem> $items
     */
    public function withItems(array $items): self
    {
        $copy = clone $this;
        $copy->items = array_values($items);

        return $copy;
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Prescription already has an id');
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

    public function professionalSystemUserId(): int
    {
        return $this->professionalSystemUserId;
    }

    public function orientationText(): ?string
    {
        return $this->orientationText;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<PrescriptionItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Validity date (`prescription.valid_until`), or null when none was set. */
    public function validUntil(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }
}
