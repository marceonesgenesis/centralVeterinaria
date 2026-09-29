<?php

declare(strict_types=1);

namespace CentralVet\Observability\ErrorTracking;

interface ErrorTrackerInterface
{
    public function captureException(\Throwable $exception, array $context = []): void;

    public function captureMessage(string $message, string $level = 'error', array $context = []): void;
}
