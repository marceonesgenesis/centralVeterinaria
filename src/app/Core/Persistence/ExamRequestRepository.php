<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ExamRequestRepositoryInterface;
use CentralVet\Domain\ExamRequest;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the ExamRequest aggregate. Every query starts
 * from TenantQuery::forTenant() (ADR 0002); tenant scoping is never accepted
 * from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `exam_request` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval and has
 * actually been applied.
 *
 * @implements ExamRequestRepositoryInterface<ExamRequest>
 */
final class ExamRequestRepository extends AbstractTenantRepository implements ExamRequestRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM exam_request WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function listByPatient(int $patientId): array
    {
        $query = $this->tenantQuery()->andEquals('patient_id', $patientId);

        $statement = $this->connection->prepare(
            "SELECT * FROM exam_request WHERE {$query->whereSql()} ORDER BY requested_at ASC"
        );
        $statement->execute($query->parameters());

        return $this->hydrateAll($statement);
    }

    public function listByEncounter(int $encounterId): array
    {
        $query = $this->tenantQuery()->andEquals('encounter_id', $encounterId);

        $statement = $this->connection->prepare(
            "SELECT * FROM exam_request WHERE {$query->whereSql()} ORDER BY requested_at ASC"
        );
        $statement->execute($query->parameters());

        return $this->hydrateAll($statement);
    }

    /**
     * 'requested' is a fixed literal appended to the WHERE clause, not
     * caller input, so it is safe outside TenantQuery's parameter binding
     * (same convention as QueueEntryRepository::listActiveByUnit()).
     */
    public function listPending(): array
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare(
            "SELECT * FROM exam_request WHERE {$query->whereSql()} "
            . "AND status = 'requested' ORDER BY requested_at ASC"
        );
        $statement->execute($query->parameters());

        return $this->hydrateAll($statement);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ExamRequest) {
            throw new InvalidArgumentException('Expected an ExamRequest entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO exam_request (
                    tenant_id, encounter_id, patient_id, exam_catalog_item_id,
                    professional_system_user_id, status, requested_at
                ) VALUES (
                    :tenant_id, :encounter_id, :patient_id, :exam_catalog_item_id,
                    :professional_system_user_id, :status, :requested_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':encounter_id' => $entity->encounterId(),
                ':patient_id' => $entity->patientId(),
                ':exam_catalog_item_id' => $entity->examCatalogItemId(),
                ':professional_system_user_id' => $entity->professionalSystemUserId(),
                ':status' => $entity->status(),
                ':requested_at' => self::formatDateTime($entity->requestedAt()),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE exam_request SET status = :status WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':status' => $entity->status(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof ExamRequest) {
            throw new InvalidArgumentException('Expected an ExamRequest entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM exam_request WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @return list<ExamRequest> */
    private function hydrateAll(\PDOStatement $statement): array
    {
        $requests = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $requests[] = $this->hydrate($row);
        }

        return $requests;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ExamRequest
    {
        return ExamRequest::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            encounterId: (int) $row['encounter_id'],
            patientId: (int) $row['patient_id'],
            examCatalogItemId: (int) $row['exam_catalog_item_id'],
            professionalSystemUserId: (int) $row['professional_system_user_id'],
            status: (string) $row['status'],
            requestedAt: new DateTimeImmutable((string) $row['requested_at']),
            updatedAt: $row['updated_at'] !== null ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }

    private static function formatDateTime(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
