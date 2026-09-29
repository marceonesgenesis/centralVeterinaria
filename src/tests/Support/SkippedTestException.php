<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use RuntimeException;

/**
 * Thrown by an integration test's setUp() when its real dependency (e.g. the
 * Redis service from docker-compose) is not reachable from the current
 * environment. Caught by tests/run.php and reported as SKIP, never as a
 * failure: a host without network access to the `redis` service is an
 * expected, non-broken condition (see tests/README notes in run.php header).
 */
final class SkippedTestException extends RuntimeException
{
}
