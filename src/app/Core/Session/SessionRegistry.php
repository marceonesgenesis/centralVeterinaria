<?php

declare(strict_types=1);

namespace CentralVet\Session;

use CentralVet\Redis\RedisConnectionFactory;

/**
 * Tracks, per user, which PHP session id is currently the authoritative one.
 *
 * This replaces the previous single-node APCu bookkeeping
 * (`TAPCache::setValue('session_'.login, session_id())`) used by
 * ApplicationAuthenticationService::checkMultiSession(). APCu is local to a
 * single PHP-FPM process/container, so it cannot detect a concurrent login
 * that landed on a different `app` replica. Redis makes the check correct
 * across every container sharing the same Redis instance.
 *
 * Semantics stay intentionally simple (one active session id per user,
 * mirroring the previous APCu behaviour): a new login overwrites the
 * pointer, which is exactly the "revoke my other session" effect required
 * when `concurrent_sessions` is disabled — the previous session fails the
 * comparison on its next request and is force-logged-out.
 *
 * Note: keys are namespaced by user id only (no tenant_id yet), because
 * tenant_id is not populated by the current login flow (see ADR 0002 and
 * the pending foundation migration). When tenant-aware login lands, this
 * class should be extended to namespace by tenant id as well.
 */
final class SessionRegistry
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix,
        private readonly int $ttlSeconds,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            RedisConnectionFactory::fromEnvironment(),
            SessionHandlerFactory::sessionKeyPrefix() . 'index:user:',
            SessionHandlerFactory::sessionLifetimeSeconds(),
        );
    }

    public function register(int $userId, string $sessionId): void
    {
        if ($userId <= 0 || $sessionId === '') {
            return;
        }

        $this->redis->setex($this->key($userId), $this->ttlSeconds, $sessionId);
    }

    public function currentSessionId(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $value = $this->redis->get($this->key($userId));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function forget(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $this->redis->del($this->key($userId));
    }

    private function key(int $userId): string
    {
        return $this->prefix . $userId;
    }
}
