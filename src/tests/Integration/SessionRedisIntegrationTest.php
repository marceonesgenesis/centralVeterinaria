<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Session\RedisSessionHandler;
use CentralVet\Session\SessionRegistry;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\RedisIntegrationTestCase;

/**
 * Integration test against the real Redis service from docker-compose.yml,
 * covering RedisSessionHandler (PHP session storage) and SessionRegistry
 * (single-active-session-per-user pointer used for forced logout).
 *
 * Uses dedicated 'cvtest:' prefixes (distinct from the real
 * 'centralvet:session:'/'...:index:user:' defaults) as an extra safety net
 * on top of RedisIntegrationTestCase's targeted per-key cleanup.
 */
final class SessionRedisIntegrationTest extends RedisIntegrationTestCase
{
    /** 'cvtest:session:test:<uniqid>:' — unique per test instance. */
    private string $sessionPrefix;

    /** 'cvtest:index:user:test:<uniqid>:' — unique per test instance. */
    private string $registryPrefix;

    public function setUp(): void
    {
        $suffix = 'test:' . uniqid('', true) . ':';
        $this->sessionPrefix = 'cvtest:session:' . $suffix;
        $this->registryPrefix = 'cvtest:index:user:' . $suffix;
        parent::setUp();
    }

    public function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }

        $this->redis->del($this->sessionPrefix . 't11-session-id');
        $this->redis->del($this->registryPrefix . '4242');
    }

    public function testRedisSessionHandlerWriteReadDestroyRoundTrip(): void
    {
        $handler = new RedisSessionHandler(
            host: (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('REDIS_PORT') ?: 6379),
            database: (int) (getenv('REDIS_DATABASE') ?: 0),
            password: getenv('REDIS_PASSWORD') ?: null,
            prefix: $this->sessionPrefix,
            ttlSeconds: 60,
        );

        Assert::true($handler->open('', ''));
        Assert::true($handler->write('t11-session-id', 'serialized-session-payload'));
        Assert::same('serialized-session-payload', $handler->read('t11-session-id'));

        Assert::true($handler->destroy('t11-session-id'));
        Assert::same('', $handler->read('t11-session-id'));

        $handler->close();
    }

    public function testSessionRegistryTracksAndForgetsTheActiveSessionPerUser(): void
    {
        $registry = new SessionRegistry($this->redis, $this->registryPrefix, 60);

        Assert::null($registry->currentSessionId(4242));

        $registry->register(4242, 'session-abc');
        Assert::same('session-abc', $registry->currentSessionId(4242));

        // A new login overwrites the pointer (single active session policy).
        $registry->register(4242, 'session-xyz');
        Assert::same('session-xyz', $registry->currentSessionId(4242));

        $registry->forget(4242);
        Assert::null($registry->currentSessionId(4242));
    }
}
