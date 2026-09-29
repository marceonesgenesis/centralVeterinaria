<?php

declare(strict_types=1);

namespace CentralVet\Lock;

use CentralVet\Lock\Exception\LockAcquisitionException;
use CentralVet\Redis\KeyNamespace;
use CentralVet\Redis\RedisConnectionFactory;
use CentralVet\Tenancy\TenantContext;

/**
 * Tenant-scoped distributed lock using SET NX EX for acquisition and a
 * compare-and-delete Lua script for release, so a lock can never be released
 * by a process that does not currently own it (classic Redis single-instance
 * lock pattern). The script only ever compares/deletes the single lock key
 * this instance owns; it runs no user input and touches no other keys.
 */
final class RedisLock implements LockInterface
{
    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call("GET", KEYS[1]) == ARGV[1] then
            return redis.call("DEL", KEYS[1])
        end
        return 0
        LUA;

    /** @var array<string, string> */
    private array $tokens = [];

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

    public function acquire(string $name, int $ttlSeconds = 30): bool
    {
        $token = bin2hex(random_bytes(16));
        $key = $this->fullKey($name);

        $acquired = $this->redis->set($key, $token, ['NX', 'EX' => max(1, $ttlSeconds)]);

        if ($acquired) {
            $this->tokens[$key] = $token;
        }

        return (bool) $acquired;
    }

    public function release(string $name): void
    {
        $key = $this->fullKey($name);
        $token = $this->tokens[$key] ?? null;

        if ($token === null) {
            return;
        }

        $this->redis->eval(self::RELEASE_SCRIPT, [$key, $token], 1);
        unset($this->tokens[$key]);
    }

    public function withLock(string $name, int $ttlSeconds, callable $callback): mixed
    {
        if (!$this->acquire($name, $ttlSeconds)) {
            throw new LockAcquisitionException("Could not acquire lock: {$name}");
        }

        try {
            return $callback();
        } finally {
            $this->release($name);
        }
    }

    private function fullKey(string $name): string
    {
        return $this->keys->tenantKey($this->tenant->tenantId(), 'lock', $name);
    }
}
