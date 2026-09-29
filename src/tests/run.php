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
// Support\RedisIntegrationTestCase). Force a dedicated database for the
// test run unless the caller already picked one explicitly, so a forgotten
// REDIS_DATABASE never points this suite at whatever a developer is using
// for local session/cache/queue data on database 0.
if (getenv('REDIS_DATABASE') === false) {
    putenv('REDIS_DATABASE=15');
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
