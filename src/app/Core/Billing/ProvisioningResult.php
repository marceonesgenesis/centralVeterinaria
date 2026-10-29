<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use InvalidArgumentException;

/** Outcome of TenantProvisioningService::fulfill(). */
final class ProvisioningResult
{
    public const PROVISIONED = 'provisioned';
    public const ALREADY_PROVISIONED = 'already_provisioned';
    public const FAILED = 'failed';
    public const NOT_PAID = 'not_paid';

    private const STATUSES = [self::PROVISIONED, self::ALREADY_PROVISIONED, self::FAILED, self::NOT_PAID];

    public function __construct(
        private readonly string $status,
        private readonly ?int $tenantId = null,
        private readonly ?string $errorCode = null,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown provisioning status "%s"', $status));
        }
    }

    public function status(): string
    {
        return $this->status;
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    /** Code saved in billing_order.provisioning_error when status is failed. */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }
}
