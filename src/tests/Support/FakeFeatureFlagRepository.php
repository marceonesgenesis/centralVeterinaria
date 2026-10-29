<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;

/**
 * In-memory double for FeatureFlagRepositoryInterface (T-05). A flag
 * absent from the list is disabled, like a missing `feature_flag` row.
 */
final class FakeFeatureFlagRepository implements FeatureFlagRepositoryInterface
{
    /** @var array<string, bool> */
    private array $flags = [];

    /** @var list<array{flag_key: string, enabled: bool, system_user_id: int}> */
    private array $changes = [];

    /** @param list<string> $enabledFlags */
    public function __construct(array $enabledFlags = [])
    {
        foreach ($enabledFlags as $flagKey) {
            $this->flags[$flagKey] = true;
        }
    }

    public function isEnabled(string $flagKey): bool
    {
        return $this->flags[$flagKey] ?? false;
    }

    public function set(string $flagKey, bool $enabled, int $systemUserId): void
    {
        $this->flags[$flagKey] = $enabled;
        $this->changes[] = ['flag_key' => $flagKey, 'enabled' => $enabled, 'system_user_id' => $systemUserId];
    }

    /** @return list<array{flag_key: string, enabled: bool, system_user_id: int}> every set() call, in order. */
    public function changes(): array
    {
        return $this->changes;
    }
}
