<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SurgeryRepositoryInterface;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Surgery;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use WeakMap;

/**
 * PDO-backed persistence for the Surgery aggregate (`surgery`, migration
 * 0011). Every query starts from TenantQuery::forTenant() (ADR 0002).
 *
 * save() of an existing surgery is a conditional UPDATE: it only applies
 * while the row still holds the status the entity was loaded with, so a
 * stale copy (e.g. a pre-op change racing a committed cancellation) cannot
 * overwrite a concurrent transition. A refused write throws
 * InvalidStatusTransitionException `Surgery <id> changed status concurrently`.
 *
 * @implements SurgeryRepositoryInterface<Surgery>
 */
final class SurgeryRepository extends AbstractTenantRepository implements SurgeryRepositoryInterface
{
    private const ACTIVE_STATUSES = [Surgery::STATUS_SCHEDULED, Surgery::STATUS_PRE_OP, Surgery::STATUS_IN_PROGRESS];

    /**
     * Status this repository last wrote for an entity instance, so the same
     * instance can be saved again after a transition (Surgery::loadedStatus()
     * only reflects the row reconstitute() read).
     *
     * @var WeakMap<Surgery, string>
     */
    private WeakMap $persistedStatus;

    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
        $this->persistedStatus = new WeakMap();
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare("SELECT * FROM surgery WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Surgery::reconstitute($row);
    }

