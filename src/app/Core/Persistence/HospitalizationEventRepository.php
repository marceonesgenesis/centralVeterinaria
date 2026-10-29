<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\HospitalizationEventRepositoryInterface;
use CentralVet\Domain\HospitalizationEvent;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for HospitalizationEvent (`hospitalization_event`,
 * migration 0010), the append-only timeline of a hospitalization. Every
 * query starts from TenantQuery::forTenant() (ADR 0002); save() only
 * inserts and remove() is refused.
 *
 * @implements HospitalizationEventRepositoryInterface<HospitalizationEvent>
 */
final class HospitalizationEventRepository extends AbstractTenantRepository implements
    HospitalizationEventRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM hospitalization_event WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : HospitalizationEvent::reconstitute($row);
    }

    public function listByHospitalization(int $hospitalizationId): array
    {
        $query = $this->tenantQuery()->andEquals('hospitalization_id', $hospitalizationId);

        $statement = $this->connection->prepare(
            "SELECT * FROM hospitalization_event WHERE {$query->whereSql()} ORDER BY recorded_at DESC, id DESC"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): HospitalizationEvent => HospitalizationEvent::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof HospitalizationEvent) {
            throw new InvalidArgumentException('Expected a HospitalizationEvent entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() !== null) {
            throw new InvalidArgumentException('HospitalizationEvent records are immutable once persisted');
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO hospitalization_event (
                tenant_id, hospitalization_id, event_type, recorded_by_system_user_id, recorded_at,
                notes_text, temperature_c, heart_rate_bpm, respiratory_rate_rpm, weight_kg,
                pain_score, from_bed_id, to_bed_id
            ) VALUES (
                :tenant_id, :hospitalization_id, :event_type, :recorded_by_system_user_id, :recorded_at,
                :notes_text, :temperature_c, :heart_rate_bpm, :respiratory_rate_rpm, :weight_kg,
                :pain_score, :from_bed_id, :to_bed_id
            )
            SQL
        );
        $statement->execute([
            ':tenant_id' => $entity->tenantId(),
            ':hospitalization_id' => $entity->hospitalizationId(),
            ':event_type' => $entity->eventType(),
            ':recorded_by_system_user_id' => $entity->recordedBySystemUserId(),
            ':recorded_at' => $entity->recordedAt()->format('Y-m-d H:i:s.u'),
            ':notes_text' => $entity->notesText(),
            ':temperature_c' => $entity->temperatureC(),
            ':heart_rate_bpm' => $entity->heartRateBpm(),
            ':respiratory_rate_rpm' => $entity->respiratoryRateRpm(),
            ':weight_kg' => $entity->weightKg(),
            ':pain_score' => $entity->painScore(),
            ':from_bed_id' => $entity->fromBedId(),
            ':to_bed_id' => $entity->toBedId(),
        ]);

        $entity->assignId((int) $this->connection->lastInsertId());

        return $entity;
    }

    public function remove(object $entity): void
    {
        throw new InvalidArgumentException('HospitalizationEvent records are append-only and cannot be removed');
    }
}
