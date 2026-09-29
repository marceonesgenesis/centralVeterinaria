<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Tenancy\SessionContextSource;

/**
 * In-memory double for CentralVet\Tenancy\SessionContextSource, so
 * TenantContext::fromAuthenticatedSession() can be exercised without a real
 * Adianti TSession.
 */
final class FakeSessionContextSource implements SessionContextSource
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
