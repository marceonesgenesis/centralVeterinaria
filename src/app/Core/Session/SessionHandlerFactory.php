<?php

declare(strict_types=1);

namespace CentralVet\Session;

use SessionHandlerInterface;

/**
 * Chooses the PHP session backend from environment configuration.
 *
 * Default is `files`, which preserves Adianti's original behaviour
 * (`new TSession` with no handler). Setting SESSION_DRIVER=redis opts into
 * Redis-backed sessions, required for horizontal scaling across the `app`
 * and future replica containers, since local files are not shared between
 * them.
 */
final class SessionHandlerFactory
{
    public static function createFromEnvironment(): ?SessionHandlerInterface
    {
        if (!self::isRedisEnabled()) {
            return null;
        }

        return new RedisSessionHandler(
            host: (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('REDIS_PORT') ?: 6379),
            database: (int) (getenv('REDIS_DATABASE') ?: 0),
            password: getenv('REDIS_PASSWORD') ?: null,
            prefix: self::sessionKeyPrefix(),
            ttlSeconds: self::sessionLifetimeSeconds(),
        );
    }

    public static function isRedisEnabled(): bool
    {
        return strtolower((string) (getenv('SESSION_DRIVER') ?: 'files')) === 'redis';
    }

    public static function sessionKeyPrefix(): string
    {
        return (string) (getenv('SESSION_PREFIX') ?: 'centralvet:session:');
    }

    public static function sessionLifetimeSeconds(): int
    {
        return (int) (getenv('SESSION_LIFETIME') ?: 7200);
    }
}
