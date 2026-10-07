<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One surgery of a patient, always scheduled from an encounter (domain
 * entity for the `surgery` table, migration 20261005_0011_phase6b_surgery).
 *
 * Status machine: `scheduled` -> `pre_op` -> `in_progress` -> `completed`;
 * `scheduled`/`pre_op` -> `cancelled`. `completed` and `cancelled` are
 * terminal. Consent may be (re)recorded only while `scheduled`/`pre_op` and
 * is required to start. The procedure name and price are copies taken at
 * scheduling (billing uses the copy).
 *
 * `loadedStatus()` is the status read by `reconstitute()` (null for a new
 * surgery) and never follows the transitions: the repository only updates
 * the row while the database status still equals it.
 */
final class Surgery
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PRE_OP = 'pre_op';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    private const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_PRE_OP,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    private const MAX_DURATION_SECONDS = 86400;

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $patientId,
        private readonly int $encounterId,
        private readonly int $roomId,
        private readonly int $procedureCatalogItemId,
        private readonly string $procedureName,
        private readonly int $procedurePriceCents,
        private readonly int $surgeonSystemUserId,
        private readonly int $scheduledBySystemUserId,
        private readonly DateTimeImmutable $scheduledStartAt,
        private readonly DateTimeImmutable $scheduledEndAt,
        private string $status,
        private readonly ?string $loadedStatus,
        private readonly ?string $notesText,
        private ?string $consentSignerName,
        private ?string $consentText,
        private ?DateTimeImmutable $consentRecordedAt,
        private ?int $consentRecordedBySystemUserId,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $completedAt,
        private ?int $completedBySystemUserId,
        private ?DateTimeImmutable $cancelledAt,
        private ?int $cancelledBySystemUserId,
        private ?string $cancellationReasonText,
        private ?int $followupAppointmentId,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function schedule(
        int $tenantId,
        int $systemUnitId,
        int $patientId,
        int $encounterId,
        int $roomId,
        int $procedureCatalogItemId,
        string $procedureName,
        int $procedurePriceCents,
        int $surgeonSystemUserId,
        int $scheduledBySystemUserId,
        DateTimeImmutable $scheduledStartAt,
        DateTimeImmutable $scheduledEndAt,
        ?string $notesText,
    ): self {
        foreach ([
            'Tenant id' => $tenantId,
            'system_unit_id' => $systemUnitId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'room_id' => $roomId,
            'procedure_catalog_item_id' => $procedureCatalogItemId,
            'surgeon_system_user_id' => $surgeonSystemUserId,
            'scheduled_by_system_user_id' => $scheduledBySystemUserId,
        ] as $field => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("{$field} must be positive");
            }
        }

        $procedureName = trim($procedureName);

        if ($procedureName === '') {
            throw new InvalidArgumentException('procedure_name is required');
        }

        if (mb_strlen($procedureName) > 190) {
            throw new InvalidArgumentException('procedure_name must have at most 190 characters');
        }

        if ($procedurePriceCents < 0) {
            throw new InvalidArgumentException('procedure_price_cents must be zero or positive');
        }

        if ($scheduledEndAt <= $scheduledStartAt) {
            throw new InvalidArgumentException('scheduled_end_at must be after scheduled_start_at');
        }

        if ($scheduledEndAt > $scheduledStartAt->modify('+' . self::MAX_DURATION_SECONDS . ' seconds')) {
            throw new InvalidArgumentException('Surgery duration cannot exceed 24 hours');
        }

        $notesText = $notesText !== null ? trim($notesText) : null;

        if ($notesText === '') {
            $notesText = null;
        }

        if ($notesText !== null && mb_strlen($notesText) > 500) {
            throw new InvalidArgumentException('notes_text must have at most 500 characters');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            patientId: $patientId,
            encounterId: $encounterId,
            roomId: $roomId,
            procedureCatalogItemId: $procedureCatalogItemId,
            procedureName: $procedureName,
            procedurePriceCents: $procedurePriceCents,
            surgeonSystemUserId: $surgeonSystemUserId,
            scheduledBySystemUserId: $scheduledBySystemUserId,
            scheduledStartAt: $scheduledStartAt,
            scheduledEndAt: $scheduledEndAt,
            status: self::STATUS_SCHEDULED,
            loadedStatus: null,
            notesText: $notesText,
            consentSignerName: null,
            consentText: null,
            consentRecordedAt: null,
            consentRecordedBySystemUserId: null,
            startedAt: null,
            completedAt: null,
            completedBySystemUserId: null,
            cancelledAt: null,
            cancelledBySystemUserId: null,
            cancellationReasonText: null,
            followupAppointmentId: null,
        );
    }

    /**
     * Rebuilds an existing surgery from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `surgery` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown surgery status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            patientId: (int) $row['patient_id'],
            encounterId: (int) $row['encounter_id'],
            roomId: (int) $row['room_id'],
            procedureCatalogItemId: (int) $row['procedure_catalog_item_id'],
            procedureName: (string) $row['procedure_name'],
            procedurePriceCents: (int) $row['procedure_price_cents'],
            surgeonSystemUserId: (int) $row['surgeon_system_user_id'],
            scheduledBySystemUserId: (int) $row['scheduled_by_system_user_id'],
            scheduledStartAt: new DateTimeImmutable((string) $row['scheduled_start_at']),
            scheduledEndAt: new DateTimeImmutable((string) $row['scheduled_end_at']),
            status: $status,
            loadedStatus: $status,
            notesText: self::nullableString($row, 'notes_text'),
            consentSignerName: self::nullableString($row, 'consent_signer_name'),
            consentText: self::nullableString($row, 'consent_text'),
            consentRecordedAt: self::nullableDate($row, 'consent_recorded_at'),
            consentRecordedBySystemUserId: self::nullableInt($row, 'consent_recorded_by_system_user_id'),
            startedAt: self::nullableDate($row, 'started_at'),
            completedAt: self::nullableDate($row, 'completed_at'),
            completedBySystemUserId: self::nullableInt($row, 'completed_by_system_user_id'),
            cancelledAt: self::nullableDate($row, 'cancelled_at'),
            cancelledBySystemUserId: self::nullableInt($row, 'cancelled_by_system_user_id'),
            cancellationReasonText: self::nullableString($row, 'cancellation_reason_text'),
            followupAppointmentId: self::nullableInt($row, 'followup_appointment_id'),
            createdAt: self::nullableDate($row, 'created_at'),
            updatedAt: self::nullableDate($row, 'updated_at'),
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Surgery already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /** Records (or replaces) the tutor's consent; only before the surgery starts. */
    public function recordConsent(
        string $signerName,
        string $consentText,
        int $recordedBySystemUserId,
        DateTimeImmutable $at,
    ): void {
        if (!$this->isOpenForPreOp()) {
            throw new InvalidStatusTransitionException("Surgery {$this->id} is not open for pre-operative changes");
        }

        $signerName = trim($signerName);

        if ($signerName === '') {
            throw new InvalidArgumentException('consent_signer_name is required');
        }

        if (mb_strlen($signerName) > 190) {
            throw new InvalidArgumentException('consent_signer_name must have at most 190 characters');
        }

        $consentText = trim($consentText);

        if ($consentText === '') {
            throw new InvalidArgumentException('consent_text is required');
        }

        if ($recordedBySystemUserId <= 0) {
            throw new InvalidArgumentException('consent_recorded_by_system_user_id must be positive');
        }

        $this->consentSignerName = $signerName;
        $this->consentText = $consentText;
        $this->consentRecordedAt = $at;
        $this->consentRecordedBySystemUserId = $recordedBySystemUserId;
    }

    public function startPreOp(): void
    {
        if ($this->status !== self::STATUS_SCHEDULED) {
            throw new InvalidStatusTransitionException("Surgery {$this->id} is not scheduled");
        }

        $this->status = self::STATUS_PRE_OP;
    }

    public function start(DateTimeImmutable $at): void
    {
        if ($this->status !== self::STATUS_PRE_OP) {
            throw new InvalidStatusTransitionException("Surgery {$this->id} is not in pre-op");
        }

        if (!$this->hasConsent()) {
            throw new InvalidStatusTransitionException("Surgery {$this->id} has no recorded consent");
        }

        $this->status = self::STATUS_IN_PROGRESS;
        $this->startedAt = $at;
    }

    public function complete(DateTimeImmutable $at, int $completedBySystemUserId): void
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            throw new InvalidStatusTransitionException("Surgery {$this->id} is not in progress");
        }

        if ($completedBySystemUserId <= 0) {
            throw new InvalidArgumentException('completed_by_system_user_id must be positive');
        }

        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = $at;
        $this->completedBySystemUserId = $completedBySystemUserId;
    }

    public function cancel(DateTimeImmutable $at, int $cancelledBySystemUserId, string $reasonText): void
    {
        if (!$this->isOpenForPreOp()) {
            throw new InvalidStatusTransitionException("Surgery {$this->id} cannot be cancelled in its current status");
        }

        $reasonText = trim($reasonText);

        if ($reasonText === '') {
            throw new InvalidArgumentException('cancellation_reason_text is required');
        }

        if (mb_strlen($reasonText) > 500) {
            throw new InvalidArgumentException('cancellation_reason_text must have at most 500 characters');
        }

        if ($cancelledBySystemUserId <= 0) {
            throw new InvalidArgumentException('cancelled_by_system_user_id must be positive');
        }

        $this->status = self::STATUS_CANCELLED;
        $this->cancelledAt = $at;
        $this->cancelledBySystemUserId = $cancelledBySystemUserId;
        $this->cancellationReasonText = $reasonText;
    }

    public function linkFollowUp(int $appointmentId): void
    {
        if ($this->status !== self::STATUS_COMPLETED) {
            throw new InvalidStatusTransitionException("Surgery {$this->id} is not completed");
        }

        if ($this->followupAppointmentId !== null) {
            throw new InvalidStatusTransitionException("Surgery {$this->id} already has a follow-up appointment");
        }

        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('followup_appointment_id must be positive');
        }

        $this->followupAppointmentId = $appointmentId;
    }

    /** Status read from the database by reconstitute(); null for a new surgery. */
    public function loadedStatus(): ?string
    {
        return $this->loadedStatus;
    }

    /** True while the surgery accepts pre-operative changes (`scheduled`/`pre_op`). */
    public function isOpenForPreOp(): bool
    {
        return $this->status === self::STATUS_SCHEDULED || $this->status === self::STATUS_PRE_OP;
    }

    public function hasConsent(): bool
    {
        return $this->consentRecordedAt !== null;
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

    public function encounterId(): int
    {
        return $this->encounterId;
    }

    public function roomId(): int
    {
        return $this->roomId;
    }

    public function procedureCatalogItemId(): int
    {
        return $this->procedureCatalogItemId;
    }

    public function procedureName(): string
    {
        return $this->procedureName;
    }

    public function procedurePriceCents(): int
    {
        return $this->procedurePriceCents;
    }

    public function surgeonSystemUserId(): int
    {
        return $this->surgeonSystemUserId;
    }

    public function scheduledBySystemUserId(): int
    {
        return $this->scheduledBySystemUserId;
    }

    public function scheduledStartAt(): DateTimeImmutable
    {
        return $this->scheduledStartAt;
    }

    public function scheduledEndAt(): DateTimeImmutable
    {
        return $this->scheduledEndAt;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function notesText(): ?string
    {
        return $this->notesText;
    }

    public function consentSignerName(): ?string
    {
        return $this->consentSignerName;
    }

    public function consentText(): ?string
    {
        return $this->consentText;
    }

    public function consentRecordedAt(): ?DateTimeImmutable
    {
        return $this->consentRecordedAt;
    }

    public function consentRecordedBySystemUserId(): ?int
    {
        return $this->consentRecordedBySystemUserId;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function completedBySystemUserId(): ?int
    {
        return $this->completedBySystemUserId;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancelledBySystemUserId(): ?int
    {
        return $this->cancelledBySystemUserId;
    }

    public function cancellationReasonText(): ?string
    {
        return $this->cancellationReasonText;
    }

    public function followupAppointmentId(): ?int
    {
        return $this->followupAppointmentId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        return isset($row[$key]) ? (string) $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function nullableInt(array $row, string $key): ?int
    {
        return isset($row[$key]) ? (int) $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function nullableDate(array $row, string $key): ?DateTimeImmutable
    {
        return isset($row[$key]) ? new DateTimeImmutable((string) $row[$key]) : null;
    }
}
