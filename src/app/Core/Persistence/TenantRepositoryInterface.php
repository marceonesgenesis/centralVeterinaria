<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\RepositoryInterface;

interface TenantRepositoryInterface extends RepositoryInterface
{
    public function tenantId(): int;
}
