<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use RuntimeException;

/**
 * Thrown by Assert::* on a failed expectation. Caught by tests/run.php,
 * which reports it as a FAIL for the current test method without stopping
 * the rest of the suite.
 */
final class AssertionFailedException extends RuntimeException
{
}
