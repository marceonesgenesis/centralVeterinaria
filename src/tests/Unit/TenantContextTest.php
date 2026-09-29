<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tenancy\Exception\MissingTenantContext;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeSessionContextSource;

final class TenantContextTest
{
    public function testFromAuthenticatedSessionBuildsContextFromSessionData(): void
    {
        $session = new FakeSessionContextSource([
            'logged' => true,
            'tenantid' => '101',
            'userid' => '7',
            'userunitid' => '3',
        ]);

        $context = TenantContext::fromAuthenticatedSession($session);

        Assert::same(101, $context->tenantId());
        Assert::same(7, $context->userId());
        Assert::same(3, $context->unitId());
    }

    public function testFromAuthenticatedSessionAllowsMissingOptionalUnit(): void
    {
        $session = new FakeSessionContextSource([
            'logged' => true,
            'tenantid' => 101,
            'userid' => 7,
            'userunitid' => null,
        ]);

        $context = TenantContext::fromAuthenticatedSession($session);

        Assert::null($context->unitId());
    }

    public function testFromAuthenticatedSessionFailsClosedWhenNotLogged(): void
    {
        $session = new FakeSessionContextSource([
            'logged' => false,
            'tenantid' => 101,
            'userid' => 7,
        ]);

        Assert::throws(MissingTenantContext::class, static fn () => TenantContext::fromAuthenticatedSession($session));
    }

    public function testFromAuthenticatedSessionFailsClosedWhenLoggedFlagAbsent(): void
    {
        // No 'logged' key at all: FakeSessionContextSource::get() returns
        // null, which must never be treated as an authenticated session.
        $session = new FakeSessionContextSource([
            'tenantid' => 101,
            'userid' => 7,
        ]);

        Assert::throws(MissingTenantContext::class, static fn () => TenantContext::fromAuthenticatedSession($session));
    }

    public function testFromAuthenticatedSessionFailsClosedOnInvalidTenantId(): void
    {
        $session = new FakeSessionContextSource([
            'logged' => true,
            'tenantid' => 'not-a-number',
            'userid' => 7,
        ]);

        Assert::throws(MissingTenantContext::class, static fn () => TenantContext::fromAuthenticatedSession($session));
    }

    public function testFromAuthenticatedSessionFailsClosedOnZeroOrNegativeIds(): void
    {
        $session = new FakeSessionContextSource([
            'logged' => true,
            'tenantid' => 0,
            'userid' => 7,
        ]);

        Assert::throws(MissingTenantContext::class, static fn () => TenantContext::fromAuthenticatedSession($session));
    }

    public function testAuthenticatedRejectsNonPositiveIdentifiers(): void
    {
        Assert::throws(MissingTenantContext::class, static fn () => TenantContext::authenticated(0, 1));
        Assert::throws(MissingTenantContext::class, static fn () => TenantContext::authenticated(1, 0));
        Assert::throws(MissingTenantContext::class, static fn () => TenantContext::authenticated(1, 1, 0));
        Assert::throws(MissingTenantContext::class, static fn () => TenantContext::authenticated(-1, 1));
    }

    public function testRequireUnitIdReturnsActiveUnit(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);

        Assert::same(5, $context->requireUnitId());
    }

    public function testRequireUnitIdFailsClosedWhenNoActiveUnit(): void
    {
        $context = TenantContext::authenticated(101, 1);

        Assert::throws(MissingTenantContext::class, static fn () => $context->requireUnitId());
    }

    public function testAssertTenantPassesForMatchingTenant(): void
    {
        $context = TenantContext::authenticated(101, 1);

        // Must not throw.
        $context->assertTenant(101);
        Assert::true(true);
    }

    public function testAssertTenantThrowsBoundaryViolationForForeignTenant(): void
    {
        $context = TenantContext::authenticated(101, 1);

        Assert::throws(TenantBoundaryViolation::class, static fn () => $context->assertTenant(202));
    }
}