    public function listByUnitAndDay(int $systemUnitId, DateTimeImmutable $day): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);
        $dayStart = $day->setTime(0, 0);

        $statement = $this->connection->prepare(
            <<<SQL
            SELECT * FROM surgery
            WHERE {$query->whereSql()}
              AND scheduled_start_at >= :day_start AND scheduled_start_at < :day_end
            ORDER BY scheduled_start_at ASC, id ASC
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':day_start' => self::timestamp($dayStart),
            ':day_end' => self::timestamp($dayStart->modify('+1 day')),
        ]);

        return array_map(
            static fn (array $row): Surgery => Surgery::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function hasOverlapInRoom(
        int $roomId,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        ?int $exceptSurgeryId,
    ): bool {
        // Locking (current) read: under REPEATABLE READ a plain SELECT would
        // answer from the transaction snapshot, which may predate a booking
        // committed by the request that held the room lock before us. FOR
        // UPDATE reads the latest committed rows and waits on pending ones
        // (MySQL 5.7 and 8). Callers take SurgeryRoomRepository::lockForScheduling()
        // first, so concurrent bookings of the same room queue on the room row.
        $query = $this->tenantQuery()->andEquals('room_id', $roomId);
        $except = $exceptSurgeryId !== null ? ' AND id <> :except_id' : '';
        $parameters = [
            ...$query->parameters(),
            ':end_at' => self::timestamp($endAt),
            ':start_at' => self::timestamp($startAt),
            ':status_scheduled' => Surgery::STATUS_SCHEDULED,
            ':status_pre_op' => Surgery::STATUS_PRE_OP,
            ':status_in_progress' => Surgery::STATUS_IN_PROGRESS,
        ];

        if ($exceptSurgeryId !== null) {
            $parameters[':except_id'] = $exceptSurgeryId;
        }

        $statement = $this->connection->prepare(
            <<<SQL
            SELECT 1 FROM surgery
            WHERE {$query->whereSql()}
              AND scheduled_start_at < :end_at AND scheduled_end_at > :start_at
              AND status IN (:status_scheduled, :status_pre_op, :status_in_progress){$except}
            LIMIT 1
            FOR UPDATE
            SQL
        );
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public function lockStatus(int $surgeryId): ?string
    {
        $query = $this->tenantQuery()->andEquals('id', $surgeryId);

        $statement = $this->connection->prepare("SELECT status FROM surgery WHERE {$query->whereSql()} FOR UPDATE");
        $statement->execute($query->parameters());

        $status = $statement->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Surgery) {
            throw new InvalidArgumentException('Expected a Surgery entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $this->insert($entity);
            $this->persistedStatus[$entity] = $entity->status();

            return $entity;
        }

        $expectedStatus = $this->persistedStatus[$entity] ?? $entity->loadedStatus() ?? Surgery::STATUS_SCHEDULED;

        $query = $this->tenantQuery()
            ->andEquals('id', $entity->id())
            ->andEquals('status', $expectedStatus);

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE surgery SET
                status = :status,
                consent_signer_name = :consent_signer_name,
                consent_text = :consent_text,
                consent_recorded_at = :consent_recorded_at,
                consent_recorded_by_system_user_id = :consent_recorded_by_system_user_id,
                started_at = :started_at,
                completed_at = :completed_at,
                completed_by_system_user_id = :completed_by_system_user_id,
                cancelled_at = :cancelled_at,
                cancelled_by_system_user_id = :cancelled_by_system_user_id,
                cancellation_reason_text = :cancellation_reason_text,
                followup_appointment_id = :followup_appointment_id
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':status' => $entity->status(),
            ':consent_signer_name' => $entity->consentSignerName(),
            ':consent_text' => $entity->consentText(),
            ':consent_recorded_at' => self::timestamp($entity->consentRecordedAt()),
            ':consent_recorded_by_system_user_id' => $entity->consentRecordedBySystemUserId(),
            ':started_at' => self::timestamp($entity->startedAt()),
            ':completed_at' => self::timestamp($entity->completedAt()),
            ':completed_by_system_user_id' => $entity->completedBySystemUserId(),
            ':cancelled_at' => self::timestamp($entity->cancelledAt()),
            ':cancelled_by_system_user_id' => $entity->cancelledBySystemUserId(),
            ':cancellation_reason_text' => $entity->cancellationReasonText(),
            ':followup_appointment_id' => $entity->followupAppointmentId(),
        ]);

        // rowCount() counts changed rows: 0 is also an unchanged row still in
        // the expected status. A locking read (latest commit, not the
        // transaction snapshot) tells the two apart.
        if ($statement->rowCount() === 0 && $this->lockStatus((int) $entity->id()) !== $expectedStatus) {
            throw new InvalidStatusTransitionException("Surgery {$entity->id()} changed status concurrently");
        }

        $this->persistedStatus[$entity] = $entity->status();

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Surgery) {
            throw new InvalidArgumentException('Expected a Surgery entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM surgery WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    private function insert(Surgery $entity): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO surgery (
                tenant_id, system_unit_id, patient_id, encounter_id, room_id,
                procedure_catalog_item_id, procedure_name, procedure_price_cents,
                surgeon_system_user_id, scheduled_by_system_user_id,
                scheduled_start_at, scheduled_end_at, status, notes_text,
                consent_signer_name, consent_text, consent_recorded_at,
                consent_recorded_by_system_user_id, started_at, completed_at,
                completed_by_system_user_id, cancelled_at, cancelled_by_system_user_id,
                cancellation_reason_text, followup_appointment_id
            ) VALUES (
                :tenant_id, :system_unit_id, :patient_id, :encounter_id, :room_id,
                :procedure_catalog_item_id, :procedure_name, :procedure_price_cents,
                :surgeon_system_user_id, :scheduled_by_system_user_id,
                :scheduled_start_at, :scheduled_end_at, :status, :notes_text,
                :consent_signer_name, :consent_text, :consent_recorded_at,
                :consent_recorded_by_system_user_id, :started_at, :completed_at,
                :completed_by_system_user_id, :cancelled_at, :cancelled_by_system_user_id,
                :cancellation_reason_text, :followup_appointment_id
            )
            SQL
        );
        $statement->execute([
            ':tenant_id' => $entity->tenantId(),
            ':system_unit_id' => $entity->systemUnitId(),
            ':patient_id' => $entity->patientId(),
            ':encounter_id' => $entity->encounterId(),
            ':room_id' => $entity->roomId(),
            ':procedure_catalog_item_id' => $entity->procedureCatalogItemId(),
            ':procedure_name' => $entity->procedureName(),
            ':procedure_price_cents' => $entity->procedurePriceCents(),
            ':surgeon_system_user_id' => $entity->surgeonSystemUserId(),
            ':scheduled_by_system_user_id' => $entity->scheduledBySystemUserId(),
            ':scheduled_start_at' => self::timestamp($entity->scheduledStartAt()),
            ':scheduled_end_at' => self::timestamp($entity->scheduledEndAt()),
            ':status' => $entity->status(),
            ':notes_text' => $entity->notesText(),
            ':consent_signer_name' => $entity->consentSignerName(),
            ':consent_text' => $entity->consentText(),
            ':consent_recorded_at' => self::timestamp($entity->consentRecordedAt()),
            ':consent_recorded_by_system_user_id' => $entity->consentRecordedBySystemUserId(),
            ':started_at' => self::timestamp($entity->startedAt()),
            ':completed_at' => self::timestamp($entity->completedAt()),
            ':completed_by_system_user_id' => $entity->completedBySystemUserId(),
            ':cancelled_at' => self::timestamp($entity->cancelledAt()),
            ':cancelled_by_system_user_id' => $entity->cancelledBySystemUserId(),
            ':cancellation_reason_text' => $entity->cancellationReasonText(),
            ':followup_appointment_id' => $entity->followupAppointmentId(),
        ]);

        $entity->assignId((int) $this->connection->lastInsertId());
    }

    private static function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.u');
    }
}
