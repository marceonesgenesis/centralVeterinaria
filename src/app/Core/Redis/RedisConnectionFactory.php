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

    public static function connect(string $host, int $port, int $database, ?string $password, float $timeout): \Redis
    {
        $redis = new \Redis();

        if (!$redis->connect($host, $port, $timeout)) {
            throw new \RuntimeException('Unable to connect to the Redis backend');
        }

        if (!empty($password)) {
            if (!$redis->auth($password)) {
                throw new \RuntimeException('Unable to authenticate with the Redis backend');
            }
        }

        if ($database > 0) {
            $redis->select($database);
        }

        return $redis;
    }
}
