<?php

declare(strict_types=1);

namespace CentralVet\Authorization;

use CentralVet\Authorization\Exception\AuthorizationDenied;

final class AuthorizationDecision
{
    public function __construct(
        private readonly bool $allowed,
        private readonly string $reason,
        private readonly string $correlationId,
    ) {
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    /**
     * One of: 'granted', 'denied:boundary' (tenant/unit mismatch or missing
     * active unit), 'denied:permission' (provider said no) or
     * 'denied:error' (provider threw — fail-closed).
     */
    public function reason(): string
    {
        return $this->reason;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Convenience for callers that prefer to fail loudly instead of
     * branching on allowed(), mirroring the throw-on-violation style already
     * used by CentralVet\Tenancy\TenantContext.
     */
    public function assertAllowed(): void
    {
        if (!$this->allowed) {
            throw new AuthorizationDenied($this->reason);
        }
    }
}
