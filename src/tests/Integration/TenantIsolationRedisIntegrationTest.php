<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Cache\RedisCache;
use CentralVet\Queue\RedisQueue;
use CentralVet\Redis\KeyNamespace;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\RedisIntegrationTestCase;

/**
 * Tenant isolation matrix at the Redis level, between two fictitious
 * tenants (101 and 202), run against the real Redis service from
 * docker-compose.yml.
 *
 * This is deliberately scoped to Redis (Cache/Queue), NOT to MySQL: the
 * `tenant`/`audit_log`/`stored_object` isolation matrix depends on the
 * foundation migration (src/app/database/migrations/
 * 20260920_0001_foundation_multitenancy.sql), which is prepared but not
 * applied — no SQL authorization was granted for this task run. See the
 * task report's "Pendências" for that follow-up.
 *
 * Cache isolation is physical: CentralVet\Redis\KeyNamespace::tenantKey()
 * embeds the tenant id directly into the Redis key, so two tenants can
 * never collide on (or read) the same cache key even for the same logical
 * key name.
 *
 * Queue isolation is logical by design (see RedisQueue's own class
 * docblock): a single shared queue channel serves every tenant, with
 * `tenant_id` carried inside each message envelope rather than as separate
 * per-tenant Redis keys — mirroring the project's "shared storage +
 * tenant_id column" convention used for the database. This test proves
 * that convention actually holds end-to-end (no cross-tenant data bleed
 * between two interleaved tenants' messages), not that the queue keys
 * themselves are tenant-prefixed.
 */
final class TenantIsolationRedisIntegrationTest extends RedisIntegrationTestCase
{
    /** Unique per test instance, so parallel suite runs never share a key. */
    private string $queue;

    private string $sharedKey;

    private KeyNamespace $keys;

    public function setUp(): void
    {
        $unique = bin2hex(random_bytes(4));
        $this->queue = 't11-isolation-queue-' . $unique;
        $this->sharedKey = 't11-shared-key-' . $unique;
        parent::setUp();
        $this->keys = new KeyNamespace('testing');
    }

    public function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }

        $this->redis->del($this->keys->tenantKey(101, 'cache', $this->sharedKey));
        $this->redis->del($this->keys->tenantKey(202, 'cache', $this->sharedKey));
        $this->redis->del($this->keys->key('queue:' . $this->queue, 'pending'));
        $this->redis->del($this->keys->key('queue:' . $this->queue, 'processing'));
    }

    public function testCacheValuesAreIsolatedBetweenTwoTenantsForTheSameLogicalKey(): void
    {
        $cacheTenant101 = new RedisCache($this->redis, $this->keys, TenantContext::authenticated(101, 1));
        $cacheTenant202 = new RedisCache($this->redis, $this->keys, TenantContext::authenticated(202, 1));

        $cacheTenant101->set($this->sharedKey, 'value-for-tenant-101');
        $cacheTenant202->set($this->sharedKey, 'value-for-tenant-202');

        Assert::same('value-for-tenant-101', $cacheTenant101->get($this->sharedKey));
        Assert::same('value-for-tenant-202', $cacheTenant202->get($this->sharedKey));

        // Confirm physical isolation directly at the Redis key level, not
        // just through each tenant's own RedisCache facade.
        $rawTenant101 = $this->redis->get($this->keys->tenantKey(101, 'cache', $this->sharedKey));
        $rawTenant202 = $this->redis->get($this->keys->tenantKey(202, 'cache', $this->sharedKey));

        Assert::false($rawTenant101 === $rawTenant202, 'The two tenants must not share the same physical Redis value');

        // Deleting tenant 101's key must never affect tenant 202's copy.
        $cacheTenant101->delete($this->sharedKey);
        Assert::false($cacheTenant101->has($this->sharedKey));
        Assert::true($cacheTenant202->has($this->sharedKey), 'Deleting one tenant\'s cache entry must not affect another tenant');
    }

    public function testQueueMessagesCarryTheCorrectTenantIdThroughAnInterleavedRoundTrip(): void
    {
        $queue = new RedisQueue($this->redis, $this->keys, baseBackoffSeconds: 1);

        $idTenant101 = $queue->push($this->queue, ['op' => 'appointment-reminder'], tenantId: 101);
        $idTenant202 = $queue->push($this->queue, ['op' => 'invoice-charge'], tenantId: 202);

        // Pushed with lPush, so pop (via brPoplpush on the right end) drains
        // in FIFO order: tenant 101's message first, then tenant 202's.
        $first = $queue->pop($this->queue, timeoutSeconds: 2);
        $second = $queue->pop($this->queue, timeoutSeconds: 2);

        Assert::notNull($first);
        Assert::notNull($second);
        Assert::same($idTenant101, $first->id);
        Assert::same(101, $first->tenantId);
        Assert::same($idTenant202, $second->id);
        Assert::same(202, $second->tenantId);

        $queue->ack($first);
        $queue->ack($second);
    }
}
