<?php

declare(strict_types=1);

namespace CentralVet\Observability\ErrorTracking;

use CentralVet\Observability\Logging\LoggerInterface;

/**
 * Local error tracking adapter: records exceptions/messages as structured
 * JSON logs instead of a SaaS error tracker (e.g. Sentry). A real provider
 * can be added later behind ErrorTrackerInterface without changing callers.
 */
final class LogErrorTracker implements ErrorTrackerInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function captureException(\Throwable $exception, array $context = []): void
    {
        $this->logger->critical('exception.captured', array_merge($context, [
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]));
    }

    public function captureMessage(string $message, string $level = 'error', array $context = []): void
    {
        $method = in_array($level, ['debug', 'info', 'warning', 'error', 'critical'], true) ? $level : 'error';
        $this->logger->{$method}($message, $context);
    }
}
