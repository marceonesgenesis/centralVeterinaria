<?php

declare(strict_types=1);

namespace CentralVet\Redis;

/**
 * Builds Redis keys prefixed by environment (and, when applicable, by
 * tenant), so a single Redis instance can be shared across environments and
 * tenants without collisions or accidental cross-tenant reads.
 */
final class KeyNamespace
{
    private const ROOT = 'cv';

    public function __construct(private readonly string $environment)
    {
    }

    public static function fromEnvironmentVariable(): self
    {
        return new self(self::sanitize(getenv('APP_ENV') ?: 'development'));
    }

    /**
     * Environment-scoped key, shared across all tenants (e.g. queue channels,
     * where a single worker services every tenant and isolation is enforced
     * via the tenant_id carried inside each message, mirroring the shared
     * database + tenant_id approach used elsewhere in the project).
     */
    public function key(string $namespace, string $key): string
    {
        return sprintf('%s:%s:%s:%s', self::ROOT, $this->environment, self::sanitize($namespace), self::sanitize($key));
    }

    /**
     * Tenant-scoped key (e.g. cache entries, locks), physically isolated per
     * tenant so two tenants can never collide on the same cache/lock name.
     */
    public function tenantKey(int $tenantId, string $namespace, string $key): string
    {
        return sprintf(
            '%s:%s:tenant:%d:%s:%s',
            self::ROOT,
            $this->environment,
            $tenantId,
            self::sanitize($namespace),
            self::sanitize($key),
        );
    }

    private static function sanitize(string $value): string
    {
        $normalized = strtolower(trim($value));
        $safe = preg_replace('/[^a-z0-9_.\-]+/', '_', $normalized);

        return $safe === '' ? '_' : $safe;
    }
}
