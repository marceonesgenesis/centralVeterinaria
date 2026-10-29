<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Encounter;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for EncounterRepositoryInterface (T-08): the `encounter`
 * table does not exist yet (migration T-01 not applied), so
 * EncounterServiceTest exercises EncounterService::start()/finish()/
 * autosave() against this instead of a real database. Tenant-scoped like the
 * real EncounterRepository (ADR 0002): findById() only ever returns an
 * encounter whose tenantId() matches this instance's own $tenantId.
 *
 * Fase 8A, T-24/T-28: every write stamps the stored encounter with the
 * fake's own clock (one second per write), like the `updated_at ... ON
 * UPDATE` column, and stores a copy rebuilt through
 * Encounter::reconstitute(); the entity passed to save() still gets its id.
 * Like EncounterRepository, save() of an existing encounter keeps the
 * stored draft columns (only updateDraft() writes them) and lockForDraft()
 * returns a copy of the stored row; `locks` and `draftUpdates` count the
 * calls.
 */
final class FakeEncounterRepository implements EncounterRepositoryInterface
{
    /** @var array<int, Encounter> */
    private array $encounters = [];
    private int $nextId = 1;
    private DateTimeImmutable $clock;
    public int $locks = 0;
    public int $draftUpdates = 0;

    public function __construct(private readonly int $tenantId, Encounter ...$seed)
    {
        $this->clock = new DateTimeImmutable('2026-10-15 08:00:00.000000');

        foreach ($seed as $encounter) {
            $this->save($encounter);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $encounter = $this->encounters[(int) $id] ?? null;

        if ($encounter === null || $encounter->tenantId() !== $this->tenantId) {
            return null;
        }

        return $encounter;
    }

    /** @return list<Encounter> */
    public function listInProgressByPatient(int $patientId): array
    {
        return array_values(array_filter(
            $this->encounters,
            fn (Encounter $encounter): bool => $encounter->tenantId() === $this->tenantId
                && $encounter->patientId() === $patientId
                && $encounter->status() !== Encounter::STATUS_FINISHED,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Encounter) {
            throw new InvalidArgumentException('FakeEncounterRepository only stores Encounter entities');
        }

        $stored = $entity->id() !== null ? ($this->encounters[$entity->id()] ?? null) : null;

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->encounters[$entity->id()] = $this->stamp($entity, $stored);

        return $this->encounters[$entity->id()];
    }

    public function lockForDraft(int $id): ?Encounter
    {
        $this->locks++;
        $stored = $this->findById($id);

        return $stored === null ? null : clone $stored;
    }

    public function updateDraft(Encounter $encounter): void
    {
        $this->draftUpdates++;
        $stored = $encounter->id() !== null ? $this->findById($encounter->id()) : null;

        if ($stored === null) {
            throw new InvalidArgumentException('Encounter must be persisted before its draft is updated');
        }

        $this->encounters[$encounter->id()] = $this->stamp($stored, $encounter);
    }

    public int $summaryUpdates = 0;

    /** Writes only the summary columns of $encounter onto the stored row (T-32, correção 1). */
    public function updateAiSummary(Encounter $encounter): void
    {
        $this->summaryUpdates++;
        $stored = $encounter->id() !== null ? $this->findById($encounter->id()) : null;

        if ($stored === null) {
            throw new InvalidArgumentException('Encounter must be persisted before its summary is updated');
        }

        $copy = clone $stored;
        $copy->acceptAiSummary((string) $encounter->aiSummaryText(), $encounter->aiSummaryAcceptedAt() ?? new DateTimeImmutable());
        $this->encounters[$encounter->id()] = $this->stamp($copy);
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Encounter && $entity->id() !== null) {
            unset($this->encounters[$entity->id()]);
        }
    }

    /**
     * Copy of $encounter with `updated_at` = the next tick of the clock and
     * the draft columns of $draft (default: $encounter itself).
     */
    private function stamp(Encounter $encounter, ?Encounter $draft = null): Encounter
    {
        $draft ??= $encounter;
        $this->clock = $this->clock->modify('+1 second');
        $format = static fn (?DateTimeImmutable $value): ?string => $value?->format('Y-m-d H:i:s.u');

        return Encounter::reconstitute([
            'id' => $encounter->id(),
            'tenant_id' => $encounter->tenantId(),
            'system_unit_id' => $encounter->systemUnitId(),
            'patient_id' => $encounter->patientId(),
            'appointment_id' => $encounter->appointmentId(),
            'professional_system_user_id' => $encounter->professionalSystemUserId(),
            'status' => $encounter->status(),
            'started_at' => $format($encounter->startedAt()),
            'finished_at' => $format($encounter->finishedAt()),
            'anamnesis_text' => $draft->anamnesisText(),
            'temperature_c' => $draft->temperatureC(),
            'heart_rate_bpm' => $draft->heartRateBpm(),
            'respiratory_rate_mpm' => $draft->respiratoryRateMpm(),
            'weight_kg' => $draft->weightKg(),
            'mucous_membranes' => $draft->mucousMembranes(),
            'capillary_refill_seconds' => $draft->capillaryRefillSeconds(),
            'physical_exam_text' => $draft->physicalExamText(),
            'diagnosis_text' => $draft->diagnosisText(),
            'clinical_plan_text' => $draft->clinicalPlanText(),
            'ai_summary_text' => $encounter->aiSummaryText(),
            'ai_summary_accepted_at' => $format($encounter->aiSummaryAcceptedAt()),
            'created_at' => $format($encounter->createdAt()),
            'updated_at' => $this->clock->format('Y-m-d H:i:s.u'),
            'paused_at' => $format($encounter->pausedAt()),
            'paused_seconds' => $encounter->pausedSeconds(),
        ]);
    }
}
