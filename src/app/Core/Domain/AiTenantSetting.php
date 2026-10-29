<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Per-tenant AI settings (`ai_tenant_setting`, migration 0014): accepted
 * term version and monthly token quota. Whether the AI is on lives in the
 * `ai_assistant` feature flag, not here.
 */
final class AiTenantSetting
{
    public const DEFAULT_MONTHLY_TOKEN_QUOTA = 300000;

    private function __construct(
        private readonly int $tenantId,
        private ?string $termsVersion,
        private ?DateTimeImmutable $termsAcceptedAt,
        private ?int $termsAcceptedBySystemUserId,
        private int $monthlyTokenQuota,
    ) {
    }

    public static function defaults(int $tenantId): self
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        return new self($tenantId, null, null, null, self::DEFAULT_MONTHLY_TOKEN_QUOTA);
    }

    /** @param array<string, mixed> $row raw column values of `ai_tenant_setting`. */
    public static function reconstitute(array $row): self
    {
        $version = isset($row['terms_version']) ? (string) $row['terms_version'] : null;

        return new self(
            (int) $row['tenant_id'],
            $version,
            $version !== null && isset($row['terms_accepted_at']) ? new DateTimeImmutable((string) $row['terms_accepted_at']) : null,
            $version !== null && isset($row['terms_accepted_by_system_user_id']) ? (int) $row['terms_accepted_by_system_user_id'] : null,
            isset($row['monthly_token_quota']) ? (int) $row['monthly_token_quota'] : self::DEFAULT_MONTHLY_TOKEN_QUOTA,
        );
    }

    public function acceptTerms(string $version, int $systemUserId, DateTimeImmutable $now): void
    {
        if (trim($version) === '') {
            throw new InvalidArgumentException('Terms version is required');
        }

        if ($systemUserId <= 0) {
            throw new InvalidArgumentException('System user id must be positive');
        }

        $this->termsVersion = $version;
        $this->termsAcceptedAt = $now;
        $this->termsAcceptedBySystemUserId = $systemUserId;
    }

    public function hasAcceptedTerms(string $version): bool
    {
        return $this->termsVersion !== null && $this->termsVersion === $version;
    }

    public function changeQuota(int $monthlyTokenQuota): void
    {
        if ($monthlyTokenQuota < 0) {
            throw new InvalidArgumentException('Monthly token quota must not be negative');
        }

        $this->monthlyTokenQuota = $monthlyTokenQuota;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function monthlyTokenQuota(): int
    {
        return $this->monthlyTokenQuota;
    }

    public function termsVersion(): ?string
    {
        return $this->termsVersion;
    }

    public function termsAcceptedAt(): ?DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function termsAcceptedBySystemUserId(): ?int
    {
        return $this->termsAcceptedBySystemUserId;
    }
}
