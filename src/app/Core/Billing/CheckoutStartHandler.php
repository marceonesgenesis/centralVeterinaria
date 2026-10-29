<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\AccountEmailReaderInterface;
use CentralVet\Billing\Contract\BillingOrderRepositoryInterface;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeClientInterface;
use CentralVet\Billing\Stripe\StripeConfig;
use CentralVet\Landing\LandingCatalog;
use CentralVet\Landing\LeadEndpoint;
use CentralVet\Landing\LeadFormToken;
use CentralVet\Landing\LeadResponse;
use CentralVet\Landing\LeadSubmissionHandler;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Security\PublicUrl;

/**
 * Regras de `POST /checkout.php` (compra pela landing), na ordem em que são
 * aplicadas: precheck do lead (método, tamanho, Content-Type, origem),
 * limite por IP (`checkout|<ip>`), JSON, `form_token`, honeypot, validação,
 * plano dentro do recorte da oferta (OfferScope, 422 em `plan_id`),
 * e-mail já cadastrado (422 em `responsible_email`, a mesma mensagem para
 * login e para pedido pago), preço do cartão configurado (502), consumo do
 * token, pedido `pending`, Customer e Checkout Session no Stripe
 * e, por fim, pedido `checkout_open`.
 *
 * O preço vem de BillingOrder::place() (PlanEntitlements), nunca do payload:
 * `price_cents`/`amount` enviados pelo cliente são ignorados. O aceite dos
 * termos (`terms_accepted === true` na TERMS_VERSION atual) é obrigatório e
 * independente de `contact_consent`. Falha do Stripe responde 502 e deixa o
 * pedido `pending` (nada foi cobrado); as Idempotency-Key por pedido tornam a
 * repetição segura.
 *
 * Respostas: 201 {"checkout_url","order"}; 200 {"accepted":true} (honeypot);
 * 400/403/405/413/429 como o lead; 422 {"errors":{campo: mensagem}};
 * 502 {"error":"gateway_unavailable"}; 503 {"accepted":false,"error":"unavailable"}.
 */
final class CheckoutStartHandler
{
    public const PLAN_UNAVAILABLE_MESSAGE = 'Plano indisponível no momento.';

    public const EMAIL_TAKEN_MESSAGE = 'Este e-mail já está cadastrado no Central Vet Pro. Entre com ele ou use outro e-mail.';

    private const RATE_PREFIX = 'checkout|';

    private const PIX_EXPIRES_AFTER_SECONDS = 3600;

    /** campo => [mensagem, tamanho máximo] */
    private const TEXT_FIELDS = [
        'responsible_name' => ['Informe o nome do responsável.', 160],
        'responsible_email' => ['Informe um e-mail válido.', 190],
        'responsible_phone' => ['Informe o telefone com DDD.', 30],
        'clinic_name' => ['Informe o nome da clínica.', 190],
    ];

    /**
     * @param \Closure(): string $uuid gera o public_id do pedido (UUID v4)
     */
    public function __construct(
        private readonly LeadFormToken $formTokens,
        private readonly LoginRateLimiter $limiter,
        private readonly BillingOrderRepositoryInterface $orders,
        private readonly StripeClientInterface $stripe,
        private readonly StripeConfig $config,
        private readonly PublicUrl $url,
        private readonly \Closure $uuid,
        private readonly AccountEmailReaderInterface $emails,
        private readonly ?OfferScope $offer = null,
    ) {
    }

