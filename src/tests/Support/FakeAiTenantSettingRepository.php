<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\Contract\AiTenantSettingRepositoryInterface;

/**
 * In-memory double for AiTenantSettingRepositoryInterface (T-05): find()
 * returns the setting given (or the last saved one); saved() exposes the
 * last save, null when nothing was saved.
 */
final class FakeAiTenantSettingRepository implements AiTenantSettingRepositoryInterface
{
    private ?AiTenantSetting $saved = null;
    private ?int $savedBySystemUserId = null;

    public function __construct(private ?AiTenantSetting $setting = null)
    {
    }

    public function find(): ?AiTenantSetting
    {
        return $this->setting;
    }

    public function save(AiTenantSetting $setting, int $systemUserId): void
    {
        $this->setting = $setting;
        $this->saved = $setting;
        $this->savedBySystemUserId = $systemUserId;
    }

    public function saved(): ?AiTenantSetting
    {
        return $this->saved;
    }

    public function savedBySystemUserId(): ?int
    {
        return $this->savedBySystemUserId;
    }
}
