<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Storage\ObjectKeyNamespace;
use CentralVet\Tests\Support\Assert;

final class ObjectKeyNamespaceTest
{
    public function testKeyFormatsEnvironmentScopedObjectKey(): void
    {
        $keys = new ObjectKeyNamespace('testing');

        Assert::same('cv/testing/avatars/logo.png', $keys->key('avatars', 'logo.png'));
    }

    public function testTenantKeyFormatsTenantScopedObjectKey(): void
    {
        $keys = new ObjectKeyNamespace('testing');

        Assert::same('cv/testing/tenant/101/avatars/logo.png', $keys->tenantKey(101, 'avatars', 'logo.png'));
    }

    public function testTenantKeysAreIsolatedPerTenantForTheSameLogicalKey(): void
    {
        $keys = new ObjectKeyNamespace('testing');

        $tenant101 = $keys->tenantKey(101, 'avatars', 'logo.png');
        $tenant202 = $keys->tenantKey(202, 'avatars', 'logo.png');

        Assert::false($tenant101 === $tenant202);
    }

    public function testNeutralizesPathTraversalSegments(): void
    {
        $keys = new ObjectKeyNamespace('testing');

        Assert::same('cv/testing/avatars/_/_/etc/passwd', $keys->key('avatars', '../../etc/passwd'));
    }

    public function testNeutralizesSingleDotSegment(): void
    {
        $keys = new ObjectKeyNamespace('testing');

        Assert::same('cv/testing/avatars/_/logo.png', $keys->key('avatars', './logo.png'));
    }

    public function testConvertsBackslashesToPathSeparators(): void
    {
        $keys = new ObjectKeyNamespace('testing');

        Assert::same('cv/testing/avatars/a/b.png', $keys->key('avatars', 'a\\b.png'));
    }

    public function testCollapsesEmptySegmentsFromRepeatedSeparators(): void
    {
        $keys = new ObjectKeyNamespace('testing');

        Assert::same('cv/testing/avatars/a/b.png', $keys->key('avatars', 'a//b.png'));
    }

    public function testUnsafeCharactersInSegmentsAreReplaced(): void
    {
        $keys = new ObjectKeyNamespace('testing');

        Assert::same('cv/testing/avatars/a_b_c.png', $keys->key('avatars', 'a b#c.png'));
    }
}
