<?php

declare(strict_types=1);

namespace CentralVet\Security;

use CentralVet\Redis\RedisConnectionFactory;

/**
 * Redis-backed brute-force throttle for the login form.
 *
 * Buckets are keyed by a SHA-256 hash of "login|ip" so that neither the raw
 * login nor the IP address is stored verbatim as a Redis key, and nothing
 * about the credential itself (never the password) is persisted or logged.
 * A fixed window counter (INCR + EXPIRE on first hit) is intentionally
 * simple; it is adequate for slowing down credential stuffing without
 * introducing a new infrastructure dependency beyond the Redis instance
 * already required for sessions.
 */
final class LoginRateLimiter
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly int $maxAttempts,
        private readonly int $decaySeconds,
        private readonly string $prefix = 'centralvet:login-throttle:',
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            RedisConnectionFactory::fromEnvironment(),
            (int) (getenv('LOGIN_RATE_LIMIT_MAX_ATTEMPTS') ?: 5),
            (int) (getenv('LOGIN_RATE_LIMIT_DECAY_SECONDS') ?: 900),
        );
    }

    public static function identifierFor(string $login, string $ip): string
    {
        return strtolower(trim($login)) . '|' . trim($ip);
    }

    public function tooManyAttempts(string $identifier): bool
    {
        return $this->attempts($identifier) >= $this->maxAttempts;
    }

    public function hit(string $identifier): int
    {
        $key = $this->key($identifier);
        $attempts = (int) $this->redis->incr($key);

        if ($attempts === 1) {
            $this->redis->expire($key, $this->decaySeconds);
        }

        return $attempts;
    }

    public function attempts(string $identifier): int
    {
        $value = $this->redis->get($this->key($identifier));

        return $value === false ? 0 : (int) $value;
    }

    public function clear(string $identifier): void
    {
        $this->redis->del($this->key($identifier));
    }

    public function secondsUntilAvailable(string $identifier): int
    {
        $ttl = $this->redis->ttl($this->key($identifier));

        return $ttl > 0 ? $ttl : 0;
    }

    private function key(string $identifier): string
    {
        return $this->prefix . hash('sha256', $identifier);
    }
}
