<?php

declare(strict_types=1);

namespace CentralVet\Authorization\Exception;

use RuntimeException;

/**
 * Thrown by AuthorizationDecision::assertAllowed() when a caller opts into
 * the throw-on-denial style instead of branching on allowed(), mirroring
 * CentralVet\Tenancy\Exception\{MissingTenantContext,TenantBoundaryViolation}.
 */
final class AuthorizationDenied extends RuntimeException
{
}
