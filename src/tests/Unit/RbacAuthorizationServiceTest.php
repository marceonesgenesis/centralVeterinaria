<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Authorization\RbacAuthorizationService;
use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakePermissionProvider;
use CentralVet\Tests\Support\SpyAuditLogWriter;

final class RbacAuthorizationServiceTest
{
    public function setUp(): void
    {
        CorrelationIdContext::clear();
    }

    public function tearDown(): void
    {
        CorrelationIdContext::clear();
    }

    public function testGrantsWhenPermissionProviderAllows(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);
        $audit = new SpyAuditLogWriter();
        $service = new RbacAuthorizationService(new FakePermissionProvider(['patient.view' => true]), $audit);

        $decision = $service->decide(new AuthorizationRequest($context, 'patient.view'));

        Assert::true($decision->allowed());
        Assert::same('granted', $decision->reason());
        Assert::count(1, $audit->events);
        Assert::same(true, $audit->events[0]->metadata['allowed']);
    }

    public function testDeniesWhenPermissionProviderRefuses(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);
        $audit = new SpyAuditLogWriter();
        $service = new RbacAuthorizationService(new FakePermissionProvider(['patient.view' => false]), $audit);

        $decision = $service->decide(new AuthorizationRequest($context, 'patient.view'));

        Assert::false($decision->allowed());
        Assert::same('denied:permission', $decision->reason());
    }

    public function testDeniesOnTenantBoundaryMismatch(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);
        $audit = new SpyAuditLogWriter();
        $service = new RbacAuthorizationService(new FakePermissionProvider(['patient.view' => true]), $audit);

        $decision = $service->decide(new AuthorizationRequest(
            $context,
            'patient.view',
            resourceTenantId: 202,
        ));

        Assert::false($decision->allowed());
        Assert::same('denied:boundary', $decision->reason());
    }

    public function testDeniesWhenUnitScopeRequiredButNoActiveUnit(): void
    {
        $context = TenantContext::authenticated(101, 1); // no active unit
        $audit = new SpyAuditLogWriter();
        $service = new RbacAuthorizationService(new FakePermissionProvider(['schedule.manage' => true]), $audit);

        $decision = $service->decide(new AuthorizationRequest(
            $context,
            'schedule.manage',
            requiresUnitScope: true,
        ));

        Assert::false($decision->allowed());
        Assert::same('denied:boundary', $decision->reason());
    }

    public function testDeniesWhenResourceUnitDiffersFromActiveUnit(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);
        $audit = new SpyAuditLogWriter();
        $service = new RbacAuthorizationService(new FakePermissionProvider(['schedule.manage' => true]), $audit);

        $decision = $service->decide(new AuthorizationRequest(
            $context,
            'schedule.manage',
            requiresUnitScope: true,
            resourceUnitId: 9,
        ));

        Assert::false($decision->allowed());
        Assert::same('denied:boundary', $decision->reason());
    }

    public function testAllowsWhenResourceUnitMatchesActiveUnit(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);
        $audit = new SpyAuditLogWriter();
        $service = new RbacAuthorizationService(new FakePermissionProvider(['schedule.manage' => true]), $audit);

        $decision = $service->decide(new AuthorizationRequest(
            $context,
            'schedule.manage',
            requiresUnitScope: true,
            resourceUnitId: 5,
        ));

        Assert::true($decision->allowed());
    }

    public function testFailsClosedWhenPermissionProviderThrows(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);
        $audit = new SpyAuditLogWriter();
        $service = new RbacAuthorizationService(new FakePermissionProvider([], throwOnCheck: true), $audit);

        $decision = $service->decide(new AuthorizationRequest($context, 'patient.view'));

        Assert::false($decision->allowed());
        Assert::same('denied:error', $decision->reason());
    }

    public function testAssertAllowedThrowsAuthorizationDeniedWhenDenied(): void
    {
        $context = TenantContext::authenticated(101, 1);
        $service = new RbacAuthorizationService(new FakePermissionProvider(['x' => false]), new SpyAuditLogWriter());

        $decision = $service->decide(new AuthorizationRequest($context, 'x'));

        Assert::throws(AuthorizationDenied::class, static fn () => $decision->assertAllowed());
    }

    public function testAuditMetadataIsRedactedBeforeBeingRecorded(): void
    {
        $context = TenantContext::authenticated(101, 1);
        $audit = new SpyAuditLogWriter();
        $service = new RbacAuthorizationService(new FakePermissionProvider(['x' => true]), $audit);

        $service->decide(new AuthorizationRequest($context, 'x', metadata: ['password' => 'super-secret', 'screen' => 'login']));

        Assert::same('*****', $audit->events[0]->metadata['password']);
        Assert::same('login', $audit->events[0]->metadata['screen']);
    }

    public function testEveryDecisionIsAuditedEvenWhenDenied(): void
    {
        $context = TenantContext::authenticated(101, 1);
        $audit = new SpyAuditLogWriter();
        $service = new RbacAuthorizationService(new FakePermissionProvider(['x' => false]), $audit);

        $service->decide(new AuthorizationRequest($context, 'x'));

        Assert::count(1, $audit->events);
        Assert::same('denied:permission', $audit->events[0]->metadata['reason']);
    }
}
