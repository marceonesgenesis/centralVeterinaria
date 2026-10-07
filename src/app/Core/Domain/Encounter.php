<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Single-aggregate clinical encounter (domain entity for the `encounter`
 * table): anamnesis, vital signs, physical exam, diagnosis and clinical
 * plan all live as direct columns on one row (per the migration's own
 * rationale), which is what lets `autosave()` persist the whole draft with
 * a single UPDATE instead of touching several child tables.
 *
 * PENDING / DO NOT WIRE YET: `encounter` is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260922_0003_phase2_encounter.sql. This class
 * is prepared and syntax-checked (php -l) only; no query runs against it
 * until that migration has explicit SQL execution approval and has actually
 * been applied.
 *
 * Plain PHP entity, mutable like `CentralVet\Domain\QueueEntry` (not an
 * immutable value object like `CentralVet\Domain\Patient`): `autosave()`,
 * `finish()` and `acceptAiSummary()` all mutate a single in-flight
 * encounter in place rather than rebuilding a new immutable copy on every
 * partial update. No Adianti dependency (ADR 0001).
 *
 * Status is intentionally a two-value state machine mirroring the
 * `encounter_status_ck` CHECK constraint in the migration:
 * `in_progress` -> `finished`, one-way, via {@see self::finish()} only —
 * there is no "reopen" operation in this plan.
 *
 * Pause (rodada 2, T-16) is NOT a third status: an `in_progress` encounter
 * with a non-null `paused_at` is paused. {@see self::resume()} (and
 * {@see self::finish()} of a paused encounter) adds the paused stretch, in
 * whole seconds, to `paused_seconds` and clears `paused_at`.
 */
