<?php

declare(strict_types=1);

namespace CentralVet\Session;

use SessionHandlerInterface;

/**
 * PHP session storage backed by Redis.
 *
 * Compatible with Adianti\Registry\TSession, which accepts an optional
 * SessionHandlerInterface in its constructor (`new TSession($handler)`).
 * Session payloads never contain passwords; they only carry the identity
 * data Adianti itself stores (login, ids, permissions), so no additional
 * redaction is required before persisting them.
 *
 * Expiration is delegated to Redis TTL (SETEX) instead of PHP's probabilistic
 * garbage collector, so gc() is a no-op by design.
 */
final class RedisSessionHandler implements SessionHandlerInterface
{
    private ?\Redis $redis = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $database,
        private readonly ?string $password,
        private readonly string $prefix,
        private readonly int $ttlSeconds,
        private readonly float $timeout = 1.5,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        $this->redis = \CentralVet\Redis\RedisConnectionFactory::connect(
            $this->host,
            $this->port,
            $this->database,
            $this->password,
            $this->timeout,
        );

        return true;
    }

    public function close(): bool
    {
        if ($this->redis !== null) {
            $this->redis->close();
            $this->redis = null;
        }

        return true;
    }

    public function read(string $id): string|false
    {
        if ($this->redis === null) {
            return '';
        }

        $data = $this->redis->get($this->key($id));

        return is_string($data) ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        if ($this->redis === null) {
            return false;
        }

        return (bool) $this->redis->setex($this->key($id), $this->ttlSeconds, $data);
    }

    public function destroy(string $id): bool
    {
        if ($this->redis !== null) {
            $this->redis->del($this->key($id));
        }

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        // Expiration is handled natively by Redis TTL (see write()); there is
        // nothing to sweep here.
        return 0;
    }

    private function key(string $id): string
    {
        return $this->prefix . $id;
    }
}
