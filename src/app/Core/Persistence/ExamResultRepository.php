<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ExamResultRepositoryInterface;
use CentralVet\Domain\ExamResult;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the ExamResult aggregate. Every query starts
 * from TenantQuery::forTenant() (ADR 0002); tenant scoping is never accepted
 * from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `exam_result` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval and has
 * actually been applied.
 *
 * @implements ExamResultRepositoryInterface<ExamResult>
 */
final class ExamResultRepository extends AbstractTenantRepository implements ExamResultRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM exam_result WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByExamRequest(int $examRequestId): ?object
    {
        $query = $this->tenantQuery()->andEquals('exam_request_id', $examRequestId);

        $statement = $this->connection->prepare(
            "SELECT * FROM exam_result WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * 'pending_review = 1' is a fixed literal appended to the WHERE clause,
     * not caller input, so it is safe outside TenantQuery's parameter
     * binding (same convention as QueueEntryRepository::listActiveByUnit()).
     */
    public function listPendingReview(): array
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare(
            "SELECT * FROM exam_result WHERE {$query->whereSql()} "
            . 'AND pending_review = 1 ORDER BY received_at ASC'
        );
        $statement->execute($query->parameters());

        $results = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = $this->hydrate($row);
        }

        return $results;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ExamResult) {
            throw new InvalidArgumentException('Expected an ExamResult entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO exam_result (
                    tenant_id, exam_request_id, structured_result_text,
                    stored_object_key, pending_review, received_at
                ) VALUES (
                    :tenant_id, :exam_request_id, :structured_result_text,
                    :stored_object_key, :pending_review, :received_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':exam_request_id' => $entity->examRequestId(),
                ':structured_result_text' => $entity->structuredResultText(),
                ':stored_object_key' => $entity->storedObjectKey(),
                ':pending_review' => $entity->pendingReview() ? 1 : 0,
                ':received_at' => self::formatDateTime($entity->receivedAt()),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE exam_result SET pending_review = :pending_review WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':pending_review' => $entity->pendingReview() ? 1 : 0,
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof ExamResult) {
            throw new InvalidArgumentException('Expected an ExamResult entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM exam_result WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ExamResult
    {
        return ExamResult::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            examRequestId: (int) $row['exam_request_id'],
            structuredResultText: $row['structured_result_text'] !== null
                ? (string) $row['structured_result_text']
                : null,
            storedObjectKey: $row['stored_object_key'] !== null ? (string) $row['stored_object_key'] : null,
            pendingReview: (bool) $row['pending_review'],
            receivedAt: new DateTimeImmutable((string) $row['received_at']),
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }

    private static function formatDateTime(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
