<?php

declare(strict_types=1);

namespace CentralVet\Billing\Stripe;

/**
 * Stripe API failure. httpStatus() is 0 for a network failure; the message
 * never carries the secret key (CurlStripeClient redacts it).
 */
final class StripeApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $stripeCode = null,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function stripeCode(): ?string
    {
        return $this->stripeCode;
    }
}
