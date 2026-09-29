<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Redis\KeyNamespace;
use CentralVet\Tests\Support\Assert;

final class KeyNamespaceTest
{
    public function testKeyFormatsEnvironmentScopedKey(): void
    {
        $keys = new KeyNamespace('testing');

        Assert::same('cv:testing:queue:pending', $keys->key('queue', 'pending'));
    }

    public function testTenantKeyFormatsTenantScopedKeyAndDiffersFromPlainKey(): void
    {
        $keys = new KeyNamespace('testing');

        $tenantKey = $keys->tenantKey(101, 'cache', 'dashboard');

        Assert::same('cv:testing:tenant:101:cache:dashboard', $tenantKey);
    }

    public function testTenantKeysAreIsolatedPerTenant(): void
    {
        $keys = new KeyNamespace('testing');

        $tenant101 = $keys->tenantKey(101, 'cache', 'dashboard');
        $tenant202 = $keys->tenantKey(202, 'cache', 'dashboard');

        Assert::false($tenant101 === $tenant202, 'Two different tenants must never share the same physical cache key');
    }

    public function testSanitizesUnsafeCharactersAndCaseInNamespaceAndKeySegments(): void
    {
        $keys = new KeyNamespace('testing');

        // Spaces/punctuation collapse to underscores and the value is
        // lower-cased, so the resulting key is a safe, predictable token.
        // (The environment segment itself is only sanitized by the
        // fromEnvironmentVariable() factory, not by a raw constructor call
        // — see testFromEnvironmentVariableSanitizesAppEnv below.)
        Assert::same('cv:testing:queue_default_:pending', $keys->key('Queue Default!', 'pending'));
        Assert::same('cv:testing:queue:pending_report_', $keys->key('queue', 'Pending Report!'));
    }

    public function testFromEnvironmentVariableSanitizesAppEnv(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=Staging Env!');

        try {
            $keys = KeyNamespace::fromEnvironmentVariable();

            Assert::same('cv:staging_env_:queue:pending', $keys->key('queue', 'pending'));
        } finally {
            $previous === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previous);
        }
    }

    public function testEmptySegmentFallsBackToUnderscore(): void
    {
        $keys = new KeyNamespace('testing');

        Assert::same('cv:testing:_:_', $keys->key('', ''));
    }
}
