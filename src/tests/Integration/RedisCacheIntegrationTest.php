<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Cache\RedisCache;
use CentralVet\Redis\KeyNamespace;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\RedisIntegrationTestCase;

/**
 * Integration test against the real Redis service from docker-compose.yml
 * (ephemeral test data, not production/dev data — see class docblock on
 * RedisIntegrationTestCase for the cleanup guarantee).
 */
final class RedisCacheIntegrationTest extends RedisIntegrationTestCase
{
    private KeyNamespace $keys;

    /** Unique per test instance, so parallel suite runs never share a key. */
    private string $fooKey;

    private string $rememberKey;

    public function setUp(): void
    {
        $unique = bin2hex(random_bytes(4));
        $this->fooKey = 't11-foo-' . $unique;
        $this->rememberKey = 't11-remember-' . $unique;
        parent::setUp();
        $this->keys = new KeyNamespace('testing');
    }

    public function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }

        $this->redis->del($this->keys->tenantKey(9101, 'cache', $this->fooKey));
        $this->redis->del($this->keys->tenantKey(9101, 'cache', $this->rememberKey));
    }

    public function testSetGetHasDeleteRoundTrip(): void
    {
        $cache = new RedisCache($this->redis, $this->keys, TenantContext::authenticated(9101, 1));

        Assert::false($cache->has($this->fooKey));
        Assert::null($cache->get($this->fooKey));

        $cache->set($this->fooKey, ['bar' => 'baz'], 60);

        Assert::true($cache->has($this->fooKey));
        Assert::same(['bar' => 'baz'], $cache->get($this->fooKey));

        $cache->delete($this->fooKey);

        Assert::false($cache->has($this->fooKey));
        Assert::null($cache->get($this->fooKey));
    }

    public function testRememberOnlyInvokesCallbackOnceUntilExpiryOrDelete(): void
    {
        $cache = new RedisCache($this->redis, $this->keys, TenantContext::authenticated(9101, 1));
        $calls = 0;
        $producer = static function () use (&$calls) {
            $calls++;

            return 'computed-value';
        };

        $first = $cache->remember($this->rememberKey, 60, $producer);
        $second = $cache->remember($this->rememberKey, 60, $producer);

        Assert::same('computed-value', $first);
        Assert::same('computed-value', $second);
        Assert::same(1, $calls, 'remember() must not recompute once a value is cached');
    }
}
