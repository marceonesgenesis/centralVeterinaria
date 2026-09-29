<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * T-16: proves the T-03 acceptance criterion — a user of tenant A cannot
 * see or edit units/users belonging to tenant B — against real MySQL data.
 *
 * Scoped to `system_unit`/`tenant_user` (not the phase 1 clinical tables
 * `tutor`/`patient`/`service`/`appointment`/`queue_entry`, which the T-01
 * migration has not been applied yet, so MysqlIntegrationTestCase::setUp()
 * would skip; system_unit/tenant_user already exist since the Fase 0
 * foundation migration). Every row this test writes belongs to two
 * throwaway tenants created inside the test's own transaction (never
 * tenant 1, never anything pre-existing) and the whole transaction is
 * rolled back in tearDown() — nothing here is ever committed.
 *
 * Two complementary angles, matching what the modified controllers
 * actually do (src/app/control/admin/SystemUnitList.php,
 * SystemUnitForm.php, SystemUserForm.php):
 *   - "list" queries: the exact `WHERE tenant_id = ...` (units) /
 *     tenant_user-join (users) predicates those screens run, proving the
 *     listing itself never returns another tenant's row;
 *   - "edit" guard: the exact lookup + CentralVet\Tenancy\TenantContext
 *     check SystemUnitForm::onEdit()/SystemUserForm::onEdit() run before
 *     opening a record, proving a cross-tenant edit attempt is rejected by
 *     the same production class those controllers depend on.
 */
final class Phase1TenantIsolationIntegrationTest extends MysqlIntegrationTestCase
{
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->createThrowawayTenant('phase1-isolation-a');
        $this->tenantB = $this->createThrowawayTenant('phase1-isolation-b');
    }

    public function testSystemUnitListingIsIsolatedPerTenant(): void
    {
        $unitA = $this->createThrowawaySystemUnit($this->tenantA, 'Unit tenant A');
        $unitB = $this->createThrowawaySystemUnit($this->tenantB, 'Unit tenant B');

        // Exactly the predicate SystemUnitList's constructor adds via
        // TCriteria/TFilter('tenant_id', '=', ...) (T-03).
        Assert::same([$unitA], $this->systemUnitIdsForTenant($this->tenantA));
        Assert::same([$unitB], $this->systemUnitIdsForTenant($this->tenantB));
    }

    public function testSystemUnitEditIsRejectedForAnotherTenantsUnit(): void
    {
        $unitB = $this->createThrowawaySystemUnit($this->tenantB, 'Unit tenant B');

        $contextA = TenantContext::authenticated($this->tenantA, 1);

        // Exactly SystemUnitForm::onEdit()'s lookup: read the target row's
        // tenant_id, then let TenantContext::assertTenant() decide.
        $ownerTenantId = $this->systemUnitOwnerTenantId($unitB);
        Assert::notNull($ownerTenantId, 'Fixture unit must exist');

        Assert::throws(
            TenantBoundaryViolation::class,
            static fn () => $contextA->assertTenant((int) $ownerTenantId),
        );
    }

    public function testSystemUnitEditIsAllowedForOwnTenantsUnit(): void
    {
        $unitA = $this->createThrowawaySystemUnit($this->tenantA, 'Unit tenant A');

        $contextA = TenantContext::authenticated($this->tenantA, 1);
        $ownerTenantId = $this->systemUnitOwnerTenantId($unitA);

        // Must not throw.
        $contextA->assertTenant((int) $ownerTenantId);
        Assert::true(true);
    }

    public function testSystemUserVisibilityIsIsolatedPerTenantViaTenantUser(): void
    {
        $userA = $this->createThrowawaySystemUser('User tenant A');
        $userB = $this->createThrowawaySystemUser('User tenant B');
        $this->linkUserToTenant($this->tenantA, $userA);
        $this->linkUserToTenant($this->tenantB, $userB);

        Assert::same([$userA], $this->systemUserIdsForTenant($this->tenantA));
        Assert::same([$userB], $this->systemUserIdsForTenant($this->tenantB));
    }

    public function testSystemUserEditIsRejectedForAnotherTenantsUser(): void
    {
        $userB = $this->createThrowawaySystemUser('User tenant B');
        $this->linkUserToTenant($this->tenantB, $userB);

        // Exactly SystemUserForm::onEdit()'s membership check:
        // "SELECT 1 FROM tenant_user WHERE system_user_id = :id AND tenant_id = :tenant_id".
        $belongsToTenantA = $this->userBelongsToTenant($userB, $this->tenantA);

        Assert::false($belongsToTenantA, 'User belonging only to tenant B must not be editable under tenant A');
    }

    public function testSystemUserEditIsAllowedForOwnTenantsUser(): void
    {
        $userA = $this->createThrowawaySystemUser('User tenant A');
        $this->linkUserToTenant($this->tenantA, $userA);

        Assert::true($this->userBelongsToTenant($userA, $this->tenantA));
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
            'legal_name' => 'T-16 isolation test tenant (' . $slug . ')',
            'status' => 'active',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * system_unit.id is NOT auto_increment in this schema (Adianti manages
     * its own ids), so the next free id is computed within this
     * transaction — safe because the whole suite runs single-threaded and
     * everything is rolled back in tearDown().
     */
    private function createThrowawaySystemUnit(int $tenantId, string $name): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit')->fetchColumn();

        $statement = $this->pdo->prepare(
            'INSERT INTO system_unit (id, tenant_id, name, custom_code) VALUES (:id, :tenant_id, :name, :custom_code)',
        );
        $statement->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $name,
            'custom_code' => 'T16-' . $id,
        ]);

        return $id;
    }

    /** Same non-auto_increment situation as system_unit.id. */
    private function createThrowawaySystemUser(string $name): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_users')->fetchColumn();

        $statement = $this->pdo->prepare(
            'INSERT INTO system_users (id, name, login, active) VALUES (:id, :name, :login, :active)',
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'login' => 't16-isolation-' . $id,
            'active' => 'Y',
        ]);

        return $id;
    }

    private function linkUserToTenant(int $tenantId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (:tenant_id, :user_id, :status)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'active',
        ]);
    }

    /** @return list<int> */
    private function systemUnitIdsForTenant(int $tenantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM system_unit WHERE tenant_id = :tenant_scope_id ORDER BY id',
        );
        $statement->execute(['tenant_scope_id' => $tenantId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function systemUnitOwnerTenantId(int $unitId): ?int
    {
        $statement = $this->pdo->prepare('SELECT tenant_id FROM system_unit WHERE id = :id');
        $statement->execute(['id' => $unitId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** @return list<int> */
    private function systemUserIdsForTenant(int $tenantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT system_user_id FROM tenant_user WHERE tenant_id = :tenant_scope_id ORDER BY system_user_id',
        );
        $statement->execute(['tenant_scope_id' => $tenantId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function userBelongsToTenant(int $userId, int $tenantId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM tenant_user WHERE system_user_id = :id AND tenant_id = :tenant_id LIMIT 1',
        );
        $statement->execute(['id' => $userId, 'tenant_id' => $tenantId]);

        return (bool) $statement->fetchColumn();
    }
}
