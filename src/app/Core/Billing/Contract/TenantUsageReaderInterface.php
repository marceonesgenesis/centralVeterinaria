<?php

declare(strict_types=1);

namespace CentralVet\Billing\Contract;

/** Uso atual do tenant para as quotas do plano (QuotaGuard). */
interface TenantUsageReaderInterface
{
    /** Vínculos em `tenant_user` com status `invited` ou `active`. */
    public function countUsers(int $tenantId): int;

    /** Linhas de `system_unit` com o `tenant_id`. */
    public function countUnits(int $tenantId): int;
}
