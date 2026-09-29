<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Authorization\Contract\PermissionProviderInterface;
use CentralVet\Tenancy\TenantContext;
use RuntimeException;

/**
 * In-memory double for PermissionProviderInterface. Can be configured to
 * grant/deny specific permission strings, or to throw (simulating a broken
 * permission source), which RbacAuthorizationService must treat as denied.
 */
final class FakePermissionProvider implements PermissionProviderInterface
{
    /** @param array<string, bool> $granted */
    public function __construct(
        private readonly array $granted = [],
        private readonly bool $throwOnCheck = false,
    ) {
    }

    public function hasPermission(TenantContext $context, string $permission): bool
    {
        if ($this->throwOnCheck) {
            throw new RuntimeException('Permission source unreachable (simulated failure)');
        }

        return $this->granted[$permission] ?? false;
    }
}
