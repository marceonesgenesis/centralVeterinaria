<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\GeneratedDocumentRepositoryInterface;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * PDO-backed persistence for generated documents (`generated_document`,
 * migration 20261006_0013_phase7b_documents). Every query starts from
 * TenantQuery::forTenant() with the tenant of the context, never from input
 * (ADR 0002).
 *
 * The version is allocated by one `INSERT ... SELECT COALESCE(MAX(version),
 * 0) + 1` (a locking read, so a retry sees the rows other transactions
 * committed); a duplicate key 1062 on `generated_document_version_uq` means
 * a concurrent request took the same version and the insert is retried, at
 * most 3 times. Never the IGNORE modifier, which on MySQL 8 turns CHECK
 * violations into warnings. After the insert a document only moves through
 * conditional transitions: one `UPDATE ... WHERE id AND tenant_id AND
 * status = <expected>` per method, whose `rowCount() === 1` is the answer.
 *
 * It does not extend AbstractTenantRepository: the T-02 contract declares
 * `findById(int)` and no generic save/remove.
 */
final class GeneratedDocumentRepository implements GeneratedDocumentRepositoryInterface
{
    /** A claim older than this is considered abandoned (worker died mid-job). */
    private const CLAIM_TTL = '-10 minutes';

    private const DUPLICATE_KEY = 1062;

    private const VERSION_RETRIES = 3;

    private const VERSION_UNIQUE_KEY = 'generated_document_version_uq';

    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function insertNextVersion(GeneratedDocument $document): GeneratedDocument
    {
        if ($document->id() !== null) {
            throw new InvalidArgumentException('Generated document already has an id');
        }

        $this->context->assertTenant($document->tenantId());

        $match = TenantQuery::forTenant($this->context->tenantId(), 'existing')
            ->andEquals('kind', $document->kind(), 'existing')
            ->andEquals('source_type', $document->sourceType(), 'existing')
            ->andEquals('source_id', $document->sourceId(), 'existing');

        $statement = $this->connection->prepare(
            <<<SQL
            INSERT INTO generated_document (
                tenant_id, system_unit_id, patient_id, tutor_id, kind, source_type, source_id,
                version, template_id, title, body_text, notify_tutor, status, attempt_count,
                requested_by_system_user_id
            )
            SELECT
                :tenant_id, :system_unit_id, :patient_id, :tutor_id, :kind, :source_type, :source_id,
                COALESCE(MAX(existing.version), 0) + 1, :template_id, :title, :body_text, :notify_tutor,
                :status, 0, :requested_by_system_user_id
            FROM generated_document existing
            WHERE {$match->whereSql()}
            SQL
        );

        $parameters = [
            ...$match->parameters(),
            ':tenant_id' => $this->context->tenantId(),
            ':system_unit_id' => $document->systemUnitId(),
            ':patient_id' => $document->patientId(),
            ':tutor_id' => $document->tutorId(),
            ':kind' => $document->kind(),
            ':source_type' => $document->sourceType(),
            ':source_id' => $document->sourceId(),
            ':template_id' => $document->templateId(),
            ':title' => $document->title(),
            ':body_text' => $document->bodyText(),
            ':notify_tutor' => $document->notifyTutor() ? 1 : 0,
            ':status' => GeneratedDocument::STATUS_QUEUED,
            ':requested_by_system_user_id' => $document->requestedBySystemUserId(),
        ];

        for ($attempt = 0; $attempt <= self::VERSION_RETRIES; $attempt++) {
            try {
                $statement->execute($parameters);
            } catch (PDOException $exception) {
                if (self::isVersionCollision($exception)) {
                    continue;
                }

                throw $exception;
            }

            $id = (int) $this->connection->lastInsertId();
            $document->assignIdentity($id, $this->versionOf($id));

            return $document;
        }

        throw new RuntimeException('Could not allocate document version');
    }

