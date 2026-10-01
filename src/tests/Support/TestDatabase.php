<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

/**
 * Resolves the MySQL database the integration tests connect to.
 *
 * TEST_DB_DATABASE wins when set (and must differ from DB_DATABASE, the
 * application's database). Otherwise DEFAULT_NAME is used; while it is null
 * the suite keeps running on the application database (DB_DATABASE), inside
 * the per-test transaction that MysqlIntegrationTestCase rolls back.
 */
final class TestDatabase
{
    public const DEFAULT_NAME = null;

    private function __construct()
    {
    }

    /** @param array<string, string> $env */
    public static function resolveName(array $env): string
    {
        $testName = (string) ($env['TEST_DB_DATABASE'] ?? '');

        if ($testName !== '') {
            if ($testName === ($env['DB_DATABASE'] ?? null)) {
                throw new \RuntimeException(sprintf(
                    'Refusing to run: test MySQL database equals the application database (%s)',
                    $testName,
                ));
            }

            return $testName;
        }

        return self::DEFAULT_NAME ?? ($env['DB_DATABASE'] ?? 'centralvet');
    }
}
