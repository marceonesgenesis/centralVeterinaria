<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Authorization\AuthorizationDecision;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;

/**
 * In-memory double for AuthorizationPolicyInterface: always allows (default)
 * or always denies, without touching RbacAuthorizationService or its
 * PermissionProviderInterface/AuditLogWriterInterface dependencies (no
 * Adianti session, no `audit_log` table). AppointmentServiceTest and
 * QueueEntryServiceTest use an "allow" instance so their existing scenarios
 * keep exercising only their own business rules (scheduling conflict,
 * cross-tenant reference, status transition), and a "deny" instance in a
 * handful of dedicated tests to prove the unit-scope authorization check
 * added to AppointmentService::schedule() / QueueEntryService::checkIn()/
 * advanceStatus() actually blocks the call instead of being decorative.
 * setAllowed() switches the decision mid-test (EncounterAccountServiceTest).
 */
final class FakeAuthorizationPolicy implements AuthorizationPolicyInterface
{
    /** @var list<AuthorizationRequest> */
    public array $requests = [];

    public function __construct(
        private bool $allowed = true,
        private readonly string $reason = 'granted',
    ) {
    }

    /**
     * Flips the decision for the following calls, so a test can set up
     * state under "allow" and then prove the guarded call is denied.
     */
    public function setAllowed(bool $allowed): void
    {
        $this->allowed = $allowed;
    }

    public function decide(AuthorizationRequest $request): AuthorizationDecision
    {
        $this->requests[] = $request;

        return new AuthorizationDecision($this->allowed, $this->reason, 'test-correlation-id');
    }
}
