<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

/**
 * Resolves the MySQL database the integration tests connect to.
 *
 * TEST_DB_DATABASE wins when set; otherwise DEFAULT_NAME, the dedicated
 * centralvet_test database (provisioned by scripts/test-db/provision.sh).
 * The resolved name must differ from DB_DATABASE, the application's
 * database: the suite never runs there.
 */
final class TestDatabase
{
    public const DEFAULT_NAME = 'centralvet_test';

    private function __construct()
    {
    }

    /** @param array<string, string> $env */
    public static function resolveName(array $env): string
    {
        $testName = (string) ($env['TEST_DB_DATABASE'] ?? '');

        if ($testName === '') {
            $testName = self::DEFAULT_NAME;
        }

        if ($testName === ($env['DB_DATABASE'] ?? null)) {
            throw new \RuntimeException(sprintf(
                'Refusing to run: test MySQL database equals the application database (%s)',
                $testName,
            ));
        }

        return $testName;
    }
}
