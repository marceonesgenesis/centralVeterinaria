<?php

declare(strict_types=1);

namespace CentralVet\Redis;

/**
 * Builds phpredis connections from environment configuration.
 *
 * Shared low-level primitive used by session storage, session revocation
 * and login rate limiting. Never logs the password; connection failures
 * are surfaced as exceptions so callers can fail closed instead of
 * silently degrading to a weaker (e.g. file-based) fallback.
 */
final class RedisConnectionFactory
{
    public static function fromEnvironment(): \Redis
    {
        return self::connect(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
            (int) (getenv('REDIS_DATABASE') ?: 0),
            getenv('REDIS_PASSWORD') ?: null,
            (float) (getenv('REDIS_TIMEOUT') ?: 1.5),
        );
    }

    /** Highest database index of a default Redis server (`databases 16`). */
    private const MAX_DATABASE = 15;

    /**
     * @throws \RuntimeException when the connection, AUTH or SELECT fails, or
     *         when $database is outside 0..15 — never falls back silently to
     *         DB 0, where the application's sessions live. When AUTH or
     *         SELECT fails the connection is closed before throwing.
     *
     * @param \Redis|null $client pre-built client (tests); null builds a new \Redis
     */
    public static function connect(
        string $host,
        int $port,
        int $database,
        ?string $password,
        float $timeout,
        ?\Redis $client = null
    ): \Redis {
        if ($database < 0 || $database > self::MAX_DATABASE) {
            throw new \RuntimeException("Unable to select Redis database {$database}");
        }

        $redis = $client ?? new \Redis();

        if (!$redis->connect($host, $port, $timeout)) {
            throw new \RuntimeException('Unable to connect to the Redis backend');
        }

        if (!empty($password)) {
            if (!$redis->auth($password)) {
                $redis->close();
                throw new \RuntimeException('Unable to authenticate with the Redis backend');
            }
        }

        if ($database > 0 && $redis->select($database) !== true) {
            $redis->close();
            throw new \RuntimeException("Unable to select Redis database {$database}");
        }

        return $redis;
    }
}
