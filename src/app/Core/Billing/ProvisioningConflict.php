<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use RuntimeException;

/**
 * Provisioning cannot go on without someone fixing the data: the
 * responsible e-mail is already a login of another user, the plan group is
 * not seeded, or the order has no subscription yet. errorCode() is what
 * goes to billing_order.provisioning_error.
 */
final class ProvisioningConflict extends RuntimeException
{
    public const LOGIN_CONFLICT = 'login_conflict';
    public const GROUP_MISSING = 'group_missing';
    public const SUBSCRIPTION_MISSING = 'subscription_missing';

    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function loginTaken(): self
    {
        return new self(self::LOGIN_CONFLICT, 'A system user with the responsible e-mail as login already exists');
    }

    public static function groupMissing(string $groupName): self
    {
        return new self(self::GROUP_MISSING, sprintf('Plan group "%s" is not seeded', $groupName));
    }

    public static function subscriptionMissing(int $billingOrderId): self
    {
        return new self(self::SUBSCRIPTION_MISSING, sprintf('Billing order %d has no subscription', $billingOrderId));
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
