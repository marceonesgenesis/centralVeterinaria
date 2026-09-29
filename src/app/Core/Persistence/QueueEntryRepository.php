<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\QueueEntryRepositoryInterface;
use CentralVet\Domain\QueueEntry;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the QueueEntry aggregate. Every query starts
 * from TenantQuery::forTenant() (ADR 0002); tenant scoping is never accepted
 * from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `queue_entry` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260921_0002_phase1_clinic_core.sql. It is
 * prepared and syntax-checked (php -l) only; it must not be constructed with
 * a real PDO connection nor executed against the live database until that
 * migration has explicit SQL execution approval and has actually been
 * applied.
 *
 * @implements QueueEntryRepositoryInterface<QueueEntry>
 */
final class QueueEntryRepository extends AbstractTenantRepository implements QueueEntryRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM queue_entry WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Active = not yet finished (status <> 'atendido'). 'atendido' is a
     * fixed literal appended to the WHERE clause, not caller input, so it is
     * safe outside TenantQuery's parameter binding.
     */
    public function listActiveByUnit(int $systemUnitId): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);

        $statement = $this->connection->prepare(
            "SELECT * FROM queue_entry WHERE {$query->whereSql()} "
            . "AND status <> 'atendido' ORDER BY checked_in_at ASC"
        );
        $statement->execute($query->parameters());

        $entries = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[] = $this->hydrate($row);
        }

        return $entries;
    }

    public function findByAppointment(int $appointmentId): ?object
    {
        $query = $this->tenantQuery()->andEquals('appointment_id', $appointmentId);

        $statement = $this->connection->prepare(
            "SELECT * FROM queue_entry WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof QueueEntry) {
            throw new InvalidArgumentException('Expected a QueueEntry entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO queue_entry (
                    tenant_id, system_unit_id, patient_id, appointment_id,
                    professional_system_user_id, status, checked_in_at,
                    called_at, started_at, finished_at
                ) VALUES (
                    :tenant_id, :system_unit_id, :patient_id, :appointment_id,
                    :professional_system_user_id, :status, :checked_in_at,
                    :called_at, :started_at, :finished_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':patient_id' => $entity->patientId(),
                ':appointment_id' => $entity->appointmentId(),
                ':professional_system_user_id' => $entity->professionalSystemUserId(),
                ':status' => $entity->status(),
                ':checked_in_at' => self::formatDateTime($entity->checkedInAt()),
                ':called_at' => self::formatDateTime($entity->calledAt()),
                ':started_at' => self::formatDateTime($entity->startedAt()),
                ':finished_at' => self::formatDateTime($entity->finishedAt()),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE queue_entry SET status = :status, called_at = :called_at, "
            . "started_at = :started_at, finished_at = :finished_at "
            . "WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':status' => $entity->status(),
            ':called_at' => self::formatDateTime($entity->calledAt()),
            ':started_at' => self::formatDateTime($entity->startedAt()),
            ':finished_at' => self::formatDateTime($entity->finishedAt()),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof QueueEntry) {
            throw new InvalidArgumentException('Expected a QueueEntry entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM queue_entry WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): QueueEntry
    {
        return QueueEntry::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            patientId: (int) $row['patient_id'],
            appointmentId: $row['appointment_id'] !== null ? (int) $row['appointment_id'] : null,
            professionalSystemUserId: (int) $row['professional_system_user_id'],
            status: (string) $row['status'],
            checkedInAt: new DateTimeImmutable((string) $row['checked_in_at']),
            calledAt: $row['called_at'] !== null ? new DateTimeImmutable((string) $row['called_at']) : null,
            startedAt: $row['started_at'] !== null ? new DateTimeImmutable((string) $row['started_at']) : null,
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable((string) $row['finished_at']) : null,
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: $row['updated_at'] !== null ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }

    private static function formatDateTime(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.u');
    }
}
