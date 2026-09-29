<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Front-desk/waiting-room queue entry (domain aggregate for the
 * `queue_entry` table): a patient checked in at a unit, waiting for or
 * currently being attended to by a professional — either linked to a prior
 * `appointment` or a walk-in (`appointment_id` is nullable; ADR per
 * migration 20260921_0002_phase1_clinic_core.sql).
 *
 * PENDING / DO NOT WIRE YET: `queue_entry` is created by that not-yet-applied
 * migration. This class is prepared and syntax-checked (php -l) only; no
 * query runs against it until the migration has explicit SQL execution
 * approval and has actually been applied.
 *
 * Plain PHP entity, mutable like `CentralVet\Domain\Service` (not an
 * immutable value object like `CentralVet\Domain\Patient`): it owns a real
 * state machine (`advance()`), so "mutate in place, persist the mutation"
 * reads clearer here than rebuilding a new immutable copy on every step.
 * No Adianti dependency (ADR 0001).
 *
 * ## Status state machine (T-08 acceptance criterion)
 * Only two forward transitions are legal, each one step at a time:
 * `aguardando` -> `em_atendimento` -> `atendido`. `advance()` is the only
 * way to change status and it always moves the entry from its current
 * status to the single next status defined in {@see self::TRANSITIONS}.
 * There is no "jump to status X" operation, so both ways of going "out of
 * turn" collapse into the same case — no legal next status from here:
 *   - skipping a step (`aguardando` -> `atendido` directly) is impossible
 *     because `aguardando`'s only next status is `em_atendimento`;
 *   - regressing (`atendido` -> `em_atendimento`) is impossible because
 *     `atendido` is terminal and has no entry in the transition table.
 * Either case throws {@see InvalidStatusTransitionException}.
 *
 * ## `atrasado` (late) — deliberately NOT a persisted transition target
 * The `queue_entry_status_ck` CHECK constraint in the migration allows
 * `atrasado` as a stored value (kept for forward compatibility / direct SQL
 * reporting), but this class treats it as a *derived*, read-time-only
 * status rather than a state `advance()` can produce or that
 * `QueueEntryService::advanceStatus()` ever writes: an entry is "late" only
 * while it is still `aguardando` and has been waiting longer than a
 * threshold — it is not a distinct stage the patient moves through, it is a
 * property of *how long* they have stayed in the `aguardando` stage. Modelling
 * it as a real transition would need a third "who/what" decides to move it
 * back to `aguardando` once attended, which the mock/spec never defines.
 * {@see self::displayStatus()} computes it on demand instead.
 */
final class QueueEntry
{
    public const STATUS_AGUARDANDO = 'aguardando';
    public const STATUS_EM_ATENDIMENTO = 'em_atendimento';
    public const STATUS_ATENDIDO = 'atendido';

    /**
     * Not a status `advance()` ever sets — see class docblock. Listed here
     * only so {@see self::VALID_STATUSES} matches the DB CHECK constraint
     * exactly, for rows read back from storage.
     */
    public const STATUS_ATRASADO = 'atrasado';

    private const VALID_STATUSES = [
        self::STATUS_AGUARDANDO,
        self::STATUS_EM_ATENDIMENTO,
        self::STATUS_ATENDIDO,
        self::STATUS_ATRASADO,
    ];

    /**
     * The only two legal forward transitions, one step at a time. Anything
     * not a key here (including `atendido`, which is terminal) has no legal
     * next status.
     *
     * @var array<string, string>
     */
    private const TRANSITIONS = [
        self::STATUS_AGUARDANDO => self::STATUS_EM_ATENDIMENTO,
        self::STATUS_EM_ATENDIMENTO => self::STATUS_ATENDIDO,
    ];

