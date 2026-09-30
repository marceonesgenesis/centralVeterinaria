<?php

declare(strict_types=1);

namespace CentralVet\Authorization;

use CentralVet\Tenancy\TenantContext;

/**
 * Everything a policy needs to decide one authorization question and to
 * build the resulting audit event. Instances are immutable.
 *
 * $metadata is extra, non-sensitive context to attach to the audit trail
 * (e.g. a screen name or a coarse filter). It must never contain passwords,
 * tokens, cookies or unnecessary PII — CentralVet\Audit\AuditRedactor still
 * redacts it defensively before persistence, but callers must not rely on
 * that as their only safeguard.
 */
final class AuthorizationRequest
{
    /**
     * Accepted action formats: `Class::method` (optionally namespaced) or a
     * dotted permission key such as `patient.view`. Anything else fails early.
     */
    public const ACTION_PATTERN = '/\A(?:[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*::[A-Za-z_][A-Za-z0-9_]*|[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)\z/';

    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        private readonly TenantContext $context,
        private readonly string $action,
        private readonly bool $requiresUnitScope = false,
        private readonly ?int $resourceTenantId = null,
        private readonly ?int $resourceUnitId = null,
        private readonly string $entityType = 'authorization',
        private readonly string|int|null $entityId = null,
        private readonly array $metadata = [],
        private readonly ?string $ipAddress = null,
        private readonly ?string $userAgent = null,
    ) {
        if (preg_match(self::ACTION_PATTERN, $action) !== 1) {
            throw new \InvalidArgumentException(
                'Invalid authorization action format: '
                . json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
    }

    public function context(): TenantContext
    {
        return $this->context;
    }

    public function action(): string
    {
        return $this->action;
    }

    /**
     * When true, the active unit (TenantContext::unitId()) must be present
     * and, if $resourceUnitId is also given, must match it. Actions that are
     * not unit-scoped (e.g. tenant-wide administration) leave this false.
     */
    public function requiresUnitScope(): bool
    {
        return $this->requiresUnitScope;
    }

    public function resourceTenantId(): ?int
    {
        return $this->resourceTenantId;
    }

    public function resourceUnitId(): ?int
    {
        return $this->resourceUnitId;
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function entityId(): string|int|null
    {
        return $this->entityId;
    }

    /** @return array<string, scalar|null> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }
}
