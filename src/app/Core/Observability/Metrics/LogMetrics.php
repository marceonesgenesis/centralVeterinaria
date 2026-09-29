<?php

declare(strict_types=1);

namespace CentralVet\Observability\Metrics;

use CentralVet\Observability\Logging\LoggerInterface;

/**
 * Local metrics adapter: emits each measurement as a structured JSON log
 * line instead of shipping to a SaaS metrics backend. Enough to prove the
 * instrumentation points work locally (e.g. `docker logs` + grep), and a
 * real backend (Prometheus pushgateway, StatsD, ...) can later be added as
 * another MetricsInterface implementation without touching call sites.
 */
final class LogMetrics implements MetricsInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function increment(string $name, int $value = 1, array $tags = []): void
    {
        $this->logger->info('metric.increment', ['metric' => $name, 'value' => $value, 'tags' => $tags]);
    }

    public function timing(string $name, float $milliseconds, array $tags = []): void
    {
        $this->logger->info('metric.timing', ['metric' => $name, 'value_ms' => $milliseconds, 'tags' => $tags]);
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $this->logger->info('metric.gauge', ['metric' => $name, 'value' => $value, 'tags' => $tags]);
    }
}
