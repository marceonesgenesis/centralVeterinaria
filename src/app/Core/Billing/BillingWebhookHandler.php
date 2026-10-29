<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Stripe\InvalidWebhookSignature;
use CentralVet\Billing\Stripe\StripeSignatureVerifier;
use CentralVet\Landing\LeadResponse;
use CentralVet\Observability\Logging\LoggerInterface;
use DateTimeImmutable;
use Throwable;

/**
 * POST /billing-webhook.php (T-17): exceção ao CSRF, autenticada só pela
 * Stripe-Signature (HMAC-SHA256 do segredo do webhook, tolerância 300 s),
 * sem cookie, sessão nem token.
 *
 * - não POST → 405 {"error":"method_not_allowed"}
 * - corpo acima de MAX_BODY_BYTES → 413 {"error":"payload_too_large"}
 * - Stripe-Signature ausente ou inválida → 400 {"error":"invalid_signature"}, sem escrita no banco
 * - JSON sem `id`/`type`/`created` → 400 {"error":"invalid_payload"}
 * - sucesso → 200 {"received":true,"result":"processed|ignored|duplicate"}
 * - exceção do ingestor → 500 {"error":"processing_failed"} (o Stripe reenvia)
 *
 * Logs só com event_id, tipo e resultado.
 */
final class BillingWebhookHandler
{
    public const MAX_BODY_BYTES = 262144;

    public function __construct(
        private readonly StripeSignatureVerifier $verifier,
        private readonly BillingEventIngestor $ingestor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, string> $headers */
    public function handle(string $method, array $headers, string $body, DateTimeImmutable $now): LeadResponse
    {
        if (strtoupper($method) !== 'POST') {
            return LeadResponse::json(405, ['error' => 'method_not_allowed'], ['Allow' => 'POST']);
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return LeadResponse::json(413, ['error' => 'payload_too_large']);
        }

        $signature = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'stripe-signature') {
                $signature = trim((string) $value);
            }
        }

        try {
            if ($signature === '') {
                throw new InvalidWebhookSignature('Stripe-Signature header is missing');
            }
            $this->verifier->verify($body, $signature, $now->getTimestamp());
        } catch (InvalidWebhookSignature) {
            $this->logger->warning('billing webhook rejected', ['result' => 'invalid_signature']);

            return LeadResponse::json(400, ['error' => 'invalid_signature']);
        }

        $event = json_decode($body, true);
        if (
            !is_array($event)
            || !is_string($event['id'] ?? null) || $event['id'] === ''
            || !is_string($event['type'] ?? null) || $event['type'] === ''
            || !is_int($event['created'] ?? null) || $event['created'] <= 0
        ) {
            $this->logger->warning('billing webhook rejected', ['result' => 'invalid_payload']);

            return LeadResponse::json(400, ['error' => 'invalid_payload']);
        }

        $context = ['event_id' => $event['id'], 'type' => $event['type']];

        try {
            $result = $this->ingestor->ingest($event, $body, $now);
        } catch (Throwable) {
            $this->logger->error('billing webhook failed', $context + ['result' => 'processing_failed']);

            return LeadResponse::json(500, ['error' => 'processing_failed']);
        }

        $this->logger->info('billing webhook received', $context + ['result' => $result]);

        return LeadResponse::json(200, ['received' => true, 'result' => $result]);
    }
}
