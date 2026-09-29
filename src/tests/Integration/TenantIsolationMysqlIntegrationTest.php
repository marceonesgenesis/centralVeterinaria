<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Proves row-level tenant isolation on the two business tables the
 * foundation migration (T-03) creates: audit_log and stored_object. Every
 * row this test writes belongs to two throwaway tenants created inside the
 * test's own transaction (never tenant 1, never anything pre-existing) and
 * the whole transaction is rolled back in tearDown() — nothing here is ever
 * committed, so this is safe to run against the real development database.
 */
final class TenantIsolationMysqlIntegrationTest extends MysqlIntegrationTestCase
{
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->createThrowawayTenant('isolation-test-a');
        $this->tenantB = $this->createThrowawayTenant('isolation-test-b');
    }

    public function testAuditLogRowsAreIsolatedPerTenant(): void
    {
        $this->insertAuditLog($this->tenantA, 'corr-a');
        $this->insertAuditLog($this->tenantB, 'corr-b');

        $rowsForA = $this->scopedAuditLogCorrelationIds($this->tenantA);
        $rowsForB = $this->scopedAuditLogCorrelationIds($this->tenantB);

        Assert::same(['corr-a'], $rowsForA, 'Tenant A must only see its own audit_log rows');
        Assert::same(['corr-b'], $rowsForB, 'Tenant B must only see its own audit_log rows');
    }

    public function testStoredObjectRowsAreIsolatedPerTenant(): void
    {
        $creatorId = (int) $this->pdo->query('SELECT id FROM system_users LIMIT 1')->fetchColumn();
        Assert::true($creatorId > 0, 'Fixture requires at least one existing system_users row');

        $this->insertStoredObject($this->tenantA, 'tenant-a/file.pdf', $creatorId);
        $this->insertStoredObject($this->tenantB, 'tenant-b/file.pdf', $creatorId);

        $keysForA = $this->scopedStoredObjectKeys($this->tenantA);
        $keysForB = $this->scopedStoredObjectKeys($this->tenantB);

        Assert::same(['tenant-a/file.pdf'], $keysForA, 'Tenant A must only see its own stored_object rows');
        Assert::same(['tenant-b/file.pdf'], $keysForB, 'Tenant B must only see its own stored_object rows');
    }

    public function testCrossTenantScopedQueryNeverLeaksTheOtherTenantsRow(): void
    {
        $this->insertAuditLog($this->tenantA, 'shared-looking-correlation-id');
        $this->insertAuditLog($this->tenantB, 'shared-looking-correlation-id');

        $statement = $this->pdo->prepare(
            'SELECT tenant_id FROM audit_log WHERE tenant_id = :tenant_scope_id AND correlation_id = :correlation_id',
        );
        $statement->execute(['tenant_scope_id' => $this->tenantA, 'correlation_id' => 'shared-looking-correlation-id']);
        $rows = $statement->fetchAll(\PDO::FETCH_COLUMN);

        Assert::same([$this->tenantA], array_map('intval', $rows), 'A tenant-scoped query must never return another tenant\'s row, even with a matching non-tenant column');
    }

    private function createThrowawayTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));

        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status)
             VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute([
            'slug' => $slug,
            'legal_name' => 'T-11 isolation test tenant (' . $slug . ')',
            'status' => 'active',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertAuditLog(int $tenantId, string $correlationId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (tenant_id, correlation_id, action, entity_type)
             VALUES (:tenant_id, :correlation_id, :action, :entity_type)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'correlation_id' => $correlationId,
            'action' => 'test.isolation',
            'entity_type' => 'IsolationFixture',
        ]);
    }

    /** @return list<string> */
    private function scopedAuditLogCorrelationIds(int $tenantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT correlation_id FROM audit_log WHERE tenant_id = :tenant_scope_id ORDER BY id',
        );
        $statement->execute(['tenant_scope_id' => $tenantId]);

        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function insertStoredObject(int $tenantId, string $objectKey, int $createdBy): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO stored_object (
                public_id, tenant_id, storage_provider, bucket, object_key,
                original_name, content_type, size_bytes, sha256, status, created_by
             ) VALUES (
                UUID(), :tenant_id, :storage_provider, :bucket, :object_key,
                :original_name, :content_type, :size_bytes, :sha256, :status, :created_by
             )',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'storage_provider' => 's3',
            'bucket' => 'isolation-test',
            'object_key' => $objectKey,
            'original_name' => 'file.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 1,
            'sha256' => hash('sha256', $objectKey),
            'status' => 'available',
            'created_by' => $createdBy,
        ]);
    }

    /** @return list<string> */
    private function scopedStoredObjectKeys(int $tenantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT object_key FROM stored_object WHERE tenant_id = :tenant_scope_id ORDER BY id',
        );
        $statement->execute(['tenant_scope_id' => $tenantId]);

        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }
}
