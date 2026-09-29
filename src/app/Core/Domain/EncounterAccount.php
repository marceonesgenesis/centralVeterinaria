<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\DiscountExceedsSubtotalException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The running bill for a single clinical encounter (domain aggregate for
 * the `encounter_account` table): one row per encounter (UNIQUE
 * encounter_id), holding subtotal/discount/total in cents while
 * status='open', then frozen once `close()` moves it to 'closed'. Its line
 * items are the separate {@see EncounterAccountItem} aggregate; its payable
 * counterpart once closed is the separate {@see Receivable} aggregate.
 *
 * subtotal_cents/total_cents are NOT kept in sync automatically by the
 * schema (documented as the Application service's own responsibility in the
 * T-01 migration's Risk note, same simplification already accepted for
 * `stock_movement`/`sale_item` in Phase 4): {@see self::refreshSubtotal()}
 * is how `CentralVet\Application\EncounterAccountService` recomputes
 * subtotal_cents from the live sum of `encounter_account_item` rows every
 * time an item is added, a discount is applied, or the account is closed —
 * so subtotal_cents/total_cents never drift from the CHECK constraints the
 * migration declares (`discount_cents <= subtotal_cents`,
 * `total_cents = subtotal_cents - discount_cents`).
 *
 * Plain PHP entity, mutable like `CentralVet\Domain\Encounter`: `open()`
 * for a brand-new instance, `reconstitute()` for repositories hydrating a
 * row, in-place mutation afterwards (`refreshSubtotal()`, `applyDiscount()`,
 * `close()`) rather than rebuilding an immutable copy. No Adianti
 * dependency (ADR 0001).
 *
 * PENDING / DO NOT WIRE YET: `encounter_account` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. This
 * class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class EncounterAccount
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    private const STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_CANCELLED];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $encounterId,
        private readonly int $patientId,
        private readonly int $tutorId,
        private readonly int $systemUnitId,
        private string $status,
        private int $subtotalCents,
        private int $discountCents,
        private ?int $discountAuthorizedBySystemUserId,
        private int $totalCents,
        private ?DateTimeImmutable $closedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Opens a brand-new account for an encounter. Always starts `open` with
     * subtotal/discount/total at zero — real values only exist once items
     * are added ({@see self::refreshSubtotal()}) and, later, a discount is
     * applied/the account is closed.
     */
    public static function open(
        int $tenantId,
        int $encounterId,
        int $patientId,
        int $tutorId,
        int $systemUnitId,
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

        if ($tutorId <= 0) {
            throw new InvalidArgumentException('tutor_id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            encounterId: $encounterId,
            patientId: $patientId,
            tutorId: $tutorId,
            systemUnitId: $systemUnitId,
            status: self::STATUS_OPEN,
            subtotalCents: 0,
            discountCents: 0,
            discountAuthorizedBySystemUserId: null,
            totalCents: 0,
            closedAt: null,
        );
    }

    /**
     * Rebuilds an existing account from persisted data. Repositories use
     * this to hydrate rows; application code should use open() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `encounter_account` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown encounter_account status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            encounterId: (int) $row['encounter_id'],
            patientId: (int) $row['patient_id'],
            tutorId: (int) $row['tutor_id'],
            systemUnitId: (int) $row['system_unit_id'],
            status: $status,
            subtotalCents: (int) $row['subtotal_cents'],
            discountCents: (int) $row['discount_cents'],
            discountAuthorizedBySystemUserId: $row['discount_authorized_by_system_user_id'] !== null
                ? (int) $row['discount_authorized_by_system_user_id']
                : null,
            totalCents: (int) $row['total_cents'],
            closedAt: $row['closed_at'] !== null ? new DateTimeImmutable((string) $row['closed_at']) : null,
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable((string) $row['updated_at'])
                : null,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('EncounterAccount already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Recomputes subtotal_cents from the live sum of this account's
     * `encounter_account_item` rows (computed by the caller —
     * `CentralVet\Application\EncounterAccountService` — via
     * `EncounterAccountItemRepositoryInterface::listByAccount()`), and
     * total_cents alongside it, so the two never drift from the currently
     * registered discount_cents.
     *
     * @throws DiscountExceedsSubtotalException when the new subtotal is
     *         smaller than the discount already registered on this account
     *         (defensive: this plan never removes an item once added, so
     *         subtotal only grows in practice).
     */
    public function refreshSubtotal(int $subtotalCents): void
    {
        if ($subtotalCents < 0) {
            throw new InvalidArgumentException('subtotal_cents cannot be negative');
        }

        if ($subtotalCents < $this->discountCents) {
            throw DiscountExceedsSubtotalException::forExcess($subtotalCents, $this->discountCents);
        }

        $this->subtotalCents = $subtotalCents;
        $this->totalCents = $subtotalCents - $this->discountCents;
    }

    /**
     * Registers (or replaces) the account's authorized discount. The
     * validation that matters for T-03's acceptance criterion — "discount
     * cannot exceed the account's items" — is enforced here against
     * subtotal_cents, which the caller must have already brought up to date
     * via {@see self::refreshSubtotal()} in the same use case, so it always
     * equals the live sum of items at the moment this runs.
     *
     * @throws InvalidStatusTransitionException when the account is not
     *         currently 'open'.
     * @throws DiscountExceedsSubtotalException when $discountCents exceeds
     *         subtotal_cents. Neither discount_cents nor total_cents is
     *         mutated when this is thrown.
     */
    public function applyDiscount(int $discountCents, int $authorizedBySystemUserId): void
    {
        if ($this->status !== self::STATUS_OPEN) {
            throw new InvalidStatusTransitionException(
                sprintf(
                    'Encounter account %s is not open and cannot receive a discount',
                    $this->id !== null ? (string) $this->id : '(new)',
                )
            );
        }

        if ($discountCents < 0) {
            throw new InvalidArgumentException('discount_cents cannot be negative');
        }

        if ($authorizedBySystemUserId <= 0) {
            throw new InvalidArgumentException('discount_authorized_by_system_user_id must be positive');
        }

        if ($discountCents > $this->subtotalCents) {
            throw DiscountExceedsSubtotalException::forExcess($this->subtotalCents, $discountCents);
        }

        $this->discountCents = $discountCents;
        $this->discountAuthorizedBySystemUserId = $authorizedBySystemUserId;
        $this->totalCents = $this->subtotalCents - $discountCents;
    }

    /**
     * Closes the account: status moves to 'closed' and closed_at is
     * stamped. subtotal_cents/discount_cents/total_cents are left exactly
     * as they stand (the caller is expected to have called
     * {@see self::refreshSubtotal()} immediately before this, per T-03's
     * "close() recalcula subtotal a partir dos itens" acceptance
     * criterion) — this method itself only performs the status transition.
     *
     * @throws InvalidStatusTransitionException when the account is not
     *         currently 'open'.
     */
    public function close(DateTimeImmutable $now): void
    {
        if ($this->status !== self::STATUS_OPEN) {
            throw new InvalidStatusTransitionException(
                sprintf(
                    'Encounter account %s is not open and cannot be closed',
                    $this->id !== null ? (string) $this->id : '(new)',
                )
            );
        }

        $this->status = self::STATUS_CLOSED;
        $this->closedAt = $now;
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

    public function tutorId(): int
    {
        return $this->tutorId;
    }

    public function systemUnitId(): int
    {
        return $this->systemUnitId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function subtotalCents(): int
    {
        return $this->subtotalCents;
    }

    public function discountCents(): int
    {
        return $this->discountCents;
    }

    public function discountAuthorizedBySystemUserId(): ?int
    {
        return $this->discountAuthorizedBySystemUserId;
    }

    public function totalCents(): int
    {
        return $this->totalCents;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
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
