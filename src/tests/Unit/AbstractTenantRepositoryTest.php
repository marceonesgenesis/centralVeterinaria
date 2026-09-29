<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Persistence\AbstractTenantRepository;
use CentralVet\Persistence\TenantQuery;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;

final class AbstractTenantRepositoryTest
{
    public function testTenantIdDelegatesToContext(): void
    {
        $repository = $this->makeRepository(101);

        Assert::same(101, $repository->tenantId());
    }

    public function testTenantQueryIsScopedToTheRepositoryTenant(): void
    {
        $repository = $this->makeRepository(101);

        $query = $repository->exposeTenantQuery();

        Assert::instanceOf(TenantQuery::class, $query);
        Assert::same('tenant_id = :tenant_scope_id', $query->whereSql());
        Assert::same([':tenant_scope_id' => 101], $query->parameters());
    }

    public function testTenantQueryHonoursAlias(): void
    {
        $repository = $this->makeRepository(202);

        $query = $repository->exposeTenantQuery('p');

        Assert::same('p.tenant_id = :tenant_scope_id', $query->whereSql());
    }

    public function testAssertEntityTenantPassesForOwnedEntity(): void
    {
        $repository = $this->makeRepository(101);

        $repository->exposeAssertEntityTenant(101);
        Assert::true(true);
    }

    public function testAssertEntityTenantRejectsForeignEntity(): void
    {
        $repository = $this->makeRepository(101);

        Assert::throws(TenantBoundaryViolation::class, static fn () => $repository->exposeAssertEntityTenant(202));
    }

    private function makeRepository(int $tenantId): FakeTenantRepositoryForTest
    {
        return new FakeTenantRepositoryForTest(TenantContext::authenticated($tenantId, 1));
    }
}

/**
 * Minimal concrete subclass exposing AbstractTenantRepository's protected
 * helpers for assertion, since the class under test is abstract by design.
 */
final class FakeTenantRepositoryForTest extends AbstractTenantRepository
{
    public function findById(int|string $id): ?object
    {
        return null;
    }

    public function save(object $entity): object
    {
        return $entity;
    }

    public function remove(object $entity): void
    {
    }

    public function exposeTenantQuery(?string $alias = null): TenantQuery
    {
        return $this->tenantQuery($alias);
    }

    public function exposeAssertEntityTenant(int $tenantId): void
    {
        $this->assertEntityTenant($tenantId);
    }
}
