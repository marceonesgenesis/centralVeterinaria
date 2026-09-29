<?php

declare(strict_types=1);

namespace CentralVet\Authorization;

use CentralVet\Audit\AuditEvent;
use CentralVet\Audit\AuditRedactor;
use CentralVet\Audit\Contract\AuditLogWriterInterface;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\Contract\PermissionProviderInterface;
use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Tenancy\Exception\MissingTenantContext;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use Throwable;

/**
 * Tenant/unit-aware RBAC policy (ADR 0002), reusable by the Adianti UI,
 * Services and any future REST/MCP adapter (ADR 0001): it has no dependency
 * on TPage and takes its subject exclusively from an already-resolved
 * CentralVet\Tenancy\TenantContext, never from a client-supplied tenant id.
 *
 * Fail-closed by construction: a tenant/unit boundary violation, a missing
 * active unit when one is required, an unknown permission, or an unexpected
 * exception from the injected PermissionProviderInterface all resolve to a
 * denied decision. Nothing here ever upgrades a denial into an allow.
 *
 * Every decision — allowed or denied — is written to the correlated business
 * audit trail (`audit_log`, ADR 0003) through the injected
 * AuditLogWriterInterface, tagged with the current
 * CentralVet\Observability\CorrelationId\CorrelationIdContext value so a
 * technical log line and the resulting audit row can be joined later. The
 * audit write is best-effort and side-channel only: a failure to record an
 * event never changes the decision already computed and never throws back
 * to the caller (see NullAuditLogWriter/PdoAuditLogWriter docblocks for the
 * pending migration dependency).
 */
final class RbacAuthorizationService implements AuthorizationPolicyInterface
{
    public function __construct(
        private readonly PermissionProviderInterface $permissions,
        private readonly AuditLogWriterInterface $auditLog,
    ) {
    }

    public function decide(AuthorizationRequest $request): AuthorizationDecision
    {
        $correlationId = CorrelationIdContext::current() ?? CorrelationIdContext::start();

        [$allowed, $reason] = $this->evaluate($request);

        $decision = new AuthorizationDecision($allowed, $reason, $correlationId);

        $this->recordAudit($request, $decision);

        return $decision;
    }

    /** @return array{0: bool, 1: string} */
    private function evaluate(AuthorizationRequest $request): array
    {
        try {
            $this->assertBoundaries($request);
        } catch (MissingTenantContext|TenantBoundaryViolation) {
            return [false, 'denied:boundary'];
        }

        try {
            return $this->permissions->hasPermission($request->context(), $request->action())
                ? [true, 'granted']
                : [false, 'denied:permission'];
        } catch (Throwable) {
            // Fail-closed: a broken/unreachable permission source must never
            // be interpreted as "allow".
            return [false, 'denied:error'];
        }
    }

    private function assertBoundaries(AuthorizationRequest $request): void
    {
        $context = $request->context();

        if ($request->resourceTenantId() !== null) {
            $context->assertTenant($request->resourceTenantId());
        }

        if ($request->requiresUnitScope()) {
            $activeUnitId = $context->requireUnitId();

            if ($request->resourceUnitId() !== null && $request->resourceUnitId() !== $activeUnitId) {
                throw new TenantBoundaryViolation('Resource does not belong to the active unit');
            }
        }
    }

    private function recordAudit(AuthorizationRequest $request, AuthorizationDecision $decision): void
    {
        try {
            $context = $request->context();

            $event = new AuditEvent(
                tenantId: $context->tenantId(),
                unitId: $context->unitId(),
                userId: $context->userId(),
                correlationId: $decision->correlationId(),
                action: $request->action(),
                entityType: $request->entityType(),
                entityId: $request->entityId(),
                beforeData: null,
                afterData: null,
                metadata: AuditRedactor::redact([
                    ...$request->metadata(),
                    'allowed' => $decision->allowed(),
                    'reason' => $decision->reason(),
                ]),
                ipAddress: $request->ipAddress(),
                userAgent: $request->userAgent(),
            );

            $this->auditLog->record($event);
        } catch (Throwable) {
            // Auditing must never block or flip an authorization outcome;
            // infrastructure gaps (e.g. migration not applied yet) are a
            // deployment concern, not a business decision.
        }
    }
}
