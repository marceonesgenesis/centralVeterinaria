<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\TenantUserDirectoryInterface;
use CentralVet\Tenancy\TenantContext;
use PDO;

/**
 * PDO-backed TenantUserDirectoryInterface (final-fix). Same rule as the
 * presentation-layer professional combo (CvTenantUsers::criteria()): an
 * active `system_users` row (active = 'Y') linked to the authenticated
 * tenant through `tenant_user`. The tenant comes only from TenantContext
 * (ADR 0002), never from caller input.
 */
final class TenantUserDirectory implements TenantUserDirectoryInterface
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    public function isActiveMember(int $systemUserId): bool
    {
        if ($systemUserId <= 0) {
            return false;
        }

        $statement = $this->connection->prepare(
            "SELECT 1 FROM system_users u "
            . 'INNER JOIN tenant_user tu ON tu.system_user_id = u.id '
            . "WHERE tu.tenant_id = :tenant_id AND u.id = :system_user_id AND u.active = 'Y' LIMIT 1"
        );
        $statement->execute([
            'tenant_id' => $this->context->tenantId(),
            'system_user_id' => $systemUserId,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
