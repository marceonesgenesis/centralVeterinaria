<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\AiTenantSetting;

/** Persistence of `ai_tenant_setting` for the current tenant. */
interface AiTenantSettingRepositoryInterface
{
    /** Null when the tenant has no row yet (callers use AiTenantSetting::defaults). */
    public function find(): ?AiTenantSetting;

    public function save(AiTenantSetting $setting, int $systemUserId): void;
}
