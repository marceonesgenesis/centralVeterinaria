<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One billable line of an {@see EncounterAccount} (domain entity for the
 * `encounter_account_item` table): either an automatic line mirroring a
 * `procedure_execution` or an `exam_request` from the account's own
 * encounter (source_id = the referenced row's id), or a free-form `manual`
 * line (source_id always null).
 *
 * There is intentionally no database FK on source_id (documented in the
 * T-01 migration as a simplification — the two catalogs a `procedure_
 * execution`/`exam_request` line can trace back to cannot both be enforced
 * by a single FK — the same simplification already accepted for
 * `sale_item.item_reference_id` in Phase 4), so source_type + source_id are
 * the only link back to the originating row, and the UNIQUE key on
 * (account_id, source_type, source_id) is what
 * `CentralVet\Application\EncounterAccountService::syncAutomaticItems()`
 * relies on (via an in-memory existing-keys set built from
 * `EncounterAccountItemRepositoryInterface::listByAccount()`) to stay
 * idempotent.
 *
 * description_text and amount_cents are captured at sync/add time (copied
 * from the referenced ProcedureCatalogItem::name()/priceCents() or
 * ExamCatalogItem::name()/priceCents() for automatic lines), not looked up
 * live — same rationale as `CentralVet\Domain\SaleItem`.
 *
 * PENDING / DO NOT WIRE YET: `encounter_account_item` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. This
 * class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class EncounterAccountItem
{
    public const TYPE_PROCEDURE_EXECUTION = 'procedure_execution';
    public const TYPE_EXAM_REQUEST = 'exam_request';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_HOSPITALIZATION_STAY = 'hospitalization_stay';
    public const TYPE_HOSPITALIZATION_ADMINISTRATION = 'hospitalization_administration';
    public const TYPE_SURGERY_PROCEDURE = 'surgery_procedure';
    public const TYPE_SURGERY_MATERIAL = 'surgery_material';

    private const TYPES = [
        self::TYPE_PROCEDURE_EXECUTION,
        self::TYPE_EXAM_REQUEST,
        self::TYPE_MANUAL,
        self::TYPE_HOSPITALIZATION_STAY,
        self::TYPE_HOSPITALIZATION_ADMINISTRATION,
        self::TYPE_SURGERY_PROCEDURE,
        self::TYPE_SURGERY_MATERIAL,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $accountId,
        private readonly string $sourceType,
        private readonly ?int $sourceId,
        private readonly string $descriptionText,
        private readonly int $amountCents,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        int $accountId,
        string $sourceType,
        ?int $sourceId,
        string $descriptionText,
        int $amountCents,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($accountId <= 0) {
            throw new InvalidArgumentException('account_id must be positive');
        }

        if (!in_array($sourceType, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "source_type must be one of: " . implode(', ', self::TYPES)
            );
        }

        if ($sourceType === self::TYPE_MANUAL) {
            if ($sourceId !== null) {
                throw new InvalidArgumentException('source_id must be null for manual items');
            }
        } elseif ($sourceId === null || $sourceId <= 0) {
            throw new InvalidArgumentException('source_id must be positive for automatic items');
        }

        $descriptionText = trim($descriptionText);

        if ($descriptionText === '') {
            throw new InvalidArgumentException('description_text is required');
        }

        if ($amountCents < 0) {
            throw new InvalidArgumentException('amount_cents cannot be negative');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            accountId: $accountId,
            sourceType: $sourceType,
            sourceId: $sourceId,
            descriptionText: $descriptionText,
            amountCents: $amountCents,
        );
    }

    /**
     * Rebuilds an existing account item from persisted data. Repositories
     * use this to hydrate rows; application code should use create()
     * instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `encounter_account_item` table.
     */
    public static function reconstitute(array $row): self
    {
        $sourceType = (string) $row['source_type'];

        if (!in_array($sourceType, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown source_type \"{$sourceType}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            accountId: (int) $row['account_id'],
            sourceType: $sourceType,
            sourceId: $row['source_id'] !== null ? (int) $row['source_id'] : null,
            descriptionText: (string) $row['description_text'],
            amountCents: (int) $row['amount_cents'],
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('EncounterAccountItem already has an id');
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

    public function accountId(): int
    {
        return $this->accountId;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function sourceId(): ?int
    {
        return $this->sourceId;
    }

    public function descriptionText(): string
    {
        return $this->descriptionText;
    }

    public function amountCents(): int
    {
        return $this->amountCents;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
