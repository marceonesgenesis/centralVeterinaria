<?php

declare(strict_types=1);

namespace CentralVet\Landing;

use CentralVet\Landing\Contract\LeadStoreInterface;
use CentralVet\Security\LoginRateLimiter;

/**
 * Regras de `POST /lead.php`, na ordem em que são aplicadas (a primeira que
 * falha responde): método, tamanho, Content-Type, origem, limite por IP,
 * token, JSON, honeypot, validação, consumo do token e gravação.
 *
 * O token só é consumido depois da validação (o visitante corrige e
 * reenvia com o mesmo token) e a gravação só acontece quando consume()
 * vence, o que impede gravação dupla com o mesmo token. Exceção da
 * gravação vai para o error_log; o corpo nunca leva a mensagem.
 */
final class LeadSubmissionHandler
{
    private const RATE_PREFIX = 'lead|';

    public function __construct(
        private readonly LoginRateLimiter $limiter,
        private readonly LeadFormToken $tokens,
        private readonly LeadStoreInterface $store,
    ) {
    }

    /**
     * @param array<string, string> $headers nomes em minúsculas
     */
    public function handle(string $method, array $headers, string $body, string $ip, \DateTimeImmutable $now): LeadResponse
    {
        if (strtoupper($method) !== 'POST') {
            return LeadResponse::error(405, 'method_not_allowed', [], ['Allow' => 'POST']);
        }

        if (strlen($body) > LeadEndpoint::MAX_BODY_BYTES) {
            return LeadResponse::error(413, 'payload_too_large');
        }

        $contentType = strtolower(ltrim((string) ($headers['content-type'] ?? '')));
        if (!str_starts_with($contentType, 'application/json')) {
            return LeadResponse::error(400, 'invalid_request');
        }

        if (!self::sameOrigin($headers)) {
            return LeadResponse::error(403, 'forbidden_origin');
        }

        $bucket = self::RATE_PREFIX . $ip;
        if ($this->limiter->tooManyAttempts($bucket)) {
            $retryAfter = $this->limiter->secondsUntilAvailable($bucket);

            return LeadResponse::error(429, 'rate_limited', ['retry_after' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
        }
        $this->limiter->hit($bucket);

        $token = (string) ($headers[strtolower(LeadEndpoint::TOKEN_HEADER)] ?? '');
        if ($token === '' || !$this->tokens->isUsable($token, $now->getTimestamp())) {
            return LeadResponse::error(403, 'invalid_token');
        }

        $payload = self::decodeObject($body);
        if ($payload === null) {
            return LeadResponse::error(400, 'invalid_request');
        }

        if (self::honeypotFilled($payload)) {
            $this->tokens->consume($token);

            return LeadResponse::accepted(200);
        }

        try {
            $lead = LeadSubmission::fromPayload($payload);
        } catch (LeadValidationException $exception) {
            return LeadResponse::error(422, 'validation', ['fields' => $exception->errors()]);
        }

        if (!$this->tokens->consume($token)) {
            return LeadResponse::error(403, 'invalid_token');
        }

        try {
            $this->store->insert($lead, $ip, $now);
        } catch (\Throwable $exception) {
            error_log(sprintf('lead.php: insert failed: %s: %s', $exception::class, $exception->getMessage()));

            return LeadResponse::error(503, 'unavailable');
        }

        return LeadResponse::accepted(201);
    }

    /** @param array<string, string> $headers */
    private static function sameOrigin(array $headers): bool
    {
        $fetchSite = $headers['sec-fetch-site'] ?? null;
        if ($fetchSite !== null && !in_array(strtolower(trim($fetchSite)), ['same-origin', 'none'], true)) {
            return false;
        }

        $origin = $headers['origin'] ?? null;
        if ($origin === null) {
            return true;
        }

        $parts = parse_url(trim($origin));
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }

        $originHost = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $host = strtolower(trim((string) ($headers['host'] ?? '')));

        return $host !== '' && hash_equals($host, $originHost);
    }

    /** @return array<string, mixed>|null */
    private static function decodeObject(string $body): ?array
    {
        try {
            $decoded = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!$decoded instanceof \stdClass) {
            return null;
        }

        $payload = json_decode($body, true, 32);

        return is_array($payload) ? $payload : null;
    }

    /** @param array<string, mixed> $payload */
    private static function honeypotFilled(array $payload): bool
    {
        $value = $payload[LeadEndpoint::HONEYPOT_FIELD] ?? null;

        if ($value === null) {
            return false;
        }

        return is_string($value) ? trim($value) !== '' : true;
    }
}
