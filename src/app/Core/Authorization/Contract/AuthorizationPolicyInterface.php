<?php

declare(strict_types=1);

namespace CentralVet\Authorization\Contract;

use CentralVet\Authorization\AuthorizationDecision;
use CentralVet\Authorization\AuthorizationRequest;

/**
 * Central authorization boundary shared by the Adianti UI, Services and any
 * future REST/MCP adapter (ADR 0001) — no dependency on TPage. Implementations
 * must be fail-closed (ADR 0002): any ambiguity, missing tenant/unit context
 * or unexpected error must resolve to a denied decision, never to an allowed
 * one.
 */
interface AuthorizationPolicyInterface
{
    public function decide(AuthorizationRequest $request): AuthorizationDecision;
}
