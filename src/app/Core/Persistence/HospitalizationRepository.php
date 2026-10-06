<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\HospitalizationRepositoryInterface;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Hospitalization;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Hospitalization aggregate
 * (`hospitalization`, migration 0010). Every query starts from
 * TenantQuery::forTenant() (ADR 0002). Bed occupancy is not touched here:
 * it is BedRepository::occupy()/release()'s job.
 *
 * @implements HospitalizationRepositoryInterface<Hospitalization>
 */
final class HospitalizationRepository extends AbstractTenantRepository implements HospitalizationRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        return $this->fetchOne($this->tenantQuery()->andEquals('id', (int) $id));
    }

    public function findActiveByPatient(int $patientId): ?object
    {
        return $this->fetchOne(
            $this->tenantQuery()
                ->andEquals('patient_id', $patientId)
                ->andEquals('status', Hospitalization::STATUS_ADMITTED),
            'ORDER BY admitted_at DESC, id DESC',
        );
    }

    public function listActiveByUnit(int $systemUnitId): array
    {
        $query = $this->tenantQuery()
            ->andEquals('system_unit_id', $systemUnitId)
            ->andEquals('status', Hospitalization::STATUS_ADMITTED);

        $statement = $this->connection->prepare(
            "SELECT * FROM hospitalization WHERE {$query->whereSql()} ORDER BY admitted_at ASC, id ASC"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): Hospitalization => Hospitalization::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Hospitalization) {
            throw new InvalidArgumentException('Expected a Hospitalization entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO hospitalization (
                    tenant_id, system_unit_id, patient_id, encounter_id, bed_id,
                    responsible_system_user_id, admitted_by_system_user_id, reason_text,
                    expected_discharge_date, daily_rate_cents, status, admitted_at,
                    discharged_at, discharged_by_system_user_id, discharge_summary_text
                ) VALUES (
                    :tenant_id, :system_unit_id, :patient_id, :encounter_id, :bed_id,
                    :responsible_system_user_id, :admitted_by_system_user_id, :reason_text,
                    :expected_discharge_date, :daily_rate_cents, :status, :admitted_at,
                    :discharged_at, :discharged_by_system_user_id, :discharge_summary_text
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':patient_id' => $entity->patientId(),
                ':encounter_id' => $entity->encounterId(),
                ':bed_id' => $entity->bedId(),
                ':responsible_system_user_id' => $entity->responsibleSystemUserId(),
                ':admitted_by_system_user_id' => $entity->admittedBySystemUserId(),
                ':reason_text' => $entity->reasonText(),
                ':expected_discharge_date' => $entity->expectedDischargeDate()?->format('Y-m-d'),
                ':daily_rate_cents' => $entity->dailyRateCents(),
                ':status' => $entity->status(),
                ':admitted_at' => self::timestamp($entity->admittedAt()),
                ':discharged_at' => self::timestamp($entity->dischargedAt()),
                ':discharged_by_system_user_id' => $entity->dischargedBySystemUserId(),
                ':discharge_summary_text' => $entity->dischargeSummaryText(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // Every domain transition (moveToBed, discharge) starts from
        // `admitted`, so the UPDATE only applies to a row still admitted: a
        // stale copy (e.g. a transfer racing a committed discharge) cannot
        // reopen the hospitalization.
        $query = $this->tenantQuery()
            ->andEquals('id', $entity->id())
            ->andEquals('status', Hospitalization::STATUS_ADMITTED);

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE hospitalization SET
                bed_id = :bed_id, status = :status, discharged_at = :discharged_at,
                discharged_by_system_user_id = :discharged_by_system_user_id,
                discharge_summary_text = :discharge_summary_text
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':bed_id' => $entity->bedId(),
            ':status' => $entity->status(),
            ':discharged_at' => self::timestamp($entity->dischargedAt()),
            ':discharged_by_system_user_id' => $entity->dischargedBySystemUserId(),
            ':discharge_summary_text' => $entity->dischargeSummaryText(),
        ]);

        // rowCount() counts changed rows: 0 is also an unchanged admitted
        // row. A locking read (sees the latest commit, not the transaction
        // snapshot) tells the two apart.
        if ($statement->rowCount() === 0 && !$this->isStillAdmitted((int) $entity->id())) {
            throw new InvalidStatusTransitionException("Hospitalization {$entity->id()} is not admitted");
        }

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Hospitalization) {
            throw new InvalidArgumentException('Expected a Hospitalization entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM hospitalization WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    private function isStillAdmitted(int $id): bool
    {
        $query = $this->tenantQuery()
            ->andEquals('id', $id)
            ->andEquals('status', Hospitalization::STATUS_ADMITTED);

        $statement = $this->connection->prepare(
            "SELECT 1 FROM hospitalization WHERE {$query->whereSql()} LIMIT 1 FOR UPDATE"
        );
        $statement->execute($query->parameters());

        return $statement->fetchColumn() !== false;
    }

    private function fetchOne(TenantQuery $query, string $orderBy = ''): ?Hospitalization
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM hospitalization WHERE {$query->whereSql()} {$orderBy} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Hospitalization::reconstitute($row);
    }

    private static function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.u');
    }
}