    public function findById(int $id): ?GeneratedDocument
    {
        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("SELECT * FROM generated_document WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : GeneratedDocument::reconstitute($row);
    }

    public function claim(int $id, DateTimeImmutable $now): bool
    {
        return $this->conditionalUpdate(
            $id,
            GeneratedDocument::STATUS_QUEUED,
            'claimed_at = :claimed_at, attempt_count = attempt_count + 1',
            [':claimed_at' => self::timestamp($now)],
            ' AND (claimed_at IS NULL OR claimed_at < :stale_before)',
            [':stale_before' => self::timestamp($now->modify(self::CLAIM_TTL))],
        )->rowCount() === 1;
    }

    public function markReady(int $id, int $storedObjectId, string $storageKey, int $sizeBytes, string $sha256, DateTimeImmutable $readyAt): bool
    {
        return $this->conditionalUpdate(
            $id,
            GeneratedDocument::STATUS_QUEUED,
            'status = :new_status, stored_object_id = :stored_object_id, storage_key = :storage_key, '
            . 'size_bytes = :size_bytes, sha256 = :sha256, ready_at = :ready_at, last_error_code = NULL',
            [
                ':new_status' => GeneratedDocument::STATUS_READY,
                ':stored_object_id' => $storedObjectId,
                ':storage_key' => $storageKey,
                ':size_bytes' => $sizeBytes,
                ':sha256' => $sha256,
                ':ready_at' => self::timestamp($readyAt),
            ],
            ' AND claimed_at IS NOT NULL',
        )->rowCount() === 1;
    }

    public function releaseClaim(int $id, string $errorCode): bool
    {
        return $this->conditionalUpdate(
            $id,
            GeneratedDocument::STATUS_QUEUED,
            'claimed_at = NULL, last_error_code = :error_code',
            [':error_code' => $errorCode],
        )->rowCount() === 1;
    }

    public function markFailed(int $id, string $errorCode, DateTimeImmutable $failedAt): bool
    {
        return $this->conditionalUpdate(
            $id,
            GeneratedDocument::STATUS_QUEUED,
            'status = :new_status, last_error_code = :error_code, failed_at = :failed_at, claimed_at = NULL',
            [
                ':new_status' => GeneratedDocument::STATUS_FAILED,
                ':error_code' => $errorCode,
                ':failed_at' => self::timestamp($failedAt),
            ],
        )->rowCount() === 1;
    }

    public function requeueFailed(int $id): bool
    {
        return $this->conditionalUpdate(
            $id,
            GeneratedDocument::STATUS_FAILED,
            'status = :new_status, failed_at = NULL, claimed_at = NULL',
            [':new_status' => GeneratedDocument::STATUS_QUEUED],
        )->rowCount() === 1;
    }

    public function markNotified(int $id, DateTimeImmutable $notifiedAt): bool
    {
        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare(
            "UPDATE generated_document SET notified_at = :notified_at WHERE {$query->whereSql()} AND notified_at IS NULL",
        );
        $statement->execute([...$query->parameters(), ':notified_at' => self::timestamp($notifiedAt)]);

        return $statement->rowCount() === 1;
    }

    public function listForUnit(int $systemUnitId, ?int $patientId, int $limit): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);

        if ($patientId !== null) {
            $query = $query->andEquals('patient_id', $patientId);
        }

        $limit = max(1, $limit);

        $statement = $this->connection->prepare(
            "SELECT * FROM generated_document WHERE {$query->whereSql()} ORDER BY created_at DESC, id DESC LIMIT {$limit}",
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): GeneratedDocument => GeneratedDocument::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function listStaleQueuedIds(DateTimeImmutable $olderThan, int $limit): array
    {
        $query = $this->tenantQuery()->andEquals('status', GeneratedDocument::STATUS_QUEUED);
        $limit = max(1, $limit);

        $statement = $this->connection->prepare(
            <<<SQL
            SELECT id FROM generated_document
            WHERE {$query->whereSql()}
              AND (
                (claimed_at IS NULL AND created_at < :created_before)
                OR claimed_at < :claimed_before
              )
            ORDER BY created_at ASC, id ASC
            LIMIT {$limit}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':created_before' => self::timestamp($olderThan),
            ':claimed_before' => self::timestamp($olderThan),
        ]);

        return array_map(static fn (mixed $id): int => (int) $id, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function tenantQuery(): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId());
    }

    private function versionOf(int $id): int
    {
        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("SELECT version FROM generated_document WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());

        return (int) $statement->fetchColumn();
    }

    /**
     * One conditional UPDATE on the tenant's row with the expected status
     * (and `$extraWhere`). Each named parameter appears once, so it also
     * works with native prepares.
     *
     * @param array<string, int|string|null> $setParameters
     * @param array<string, int|string|null> $extraParameters
     */
    private function conditionalUpdate(
        int $id,
        string $expectedStatus,
        string $set,
        array $setParameters,
        string $extraWhere = '',
        array $extraParameters = [],
    ): PDOStatement {
        $query = $this->tenantQuery()->andEquals('id', $id)->andEquals('status', $expectedStatus);

        $statement = $this->connection->prepare(
            "UPDATE generated_document SET {$set} WHERE {$query->whereSql()}{$extraWhere}",
        );
        $statement->execute([...$query->parameters(), ...$setParameters, ...$extraParameters]);

        return $statement;
    }

    /** Only the version UNIQUE is retried; every other violation (CHECK, FK, other UNIQUE) surfaces. */
    private static function isVersionCollision(PDOException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === self::DUPLICATE_KEY
            && str_contains((string) ($exception->errorInfo[2] ?? $exception->getMessage()), self::VERSION_UNIQUE_KEY);
    }

    private static function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
