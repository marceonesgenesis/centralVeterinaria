<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Redis\RedisConnectionFactory;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\RedisIntegrationTestCase;

/**
 * RedisConnectionFactory::connect() must never fall back silently to DB 0
 * (where the application's sessions live) when the requested database is
 * out of range or SELECT fails. Read-only: no key is written.
 */
final class RedisConnectionFactoryIntegrationTest extends RedisIntegrationTestCase
{
    public function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->close();
        }
    }

    public function testDatabaseAboveRangeIsRefused(): void
    {
        $this->assertRefused(99);
    }

    public function testNegativeDatabaseIsRefused(): void
    {
        $this->assertRefused(-1);
    }

    public function testDatabaseFifteenIsSelected(): void
    {
        $redis = $this->connectTo(15);

        try {
            Assert::same(15, $redis->getDbNum());
        } finally {
            $redis->close();
        }
    }

    private function assertRefused(int $database): void
    {
        $message = null;
        $redis = null;

        try {
            $redis = $this->connectTo($database);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        if ($redis instanceof \Redis) {
            $db = $redis->getDbNum();
            $redis->close();
            throw new \RuntimeException("Expected RuntimeException for database {$database}, got a connection on DB {$db}");
        }

        Assert::same("Unable to select Redis database {$database}", $message);
    }

    private function connectTo(int $database): \Redis
    {
        return RedisConnectionFactory::connect(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
            $database,
            getenv('REDIS_PASSWORD') ?: null,
            1.0,
        );
    }
}