    /**
     * @param array<string, string> $headers nomes em minúsculas
     */
    public function handle(string $method, array $headers, string $body, string $ip, \DateTimeImmutable $now): LeadResponse
    {
        $early = LeadSubmissionHandler::precheck($method, $headers, $body);
        if ($early !== null) {
            return $early;
        }

        $bucket = self::RATE_PREFIX . $ip;
        if ($this->limiter->tooManyAttempts($bucket)) {
            $retryAfter = $this->limiter->secondsUntilAvailable($bucket);

            return LeadResponse::error(429, 'rate_limited', ['retry_after' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
        }
        $this->limiter->hit($bucket);

        $payload = self::decodeObject($body);
        if ($payload === null) {
            return LeadResponse::error(400, 'invalid_request');
        }

        $token = is_string($payload['form_token'] ?? null) ? $payload['form_token'] : '';
        if ($token === '' || !$this->formTokens->isUsable($token, $now->getTimestamp())) {
            return LeadResponse::error(403, 'invalid_token');
        }

        if (self::honeypotFilled($payload)) {
            $this->formTokens->consume($token);

            return LeadResponse::accepted(200);
        }

        $errors = self::validate($payload);
        if ($errors !== []) {
            return LeadResponse::json(422, ['errors' => $errors]);
        }

        if (!($this->offer ?? OfferScope::fromEnvironment())->isPlanOffered((string) $payload['plan_id'])) {
            return LeadResponse::json(422, ['errors' => ['plan_id' => self::PLAN_UNAVAILABLE_MESSAGE]]);
        }

        if ($this->emails->isTaken(trim((string) $payload['responsible_email']))) {
            return LeadResponse::json(422, ['errors' => ['responsible_email' => self::EMAIL_TAKEN_MESSAGE]]);
        }

        $planId = (string) $payload['plan_id'];
        if ($payload['payment_method'] === BillingOrder::METHOD_CARD) {
            try {
                $this->config->priceIdFor($planId);
            } catch (\RuntimeException) {
                error_log(sprintf('[CheckoutStart] price not configured for plan %s', $planId));

                return LeadResponse::json(502, ['error' => 'gateway_unavailable']);
            }
        }

        if (!$this->formTokens->consume($token)) {
            return LeadResponse::error(403, 'invalid_token');
        }

        try {
            $order = BillingOrder::place(
                ($this->uuid)(),
                (string) $payload['plan_id'],
                (string) $payload['payment_method'],
                trim((string) $payload['responsible_name']),
                trim((string) $payload['responsible_email']),
                trim((string) $payload['responsible_phone']),
                trim((string) $payload['clinic_name']),
                LandingCatalog::TERMS_VERSION,
                $now,
                $ip,
                ($payload['contact_consent'] ?? false) === true,
            );
            $order = $order->withId($this->orders->insert($order));
        } catch (\Throwable $exception) {
            error_log(sprintf('checkout.php: order insert failed: %s: %s', $exception::class, $exception->getMessage()));

            return LeadResponse::error(503, 'unavailable');
        }

        try {
            $customer = $this->stripe->request('POST', '/v1/customers', [
                'email' => $order->responsibleEmail(),
                'name' => $order->responsibleName(),
                'metadata' => ['billing_order' => $order->publicId()],
            ], 'customer-' . $order->publicId());
            $customerId = self::stringField($customer, 'id');

            $session = $this->stripe->request(
                'POST',
                '/v1/checkout/sessions',
                $this->sessionParams($order, $customerId),
                'checkout-' . $order->publicId(),
            );
            $sessionId = self::stringField($session, 'id');
            $sessionUrl = self::stringField($session, 'url');
        } catch (StripeApiException $exception) {
            error_log(sprintf('checkout.php: Stripe failed for order %s: %s', $order->publicId(), $exception->getMessage()));

            return LeadResponse::json(502, ['error' => 'gateway_unavailable']);
        }

        try {
            $order->attachCheckout($sessionId, $customerId);
            $this->orders->save($order);
        } catch (\Throwable $exception) {
            error_log(sprintf('checkout.php: order save failed for %s: %s: %s', $order->publicId(), $exception::class, $exception->getMessage()));

            return LeadResponse::error(503, 'unavailable');
        }

        return LeadResponse::json(201, ['checkout_url' => $sessionUrl, 'order' => $order->publicId()]);
    }

    /** @return array<string, mixed> */
    private function sessionParams(BillingOrder $order, string $customerId): array
    {
        $publicId = $order->publicId();
        $metadata = ['billing_order' => $publicId];

        $params = [
            'customer' => $customerId,
            'client_reference_id' => $publicId,
            'metadata' => $metadata,
            'success_url' => $this->url->to('landing.php?checkout=success&order=' . rawurlencode($publicId)),
            'cancel_url' => $this->url->to('landing.php?checkout=cancelled&order=' . rawurlencode($publicId)),
        ];

        if ($order->paymentMethod() === BillingOrder::METHOD_CARD) {
            return $params + [
                'mode' => 'subscription',
                'payment_method_types' => ['card'],
                'line_items' => [['price' => $this->config->priceIdFor($order->planId()), 'quantity' => 1]],
                'subscription_data' => ['metadata' => $metadata],
            ];
        }

        $planName = PlanEntitlements::forPlan($order->planId())->name();

        return $params + [
            'mode' => 'payment',
            'payment_method_types' => ['pix'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'brl',
                    'unit_amount' => $order->priceCents(),
                    'product_data' => ['name' => sprintf('Central Vet Pro %s — 1º mês', $planName)],
                ],
                'quantity' => 1,
            ]],
            'payment_method_options' => ['pix' => ['expires_after_seconds' => self::PIX_EXPIRES_AFTER_SECONDS]],
            'payment_intent_data' => ['metadata' => $metadata],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string> campo => mensagem
     */
    private static function validate(array $payload): array
    {
        $errors = [];

        $planId = $payload['plan_id'] ?? null;
        if (!is_string($planId) || LandingCatalog::plan($planId) === null) {
            $errors['plan_id'] = 'Escolha um plano.';
        }

        $method = $payload['payment_method'] ?? null;
        if (!is_string($method) || !array_key_exists($method, LandingCatalog::PAYMENT_METHODS)) {
            $errors['payment_method'] = 'Escolha a forma de pagamento: cartão ou Pix.';
        }

        foreach (self::TEXT_FIELDS as $field => [$message, $max]) {
            $value = $payload[$field] ?? null;
            $value = is_string($value) ? trim($value) : '';
            $valid = $value !== '' && mb_strlen($value) <= $max && !preg_match('/[\x00-\x1F\x7F<>]/u', $value);
            if ($valid && $field === 'responsible_email') {
                $valid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            }
            if (!$valid) {
                $errors[$field] = $message;
            }
        }

        if (($payload['terms_accepted'] ?? null) !== true) {
            $errors['terms_accepted'] = 'Aceite os Termos de Uso e a Política de Privacidade para continuar.';
        }

        if (($payload['terms_version'] ?? null) !== LandingCatalog::TERMS_VERSION) {
            $errors['terms_version'] = 'Os Termos de Uso foram atualizados. Recarregue a página e aceite a versão atual.';
        }

        $consent = $payload['contact_consent'] ?? false;
        if (!is_bool($consent)) {
            $errors['contact_consent'] = 'Autorização de contato inválida.';
        }

        return $errors;
    }

    /** @param array<string, mixed> $response */
    private static function stringField(array $response, string $field): string
    {
        $value = $response[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new StripeApiException(sprintf('Stripe response without "%s"', $field));
        }

        return $value;
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
