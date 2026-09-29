<?php

declare(strict_types=1);

namespace CentralVet\Tenancy;

use CentralVet\Tenancy\Exception\MissingTenantContext;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;

final class TenantContext
{
    private function __construct(
        private readonly int $tenantId,
        private readonly int $userId,
        private readonly ?int $unitId,
    ) {
    }

    public static function fromAuthenticatedSession(SessionContextSource $session): self
    {
        if ($session->get('logged') !== true) {
            throw new MissingTenantContext('An authenticated session is required');
        }

        return self::authenticated(
            self::positiveInteger($session->get('tenantid'), 'tenantid'),
            self::positiveInteger($session->get('userid'), 'userid'),
            self::optionalPositiveInteger($session->get('userunitid'), 'userunitid'),
        );
    }

    public static function authenticated(int $tenantId, int $userId, ?int $unitId = null): self
    {
        if ($tenantId <= 0 || $userId <= 0 || ($unitId !== null && $unitId <= 0)) {
            throw new MissingTenantContext('Tenant, user and unit identifiers must be positive');
        }

        return new self($tenantId, $userId, $unitId);
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function unitId(): ?int
    {
        return $this->unitId;
    }

    public function requireUnitId(): int
    {
        return $this->unitId ?? throw new MissingTenantContext('An active unit is required');
    }

    public function assertTenant(int $tenantId): void
    {
        if ($tenantId !== $this->tenantId) {
            throw new TenantBoundaryViolation('Resource does not belong to the authenticated tenant');
        }
    }

    private static function positiveInteger(mixed $value, string $key): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new MissingTenantContext("Missing or invalid session key: {$key}");
        }

        return (int) $value;
    }

    private static function optionalPositiveInteger(mixed $value, string $key): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::positiveInteger($value, $key);
    }
}
