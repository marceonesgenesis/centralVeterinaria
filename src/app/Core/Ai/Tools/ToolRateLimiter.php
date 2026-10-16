<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

use CentralVet\Redis\RedisConnectionFactory;
use CentralVet\Security\FileRateLimitBackend;
use CentralVet\Security\LoginRateLimiter;

/**
 * Fixed-window throttle of copilot tool calls: MAX_CALLS per tenant and user
 * every DECAY_SECONDS, on the same backend as the login throttle
 * (RATE_LIMIT_DRIVER = redis | files), under its own key prefix.
 */
final class ToolRateLimiter
{
    public const MAX_CALLS = 30;
    public const DECAY_SECONDS = 600;
    public const PREFIX = 'centralvet:ai-tool:';

    public function __construct(private readonly LoginRateLimiter $limiter)
    {
    }

    public static function fromEnvironment(): self
    {
        $driver = getenv('RATE_LIMIT_DRIVER') ?: 'redis';
        if (!in_array($driver, ['redis', 'files'], true)) {
            throw new \RuntimeException('Unsupported rate limit driver');
        }
        $backend = $driver === 'files'
            ? new FileRateLimitBackend(
                getenv('RATE_LIMIT_DIRECTORY') ?: sys_get_temp_dir() . '/centralvet-rate-limit-' . hash('sha256', dirname(__DIR__, 4)),
                self::DECAY_SECONDS,
            )
            : RedisConnectionFactory::fromEnvironment();

        return new self(new LoginRateLimiter($backend, self::MAX_CALLS, self::DECAY_SECONDS, self::PREFIX));
    }

    /** Counts the call and returns false once the window is already full. */
    public function attempt(int $tenantId, int $userId): bool
    {
        $identifier = $tenantId . '|' . $userId;
        if ($this->limiter->tooManyAttempts($identifier)) {
            return false;
        }
        $this->limiter->hit($identifier);

        return true;
    }
}
