<?php

declare(strict_types=1);

namespace CentralVet\Authorization\Contract;

use CentralVet\Tenancy\TenantContext;

/**
 * Port resolving whether the tenant-scoped subject in $context holds the
 * given permission. Implementations decide what a "permission" string means
 * (an Adianti program/class today, a future role-permission catalog entry
 * tomorrow); the policy that calls this port only cares about the boolean
 * answer and always treats a thrown exception the same as a false return:
 * "not granted" (fail-closed, ADR 0002).
 */
interface PermissionProviderInterface
{
    public function hasPermission(TenantContext $context, string $permission): bool;
}
