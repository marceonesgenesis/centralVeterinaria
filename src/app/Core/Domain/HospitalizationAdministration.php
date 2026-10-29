<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One scheduled administration of a {@see HospitalizationOrder} — domain
 * aggregate for the `hospitalization_administration` table (migration 0010,
 * Fase 6A).
 *
 * ## Status state machine
 * `pending` -> `done` | `skipped` | `cancelled`; all three are terminal.
 * `done`/`skipped` record who and when (`hospitalization_administration_performed_ck`);
 * skipping requires a note.
 *
 * "Late" is never stored: {@see self::classify()} derives the timeliness from
 * the scheduled time, the performed time and "now", with a tolerance of
 * {@see self::LATE_TOLERANCE_MINUTES} minutes. No Adianti dependency (ADR 0001).
 */
final class HospitalizationAdministration
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_CANCELLED = 'cancelled';

    private const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DONE,
        self::STATUS_SKIPPED,
        self::STATUS_CANCELLED,
    ];

    public const LATE_TOLERANCE_MINUTES = 30;

    public const TIMELINESS_UPCOMING = 'upcoming';
    public const TIMELINESS_DUE = 'due';
    public const TIMELINESS_LATE = 'late';
    public const TIMELINESS_DONE = 'done';
    public const TIMELINESS_DONE_LATE = 'done_late';
    public const TIMELINESS_SKIPPED = 'skipped';
    public const TIMELINESS_CANCELLED = 'cancelled';

    private const NOTES_MAX_LENGTH = 500;

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $hospitalizationId,
        private readonly int $orderId,
        private readonly DateTimeImmutable $scheduledAt,
        private string $status,
        private ?DateTimeImmutable $performedAt,
        private ?int $performedBySystemUserId,
        private ?string $notesText,
    ) {
    }

    /** Creates a new `pending` administration. */
    public static function schedule(int $tenantId, int $hospitalizationId, int $orderId, DateTimeImmutable $scheduledAt): self
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($hospitalizationId <= 0) {
            throw new InvalidArgumentException('hospitalization_id must be positive');
        }

        if ($orderId <= 0) {
            throw new InvalidArgumentException('order_id must be positive');
        }

        return new self(null, $tenantId, $hospitalizationId, $orderId, $scheduledAt, self::STATUS_PENDING, null, null, null);
    }

    /**
     * Rebuilds a persisted administration. Repositories use this to hydrate
     * rows; application code should use schedule() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $hospitalizationId,
        int $orderId,
        DateTimeImmutable $scheduledAt,
        string $status,
        ?DateTimeImmutable $performedAt,
        ?int $performedBySystemUserId,
        ?string $notesText,
    ): self {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown hospitalization_administration status \"{$status}\"");
        }

        return new self($id, $tenantId, $hospitalizationId, $orderId, $scheduledAt, $status, $performedAt, $performedBySystemUserId, $notesText);
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('HospitalizationAdministration already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Derives how an administration stands against its schedule.
     *
     * - pending: `upcoming` before the time, `due` up to time + tolerance
     *   (inclusive), `late` after that;
     * - done: `done` when performed up to time + tolerance, `done_late` after;
     * - skipped / cancelled: returned as is.
     */
    public static function classify(
        string $status,
        DateTimeImmutable $scheduledAt,
        ?DateTimeImmutable $performedAt,
        DateTimeImmutable $now,
    ): string {
        $deadline = $scheduledAt->modify('+' . self::LATE_TOLERANCE_MINUTES . ' minutes');

        return match ($status) {
            self::STATUS_PENDING => match (true) {
                $now < $scheduledAt => self::TIMELINESS_UPCOMING,
                $now <= $deadline => self::TIMELINESS_DUE,
                default => self::TIMELINESS_LATE,
            },
            self::STATUS_DONE => $performedAt !== null && $performedAt > $deadline
                ? self::TIMELINESS_DONE_LATE
                : self::TIMELINESS_DONE,
            self::STATUS_SKIPPED => self::TIMELINESS_SKIPPED,
            self::STATUS_CANCELLED => self::TIMELINESS_CANCELLED,
            default => throw new InvalidArgumentException("Unknown hospitalization_administration status \"{$status}\""),
        };
    }

    /**
     * Records the administration as performed. The note is optional.
     *
     * @throws InvalidStatusTransitionException when not pending.
     */
    public function markDone(DateTimeImmutable $at, int $performedBySystemUserId, string $notesText): void
    {
        $this->perform(self::STATUS_DONE, $at, $performedBySystemUserId, $notesText, false);
    }

    /**
     * Records the administration as not performed; the reason is mandatory.
     *
     * @throws InvalidStatusTransitionException when not pending.
     * @throws InvalidArgumentException when the note is empty.
     */
    public function markSkipped(DateTimeImmutable $at, int $performedBySystemUserId, string $notesText): void
    {
        $this->perform(self::STATUS_SKIPPED, $at, $performedBySystemUserId, $notesText, true);
    }

    /**
     * Cancels a pending administration (order suspended or hospitalization
     * discharged).
     *
     * @throws InvalidStatusTransitionException when not pending.
     */
    public function cancel(): void
    {
        $this->assertPending();
        $this->status = self::STATUS_CANCELLED;
    }

    private function perform(string $status, DateTimeImmutable $at, int $performedBySystemUserId, string $notesText, bool $notesRequired): void
    {
        $this->assertPending();

        if ($performedBySystemUserId <= 0) {
            throw new InvalidArgumentException('performed_by_system_user_id must be positive');
        }

        $notesText = trim($notesText);
        if ($notesRequired && $notesText === '') {
            throw new InvalidArgumentException('notes_text is required');
        }

        if (mb_strlen($notesText) > self::NOTES_MAX_LENGTH) {
            throw new InvalidArgumentException('notes_text must have at most 500 characters');
        }

        $this->status = $status;
        $this->performedAt = $at;
        $this->performedBySystemUserId = $performedBySystemUserId;
        $this->notesText = $notesText === '' ? null : $notesText;
    }

    private function assertPending(): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new InvalidStatusTransitionException(
                sprintf('Administration %s is not pending', $this->id !== null ? (string) $this->id : '(new)')
            );
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

    public function hospitalizationId(): int
    {
        return $this->hospitalizationId;
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function scheduledAt(): DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function performedAt(): ?DateTimeImmutable
    {
        return $this->performedAt;
    }

    public function performedBySystemUserId(): ?int
    {
        return $this->performedBySystemUserId;
    }

    public function notesText(): ?string
    {
        return $this->notesText;
    }
}
