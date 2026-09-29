<?php

declare(strict_types=1);

namespace CentralVet\Observability\Metrics;

/** Default adapter: metrics collection disabled, zero overhead. */
final class NullMetrics implements MetricsInterface
{
    public function increment(string $name, int $value = 1, array $tags = []): void
    {
    }

    public function timing(string $name, float $milliseconds, array $tags = []): void
    {
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
    }
}
