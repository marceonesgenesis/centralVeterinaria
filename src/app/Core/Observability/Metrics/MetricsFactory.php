<?php

declare(strict_types=1);

namespace CentralVet\Observability\Metrics;

use CentralVet\Observability\Logging\LoggerInterface;

/**
 * Picks the metrics adapter via METRICS_DRIVER (default: "null"). No driver
 * requires a real external provider or secret; "log" is the local option.
 */
final class MetricsFactory
{
    public static function fromEnvironment(LoggerInterface $logger): MetricsInterface
    {
        return match (strtolower((string) (getenv('METRICS_DRIVER') ?: 'null'))) {
            'log' => new LogMetrics($logger),
            default => new NullMetrics(),
        };
    }
}
