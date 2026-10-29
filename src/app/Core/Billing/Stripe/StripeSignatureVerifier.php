<?php

declare(strict_types=1);

namespace CentralVet\Billing\Stripe;

/**
 * Verifies the Stripe-Signature header of a webhook request:
 * "t=<unix ts>,v1=<hex HMAC-SHA256 of "<t>.<payload>">[,v1=...]".
 * Any v1 matching (hash_equals) is accepted, inside the tolerance window.
 */
final class StripeSignatureVerifier
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    /** @throws InvalidWebhookSignature */
    public function verify(string $payload, string $signatureHeader, int $now): void
    {
        if ($this->webhookSecret === '') {
            throw new InvalidWebhookSignature('Webhook secret is not configured');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', trim($signatureHeader)) as $part) {
            [$key, $value] = array_map('trim', explode('=', $part, 2)) + [1 => ''];
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new InvalidWebhookSignature('Stripe-Signature header is missing or malformed');
        }

        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            throw new InvalidWebhookSignature('Stripe-Signature timestamp is outside the tolerance');
        }

        $expected = self::sign($payload, $this->webhookSecret, $timestamp);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidWebhookSignature('Stripe-Signature does not match the payload');
    }

    /** Builds a valid header for tests: "t=<ts>,v1=<hex>". */
    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . self::sign($payload, $secret, $timestamp);
    }

    private static function sign(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
