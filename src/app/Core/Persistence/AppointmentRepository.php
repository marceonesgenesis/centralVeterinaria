<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Appointment;
use CentralVet\Domain\Contract\AppointmentRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Appointment aggregate.
 *
 * PENDING / DO NOT WIRE YET: depends on the `appointment` table created by
 * the not-yet-applied migration
 * src/app/database/migrations/20260921_0002_phase1_clinic_core.sql (ADR
 * 0003). This class is prepared and syntax-checked (php -l) only; it must
 * not be constructed with a real PDO connection or executed against the
 * live database until that migration has explicit SQL execution approval
 * and has actually been applied.
 *
 * Every query starts from `AbstractTenantRepository::tenantQuery()`, which
 * always seeds the predicate list with `tenant_id = :tenant_scope_id`
 * (ADR 0002) — the tenant filter is never a plain `andEquals()` call and can
 * never be overridden by caller input.
 *
 * Scheduling-conflict detection (T-07's acceptance criterion) is
 * deliberately NOT implemented here: it lives in
 * CentralVet\Application\AppointmentService::schedule() instead, operating
 * on the plain Appointment objects returned by listByProfessionalAndDate().
 * Reasons: (1) the conflict check needs each candidate appointment's
 * service duration, which lives in a different aggregate (Service, T-06) —
 * a cross-aggregate join belongs in the Application layer, not behind a
 * single-table repository; (2) keeping the rule in plain PHP lets it be
 * unit-tested (T-16) without a live database, consistent with this
 * repository's PENDING/DO NOT WIRE YET status.
 *
 * @implements AppointmentRepositoryInterface<Appointment>
 */
final class AppointmentRepository extends AbstractTenantRepository implements AppointmentRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery();
        $statement = $this->connection->prepare(
            "SELECT * FROM appointment WHERE {$query->whereSql()} AND id = :appointment_id"
        );
        $statement->execute([...$query->parameters(), ':appointment_id' => (int) $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? Appointment::fromRow($row) : null;
    }

    public function listByProfessionalAndDate(int $professionalSystemUserId, DateTimeImmutable $date): array
    {
        $query = $this->tenantQuery()->andEquals('professional_system_user_id', $professionalSystemUserId);
        $statement = $this->connection->prepare(
            <<<SQL
            SELECT * FROM appointment
            WHERE {$query->whereSql()}
              AND scheduled_at >= :day_start AND scheduled_at < :day_end
            ORDER BY scheduled_at
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':day_start' => $date->format('Y-m-d 00:00:00.000000'),
            ':day_end' => $date->modify('+1 day')->format('Y-m-d 00:00:00.000000'),
        ]);

        return array_map(
            static fn (array $row): Appointment => Appointment::fromRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function listByUnitAndDate(int $systemUnitId, DateTimeImmutable $date): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);
        $statement = $this->connection->prepare(
            <<<SQL
            SELECT * FROM appointment
            WHERE {$query->whereSql()}
              AND scheduled_at >= :day_start AND scheduled_at < :day_end
            ORDER BY scheduled_at
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':day_start' => $date->format('Y-m-d 00:00:00.000000'),
            ':day_end' => $date->modify('+1 day')->format('Y-m-d 00:00:00.000000'),
        ]);

        return array_map(
            static fn (array $row): Appointment => Appointment::fromRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Appointment) {
            throw new InvalidArgumentException('AppointmentRepository::save expects an Appointment entity');
        }

        $this->assertEntityTenant($entity->tenantId);

        if ($entity->id === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO appointment (
                    tenant_id, system_unit_id, patient_id, service_id,
                    professional_system_user_id, scheduled_at, status
                ) VALUES (
                    :tenant_id, :system_unit_id, :patient_id, :service_id,
                    :professional_system_user_id, :scheduled_at, :status
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId,
                ':system_unit_id' => $entity->systemUnitId,
                ':patient_id' => $entity->patientId,
                ':service_id' => $entity->serviceId,
                ':professional_system_user_id' => $entity->professionalSystemUserId,
                ':scheduled_at' => $entity->scheduledAt->format('Y-m-d H:i:s.u'),
                ':status' => $entity->status,
            ]);

            return $entity->withId((int) $this->connection->lastInsertId());
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id);
        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE appointment SET
                system_unit_id = :system_unit_id, patient_id = :patient_id,
                service_id = :service_id,
                professional_system_user_id = :professional_system_user_id,
                scheduled_at = :scheduled_at, status = :status
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':system_unit_id' => $entity->systemUnitId,
            ':patient_id' => $entity->patientId,
            ':service_id' => $entity->serviceId,
            ':professional_system_user_id' => $entity->professionalSystemUserId,
            ':scheduled_at' => $entity->scheduledAt->format('Y-m-d H:i:s.u'),
            ':status' => $entity->status,
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Appointment || $entity->id === null) {
            throw new InvalidArgumentException('AppointmentRepository::remove expects a persisted Appointment entity');
        }

        $this->assertEntityTenant($entity->tenantId);

        $query = $this->tenantQuery()->andEquals('id', $entity->id);
        $statement = $this->connection->prepare("DELETE FROM appointment WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }
}
