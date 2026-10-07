<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\QueueEntryRepositoryInterface;
use CentralVet\Domain\QueueEntry;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;

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
            $insert = [
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
            ];

            try {
                $statement->execute($insert);
            } catch (PDOException $e) {
                // Migration 0008: a concurrent second check-in of the same
                // appointment passes the service's findByAppointment() check
                // and is stopped only by the UNIQUE index. Surface it as the
                // same domain message QueueEntryService::checkIn() uses.
                $appointmentId = $entity->appointmentId();

                if ($appointmentId !== null && self::isAppointmentUniqueViolation($e)) {
                    throw new DomainException("Appointment {$appointmentId} is already in the queue", 0, $e);
                }

                throw $e;
            }

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

    public function listAppointmentIdsInQueue(array $appointmentIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $appointmentIds)));

        if ($ids === []) {
            return [];
        }

        $query = $this->tenantQuery();
        $parameters = $query->parameters();
        $placeholders = [];

        foreach ($ids as $index => $id) {
            $placeholder = ':appointment_' . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = $id;
        }

        $statement = $this->connection->prepare(
            "SELECT DISTINCT appointment_id FROM queue_entry WHERE {$query->whereSql()} "
            . 'AND appointment_id IN (' . implode(', ', $placeholders) . ') ORDER BY appointment_id'
        );
        $statement->execute($parameters);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** SQLSTATE 23000 on the `queue_entry_appointment_uq` index only (not FKs or other keys). */
    private static function isAppointmentUniqueViolation(PDOException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'queue_entry_appointment_uq');
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
