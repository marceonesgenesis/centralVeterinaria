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

    public function setUp(): void
    {
        parent::setUp();
        $this->keys = new KeyNamespace('testing');
    }

    public function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }

        $this->redis->del($this->keys->tenantKey(9101, 'cache', 't11-foo'));
        $this->redis->del($this->keys->tenantKey(9101, 'cache', 't11-remember'));
    }

    public function testSetGetHasDeleteRoundTrip(): void
    {
        $cache = new RedisCache($this->redis, $this->keys, TenantContext::authenticated(9101, 1));

        Assert::false($cache->has('t11-foo'));
        Assert::null($cache->get('t11-foo'));

        $cache->set('t11-foo', ['bar' => 'baz'], 60);

        Assert::true($cache->has('t11-foo'));
        Assert::same(['bar' => 'baz'], $cache->get('t11-foo'));

        $cache->delete('t11-foo');

        Assert::false($cache->has('t11-foo'));
        Assert::null($cache->get('t11-foo'));
    }

    public function testRememberOnlyInvokesCallbackOnceUntilExpiryOrDelete(): void
    {
        $cache = new RedisCache($this->redis, $this->keys, TenantContext::authenticated(9101, 1));
        $calls = 0;
        $producer = static function () use (&$calls) {
            $calls++;

            return 'computed-value';
        };

        $first = $cache->remember('t11-remember', 60, $producer);
        $second = $cache->remember('t11-remember', 60, $producer);

        Assert::same('computed-value', $first);
        Assert::same('computed-value', $second);
        Assert::same(1, $calls, 'remember() must not recompute once a value is cached');
    }
}
