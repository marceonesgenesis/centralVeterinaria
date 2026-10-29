<?php

declare(strict_types=1);

namespace CentralVet\Billing\Stripe;

/**
 * Minimal Stripe REST client (Etapa 3, T-08): no SDK, one method.
 *
 * Params are sent as application/x-www-form-urlencoded with nested
 * brackets (line_items[0][price]=...) in the body for POST and in the
 * query string for GET/DELETE. The decoded JSON response is returned.
 */
interface StripeClientInterface
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws StripeApiException on HTTP >= 400, network failure or invalid JSON
     */
    public function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array;
}
