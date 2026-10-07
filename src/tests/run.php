<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free test runner for CentralVet\Tests (T-11).
 *
 * PHPUnit is NOT a dependency of this project (composer.json has no
 * require-dev; "phpunit/phpunit" only ever appears in composer.lock as a
 * require-dev of unrelated third-party packages, never installed under
 * vendor/), so this runner replaces it with something small and
 * deterministic, invoked exactly as composer.json's "test:unit" script
 * already expects: `php tests/run.php`.
 *
 * Discovery convention: every *Test.php file directly under Unit/ or
 * Integration/ must declare a class CentralVet\Tests\{Suite}\{FileName}
 * with zero-argument public "test*" methods. Optional public setUp()/
 * tearDown() methods run around each test method, mirroring familiar
 * xUnit semantics.
 *
 * Three outcomes per test:
 *   PASS - the method ran without throwing.
 *   FAIL - an CentralVet\Tests\Support\AssertionFailedException (or any
 *          other Throwable) was thrown.
 *   SKIP - a CentralVet\Tests\Support\SkippedTestException was thrown,
 *          which integration tests use when their real dependency (the
 *          `redis` service from docker-compose.yml) is not reachable from
 *          the current environment. A skip is never a failure and never
 *          flips the process exit code.
 *
 * Exit code is 0 only when zero tests failed (skips are fine).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Integration tests connect to the real `redis` service (see
// Support\RedisIntegrationTestCase). The inherited REDIS_DATABASE is the
// APPLICATION's database (docker-compose.yml injects REDIS_DATABASE=0 into
// the `app` container, and browser sessions live there), so it is always
// ignored: the suite runs on TEST_REDIS_DATABASE, default 15. If that
// resolves to the application's own database, refuse to run at all.
// ('0' is falsy in PHP, so an explicit TEST_REDIS_DATABASE=0 is checked
// against false/'' instead of using ?:.)
$centralvetInheritedRedisDatabase = getenv('REDIS_DATABASE');
$centralvetApplicationRedisDatabase = ($centralvetInheritedRedisDatabase === false || $centralvetInheritedRedisDatabase === '')
    ? 0
    : (int) $centralvetInheritedRedisDatabase;
$centralvetTestRedisDatabaseRaw = getenv('TEST_REDIS_DATABASE');
$centralvetTestRedisDatabase = ($centralvetTestRedisDatabaseRaw === false || $centralvetTestRedisDatabaseRaw === '')
    ? '15'
    : $centralvetTestRedisDatabaseRaw;

// Redis has databases 0..15 (redis.conf default `databases 16`). Anything
// else must be refused up front: RedisConnectionFactory skips select() for
// values <= 0 and ignores select()'s false return for values > 15, so both
// "-1" and "99" would silently run the suite on DB 0, next to the browser
// sessions (T-55).
if (!ctype_digit($centralvetTestRedisDatabase) || (int) $centralvetTestRedisDatabase > 15) {
    fwrite(STDERR, sprintf(
        "Refusing to run: TEST_REDIS_DATABASE must be an integer between 0 and 15 (%s)\n",
        $centralvetTestRedisDatabase,
    ));
    exit(1);
}

if ((int) $centralvetTestRedisDatabase === $centralvetApplicationRedisDatabase) {
    fwrite(STDERR, sprintf(
        "Refusing to run: test Redis database equals the application database (%d)\n",
        $centralvetApplicationRedisDatabase,
    ));
    exit(1);
}

putenv('REDIS_DATABASE=' . $centralvetTestRedisDatabase);

// Preflight: when the real Redis is reachable, select() the test database
// once and abort if the server refuses it (false), instead of letting the
// integration tests fall back to DB 0. Unreachable Redis is not an error
// here: RedisIntegrationTestCase reports those tests as SKIP.
if (extension_loaded('redis')) {
    $centralvetPreflightRedis = new \Redis();

    try {
        $centralvetPreflightConnected = @$centralvetPreflightRedis->connect(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
            1.5,
        );
    } catch (\Throwable) {
        $centralvetPreflightConnected = false;
    }

    if ($centralvetPreflightConnected) {
        $centralvetPreflightPassword = getenv('REDIS_PASSWORD');

        if (is_string($centralvetPreflightPassword) && $centralvetPreflightPassword !== '') {
            $centralvetPreflightRedis->auth($centralvetPreflightPassword);
        }

        if ($centralvetPreflightRedis->select((int) $centralvetTestRedisDatabase) === false) {
            fwrite(STDERR, sprintf(
                "Refusing to run: Redis refused SELECT %d for TEST_REDIS_DATABASE\n",
                (int) $centralvetTestRedisDatabase,
            ));
            exit(1);
        }

        $centralvetPreflightRedis->close();
    }

    unset($centralvetPreflightRedis, $centralvetPreflightConnected, $centralvetPreflightPassword);
}

// Belt-and-braces autoloader for the CentralVet\Tests\ namespace: the
// project's composer.json already declares it under "autoload-dev", but an
// environment where `composer install --no-dev` was used (this project's
// own docker/php/Dockerfile does exactly that for the app/worker images)
// would not wire it up. This mirrors exactly the PSR-4 mapping declared in
// composer.json (CentralVet\Tests\ => tests/), so behaviour is identical
// either way.
spl_autoload_register(static function (string $class): void {
    $prefix = 'CentralVet\\Tests\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

// MySQL: integration tests run on the dedicated test database
// (TestDatabase::DEFAULT_NAME = centralvet_test, or TEST_DB_DATABASE), never
// on the application's DB_DATABASE. Refuse up front when the name resolves
// to the application database, or when MySQL is reachable but the resolved
// database does not exist (it would otherwise skip or fail every MySQL test).
// Unreachable MySQL is not an error here: MysqlIntegrationTestCase reports
// those tests as SKIP.
try {
    $centralvetTestDbName = \CentralVet\Tests\Support\TestDatabase::resolveName(getenv());
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if (extension_loaded('pdo_mysql')) {
    try {
        $centralvetPreflightPdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
            ),
            (string) (getenv('DB_USERNAME') ?: 'centralvet'),
            (string) (getenv('DB_PASSWORD') ?: ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 2],
        );
    } catch (\Throwable) {
        $centralvetPreflightPdo = null;
    }

    if ($centralvetPreflightPdo !== null) {
        $centralvetPreflightStatement = $centralvetPreflightPdo->prepare(
            'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
        );
        $centralvetPreflightStatement->execute([$centralvetTestDbName]);

        if ($centralvetPreflightStatement->fetchColumn() === false) {
            fwrite(STDERR, sprintf(
                "Refusing to run: test MySQL database %s not found (see docs/runbooks/tests.md)\n",
                $centralvetTestDbName,
            ));
            exit(1);
        }
    }

    unset($centralvetPreflightPdo, $centralvetPreflightStatement);
}

unset($centralvetTestDbName);

use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\SkippedTestException;

/** @return list<string> */
function centralvet_discover_test_files(string $directory): array
{
    $files = glob($directory . '/*Test.php') ?: [];
    sort($files);

    return $files;
}

$suites = [
    'Unit' => centralvet_discover_test_files(__DIR__ . '/Unit'),
    'Integration' => centralvet_discover_test_files(__DIR__ . '/Integration'),
];

$total = 0;
$passed = 0;
$failed = 0;
$skipped = 0;
/** @var list<string> $failures */
$failures = [];

foreach ($suites as $suiteName => $files) {
    foreach ($files as $file) {
        require_once $file;

        $className = basename($file, '.php');
        $fqcn = "CentralVet\\Tests\\{$suiteName}\\{$className}";

        if (!class_exists($fqcn)) {
            continue;
        }

        $reflection = new ReflectionClass($fqcn);

        if ($reflection->isAbstract()) {
            continue;
        }

        $methods = array_values(array_filter(
            get_class_methods($fqcn),
            static fn (string $method): bool => str_starts_with($method, 'test'),
        ));
        sort($methods);

        foreach ($methods as $method) {
            $total++;
            $label = "{$suiteName}\\{$className}::{$method}";
            $instance = $reflection->newInstance();

            try {
                if (method_exists($instance, 'setUp')) {
                    $instance->setUp();
                }

                $instance->{$method}();

                if (method_exists($instance, 'tearDown')) {
                    $instance->tearDown();
                }

                $passed++;
                echo "  PASS  {$label}\n";
            } catch (SkippedTestException $e) {
                $skipped++;
                echo "  SKIP  {$label} ({$e->getMessage()})\n";
                centralvet_safe_teardown($instance);
            } catch (Throwable $e) {
                $failed++;
                $kind = $e instanceof AssertionFailedException ? '' : ' [' . $e::class . ']';
                $failures[] = "{$label}{$kind}: {$e->getMessage()}";
                echo "  FAIL  {$label} - {$e->getMessage()}\n";
                centralvet_safe_teardown($instance);
            }
        }
    }
}

function centralvet_safe_teardown(object $instance): void
{
    if (!method_exists($instance, 'tearDown')) {
        return;
    }

    try {
        $instance->tearDown();
    } catch (Throwable) {
        // tearDown() failures after an already-recorded outcome must not
        // mask the original result or crash the runner.
    }
}

echo "\n";
printf("Total: %d, Passed: %d, Failed: %d, Skipped: %d\n", $total, $passed, $failed, $skipped);

if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
}

exit($failed > 0 ? 1 : 0);
