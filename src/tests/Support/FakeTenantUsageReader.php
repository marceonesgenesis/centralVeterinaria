<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Billing\Contract\TenantUsageReaderInterface;

/** Em memória: contagens por tenant em $users e $units (ausente = 0). */
final class FakeTenantUsageReader implements TenantUsageReaderInterface
{
    /** @var array<int, int> */
    public array $users = [];

    /** @var array<int, int> */
    public array $units = [];

    public function countUsers(int $tenantId): int
    {
        return $this->users[$tenantId] ?? 0;
    }

    public function countUnits(int $tenantId): int
    {
        return $this->units[$tenantId] ?? 0;
    }
}
