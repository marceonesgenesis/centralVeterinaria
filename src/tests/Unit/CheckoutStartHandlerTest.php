<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\CheckoutStartHandler;
use CentralVet\Billing\Stripe\CurlStripeClient;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeConfig;
use CentralVet\Landing\LandingCatalog;
use CentralVet\Landing\LeadFormToken;
use CentralVet\Landing\LeadResponse;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Security\PublicUrl;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAccountEmailReader;
use CentralVet\Tests\Support\FakeBillingOrderRepository;
use CentralVet\Tests\Support\FakeRedis;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * POST /checkout.php (Etapa 3, T-16): o preço vem do servidor, o aceite dos
 * termos é obrigatório e separado do consentimento de contato, e falha do
 * Stripe deixa o pedido pending sem cobrança.
 */
final class CheckoutStartHandlerTest
{
    private const IP = '203.0.113.9';
    private const ISSUED_AT = 1_000_000;
    private const PUBLIC_ID = '0f8b1c2d-3e4f-4a5b-8c6d-7e8f9a0b1c2d';
    private const SESSION_URL = 'https://checkout.stripe.test/c/pay/cs_test_1';

    private FakeRedis $redis;
    private LeadFormToken $tokens;
    private FakeBillingOrderRepository $orders;
    private FakeStripeClient $stripe;
    private FakeAccountEmailReader $emails;
    private CheckoutStartHandler $handler;

