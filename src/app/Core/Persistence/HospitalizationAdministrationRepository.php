<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\HospitalizationAdministrationRepositoryInterface;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\HospitalizationAdministration;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the HospitalizationAdministration aggregate
 * (`hospitalization_administration`, migration 0010). Every query starts
 * from TenantQuery::forTenant() (ADR 0002); the flowboard JOIN also pins
 * every joined table to the context tenant. The UNIQUE
 * (order_id, scheduled_at) keeps a schedule from being generated twice.
 *
 * @implements HospitalizationAdministrationRepositoryInterface<HospitalizationAdministration>
 */
final class HospitalizationAdministrationRepository extends AbstractTenantRepository implements
    HospitalizationAdministrationRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $rows = $this->select($this->tenantQuery()->andEquals('id', (int) $id), 'LIMIT 1');

        return $rows[0] ?? null;
    }

    public function listByHospitalization(int $hospitalizationId): array
    {
        return $this->select(
            $this->tenantQuery()->andEquals('hospitalization_id', $hospitalizationId),
            'ORDER BY scheduled_at ASC, id ASC',
        );
    }

    public function listPendingByOrder(int $orderId): array
    {
        return $this->select(
            $this->tenantQuery()
                ->andEquals('order_id', $orderId)
                ->andEquals('status', HospitalizationAdministration::STATUS_PENDING),
            'ORDER BY scheduled_at ASC, id ASC',
        );
    }

    public function listPendingByHospitalization(int $hospitalizationId): array
    {
        return $this->select(
            $this->tenantQuery()
                ->andEquals('hospitalization_id', $hospitalizationId)
                ->andEquals('status', HospitalizationAdministration::STATUS_PENDING),
            'ORDER BY scheduled_at ASC, id ASC',
        );
    }

    public function listBoardRows(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $query = $this->tenantQuery('a');
        $tenantId = $this->tenantId();

        $statement = $this->connection->prepare(
            <<<SQL
            SELECT a.id AS administration_id, a.hospitalization_id, p.name AS patient_name,
                   b.code AS bed_code, o.order_type, o.description_text, o.dose_text, o.route,
                   a.scheduled_at, a.status, a.performed_at
            FROM hospitalization_administration a
            JOIN hospitalization h
              ON h.id = a.hospitalization_id AND h.tenant_id = :tenant_h
            JOIN patient p
              ON p.id = h.patient_id AND p.tenant_id = :tenant_p
            JOIN bed b
              ON b.id = h.bed_id AND b.tenant_id = :tenant_b
            JOIN hospitalization_order o
              ON o.id = a.order_id AND o.tenant_id = :tenant_o
            WHERE {$query->whereSql()}
              AND h.status = 'admitted'
              AND h.system_unit_id = :system_unit_id
              AND (
                    (a.status = 'pending' AND a.scheduled_at <= :pending_to)
                 OR (a.status IN ('done', 'skipped') AND a.scheduled_at >= :performed_from AND a.scheduled_at <= :performed_to)
              )
            ORDER BY a.scheduled_at ASC, a.id ASC
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':tenant_h' => $tenantId,
            ':tenant_p' => $tenantId,
            ':tenant_b' => $tenantId,
            ':tenant_o' => $tenantId,
            ':system_unit_id' => $systemUnitId,
            ':pending_to' => self::timestamp($to),
            ':performed_from' => self::timestamp($from),
            ':performed_to' => self::timestamp($to),
        ]);

        return array_map(
            static fn (array $row): array => [
                'administration_id' => (int) $row['administration_id'],
                'hospitalization_id' => (int) $row['hospitalization_id'],
                'patient_name' => (string) $row['patient_name'],
                'bed_code' => (string) $row['bed_code'],
                'order_type' => (string) $row['order_type'],
                'description_text' => (string) $row['description_text'],
                'dose_text' => (string) $row['dose_text'],
                'route' => (string) $row['route'],
                'scheduled_at' => self::boardDate((string) $row['scheduled_at']),
                'status' => (string) $row['status'],
                'performed_at' => $row['performed_at'] !== null ? self::boardDate((string) $row['performed_at']) : null,
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof HospitalizationAdministration) {
            throw new InvalidArgumentException('Expected a HospitalizationAdministration entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO hospitalization_administration (
                    tenant_id, hospitalization_id, order_id, scheduled_at, status,
                    performed_at, performed_by_system_user_id, notes_text
                ) VALUES (
                    :tenant_id, :hospitalization_id, :order_id, :scheduled_at, :status,
                    :performed_at, :performed_by_system_user_id, :notes_text
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':hospitalization_id' => $entity->hospitalizationId(),
                ':order_id' => $entity->orderId(),
                ':scheduled_at' => self::timestamp($entity->scheduledAt()),
                ':status' => $entity->status(),
                ':performed_at' => self::timestamp($entity->performedAt()),
                ':performed_by_system_user_id' => $entity->performedBySystemUserId(),
                ':notes_text' => $entity->notesText(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // Every domain transition leaves `pending` (markDone/markSkipped/
        // cancel), so the UPDATE only applies to a row still pending: a
        // concurrent done x skipped/cancel loses here (InnoDB re-evaluates
        // the WHERE on the committed row) instead of overwriting the winner.
        $query = $this->tenantQuery()
            ->andEquals('id', $entity->id())
            ->andEquals('status', HospitalizationAdministration::STATUS_PENDING);

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE hospitalization_administration SET
                status = :status, performed_at = :performed_at,
                performed_by_system_user_id = :performed_by_system_user_id, notes_text = :notes_text
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':status' => $entity->status(),
            ':performed_at' => self::timestamp($entity->performedAt()),
            ':performed_by_system_user_id' => $entity->performedBySystemUserId(),
            ':notes_text' => $entity->notesText(),
        ]);

        if ($statement->rowCount() !== 1) {
            throw new InvalidStatusTransitionException("Administration {$entity->id()} is not pending");
        }

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof HospitalizationAdministration) {
            throw new InvalidArgumentException('Expected a HospitalizationAdministration entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare(
            "DELETE FROM hospitalization_administration WHERE {$query->whereSql()}"
        );
        $statement->execute($query->parameters());
    }

    /** @return list<HospitalizationAdministration> */
    private function select(TenantQuery $query, string $suffix): array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM hospitalization_administration WHERE {$query->whereSql()} {$suffix}"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): HospitalizationAdministration => HospitalizationAdministration::reconstitute(
                (int) $row['id'],
                (int) $row['tenant_id'],
                (int) $row['hospitalization_id'],
                (int) $row['order_id'],
                new DateTimeImmutable((string) $row['scheduled_at']),
                (string) $row['status'],
                isset($row['performed_at']) ? new DateTimeImmutable((string) $row['performed_at']) : null,
                isset($row['performed_by_system_user_id']) ? (int) $row['performed_by_system_user_id'] : null,
                isset($row['notes_text']) ? (string) $row['notes_text'] : null,
            ),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private static function boardDate(string $value): string
    {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    }

    private static function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.u');
    }
}
