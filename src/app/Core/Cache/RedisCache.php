<?php

declare(strict_types=1);

namespace CentralVet\Cache;

use CentralVet\Redis\KeyNamespace;
use CentralVet\Redis\RedisConnectionFactory;
use CentralVet\Tenancy\TenantContext;

/**
 * Tenant-scoped Redis cache. Every key is physically prefixed by environment
 * and tenant id (via KeyNamespace::tenantKey), so two tenants never collide
 * on the same cache key even though they share the same Redis instance.
 *
 * The Redis connection is injected as a plain \Redis instance (same pattern
 * as CentralVet\Session\SessionRegistry), so tests can point it at a real
 * disposable Redis database instead of mocking the client.
 */
final class RedisCache implements CacheInterface
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly KeyNamespace $keys,
        private readonly TenantContext $tenant,
    ) {
    }

    public static function fromEnvironment(TenantContext $tenant): self
    {
        return new self(
            RedisConnectionFactory::fromEnvironment(),
            KeyNamespace::fromEnvironmentVariable(),
            $tenant,
        );
    }

    public function get(string $key): mixed
    {
        $raw = $this->redis->get($this->fullKey($key));

        if ($raw === false) {
            return null;
        }

        return unserialize($raw, ['allowed_classes' => false]);
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): void
    {
        $payload = serialize($value);
        $fullKey = $this->fullKey($key);

        if ($ttlSeconds > 0) {
            $this->redis->setex($fullKey, $ttlSeconds, $payload);
        } else {
            $this->redis->set($fullKey, $payload);
        }
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->fullKey($key));
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->fullKey($key));
    }

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttlSeconds);

        return $value;
    }

    private function fullKey(string $key): string
    {
        return $this->keys->tenantKey($this->tenant->tenantId(), 'cache', $key);
    }
}