    public function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            throw new SkippedTestException('ext-redis is not loaded in this PHP runtime');
        }

        $this->redis = new FakeRedis();
        $this->tokens = new LeadFormToken($this->redis);
        $this->orders = new FakeBillingOrderRepository();
        $this->stripe = new FakeStripeClient();
        $this->emails = new FakeAccountEmailReader();
        $this->handler = $this->handlerWith(new StripeConfig('placeholder-secret', 'placeholder-webhook', [
            'starter' => 'price_starter_placeholder',
            'pro' => 'price_pro_placeholder',
            'business' => 'price_business_placeholder',
            'enterprise' => 'price_enterprise_placeholder',
        ]));
    }

    public function testTakenEmailAnswers422WithoutOrderNorStripeAndKeepsTheToken(): void
    {
        $this->emails->taken = ['maria@example.com'];
        $token = $this->tokens->issue(self::ISSUED_AT);

        $response = $this->post(['responsible_email' => ' Maria@Example.COM '] + $this->validPayload('card'), $token);

        Assert::same(422, $response->status);
        Assert::same(['errors' => ['responsible_email' => CheckoutStartHandler::EMAIL_TAKEN_MESSAGE]], $response->body);
        Assert::same(
            'Este e-mail já está cadastrado no Central Vet Pro. Entre com ele ou use outro e-mail.',
            CheckoutStartHandler::EMAIL_TAKEN_MESSAGE,
        );
        Assert::count(0, $this->stripe->calls);
        Assert::null($this->orders->findByPublicId(self::PUBLIC_ID));
        Assert::same(1, $this->emails->calls);
        Assert::true($this->tokens->isUsable($token, self::ISSUED_AT + 10), 'Taken e-mail keeps the token for the retry');

        $this->queueStripeSuccess();
        $retry = $this->post(['responsible_email' => 'outra@example.com'] + $this->validPayload('card'), $token);

        Assert::same(201, $retry->status);
        Assert::same('outra@example.com', $this->orders->findByPublicId(self::PUBLIC_ID)?->responsibleEmail());
        Assert::count(2, $this->stripe->calls);
    }

    public function testValidationErrorsDoNotQueryTheAccountEmails(): void
    {
        $this->emails->taken = ['maria@example.com'];

        $response = $this->post(['terms_accepted' => false] + $this->validPayload('card'));

        Assert::same(422, $response->status);
        Assert::false(isset($response->body['errors']['responsible_email']), 'taken check runs only after validate()');
        Assert::same(0, $this->emails->calls);
    }

    public function testMissingCardPriceAnswers502BeforeOrderAndStripeButPixStillOpens(): void
    {
        $this->handler = $this->handlerWith(new StripeConfig('placeholder-secret', 'placeholder-webhook', [
            'starter' => 'price_starter_placeholder',
            'pro' => '',
            'business' => 'price_business_placeholder',
            'enterprise' => 'price_enterprise_placeholder',
        ]));
        $token = $this->tokens->issue(self::ISSUED_AT);

        $response = $this->silently(fn (): LeadResponse => $this->post($this->validPayload('card'), $token));

        Assert::same(502, $response->status);
        Assert::same(['error' => 'gateway_unavailable'], $response->body);
        Assert::count(0, $this->stripe->calls);
        Assert::null($this->orders->findByPublicId(self::PUBLIC_ID));
        Assert::true($this->tokens->isUsable($token, self::ISSUED_AT + 10), 'Missing price keeps the token');

        $this->queueStripeSuccess();
        $pix = $this->post($this->validPayload('pix'), $token);

        Assert::same(201, $pix->status);
        Assert::same(BillingOrder::STATUS_CHECKOUT_OPEN, $this->orders->findByPublicId(self::PUBLIC_ID)?->status());
    }

    public function testCardCheckoutIgnoresClientPriceAndOpensSubscriptionSession(): void
    {
        $this->queueStripeSuccess();

        $response = $this->post(['price_cents' => 1, 'amount' => 1] + $this->validPayload('card'));

        Assert::same(201, $response->status);
        Assert::same(['checkout_url' => self::SESSION_URL, 'order' => self::PUBLIC_ID], $response->body);
        Assert::same('no-store', $response->headers['Cache-Control'] ?? null);

        $order = $this->orders->findByPublicId(self::PUBLIC_ID);
        Assert::notNull($order);
        Assert::same(BillingOrder::STATUS_CHECKOUT_OPEN, $order->status());
        Assert::same(9700, $order->priceCents());
        Assert::same('pro', $order->planId());
        Assert::same('card', $order->paymentMethod());
        Assert::same('cs_test_1', $order->stripeCheckoutSessionId());
        Assert::same('cus_test_1', $order->stripeCustomerId());
        Assert::same(LandingCatalog::TERMS_VERSION, $order->termsVersion());
        Assert::same(self::IP, $order->termsIp());
        Assert::false($order->contactConsent());

        Assert::count(2, $this->stripe->calls);
        $customer = $this->stripe->calls[0];
        Assert::same('POST', $customer['method']);
        Assert::same('/v1/customers', $customer['path']);
        Assert::same('customer-' . self::PUBLIC_ID, $customer['idempotencyKey']);
        Assert::same('maria@example.com', $customer['params']['email']);
        Assert::same('Maria Souza', $customer['params']['name']);
        Assert::same(['billing_order' => self::PUBLIC_ID], $customer['params']['metadata']);

        $session = $this->stripe->calls[1];
        $params = $session['params'];
        Assert::same('POST', $session['method']);
        Assert::same('/v1/checkout/sessions', $session['path']);
        Assert::same('checkout-' . self::PUBLIC_ID, $session['idempotencyKey']);
        Assert::same('subscription', $params['mode']);
        Assert::same(['card'], $params['payment_method_types']);
        Assert::same([['price' => 'price_pro_placeholder', 'quantity' => 1]], $params['line_items']);
        Assert::same('cus_test_1', $params['customer']);
        Assert::same(self::PUBLIC_ID, $params['client_reference_id']);
        Assert::same(['billing_order' => self::PUBLIC_ID], $params['metadata']);
        Assert::same(['metadata' => ['billing_order' => self::PUBLIC_ID]], $params['subscription_data']);
        Assert::same('https://app.centralvet.test/landing.php?checkout=success&order=' . self::PUBLIC_ID, $params['success_url']);
        Assert::same('https://app.centralvet.test/landing.php?checkout=cancelled&order=' . self::PUBLIC_ID, $params['cancel_url']);
        Assert::false(str_contains((string) json_encode($params), '"unit_amount"'), 'Card session must use the Stripe price id');
    }

    public function testPixCheckoutSendsServerPriceAsUnitAmount(): void
    {
        $this->queueStripeSuccess();

        $response = $this->post(['price_cents' => 1, 'amount' => 1] + $this->validPayload('pix'));

        Assert::same(201, $response->status);
        $order = $this->orders->findByPublicId(self::PUBLIC_ID);
        Assert::notNull($order);
        Assert::same(9700, $order->priceCents());
        Assert::same(BillingOrder::STATUS_CHECKOUT_OPEN, $order->status());

        $params = $this->stripe->calls[1]['params'];
        Assert::same('payment', $params['mode']);
        Assert::same(['pix'], $params['payment_method_types']);
        Assert::same(9700, $params['line_items'][0]['price_data']['unit_amount']);
        Assert::same('brl', $params['line_items'][0]['price_data']['currency']);
        Assert::same('Central Vet Pro Pro — 1º mês', $params['line_items'][0]['price_data']['product_data']['name']);
        Assert::same(1, $params['line_items'][0]['quantity']);
        Assert::same(['pix' => ['expires_after_seconds' => 3600]], $params['payment_method_options']);
        Assert::same(['metadata' => ['billing_order' => self::PUBLIC_ID]], $params['payment_intent_data']);
        Assert::false(isset($params['subscription_data']), 'Pix 1st month is a one-off payment');
        Assert::false(isset($params['line_items'][0]['price']), 'Pix must not use the recurring price id');
    }

    public function testTermsNotAcceptedAnswers422WithoutCallingStripe(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);

        $response = $this->post(['terms_accepted' => false] + $this->validPayload('card'), $token);

        Assert::same(422, $response->status);
        Assert::true(isset($response->body['errors']['terms_accepted']), 'errors.terms_accepted must be present');
        Assert::count(0, $this->stripe->calls);
        Assert::null($this->orders->findByPublicId(self::PUBLIC_ID));
        Assert::true($this->tokens->isUsable($token, self::ISSUED_AT + 10), 'Validation error keeps the token for the retry');
    }

    public function testTermsAcceptedMustBeStrictTrueAndVersionCurrent(): void
    {
        $response = $this->post(['terms_accepted' => 'true', 'terms_version' => 'assinatura-2020-01'] + $this->validPayload('card'));

        Assert::same(422, $response->status);
        Assert::true(isset($response->body['errors']['terms_accepted']), 'string "true" is not acceptance');
        Assert::true(isset($response->body['errors']['terms_version']), 'old terms version is refused');
        Assert::count(0, $this->stripe->calls);
    }

    public function testUnknownPlanAnswers422(): void
    {
        $response = $this->post(['plan_id' => 'gold'] + $this->validPayload('card'));

        Assert::same(422, $response->status);
        Assert::true(isset($response->body['errors']['plan_id']), 'errors.plan_id must be present');
        Assert::count(0, $this->stripe->calls);
    }

    public function testInvalidMethodEmailAndEmptyFieldsAnswer422Together(): void
    {
        $payload = [
            'payment_method' => 'boleto',
            'responsible_email' => 'not-an-email',
            'responsible_name' => '  ',
            'responsible_phone' => '',
            'clinic_name' => '',
        ] + $this->validPayload('card');

        $response = $this->post($payload);

        Assert::same(422, $response->status);
        Assert::same(
            ['payment_method', 'responsible_name', 'responsible_email', 'responsible_phone', 'clinic_name'],
            array_keys($response->body['errors']),
        );
        Assert::count(0, $this->stripe->calls);
    }

    public function testStripeFailureAnswers502AndLeavesOrderPending(): void
    {
        $this->stripe->queue('POST', '/v1/customers', ['id' => 'cus_test_1']);
        $this->stripe->fail('POST', '/v1/checkout/sessions', new StripeApiException('Stripe API error (HTTP 500)', 500));

        $response = $this->silently(fn (): LeadResponse => $this->post($this->validPayload('card')));

        Assert::same(502, $response->status);
        Assert::same(['error' => 'gateway_unavailable'], $response->body);
        $order = $this->orders->findByPublicId(self::PUBLIC_ID);
        Assert::notNull($order);
        Assert::same(BillingOrder::STATUS_PENDING, $order->status());
        Assert::null($order->stripeCheckoutSessionId());
    }

    public function testEmptyStripeKeyAnswers502WithoutAnyRequestLeaving(): void
    {
        $transportCalls = 0;
        $config = new StripeConfig('', 'placeholder-webhook', [
            'starter' => 'price_starter_placeholder',
            'pro' => 'price_pro_placeholder',
            'business' => 'price_business_placeholder',
            'enterprise' => 'price_enterprise_placeholder',
        ]);
        $handler = new CheckoutStartHandler(
            $this->tokens,
            new LoginRateLimiter($this->redis, 10, 3600, 'centralvet:checkout-throttle:'),
            $this->orders,
            new CurlStripeClient($config, static function () use (&$transportCalls): array {
                $transportCalls++;

                return ['status' => 200, 'body' => '{"id":"cus_test_1"}'];
            }),
            $config,
            new PublicUrl('https://app.centralvet.test'),
            static fn (): string => self::PUBLIC_ID,
            $this->emails,
        );
        $this->handler = $handler;

        $response = $this->silently(fn (): LeadResponse => $this->post($this->validPayload('card')));

        Assert::same(502, $response->status);
        Assert::same(['error' => 'gateway_unavailable'], $response->body);
        Assert::same(0, $transportCalls);
    }

    public function testHoneypotAnswers200WithoutOrderNorStripe(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);

        $response = $this->post(['website' => 'http://spam.example'] + $this->validPayload('card'), $token);

        Assert::same(200, $response->status);
        Assert::count(0, $this->stripe->calls);
        Assert::null($this->orders->findByPublicId(self::PUBLIC_ID));
        Assert::false($this->tokens->isUsable($token, self::ISSUED_AT + 10), 'Honeypot must consume the token');
    }

    public function testTokenIsSingleUseAndRequired(): void
    {
        $this->queueStripeSuccess();
        $token = $this->tokens->issue(self::ISSUED_AT);

        Assert::same(201, $this->post($this->validPayload('card'), $token)->status);

        $reused = $this->post($this->validPayload('card'), $token);
        Assert::same(403, $reused->status);
        Assert::same('invalid_token', $reused->body['error'] ?? null);

        $missing = $this->post(['form_token' => ''] + $this->validPayload('card'), $token);
        Assert::same(403, $missing->status);
        Assert::count(2, $this->stripe->calls);
    }

    public function testForeignOriginIsRefusedByPrecheck(): void
    {
        $headers = $this->headers();
        $headers['origin'] = 'https://evil.example';
        $token = $this->tokens->issue(self::ISSUED_AT);

        $response = $this->handler->handle('POST', $headers, $this->json(['form_token' => $token] + $this->validPayload('card')), self::IP, $this->now());

        Assert::same(403, $response->status);
        Assert::same('forbidden_origin', $response->body['error'] ?? null);
    }

    public function testEleventhPostFromSameIpAnswers429(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->post(['form_token' => 'missing'] + $this->validPayload('card'), 'missing');
            Assert::same(403, $response->status, "POST {$i} must reach the token check");
        }

        $response = $this->post(['form_token' => 'missing'] + $this->validPayload('card'), 'missing');

        Assert::same(429, $response->status);
        Assert::same('3600', $response->headers['Retry-After'] ?? null);
        Assert::true($this->redis->hasKey('centralvet:checkout-throttle:' . hash('sha256', 'checkout|' . self::IP)), 'bucket uses the checkout| prefix');
    }

    private function handlerWith(StripeConfig $config): CheckoutStartHandler
    {
        return new CheckoutStartHandler(
            $this->tokens,
            new LoginRateLimiter($this->redis, 10, 3600, 'centralvet:checkout-throttle:'),
            $this->orders,
            $this->stripe,
            $config,
            new PublicUrl('https://app.centralvet.test'),
            static fn (): string => self::PUBLIC_ID,
            $this->emails,
        );
    }

    private function queueStripeSuccess(): void
    {
        $this->stripe->queue('POST', '/v1/customers', ['id' => 'cus_test_1']);
        $this->stripe->queue('POST', '/v1/checkout/sessions', ['id' => 'cs_test_1', 'url' => self::SESSION_URL]);
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload, ?string $token = null): LeadResponse
    {
        if (!array_key_exists('form_token', $payload)) {
            $payload['form_token'] = $token ?? $this->tokens->issue(self::ISSUED_AT);
        }

        return $this->handler->handle('POST', $this->headers(), $this->json($payload), self::IP, $this->now());
    }

    /** @return array<string, mixed> */
    private function validPayload(string $method): array
    {
        return [
            'plan_id' => 'pro',
            'payment_method' => $method,
            'responsible_name' => 'Maria Souza',
            'responsible_email' => 'maria@example.com',
            'responsible_phone' => '(85) 99999-1234',
            'clinic_name' => 'Clínica Patas',
            'terms_accepted' => true,
            'terms_version' => 'assinatura-2026-10',
            'contact_consent' => false,
            'website' => '',
        ];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'content-type' => 'application/json',
            'origin' => 'http://127.0.0.1:8081',
            'host' => '127.0.0.1:8081',
            'sec-fetch-site' => 'same-origin',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . (self::ISSUED_AT + 10)))->setTimezone(new \DateTimeZone('America/Fortaleza'));
    }

    /** @param \Closure(): LeadResponse $call */
    private function silently(\Closure $call): LeadResponse
    {
        $previous = ini_set('error_log', '/dev/null');
        try {
            return $call();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }
}
