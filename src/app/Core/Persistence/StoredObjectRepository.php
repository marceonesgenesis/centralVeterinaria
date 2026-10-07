<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\StoredObjectRepositoryInterface;
use CentralVet\Storage\StoredObjectMetadata;
use CentralVet\Tenancy\TenantContext;
use PDO;
use RuntimeException;

/**
 * PDO-backed index of uploaded objects in `stored_object` (migration 0001),
 * rodada 2, T-52. Every query starts from TenantQuery::forTenant() of the
 * TenantContext's tenant (ADR 0002); the tenant_id written by record() comes
 * from the context, never from the caller. It does not extend
 * AbstractTenantRepository: rows are plain arrays, not entities, so the
 * findById/save/remove contract does not apply.
 */
final class StoredObjectRepository implements StoredObjectRepositoryInterface
{
    private const STATUS_AVAILABLE = 'available';

    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function record(StoredObjectMetadata $metadata, string $originalName, ?int $systemUnitId, int $createdBy): array
    {
        $publicId = self::uuidV4();
        $row = $metadata->toStoredObjectRow();

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO stored_object (
                public_id, tenant_id, system_unit_id, storage_provider, bucket, object_key, version_id,
                original_name, content_type, size_bytes, sha256, status, created_by
            ) VALUES (
                :public_id, :tenant_id, :system_unit_id, :storage_provider, :bucket, :object_key, :version_id,
                :original_name, :content_type, :size_bytes, :sha256, :status, :created_by
            )
            SQL
        );
        $statement->execute([
            ':public_id' => $publicId,
            ':tenant_id' => $this->context->tenantId(),
            ':system_unit_id' => $systemUnitId,
            ':storage_provider' => $row['storage_provider'],
            ':bucket' => $row['bucket'],
            ':object_key' => $row['object_key'],
            ':version_id' => $row['version_id'],
            ':original_name' => $originalName,
            ':content_type' => $row['content_type'],
            ':size_bytes' => $row['size_bytes'],
            ':sha256' => $row['sha256'],
            ':status' => self::STATUS_AVAILABLE,
            ':created_by' => $createdBy,
        ]);

        $saved = $this->findByPublicId($publicId);

        if ($saved === null) {
            throw new RuntimeException('Stored object was not found after insert');
        }

        return $saved;
    }

    public function listByObjectKeyFragment(string $fragment): array
    {
        $query = TenantQuery::forTenant($this->context->tenantId())->andEquals('status', self::STATUS_AVAILABLE);

        $statement = $this->connection->prepare(
            'SELECT public_id, original_name, content_type, size_bytes, created_at, object_key FROM stored_object '
            . "WHERE {$query->whereSql()} AND deleted_at IS NULL AND object_key LIKE CONCAT('%', :fragment, '%') "
            . 'ORDER BY created_at DESC, id DESC'
        );
        $statement->execute([...$query->parameters(), ':fragment' => self::escapeLike($fragment)]);

        return array_map(static fn (array $row): array => [
            'public_id' => (string) $row['public_id'],
            'original_name' => (string) $row['original_name'],
            'content_type' => (string) $row['content_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'created_at' => (string) $row['created_at'],
            'object_key' => (string) $row['object_key'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Only an available, not soft-deleted row of the tenant (rodada 3, T-03). */
    public function findByPublicId(string $publicId): ?array
    {
        $query = TenantQuery::forTenant($this->context->tenantId())
            ->andEquals('public_id', $publicId)
            ->andEquals('status', self::STATUS_AVAILABLE);

        $statement = $this->connection->prepare("SELECT * FROM stored_object WHERE {$query->whereSql()} AND deleted_at IS NULL LIMIT 1");
        $statement->execute($query->parameters());
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** LIKE metacharacters in the fragment match literally (default escape "\"). */
    private static function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
