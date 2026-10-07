<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Redis\RedisConnectionFactory;

/**
 * Shared setup for integration tests that need a real Redis connection
 * (the `redis` service from docker-compose.yml — ephemeral test data, never
 * production). Connecting is attempted once per test; when it fails (e.g.
 * running on a host that cannot resolve/reach the `redis` service, such as
 * outside the project's own containers), the test is reported as SKIPPED,
 * never as a failure.
 *
 * All keys these tests write use the 'testing' KeyNamespace environment and
 * dedicated prefixes/tenant ids, and every test cleans up exactly the keys
 * it created in tearDown() — no FLUSHALL/FLUSHDB, so this never touches
 * unrelated data that might already live in the same Redis instance.
 *
 * The database comes from REDIS_DATABASE as set by tests/run.php, which
 * always replaces the inherited (application) value with
 * TEST_REDIS_DATABASE (default 15) and refuses to run when both match, so
 * these tests never share a database with the application's sessions.
 */
abstract class RedisIntegrationTestCase
{
    protected \Redis $redis;

    public function setUp(): void
    {
        try {
            $this->redis = RedisConnectionFactory::fromEnvironment();
        } catch (\Throwable $e) {
            throw new SkippedTestException('Real Redis service unreachable: ' . $e->getMessage());
        }
    }
}
