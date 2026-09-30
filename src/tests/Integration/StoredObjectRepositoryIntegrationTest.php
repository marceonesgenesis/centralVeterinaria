<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Persistence\StoredObjectRepository;
use CentralVet\Storage\StoredObjectMetadata;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * StoredObjectRepository against the real `stored_object` table (migration
 * 0001), rodada 2, T-52. Every row belongs to throwaway tenants created
 * inside the test's transaction, rolled back in tearDown().
 */
final class StoredObjectRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $unitId;
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('r2-object-a');
        $this->tenantB = $this->createTenant('r2-object-b');
    }

    public function testRecordWritesAvailableRowWithUuidAndListFindsIt(): void
    {
        $repository = $this->repositoryFor($this->tenantA);
        $key = sprintf('cv/test/tenant/%1$d/objects/tenant/%1$d/encounter/10/r2.pdf', $this->tenantA);

        $row = $repository->record($this->metadata($key, 'application/pdf', 1234), 'r2.pdf', $this->unitId, $this->userId);

        Assert::true(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $row['public_id']) === 1, 'public_id is a UUID v4');
        Assert::same($this->tenantA, (int) $row['tenant_id']);
        Assert::same($this->unitId, (int) $row['system_unit_id']);
        Assert::same('available', $row['status']);
        Assert::same($key, $row['object_key']);
        Assert::same('r2.pdf', $row['original_name']);
        Assert::same($this->userId, (int) $row['created_by']);

        $listed = $repository->listByObjectKeyFragment(sprintf('tenant/%d/encounter/10/', $this->tenantA));
        Assert::count(1, $listed);
        Assert::same($row['public_id'], $listed[0]['public_id']);
        Assert::same('r2.pdf', $listed[0]['original_name']);
        Assert::same('application/pdf', $listed[0]['content_type']);
        Assert::same(1234, $listed[0]['size_bytes']);
        Assert::same($key, $listed[0]['object_key']);
        Assert::true($listed[0]['created_at'] !== '', 'created_at is filled');

        Assert::same([], $repository->listByObjectKeyFragment(sprintf('tenant/%d/encounter/1/', $this->tenantA)));
        Assert::same($row['public_id'], $repository->findByPublicId((string) $row['public_id'])['public_id'] ?? null);
    }

    public function testOtherTenantSeesNothing(): void
    {
        $key = sprintf('tenant/%d/encounter/10/r2.pdf', $this->tenantA);
        $row = $this->repositoryFor($this->tenantA)->record($this->metadata($key, 'application/pdf', 10), 'r2.pdf', null, $this->userId);

        $other = $this->repositoryFor($this->tenantB);
        Assert::same([], $other->listByObjectKeyFragment(sprintf('tenant/%d/encounter/10/', $this->tenantA)));
        Assert::same([], $other->listByObjectKeyFragment('encounter/10/'));
        Assert::null($other->findByPublicId((string) $row['public_id']));
    }

    public function testListSkipsDeletedAndUnavailableAndOrdersNewestFirst(): void
    {
        $repository = $this->repositoryFor($this->tenantA);
        $fragment = sprintf('tenant/%d/encounter/20/', $this->tenantA);

        $first = $repository->record($this->metadata($fragment . 'a.pdf', 'application/pdf', 1), 'a.pdf', null, $this->userId);
        $second = $repository->record($this->metadata($fragment . 'b.pdf', 'application/pdf', 2), 'b.pdf', null, $this->userId);
        $deleted = $repository->record($this->metadata($fragment . 'c.pdf', 'application/pdf', 3), 'c.pdf', null, $this->userId);
        $pending = $repository->record($this->metadata($fragment . 'd.pdf', 'application/pdf', 4), 'd.pdf', null, $this->userId);

        $this->pdo->prepare('UPDATE stored_object SET deleted_at = CURRENT_TIMESTAMP(6) WHERE public_id = ?')->execute([$deleted['public_id']]);
        $this->pdo->prepare("UPDATE stored_object SET status = 'pending' WHERE public_id = ?")->execute([$pending['public_id']]);
        // same created_at: the id DESC tiebreak decides
        $this->pdo->prepare("UPDATE stored_object SET created_at = '2031-01-01 00:00:00' WHERE public_id IN (?, ?)")
            ->execute([$first['public_id'], $second['public_id']]);

        $names = array_column($repository->listByObjectKeyFragment($fragment), 'original_name');
        Assert::same(['b.pdf', 'a.pdf'], $names);
    }

    private function metadata(string $key, string $contentType, int $size): StoredObjectMetadata
    {
        return new StoredObjectMetadata('s3', 'r2-test-bucket', $key, null, $contentType, $size, hash('sha256', $key));
    }

    private function repositoryFor(int $tenantId): StoredObjectRepository
    {
        return new StoredObjectRepository(TenantContext::authenticated($tenantId, $this->userId, null), $this->pdo);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'R2 test tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
