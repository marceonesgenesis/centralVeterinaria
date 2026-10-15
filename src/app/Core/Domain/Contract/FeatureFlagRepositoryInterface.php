<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

/**
 * Generic per-tenant feature flags (`feature_flag`). A missing row means
 * the flag is off.
 */
interface FeatureFlagRepositoryInterface
{
    public const AI_ASSISTANT = 'ai_assistant';

    public function isEnabled(string $flagKey): bool;

    public function set(string $flagKey, bool $enabled, int $systemUserId): void;
}
