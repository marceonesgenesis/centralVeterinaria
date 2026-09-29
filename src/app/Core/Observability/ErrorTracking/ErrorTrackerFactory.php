<?php

declare(strict_types=1);

namespace CentralVet\Observability\ErrorTracking;

use CentralVet\Observability\Logging\LoggerInterface;

/**
 * Picks the error tracking adapter via ERROR_TRACKING_DRIVER (default:
 * "null"). No driver requires a real external provider or secret; "log" is
 * the local option.
 */
final class ErrorTrackerFactory
{
    public static function fromEnvironment(LoggerInterface $logger): ErrorTrackerInterface
    {
        return match (strtolower((string) (getenv('ERROR_TRACKING_DRIVER') ?: 'null'))) {
            'log' => new LogErrorTracker($logger),
            default => new NullErrorTracker(),
        };
    }
}
