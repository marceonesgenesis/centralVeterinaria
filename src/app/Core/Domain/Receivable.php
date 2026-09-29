<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\OverpaymentException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The amount owed by a tutor for a closed {@see EncounterAccount} (domain
 * aggregate for the `receivable` table): tracks paid_cents against
 * total_cents, with status moving from 'open' through 'partially_paid' to
 * 'paid' as payments are recorded against it.
 *
 * total_cents is fixed at creation time — copied from
 * `EncounterAccount::totalCents()` by
 * `CentralVet\Application\EncounterAccountService::close()` — and never
 * recomputed afterwards (T-03's acceptance criterion: "Receivable::
 * totalCents igual a EncounterAccount::totalCents, nunca recalculado de
 * forma diferente"). Recording payments against paid_cents/status is a
 * separate use case (T-06, `CentralVet\Application\PaymentService`, out of
 * this class's scope today) — this T-03 slice only ever creates a
 * receivable at 'open'/paid_cents=0 via {@see self::open()}.
 *
 * Plain PHP entity, mutable like `CentralVet\Domain\ExamRequest`: `open()`
 * for a brand-new instance, `reconstitute()` for repositories hydrating a
 * row. No Adianti dependency (ADR 0001).
 *
 * PENDING / DO NOT WIRE YET: `receivable` is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. This
 * class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class Receivable
{
    public const STATUS_OPEN = 'open';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    private const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $encounterAccountId,
        private readonly int $tutorId,
        private readonly int $totalCents,
        private int $paidCents,
        private string $status,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Opens a brand-new receivable for a just-closed encounter account.
     * Always starts 'open' with paid_cents at zero — this class exposes no
     * "record a payment" mutation in this T-03 slice; that is T-06's
     * responsibility.
     */
    public static function open(
        int $tenantId,
        int $encounterAccountId,
        int $tutorId,
        int $totalCents,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($encounterAccountId <= 0) {
            throw new InvalidArgumentException('encounter_account_id must be positive');
        }

        if ($tutorId <= 0) {
            throw new InvalidArgumentException('tutor_id must be positive');
        }

        if ($totalCents < 0) {
            throw new InvalidArgumentException('total_cents cannot be negative');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            encounterAccountId: $encounterAccountId,
            tutorId: $tutorId,
            totalCents: $totalCents,
            paidCents: 0,
            status: self::STATUS_OPEN,
        );
    }

    /**
     * Rebuilds an existing receivable from persisted data. Repositories use
     * this to hydrate rows; application code should use open() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `receivable` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown receivable status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            encounterAccountId: (int) $row['encounter_account_id'],
            tutorId: (int) $row['tutor_id'],
            totalCents: (int) $row['total_cents'],
            paidCents: (int) $row['paid_cents'],
            status: $status,
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable((string) $row['updated_at'])
                : null,
        );
    }

    /**
     * Records a payment against this receivable (T-06's own use case,
     * anticipated but deliberately left unimplemented by T-03 — see class
     * docblock). Only legal mutation of paid_cents/status: increments
     * paid_cents by $amountCents and moves status to 'paid' when the new
     * paid_cents equals total_cents exactly, or 'partially_paid' when it is
     * still less — status is NEVER set to 'paid' with a residual balance.
     *
     * @throws OverpaymentException when paid_cents + $amountCents would
     *         exceed total_cents. Thrown before any field is mutated, so
     *         paid_cents/status are left exactly as they were —
     *         `CentralVet\Application\PaymentService::register()` relies on
     *         this to guarantee nothing is persisted on overpayment.
     */
    public function recordPayment(int $amountCents): void
    {
        if ($amountCents < 1) {
            throw new InvalidArgumentException('amount_cents must be >= 1');
        }

        $newPaidCents = $this->paidCents + $amountCents;

        if ($newPaidCents > $this->totalCents) {
            throw OverpaymentException::forExcess($this->totalCents, $this->paidCents, $amountCents);
        }

        $this->paidCents = $newPaidCents;
        $this->status = $newPaidCents === $this->totalCents ? self::STATUS_PAID : self::STATUS_PARTIALLY_PAID;
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Receivable already has an id');
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

    public function encounterAccountId(): int
    {
        return $this->encounterAccountId;
    }

    public function tutorId(): int
    {
        return $this->tutorId;
    }

    public function totalCents(): int
    {
        return $this->totalCents;
    }

    public function paidCents(): int
    {
        return $this->paidCents;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