final class Encounter
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FINISHED = 'finished';

    private const VALID_STATUSES = [
        self::STATUS_IN_PROGRESS,
        self::STATUS_FINISHED,
    ];

    /**
     * Draft field names accepted by {@see self::applyDraft()}, matching
     * `EncounterService::autosave()`'s $draft keys 1:1 and, in turn, the
     * migration's clinical columns (anamnesis/vitals/exam/diagnosis/plan).
     */
    private const DRAFT_FIELDS = [
        'anamnesis_text',
        'temperature_c',
        'heart_rate_bpm',
        'respiratory_rate_mpm',
        'weight_kg',
        'mucous_membranes',
        'capillary_refill_seconds',
        'physical_exam_text',
        'diagnosis_text',
        'clinical_plan_text',
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $patientId,
        private readonly ?int $appointmentId,
        private readonly int $professionalSystemUserId,
        private string $status,
        private readonly DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $finishedAt,
        private ?string $anamnesisText,
        private float|int|string|null $temperatureC,
        private int|string|null $heartRateBpm,
        private int|string|null $respiratoryRateMpm,
        private float|int|string|null $weightKg,
        private ?string $mucousMembranes,
        private float|int|string|null $capillaryRefillSeconds,
        private ?string $physicalExamText,
        private ?string $diagnosisText,
        private ?string $clinicalPlanText,
        private ?string $aiSummaryText,
        private ?DateTimeImmutable $aiSummaryAcceptedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
        private ?DateTimeImmutable $pausedAt = null,
        private int $pausedSeconds = 0,
    ) {
    }

    /**
     * Starts a new encounter. Always begins `in_progress` with no clinical
     * data filled in yet — anamnesis/vitals/exam/diagnosis/plan are all
     * gathered afterwards through {@see self::applyDraft()} (autosave).
     */
    public static function start(
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
            status: self::STATUS_IN_PROGRESS,
            startedAt: $now,
            finishedAt: null,
            anamnesisText: null,
            temperatureC: null,
            heartRateBpm: null,
            respiratoryRateMpm: null,
            weightKg: null,
            mucousMembranes: null,
            capillaryRefillSeconds: null,
            physicalExamText: null,
            diagnosisText: null,
            clinicalPlanText: null,
            aiSummaryText: null,
            aiSummaryAcceptedAt: null,
        );
    }

    /**
     * Rebuilds an existing encounter from persisted data. Repositories use
     * this to hydrate rows; application code should use start() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `encounter` table (as EncounterRepository::hydrate() reads
     *        them off a PDO fetch).
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown encounter status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            patientId: (int) $row['patient_id'],
            appointmentId: $row['appointment_id'] !== null ? (int) $row['appointment_id'] : null,
            professionalSystemUserId: (int) $row['professional_system_user_id'],
            status: $status,
            startedAt: new DateTimeImmutable((string) $row['started_at']),
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable((string) $row['finished_at']) : null,
            anamnesisText: $row['anamnesis_text'] !== null ? (string) $row['anamnesis_text'] : null,
            temperatureC: $row['temperature_c'],
            heartRateBpm: $row['heart_rate_bpm'] !== null ? (int) $row['heart_rate_bpm'] : null,
            respiratoryRateMpm: $row['respiratory_rate_mpm'] !== null ? (int) $row['respiratory_rate_mpm'] : null,
            weightKg: $row['weight_kg'],
            mucousMembranes: $row['mucous_membranes'] !== null ? (string) $row['mucous_membranes'] : null,
            capillaryRefillSeconds: $row['capillary_refill_seconds'],
            physicalExamText: $row['physical_exam_text'] !== null ? (string) $row['physical_exam_text'] : null,
            diagnosisText: $row['diagnosis_text'] !== null ? (string) $row['diagnosis_text'] : null,
            clinicalPlanText: $row['clinical_plan_text'] !== null ? (string) $row['clinical_plan_text'] : null,
            aiSummaryText: $row['ai_summary_text'] !== null ? (string) $row['ai_summary_text'] : null,
            aiSummaryAcceptedAt: $row['ai_summary_accepted_at'] !== null
                ? new DateTimeImmutable((string) $row['ai_summary_accepted_at'])
                : null,
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable((string) $row['updated_at'])
                : null,
            pausedAt: ($row['paused_at'] ?? null) !== null
                ? new DateTimeImmutable((string) $row['paused_at'])
                : null,
            pausedSeconds: (int) ($row['paused_seconds'] ?? 0),
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Encounter already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Merges a partial clinical draft in place: only keys present in
     * $draft (and listed in {@see self::DRAFT_FIELDS}) are overwritten,
     * everything else keeps its current value — this is what lets
     * `EncounterService::autosave()` accept an arbitrary subset of fields on
     * every call without callers having to resend the whole record.
     *
     * @param array<string, mixed> $draft
     */
    public function applyDraft(array $draft): void
    {
        if (array_key_exists('anamnesis_text', $draft)) {
            $this->anamnesisText = $draft['anamnesis_text'] !== null ? (string) $draft['anamnesis_text'] : null;
        }

        if (array_key_exists('temperature_c', $draft)) {
            $this->temperatureC = $draft['temperature_c'];
        }

        if (array_key_exists('heart_rate_bpm', $draft)) {
            $this->heartRateBpm = $draft['heart_rate_bpm'] !== null ? (int) $draft['heart_rate_bpm'] : null;
        }

        if (array_key_exists('respiratory_rate_mpm', $draft)) {
            $this->respiratoryRateMpm = $draft['respiratory_rate_mpm'] !== null ? (int) $draft['respiratory_rate_mpm'] : null;
        }

        if (array_key_exists('weight_kg', $draft)) {
            $this->weightKg = $draft['weight_kg'];
        }

        if (array_key_exists('mucous_membranes', $draft)) {
            $this->mucousMembranes = $draft['mucous_membranes'] !== null ? (string) $draft['mucous_membranes'] : null;
        }

        if (array_key_exists('capillary_refill_seconds', $draft)) {
            $this->capillaryRefillSeconds = $draft['capillary_refill_seconds'];
        }

        if (array_key_exists('physical_exam_text', $draft)) {
            $this->physicalExamText = $draft['physical_exam_text'] !== null ? (string) $draft['physical_exam_text'] : null;
        }

        if (array_key_exists('diagnosis_text', $draft)) {
            $this->diagnosisText = $draft['diagnosis_text'] !== null ? (string) $draft['diagnosis_text'] : null;
        }

        if (array_key_exists('clinical_plan_text', $draft)) {
            $this->clinicalPlanText = $draft['clinical_plan_text'] !== null ? (string) $draft['clinical_plan_text'] : null;
        }
    }

    /**
     * Closes the encounter: `status` moves to `finished` and `finished_at`
     * is stamped. One-way — there is no operation in this plan that reopens
     * a finished encounter.
     *
     * @throws InvalidStatusTransitionException when already finished.
     */
    public function finish(DateTimeImmutable $now): void
    {
        if ($this->status === self::STATUS_FINISHED) {
            throw new InvalidStatusTransitionException(
                sprintf(
                    'Encounter %s is already finished',
                    $this->id !== null ? (string) $this->id : '(new)',
                )
            );
        }

        if ($this->pausedAt !== null) {
            $this->accumulatePause($now);
        }

        $this->status = self::STATUS_FINISHED;
        $this->finishedAt = $now;
    }

    /**
     * Pauses an in-progress encounter: stamps `paused_at`; status stays
     * `in_progress`.
     *
     * @throws InvalidStatusTransitionException when finished or already paused.
     */
    public function pause(DateTimeImmutable $now): void
    {
        if ($this->status === self::STATUS_FINISHED) {
            throw new InvalidStatusTransitionException(
                sprintf('Encounter %s is finished and cannot be paused', $this->label())
            );
        }

        if ($this->pausedAt !== null) {
            throw new InvalidStatusTransitionException(
                sprintf('Encounter %s is already paused', $this->label())
            );
        }

        $this->pausedAt = $now;
    }

    /**
     * Resumes a paused encounter: adds `now - paused_at` (whole seconds) to
     * `paused_seconds` and clears `paused_at`.
     *
     * @throws InvalidStatusTransitionException when not paused.
     */
    public function resume(DateTimeImmutable $now): void
    {
        if ($this->pausedAt === null) {
            throw new InvalidStatusTransitionException(
                sprintf('Encounter %s is not paused', $this->label())
            );
        }

        $this->accumulatePause($now);
    }

    public function isPaused(): bool
    {
        return $this->pausedAt !== null;
    }

    public function pausedAt(): ?DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function pausedSeconds(): int
    {
        return $this->pausedSeconds;
    }

    private function accumulatePause(DateTimeImmutable $now): void
    {
        $elapsed = $now->getTimestamp() - $this->pausedAt->getTimestamp();
        $this->pausedSeconds += max(0, $elapsed);
        $this->pausedAt = null;
    }

    private function label(): string
    {
        return $this->id !== null ? (string) $this->id : '(new)';
    }

    /** Records the AI-generated summary the professional accepted, with the acceptance timestamp. */
    public function acceptAiSummary(string $summaryText, DateTimeImmutable $now): void
    {
        $this->aiSummaryText = $summaryText;
        $this->aiSummaryAcceptedAt = $now;
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

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function anamnesisText(): ?string
    {
        return $this->anamnesisText;
    }

    public function temperatureC(): float|int|string|null
    {
        return $this->temperatureC;
    }

    public function heartRateBpm(): ?int
    {
        return $this->heartRateBpm;
    }

    public function respiratoryRateMpm(): ?int
    {
        return $this->respiratoryRateMpm;
    }

    public function weightKg(): float|int|string|null
    {
        return $this->weightKg;
    }

    public function mucousMembranes(): ?string
    {
        return $this->mucousMembranes;
    }

    public function capillaryRefillSeconds(): float|int|string|null
    {
        return $this->capillaryRefillSeconds;
    }

    public function physicalExamText(): ?string
    {
        return $this->physicalExamText;
    }

    public function diagnosisText(): ?string
    {
        return $this->diagnosisText;
    }

    public function clinicalPlanText(): ?string
    {
        return $this->clinicalPlanText;
    }

    public function aiSummaryText(): ?string
    {
        return $this->aiSummaryText;
    }

    public function aiSummaryAcceptedAt(): ?DateTimeImmutable
    {
        return $this->aiSummaryAcceptedAt;
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
