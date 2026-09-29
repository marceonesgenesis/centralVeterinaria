<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

/**
 * Read-only lookup of the system users that belong to the authenticated
 * tenant (final-fix). Application services use it to reject a
 * `professional_system_user_id` supplied in caller input that does not
 * resolve within the tenant, the same way they reject a cross-tenant
 * encounter_id (CrossTenantReferenceException, ADR 0002).
 *
 * The tenant is never taken from caller input: implementations are bound
 * to the TenantContext they were built with.
 */
interface TenantUserDirectoryInterface
{
    /**
     * True only when $systemUserId is an active system user linked to the
     * authenticated tenant (tenant_user membership). A nonexistent user and
     * a user of another tenant are indistinguishable: both return false.
     */
    public function isActiveMember(int $systemUserId): bool;
}