    /** Default "how long in `aguardando` counts as late" threshold for {@see self::displayStatus()}. */
    private const DEFAULT_OVERDUE_THRESHOLD_MINUTES = 30;

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $patientId,
        private readonly ?int $appointmentId,
        private readonly int $professionalSystemUserId,
        private string $status,
        private readonly DateTimeImmutable $checkedInAt,
        private ?DateTimeImmutable $calledAt,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $finishedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Checks a patient into the queue. Always starts at `aguardando`;
     * `appointmentId` is nullable — a walk-in check-in without a prior
     * appointment is a supported flow (per migration comment).
     */
    public static function checkIn(
        int $tenantId,
        int $systemUnitId,
        int $patientId,
        ?int $appointmentId,
        int $professionalSystemUserId,
        DateTimeImmutable $now,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        if ($patientId <= 0) {
            throw new InvalidArgumentException('patient_id must be positive');
        }

        if ($appointmentId !== null && $appointmentId <= 0) {
            throw new InvalidArgumentException('appointment_id must be a positive integer when present');
        }

        if ($professionalSystemUserId <= 0) {
            throw new InvalidArgumentException('professional_system_user_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            patientId: $patientId,
            appointmentId: $appointmentId,
            professionalSystemUserId: $professionalSystemUserId,
            status: self::STATUS_AGUARDANDO,
            checkedInAt: $now,
            calledAt: null,
            startedAt: null,
            finishedAt: null,
        );
    }

    /**
     * Rebuilds an existing queue entry from persisted data. Repositories use
     * this to hydrate rows; application code should use checkIn() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $systemUnitId,
        int $patientId,
        ?int $appointmentId,
        int $professionalSystemUserId,
        string $status,
        DateTimeImmutable $checkedInAt,
        ?DateTimeImmutable $calledAt,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $finishedAt,
        ?DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown queue_entry status \"{$status}\"");
        }

        return new self(
            $id,
            $tenantId,
            $systemUnitId,
            $patientId,
            $appointmentId,
            $professionalSystemUserId,
            $status,
            $checkedInAt,
            $calledAt,
            $startedAt,
            $finishedAt,
            $createdAt,
            $updatedAt,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('QueueEntry already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Advances the entry by exactly one step, following
     * {@see self::TRANSITIONS}. This is the ONLY way status changes; there
     * is no "set status" method, which is what makes skipping a step or
     * regressing structurally impossible rather than merely validated.
     *
     * @throws InvalidStatusTransitionException when the current status has
     *         no legal next status (either because it is terminal, i.e.
     *         `atendido`, or — defensively — an unrecognized value).
     */
    public function advance(DateTimeImmutable $now): void
    {
        $next = self::TRANSITIONS[$this->status] ?? null;

        if ($next === null) {
            throw new InvalidStatusTransitionException(
                sprintf(
                    'Queue entry %s cannot advance from status "%s": no legal next status',
                    $this->id !== null ? (string) $this->id : '(new)',
                    $this->status,
                )
            );
        }

        $this->status = $next;

        if ($next === self::STATUS_EM_ATENDIMENTO) {
            $this->calledAt ??= $now;
            $this->startedAt = $now;
        } elseif ($next === self::STATUS_ATENDIDO) {
            $this->finishedAt = $now;
        }
    }

    /**
     * Read-time derived status: `atrasado` when still `aguardando` and past
     * the threshold, the persisted status otherwise. See class docblock for
     * why `atrasado` is computed here instead of being a state `advance()`
     * can reach.
     */
    public function displayStatus(
        ?DateTimeImmutable $now = null,
        int $overdueThresholdMinutes = self::DEFAULT_OVERDUE_THRESHOLD_MINUTES,
    ): string {
        return $this->isOverdue($now, $overdueThresholdMinutes) ? self::STATUS_ATRASADO : $this->status;
    }

    public function isOverdue(
        ?DateTimeImmutable $now = null,
        int $overdueThresholdMinutes = self::DEFAULT_OVERDUE_THRESHOLD_MINUTES,
    ): bool {
        if ($this->status !== self::STATUS_AGUARDANDO) {
            return false;
        }

        $now ??= new DateTimeImmutable();
        $waitingSeconds = $now->getTimestamp() - $this->checkedInAt->getTimestamp();

        return $waitingSeconds >= ($overdueThresholdMinutes * 60);
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

    public function patientId(): int
    {
        return $this->patientId;
    }

    public function appointmentId(): ?int
    {
        return $this->appointmentId;
    }

    public function professionalSystemUserId(): int
    {
        return $this->professionalSystemUserId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function checkedInAt(): DateTimeImmutable
    {
        return $this->checkedInAt;
    }

    public function calledAt(): ?DateTimeImmutable
    {
        return $this->calledAt;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
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
