<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Landing\LeadFormToken;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeRedis;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Single-use, minimum-age, TTL-bounded token for the public lead form,
 * plus the FakeRedis operations (incr/expire/ttl/del) that both the token
 * and LoginRateLimiter rely on.
 */
final class LeadFormTokenTest
{
    private const PREFIX = 'centralvet:lead-token:';

    public function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            throw new SkippedTestException('ext-redis is not loaded in this PHP runtime');
        }
    }

    public function testIssuedTokenIs64LowercaseHexCharacters(): void
    {
        $token = (new LeadFormToken(new FakeRedis()))->issue(1000);

        Assert::true(preg_match('/^[0-9a-f]{64}$/', $token) === 1, 'Token must be 64 hex characters');
    }

    public function testTokenRespectsMinimumAgeAndTtl(): void
    {
        $tokens = new LeadFormToken(new FakeRedis());
        $token = $tokens->issue(1000);

        Assert::false($tokens->isUsable($token, 1002), 'Token younger than 3 s must be refused');
        Assert::true($tokens->isUsable($token, 1003), 'Token exactly 3 s old must be accepted');
        Assert::true($tokens->isUsable($token, 8200), 'Token exactly 7200 s old must be accepted');
        Assert::false($tokens->isUsable($token, 8201), 'Token older than 7200 s must be refused');
    }

    public function testConsumeSucceedsOnlyOnce(): void
    {
        $tokens = new LeadFormToken(new FakeRedis());
        $token = $tokens->issue(1000);

        Assert::true($tokens->consume($token), 'First consume must win');
        Assert::false($tokens->consume($token), 'Second consume must lose');
        Assert::false($tokens->isUsable($token, 1003), 'Consumed token must no longer be usable');
    }

    public function testMalformedOrUnknownTokenIsNotUsable(): void
    {
        $tokens = new LeadFormToken(new FakeRedis());

        Assert::false($tokens->isUsable('abc', 1003));
        Assert::false($tokens->isUsable(str_repeat('a', 64), 1003), 'Unknown token must be refused');
        Assert::false($tokens->consume('abc'));
    }

    public function testRedisStoresOnlyTheSha256OfTheTokenWithTtl(): void
    {
        $redis = new FakeRedis();
        $token = (new LeadFormToken($redis))->issue(1000);
        $key = self::PREFIX . hash('sha256', $token);

        Assert::true($redis->hasKey($key), 'Key must be prefix + sha256(token)');
        Assert::same('1000', $redis->rawValue($key), 'Value must be the issue instant');
        Assert::same(LeadFormToken::TTL_SECONDS, $redis->ttl($key));
        Assert::false($redis->hasKey(self::PREFIX . $token), 'Raw token must never be stored as key');
        Assert::false($redis->hasKey($token), 'Raw token must never be stored as key');
    }

    public function testFakeRedisFollowsPhpredisSemantics(): void
    {
        $redis = new FakeRedis();

        Assert::same(-2, $redis->ttl('missing'));
        Assert::same(1, $redis->incr('counter'));
        Assert::same(2, $redis->incr('counter'));
        Assert::same(-1, $redis->ttl('counter'));
        Assert::true($redis->expire('counter', 60));
        Assert::same(60, $redis->ttl('counter'));
        Assert::false($redis->expire('missing', 60));

        $redis->set('plain', 'x', ['ex' => 30]);
        Assert::same(30, $redis->ttl('plain'));
        $redis->set('plain', 'y');
        Assert::same(-1, $redis->ttl('plain'), 'set without EX clears the TTL');

        Assert::same(2, $redis->del('counter', 'plain', 'missing'));
        Assert::same(0, $redis->del('counter'));
        Assert::same(-2, $redis->ttl('counter'));
    }

    public function testLeadThrottleBlocksAfterTenHitsPerHour(): void
    {
        $limiter = new LoginRateLimiter(new FakeRedis(), 10, 3600, 'centralvet:lead-throttle:');
        $identifier = 'lead|203.0.113.7';

        for ($i = 0; $i < 9; $i++) {
            $limiter->hit($identifier);
        }

        Assert::false($limiter->tooManyAttempts($identifier), '9 hits must still be allowed');

        $limiter->hit($identifier);

        Assert::true($limiter->tooManyAttempts($identifier));
        Assert::same(3600, $limiter->secondsUntilAvailable($identifier));
    }
}
