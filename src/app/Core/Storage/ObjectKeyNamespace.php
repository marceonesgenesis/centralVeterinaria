<?php

declare(strict_types=1);

namespace CentralVet\Storage;

/**
 * Builds object storage keys prefixed by environment (and, when applicable,
 * by tenant), following the same convention as
 * CentralVet\Redis\KeyNamespace: a single bucket can be shared across
 * environments and tenants without collisions or accidental cross-tenant
 * reads, mirroring the project's "shared backend + explicit scope prefix"
 * approach used for the database (tenant_id) and Redis (key prefix).
 */
final class ObjectKeyNamespace
{
    private const ROOT = 'cv';

    public function __construct(private readonly string $environment)
    {
    }

    public static function fromEnvironmentVariable(): self
    {
        return new self(self::sanitizeSegment(getenv('APP_ENV') ?: 'development'));
    }

    /**
     * Environment-scoped object key, shared across all tenants (e.g.
     * system-level assets that do not belong to a single tenant).
     */
    public function key(string $namespace, string $key): string
    {
        return sprintf(
            '%s/%s/%s/%s',
            self::ROOT,
            $this->environment,
            self::sanitizeSegment($namespace),
            self::sanitizeKey($key),
        );
    }

    /**
     * Tenant-scoped object key, physically isolated per tenant so two
     * tenants can never collide on (or read) the same object key.
     */
    public function tenantKey(int $tenantId, string $namespace, string $key): string
    {
        return sprintf(
            '%s/%s/tenant/%d/%s/%s',
            self::ROOT,
            $this->environment,
            $tenantId,
            self::sanitizeSegment($namespace),
            self::sanitizeKey($key),
        );
    }

    private static function sanitizeSegment(string $value): string
    {
        $normalized = strtolower(trim($value));
        $safe = preg_replace('/[^a-z0-9_.\-]+/', '_', $normalized);

        return $safe === '' ? '_' : $safe;
    }

    /**
     * Sanitizes a free-form logical key: preserves "/" as a path separator
     * (so callers can group objects, e.g. "avatars/<uuid>.png"), strips any
     * path-traversal segment ("." / "..") and any character outside a safe
     * S3-key set, and drops empty segments produced by that stripping.
     */
    private static function sanitizeKey(string $key): string
    {
        $normalizedKey = str_replace('\\', '/', $key);

        $segments = array_values(array_filter(
            array_map(
                static function (string $segment): string {
                    $safe = (string) preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $segment);

                    return $safe === '.' || $safe === '..' ? '_' : $safe;
                },
                explode('/', $normalizedKey),
            ),
            static fn (string $segment): bool => $segment !== '',
        ));

        return $segments === [] ? '_' : implode('/', $segments);
    }
}
