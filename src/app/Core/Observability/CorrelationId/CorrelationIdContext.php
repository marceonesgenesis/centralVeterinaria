<?php

declare(strict_types=1);

namespace CentralVet\Observability\CorrelationId;

/**
 * Per-process holder for the current request/job correlation id.
 *
 * This is technical observability plumbing (logs, metrics, queue messages),
 * separate from the `audit_log.correlation_id` column used for business
 * auditing (see ADR 0003). The two may share the same value when a request
 * both logs and writes an audit entry, but this class has no dependency on
 * the audit_log table or its migration.
 *
 * Each Adianti entrypoint (index.php/cmd.php/worker.php) runs as its own PHP
 * process, so a static holder is safe here for the same reason TSession is
 * used as a static facade elsewhere in this codebase: there is exactly one
 * logical "request" per process lifetime (or, for the worker, one job at a
 * time within its loop).
 */
final class CorrelationIdContext
{
    private static ?string $current = null;

    private function __construct()
    {
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Starts (or restarts) the current correlation id, accepting an incoming
     * value (e.g. from an upstream header or a re-queued job) when it looks
     * safe to reuse, otherwise generating a fresh one.
     */
    public static function start(?string $incoming = null): string
    {
        return self::$current = self::sanitize($incoming) ?? self::generate();
    }

    public static function current(): ?string
    {
        return self::$current;
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    private static function sanitize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = substr(trim($value), 0, 64);

        return preg_match('/^[A-Za-z0-9_-]+$/', $trimmed) === 1 ? $trimmed : null;
    }
}
