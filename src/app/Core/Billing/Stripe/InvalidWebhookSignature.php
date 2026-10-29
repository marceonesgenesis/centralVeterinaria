<?php

declare(strict_types=1);

namespace CentralVet\Billing\Stripe;

/** The Stripe-Signature header of a webhook request did not verify. */
final class InvalidWebhookSignature extends \RuntimeException
{
}
