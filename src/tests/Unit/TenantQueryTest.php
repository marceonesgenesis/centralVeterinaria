<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Persistence\TenantQuery;
use CentralVet\Tests\Support\Assert;
use InvalidArgumentException;

final class TenantQueryTest
{
    public function testForTenantStartsWithTenantPredicate(): void
    {
        $query = TenantQuery::forTenant(101);

        Assert::same('tenant_id = :tenant_scope_id', $query->whereSql());
        Assert::same([':tenant_scope_id' => 101], $query->parameters());
    }

    public function testForTenantWithAliasPrefixesColumns(): void
    {
        $query = TenantQuery::forTenant(101, 't');

        Assert::same('t.tenant_id = :tenant_scope_id', $query->whereSql());
    }

    public function testForTenantRejectsNonPositiveTenantId(): void
    {
        Assert::throws(InvalidArgumentException::class, static fn () => TenantQuery::forTenant(0));
        Assert::throws(InvalidArgumentException::class, static fn () => TenantQuery::forTenant(-5));
    }

    public function testTenantPredicateCannotBeOverriddenByAndEquals(): void
    {
        $query = TenantQuery::forTenant(101);

        Assert::throws(InvalidArgumentException::class, static fn () => $query->andEquals('tenant_id', 202));
    }

    public function testAndEqualsAccumulatesPredicatesJoinedByAnd(): void
    {
        $query = TenantQuery::forTenant(101)
            ->andEquals('status', 'active')
            ->andEquals('unit_id', 3);

        Assert::same(
            'tenant_id = :tenant_scope_id AND status = :tenant_filter_0 AND unit_id = :tenant_filter_1',
            $query->whereSql(),
        );
        Assert::same([
            ':tenant_scope_id' => 101,
            ':tenant_filter_0' => 'active',
            ':tenant_filter_1' => 3,
        ], $query->parameters());
    }

    public function testAndEqualsIsImmutableAndDoesNotMutateOriginal(): void
    {
        $base = TenantQuery::forTenant(101);
        $extended = $base->andEquals('status', 'active');

        Assert::same('tenant_id = :tenant_scope_id', $base->whereSql());
        Assert::same('tenant_id = :tenant_scope_id AND status = :tenant_filter_0', $extended->whereSql());
    }

    public function testAndEqualsRejectsUnsafeColumnIdentifier(): void
    {
        $query = TenantQuery::forTenant(101);

        Assert::throws(InvalidArgumentException::class, static fn () => $query->andEquals('id; DROP TABLE tenant;--', 1));
        Assert::throws(InvalidArgumentException::class, static fn () => $query->andEquals('id = 1 OR 1=1', 1));
        Assert::throws(InvalidArgumentException::class, static fn () => $query->andEquals('', 1));
    }

    public function testForTenantRejectsUnsafeAlias(): void
    {
        Assert::throws(InvalidArgumentException::class, static fn () => TenantQuery::forTenant(101, 't; DROP TABLE tenant;--'));
    }

    public function testAndEqualsRejectsUnsafeAlias(): void
    {
        $query = TenantQuery::forTenant(101);

        Assert::throws(InvalidArgumentException::class, static fn () => $query->andEquals('status', 'active', 'a OR 1=1'));
    }
}
