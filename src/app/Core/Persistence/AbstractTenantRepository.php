<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Tenancy\TenantContext;

abstract class AbstractTenantRepository implements TenantRepositoryInterface
{
    public function __construct(protected readonly TenantContext $context)
    {
    }

    final public function tenantId(): int
    {
        return $this->context->tenantId();
    }

    final protected function tenantQuery(?string $alias = null): TenantQuery
    {
        return TenantQuery::forTenant($this->tenantId(), $alias);
    }

    final protected function assertEntityTenant(int $tenantId): void
    {
        $this->context->assertTenant($tenantId);
    }
}
