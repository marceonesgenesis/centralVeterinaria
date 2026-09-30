<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Encounter aggregate. Every query starts
 * from TenantQuery::forTenant() (ADR 0002); tenant scoping is never accepted
 * from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `encounter` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260922_0003_phase2_encounter.sql. It is
 * prepared and syntax-checked (php -l) only; it must not be constructed with
 * a real PDO connection nor executed against the live database until that
 * migration has explicit SQL execution approval and has actually been
 * applied.
 *
 * @implements EncounterRepositoryInterface<Encounter>
 */
final class EncounterRepository extends AbstractTenantRepository implements EncounterRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM encounter WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * In-progress = not yet finished (status <> 'finished'). 'finished' is a
     * fixed literal appended to the WHERE clause, not caller input, so it is
     * safe outside TenantQuery's parameter binding (same convention as
     * QueueEntryRepository::listActiveByUnit()).
     */
    public function listInProgressByPatient(int $patientId): array
    {
        $query = $this->tenantQuery()->andEquals('patient_id', $patientId);

        $statement = $this->connection->prepare(
            "SELECT * FROM encounter WHERE {$query->whereSql()} "
            . "AND status <> 'finished' ORDER BY started_at ASC"
        );
        $statement->execute($query->parameters());

        $encounters = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $encounters[] = $this->hydrate($row);
        }

        return $encounters;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Encounter) {
            throw new InvalidArgumentException('Expected an Encounter entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO encounter (
                    tenant_id, system_unit_id, patient_id, appointment_id,
                    professional_system_user_id, status, started_at, finished_at,
                    anamnesis_text, temperature_c, heart_rate_bpm, respiratory_rate_mpm,
                    weight_kg, mucous_membranes, capillary_refill_seconds,
                    physical_exam_text, diagnosis_text, clinical_plan_text,
                    ai_summary_text, ai_summary_accepted_at, paused_at, paused_seconds
                ) VALUES (
                    :tenant_id, :system_unit_id, :patient_id, :appointment_id,
                    :professional_system_user_id, :status, :started_at, :finished_at,
                    :anamnesis_text, :temperature_c, :heart_rate_bpm, :respiratory_rate_mpm,
                    :weight_kg, :mucous_membranes, :capillary_refill_seconds,
                    :physical_exam_text, :diagnosis_text, :clinical_plan_text,
                    :ai_summary_text, :ai_summary_accepted_at, :paused_at, :paused_seconds
                )
                SQL
            );
            $statement->execute($this->bindingsFor($entity));

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE encounter SET
                status = :status, finished_at = :finished_at,
                anamnesis_text = :anamnesis_text, temperature_c = :temperature_c,
                heart_rate_bpm = :heart_rate_bpm, respiratory_rate_mpm = :respiratory_rate_mpm,
                weight_kg = :weight_kg, mucous_membranes = :mucous_membranes,
                capillary_refill_seconds = :capillary_refill_seconds,
                physical_exam_text = :physical_exam_text, diagnosis_text = :diagnosis_text,
                clinical_plan_text = :clinical_plan_text, ai_summary_text = :ai_summary_text,
                ai_summary_accepted_at = :ai_summary_accepted_at,
                paused_at = :paused_at, paused_seconds = :paused_seconds
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ...$this->bindingsFor($entity, includeInsertOnly: false),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Encounter) {
            throw new InvalidArgumentException('Expected an Encounter entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM encounter WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /**
     * Shared parameter bindings for INSERT/UPDATE. $includeInsertOnly=false
     * drops the insert-only columns (tenant/unit/patient/appointment/
     * professional/started_at) that an UPDATE never rewrites, while keeping
     * the same keys as the base set so `[...$base, ...$extra]` cannot
     * collide.
     *
     * @return array<string, mixed>
     */
    private function bindingsFor(Encounter $entity, bool $includeInsertOnly = true): array
    {
        $mutable = [
            ':status' => $entity->status(),
            ':finished_at' => self::formatDateTime($entity->finishedAt()),
            ':anamnesis_text' => $entity->anamnesisText(),
            ':temperature_c' => $entity->temperatureC(),
            ':heart_rate_bpm' => $entity->heartRateBpm(),
            ':respiratory_rate_mpm' => $entity->respiratoryRateMpm(),
            ':weight_kg' => $entity->weightKg(),
            ':mucous_membranes' => $entity->mucousMembranes(),
            ':capillary_refill_seconds' => $entity->capillaryRefillSeconds(),
            ':physical_exam_text' => $entity->physicalExamText(),
            ':diagnosis_text' => $entity->diagnosisText(),
            ':clinical_plan_text' => $entity->clinicalPlanText(),
            ':ai_summary_text' => $entity->aiSummaryText(),
            ':ai_summary_accepted_at' => self::formatDateTime($entity->aiSummaryAcceptedAt()),
            ':paused_at' => self::formatDateTime($entity->pausedAt()),
            ':paused_seconds' => $entity->pausedSeconds(),
        ];

        if (!$includeInsertOnly) {
            return $mutable;
        }

        return [
            ':tenant_id' => $entity->tenantId(),
            ':system_unit_id' => $entity->systemUnitId(),
            ':patient_id' => $entity->patientId(),
            ':appointment_id' => $entity->appointmentId(),
            ':professional_system_user_id' => $entity->professionalSystemUserId(),
            ':started_at' => self::formatDateTime($entity->startedAt()),
            ...$mutable,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Encounter
    {
        return Encounter::reconstitute($row);
    }

    private static function formatDateTime(?\DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.u');
    }
}
