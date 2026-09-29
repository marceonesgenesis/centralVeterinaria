<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Lock\Exception\LockAcquisitionException;
use CentralVet\Lock\RedisLock;
use CentralVet\Redis\KeyNamespace;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeRedis;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Exercises RedisLock's acquire/release/withLock contract entirely against
 * an in-memory FakeRedis (see Support/FakeRedis.php) — no real Redis
 * connection involved, matching T-11's unit-test scope for this class.
 */
final class RedisLockTest
{
    public function setUp(): void
    {
        // FakeRedis extends the native \Redis class purely to satisfy
        // RedisLock's constructor type hint; it opens no real connection.
        // That still requires the redis extension itself to be loaded (it
        // always is in this project's own PHP runtime, see
        // docker/php/Dockerfile), so skip cleanly on a host without it
        // instead of a hard fatal error when the class is declared.
        if (!class_exists(\Redis::class)) {
            throw new SkippedTestException('ext-redis is not loaded in this PHP runtime');
        }
    }

    public function testAcquireSucceedsWhenLockIsFree(): void
    {
        $lock = $this->makeLock(101, new FakeRedis());

        Assert::true($lock->acquire('invoice-close', 30));
    }

    public function testAcquireFailsWhenLockIsAlreadyHeld(): void
    {
        $redis = new FakeRedis();
        $lock1 = $this->makeLock(101, $redis);
        $lock2 = $this->makeLock(101, $redis);

        Assert::true($lock1->acquire('invoice-close', 30));
        Assert::false($lock2->acquire('invoice-close', 30));
    }

    public function testReleaseFreesTheLockForItsOwner(): void
    {
        $redis = new FakeRedis();
        $lock = $this->makeLock(101, $redis);

        Assert::true($lock->acquire('invoice-close', 30));
        $lock->release('invoice-close');

        Assert::true($lock->acquire('invoice-close', 30), 'Lock must be acquirable again after release');
    }

    public function testReleaseNeverRemovesALockItDoesNotOwn(): void
    {
        $redis = new FakeRedis();
        $lock = $this->makeLock(101, $redis);
        $key = $this->fullKey(101, 'invoice-close');

        Assert::true($lock->acquire('invoice-close', 30));

        // Simulate another process having since taken over the same key
        // (e.g. because this lock's TTL already expired and someone else
        // acquired it) by overwriting the stored token directly.
        $redis->forceSet($key, 'someone-elses-token');

        $lock->release('invoice-close');

        Assert::true($redis->hasKey($key), 'Compare-and-delete must not remove a key owned by another token');
        Assert::same('someone-elses-token', $redis->rawValue($key));
    }

    public function testWithLockRunsCallbackAndReleasesAfterwards(): void
    {
        $redis = new FakeRedis();
        $lock = $this->makeLock(101, $redis);

        $result = $lock->withLock('invoice-close', 30, static fn () => 'done');

        Assert::same('done', $result);
        Assert::true($lock->acquire('invoice-close', 30), 'Lock must be released once the callback finishes');
    }

    public function testWithLockThrowsAndNeverRunsCallbackWhenLockIsHeld(): void
    {
        $redis = new FakeRedis();
        $lock1 = $this->makeLock(101, $redis);
        $lock2 = $this->makeLock(101, $redis);

        Assert::true($lock1->acquire('invoice-close', 30));

        $called = false;
        Assert::throws(LockAcquisitionException::class, static function () use ($lock2, &$called): void {
            $lock2->withLock('invoice-close', 30, static function () use (&$called) {
                $called = true;

                return null;
            });
        });

        Assert::false($called, 'Callback must never run when the lock could not be acquired');
    }

    public function testLocksAreIsolatedPerTenant(): void
    {
        $redis = new FakeRedis();
        $tenant101 = $this->makeLock(101, $redis);
        $tenant202 = $this->makeLock(202, $redis);

        Assert::true($tenant101->acquire('invoice-close', 30));
        // A different tenant using the same lock name must not contend with
        // tenant 101's lock: physically different Redis keys.
        Assert::true($tenant202->acquire('invoice-close', 30));
    }

    private function makeLock(int $tenantId, FakeRedis $redis): RedisLock
    {
        return new RedisLock($redis, new KeyNamespace('testing'), TenantContext::authenticated($tenantId, 1));
    }

    private function fullKey(int $tenantId, string $name): string
    {
        return (new KeyNamespace('testing'))->tenantKey($tenantId, 'lock', $name);
    }
}
