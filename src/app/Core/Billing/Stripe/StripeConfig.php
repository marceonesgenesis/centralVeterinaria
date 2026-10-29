<?php

declare(strict_types=1);

namespace CentralVet\Billing\Stripe;

/**
 * Stripe credentials and price ids, read from the environment
 * (STRIPE_* in docker-compose.yml / .env). Values are never logged.
 */
final class StripeConfig
{
    public const API_VERSION = '2024-06-20';

    /** Plan id (LandingCatalog) => environment variable with its Stripe price id. */
    private const PRICE_VARIABLES = [
        'starter' => 'STRIPE_PRICE_STARTER',
        'pro' => 'STRIPE_PRICE_PRO',
        'business' => 'STRIPE_PRICE_BUSINESS',
        'enterprise' => 'STRIPE_PRICE_ENTERPRISE',
    ];

    /** @param array<string, string> $priceIds plan id => Stripe price id */
    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret,
        private readonly array $priceIds,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $priceIds = [];
        foreach (self::PRICE_VARIABLES as $planId => $variable) {
            $priceIds[$planId] = self::env($variable);
        }

        return new self(self::env('STRIPE_SECRET_KEY'), self::env('STRIPE_WEBHOOK_SECRET'), $priceIds);
    }

    public function secretKey(): string
    {
        return $this->secretKey;
    }

    public function webhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function priceIdFor(string $planId): string
    {
        $priceId = trim((string) ($this->priceIds[$planId] ?? ''));
        if ($priceId === '') {
            throw new \RuntimeException(sprintf('Stripe price for plan "%s" is not configured', $planId));
        }

        return $priceId;
    }

    private static function env(string $name): string
    {
        return trim((string) (getenv($name) ?: ''));
    }
}
