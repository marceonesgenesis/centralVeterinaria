<?php

declare(strict_types=1);

namespace CentralVet\Billing\Stripe;

/**
 * StripeClientInterface over cURL (Etapa 3, T-08). The optional $transport
 * closure replaces cURL in tests:
 *   fn (string $method, string $url, array $headers, string $body): array{status: int, body: string}
 * $headers is a list of "Name: value" lines.
 */
final class CurlStripeClient implements StripeClientInterface
{
    private const BASE_URL = 'https://api.stripe.com';
    private const TIMEOUT_SECONDS = 30;
    private const SECRET_KEY_PATTERN = '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/';

    private readonly \Closure $transport;

    public function __construct(
        private readonly StripeConfig $config,
        ?\Closure $transport = null,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    public function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $method = strtoupper($method);

        // Fail closed before building the URL or the body: a missing or
        // malformed key must never send customer data to the network. Only
        // secret (sk_) and restricted (rk_) test/live keys pass.
        if (preg_match(self::SECRET_KEY_PATTERN, $this->config->secretKey()) !== 1) {
            throw new StripeApiException(sprintf(
                'Stripe request %s %s refused: STRIPE_SECRET_KEY is empty or not a secret or restricted API key (test or live)',
                $method,
                $path,
            ));
        }

        $url = self::BASE_URL . '/' . ltrim($path, '/');
        $encoded = self::encode($params);
        $body = '';

        if ($method === 'GET' || $method === 'DELETE') {
            if ($encoded !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . $encoded;
            }
        } else {
            $body = $encoded;
        }

        $headers = [
            'Authorization: Bearer ' . $this->config->secretKey(),
            'Stripe-Version: ' . StripeConfig::API_VERSION,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        try {
            $response = ($this->transport)($method, $url, $headers, $body);
        } catch (\Throwable $e) {
            throw new StripeApiException(sprintf(
                'Stripe request %s %s failed: %s',
                $method,
                $path,
                $this->redact($e->getMessage()),
            ));
        }

        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if ($status >= 400 || $status === 0) {
            $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
            $detail = isset($error['message']) && is_string($error['message']) ? ': ' . $this->redact($error['message']) : '';

            throw new StripeApiException(
                sprintf('Stripe request %s %s failed with HTTP %d%s%s', $method, $path, $status, $code !== null ? " ({$code})" : '', $detail),
                $status,
                $code,
            );
        }

        if (!is_array($decoded)) {
            throw new StripeApiException(sprintf('Stripe request %s %s returned invalid JSON (HTTP %d)', $method, $path, $status), $status);
        }

        return $decoded;
    }

    /**
     * Form-encodes nested params the way Stripe expects
     * (a[b][0][c]=v); booleans become true/false, nulls are skipped.
     *
     * @param array<array-key, mixed> $params
     */
    private static function encode(array $params): string
    {
        $pairs = [];
        self::flatten($params, '', $pairs);

        return implode('&', array_map(
            static fn (array $pair): string => urlencode($pair[0]) . '=' . urlencode($pair[1]),
            $pairs,
        ));
    }

    /**
     * @param array<array-key, mixed> $params
     * @param list<array{0: string, 1: string}> $pairs
     */
    private static function flatten(array $params, string $prefix, array &$pairs): void
    {
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                self::flatten($value, $name, $pairs);
            } elseif (is_bool($value)) {
                $pairs[] = [$name, $value ? 'true' : 'false'];
            } elseif ($value !== null) {
                $pairs[] = [$name, (string) $value];
            }
        }
    }

    private function redact(string $message): string
    {
        $secret = $this->config->secretKey();
        if ($secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return (string) preg_replace('/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9*]*/', '[redacted]', $message);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private static function curlTransport(string $method, string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not available');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('cURL could not be initialised');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            throw new \RuntimeException('Network error: ' . ($error !== '' ? $error : 'unknown'));
        }

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => (string) $responseBody];
    }
}
