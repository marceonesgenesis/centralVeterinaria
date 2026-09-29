<?php

declare(strict_types=1);

namespace CentralVet\Authorization;

use CentralVet\Authorization\Contract\PermissionProviderInterface;
use CentralVet\Tenancy\SessionContextSource;
use CentralVet\Tenancy\TenantContext;

/**
 * Adapts CentralVet\Authorization to the permission data Adianti already
 * populates on login (`programs`/`methods` session keys — the same source
 * `SystemPermission::checkPermission()` reads from, see
 * app/model/admin/SystemPermission.php). Reused here instead of duplicated
 * so the legacy menu gate and the new central policy never disagree about
 * what a user can do.
 *
 * $permission is either a class name ("SomeForm") or "SomeForm::method" to
 * also honour a per-method restriction. Unlike SystemPermission, there is no
 * "public_classes" bypass here: this provider only answers the permission
 * question, least-privilege by default (ADR 0002). A public/anonymous
 * allowance (e.g. LoginForm itself) stays a UI/menu concern for the
 * Presentation layer, not part of the reusable core policy.
 *
 * Depends on SessionContextSource (not TSession directly) so it works with
 * both AdiantiSessionContextSource in production and a test double
 * elsewhere, consistent with CentralVet\Tenancy\TenantContext.
 */
final class AdiantiProgramPermissionProvider implements PermissionProviderInterface
{
    public function __construct(private readonly SessionContextSource $session)
    {
    }

    public function hasPermission(TenantContext $context, string $permission): bool
    {
        [$class, $method] = array_pad(explode('::', $permission, 2), 2, '');

        $programs = $this->session->get('programs');

        if (!is_array($programs) || empty($programs[$class])) {
            return false;
        }

        if ($method === '') {
            return true;
        }

        $methods = $this->session->get('methods');
        $methodExplicitlyDenied = is_array($methods) && ($methods[$class][$method] ?? null) === false;

        return !$methodExplicitlyDenied;
    }
}
