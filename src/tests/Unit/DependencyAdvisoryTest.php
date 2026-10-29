<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\PasswordResetToken;
use CentralVet\Tests\Support\Assert;
use Dompdf\Dompdf;
use InvalidArgumentException;

/**
 * S5 (T-04): dompdf >= 3.1.6 and firebase/php-jwt >= 7.0.0 are locked,
 * the protected composer.json hash in framework_hashes.php is current,
 * and the JWT and PDF paths still work on the new versions.
 */
final class DependencyAdvisoryTest
{
    private const NOW = 1_800_000_000;

    public function testLockedDompdfIsAtLeast316(): void
    {
        $version = self::lockedVersion('dompdf/dompdf');
        Assert::true(version_compare($version, '3.1.6', '>='), "dompdf/dompdf locked at {$version}, expected >= 3.1.6");
    }

    public function testLockedPhpJwtIsAtLeast700(): void
    {
        $version = self::lockedVersion('firebase/php-jwt');
        Assert::true(version_compare($version, '7.0.0', '>='), "firebase/php-jwt locked at {$version}, expected >= 7.0.0");
    }

    public function testFrameworkHashMatchesComposerJson(): void
    {
        $hashes = require self::src() . '/app/config/framework_hashes.php';
        Assert::true(is_array($hashes) && isset($hashes['composer.json']), 'framework_hashes.php must list composer.json');
        Assert::same(hash_file('sha512', self::src() . '/composer.json'), $hashes['composer.json']);
    }

    public function testShortKeyIsRejectedWithClearMessage(): void
    {
        $message = null;
        try {
            new PasswordResetToken(str_repeat('a', 31));
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
        Assert::same('JWT HMAC key must have at least 32 bytes', $message);
    }

    public function testThirtyTwoByteKeyIssuesAndVerifies(): void
    {
        $tokens = new PasswordResetToken(str_repeat('k', 32));
        $claims = $tokens->verify($tokens->issue('admin', 'hash-A', self::NOW), self::NOW + 60);
        Assert::same('admin', $claims['user']);
    }

    public function testDompdfRendersPdf(): void
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<p>ZZ</p>');
        $dompdf->render();
        Assert::true(str_starts_with((string) $dompdf->output(), '%PDF'), 'dompdf output must start with %PDF');
    }

    private static function src(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function lockedVersion(string $name): string
    {
        $lock = json_decode((string) file_get_contents(self::src() . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($lock['packages'] ?? [] as $package) {
            if (($package['name'] ?? null) === $name) {
                return ltrim((string) $package['version'], 'v');
            }
        }

        return '0.0.0';
    }
}
