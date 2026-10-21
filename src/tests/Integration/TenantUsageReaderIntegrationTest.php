<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Persistence\TenantUsageReader;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Etapa 3, T-15: contagem de usuários (tenant_user invited/active) e de
 * unidades (system_unit) por tenant, sem vazar entre tenants. Tudo é
 * desfeito no rollback do tearDown().
 */
final class TenantUsageReaderIntegrationTest extends MysqlIntegrationTestCase
{
    public function testCountUsersCountsInvitedAndActiveOfTheTenantOnly(): void
    {
        $tenantA = $this->createTenant('t15-a');
        $tenantB = $this->createTenant('t15-b');

        $this->linkUser($tenantA, $this->createUser(), 'active');
        $this->linkUser($tenantA, $this->createUser(), 'invited');
        $this->linkUser($tenantA, $this->createUser(), 'inactive');
        $this->linkUser($tenantB, $this->createUser(), 'active');
        $this->linkUser($tenantB, $this->createUser(), 'active');
        $this->linkUser($tenantB, $this->createUser(), 'invited');

        $reader = new TenantUsageReader($this->pdo);

        Assert::same(2, $reader->countUsers($tenantA));
        Assert::same(3, $reader->countUsers($tenantB));
    }

    public function testCountUnitsCountsOnlyTheTenantUnits(): void
    {
        $tenantA = $this->createTenant('t15-a');
        $tenantB = $this->createTenant('t15-b');
        $empty = $this->createTenant('t15-c');

        $this->createUnit($tenantA);
        $this->createUnit($tenantB);
        $this->createUnit($tenantB);

        $reader = new TenantUsageReader($this->pdo);

        Assert::same(1, $reader->countUnits($tenantA));
        Assert::same(2, $reader->countUnits($tenantB));
        Assert::same(0, $reader->countUnits($empty));
        Assert::same(0, $reader->countUsers($empty));
    }

    private function createTenant(string $prefix): int
    {
        $slug = $prefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            "INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, 'active')",
        );
        $statement->execute(['slug' => $slug, 'name' => 'T-15 usage tenant (' . $slug . ')']);

        return (int) $this->pdo->lastInsertId();
    }

    /** system_users.id não é auto_increment (Adianti). */
    private function createUser(): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_users')->fetchColumn();
        $statement = $this->pdo->prepare(
            "INSERT INTO system_users (id, name, login, active) VALUES (:id, :name, :login, 'Y')",
        );
        $statement->execute(['id' => $id, 'name' => 'T-15 user ' . $id, 'login' => 't15-usage-' . $id]);

        return $id;
    }

    private function linkUser(int $tenantId, int $userId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (:tenant_id, :user_id, :status)',
        );
        $statement->execute(['tenant_id' => $tenantId, 'user_id' => $userId, 'status' => $status]);
    }

    /** system_unit.id não é auto_increment (Adianti). */
    private function createUnit(int $tenantId): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit')->fetchColumn();
        $statement = $this->pdo->prepare(
            'INSERT INTO system_unit (id, tenant_id, name, custom_code) VALUES (:id, :tenant_id, :name, :code)',
        );
        $statement->execute(['id' => $id, 'tenant_id' => $tenantId, 'name' => 'T-15 unit ' . $id, 'code' => 'T15-' . $id]);

        return $id;
    }
}
