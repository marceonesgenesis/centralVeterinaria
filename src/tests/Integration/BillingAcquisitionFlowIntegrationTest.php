<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\AccessDecision;
use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\BillingEventIngestor;
use CentralVet\Billing\BillingEventProcessor;
use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\BillingReconciler;
use CentralVet\Billing\BillingWebhookHandler;
use CentralVet\Billing\CheckoutStartHandler;
use CentralVet\Billing\LoginConflictRefundService;
use CentralVet\Billing\PixInvoiceIssuer;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Billing\ProvisioningConflict;
use CentralVet\Billing\QuotaExceededException;
use CentralVet\Billing\QuotaGuard;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeConfig;
use CentralVet\Billing\Stripe\StripeSignatureVerifier;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionAccessGuard;
use CentralVet\Billing\SubscriptionInvoice;
use CentralVet\Billing\TenantProvisioningService;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Landing\LeadFormToken;
use CentralVet\Landing\LeadResponse;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Persistence\AccountEmailReader;
use CentralVet\Persistence\BillingEventRepository;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Persistence\SubscriptionInvoiceRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Persistence\TenantUsageReader;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Tenancy\ClinicTeamService;
use CentralVet\Tenancy\ClinicUnitService;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeRedis;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\SkippedTestException;
use DateTimeImmutable;

/**
 * Cenários de aquisição da auditoria (Etapa 3, T-22), de ponta a ponta no
 * centralvet_test com a 0017: POST /checkout.php (CheckoutStartHandler),
 * webhook assinado com o segredo de teste (BillingWebhookHandler →
 * BillingEventIngestor → BillingEventProcessor → TenantProvisioningService),
 * reconciliador (BillingReconciler com PixInvoiceIssuer), guarda de acesso
 * (SubscriptionAccessGuard) e a gestão da clínica (ClinicTeamService e
 * ClinicUnitService), montados como em src/checkout.php,
 * src/billing-webhook.php e src/bin/billing-reconcile.php. O Stripe é o
 * FakeStripeClient (nenhuma chamada de rede). O TransactionRunner usa
 * SAVEPOINT dentro da transação do teste, desfeita no tearDown() — nada é
 * commitado. Cada método cobre uma letra da lista da auditoria (a..p); (q)
 * é o estorno do login_conflict pago (T-31).
 */
final class BillingAcquisitionFlowIntegrationTest extends MysqlIntegrationTestCase
{
    private const SECRET = 'whsec_test_etapa3';
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';
    private const IP = '203.0.113.22';
    private const ALLOWED_PROGRAM = 'PatientList';

    private BillingOrderRepository $orders;
    private SubscriptionRepository $subscriptions;
    private SubscriptionInvoiceRepository $invoices;
    private BillingEventRepository $events;
    private FakeStripeClient $stripe;
    private FakeEmailProvider $email;
    private FakeRedis $redis;
    private DateTimeImmutable $now;
    private string $lastPublicId = '';

    /** @var array<string, int> */
    private array $groups = [];

    /** @var array<string, \Closure> */
    private array $fixtures;

    private ?string $previousErrorLog = null;

    public function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            throw new SkippedTestException('ext-redis is not loaded in this PHP runtime');
        }

        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'billing_event'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0017 must be applied to the test database');

        // Os serviços registram falhas esperadas (502, provisionamento) com error_log.
        $this->previousErrorLog = (string) ini_set('error_log', '/dev/null');

        $this->orders = new BillingOrderRepository($this->pdo);
        $this->subscriptions = new SubscriptionRepository($this->pdo);
        $this->invoices = new SubscriptionInvoiceRepository($this->pdo);
        $this->events = new BillingEventRepository($this->pdo);
        $this->stripe = new FakeStripeClient();
        $this->email = new FakeEmailProvider();
        $this->redis = new FakeRedis();
        $this->now = new DateTimeImmutable('2032-03-01 12:00:00');
        $this->fixtures = require __DIR__ . '/../Support/Fixtures/stripe-events.php';
        $this->createPlanGroups();
    }

    public function tearDown(): void
    {
        if ($this->previousErrorLog !== null) {
            ini_set('error_log', $this->previousErrorLog);
        }

        parent::tearDown();
    }

    /** (a) pagamento aprovado no cartão → pedido provisioned, 1 tenant, 1 convite. */
    public function testScenarioAApprovedCardPaymentProvisionsOneTenantWithOneInvite(): void
    {
        $tenantsBefore = $this->countTenants();

        $checkout = $this->startCheckout('pro', BillingOrder::METHOD_CARD);

        Assert::same(201, $checkout['response']->status);
        Assert::same(['checkout_url' => 'https://checkout.stripe.test/' . $checkout['session'], 'order' => $checkout['publicId']], $checkout['response']->body);
        Assert::same(BillingOrder::STATUS_CHECKOUT_OPEN, $this->order($checkout['orderId'])->status());
        Assert::same(9700, $this->order($checkout['orderId'])->priceCents(), 'price comes from the server');

        $stripeSubscriptionId = 'sub_t22_' . bin2hex(random_bytes(5));
        $this->queueSubscription($stripeSubscriptionId);
        [$response] = $this->post(($this->fixtures['checkoutCompleted'])($checkout['session'], $stripeSubscriptionId, $this->created()));

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        $order = $this->order($checkout['orderId']);
        Assert::same(BillingOrder::STATUS_PROVISIONED, $order->status());
        Assert::same($tenantsBefore + 1, $this->countTenants(), 'exactly one tenant');
        Assert::count(1, $this->email->deliveries(), 'exactly one invite');
        Assert::same($checkout['email'], $this->email->deliveries()[0]->recipient);
        Assert::stringContains('index.php?class=SystemPasswordResetForm&method=onLoad&jwt=', $this->email->deliveries()[0]->body);
        Assert::notNull($order->inviteSentAt());
        $subscription = $this->subscriptions->findByBillingOrderId($checkout['orderId']);
        Assert::same(Subscription::STATUS_ACTIVE, $subscription?->status());
        Assert::same($order->tenantId(), $subscription?->tenantId());
        Assert::same(1, $this->count("SELECT COUNT(*) FROM tenant_user WHERE tenant_id = ? AND status = 'invited'", [$order->tenantId()]));
        Assert::same(AccessDecision::REASON_FULL, $this->guard()->decide((int) $order->tenantId(), self::ALLOWED_PROGRAM, $this->now)->reason());
    }

    /** (b) pagamento recusado (async_payment_failed) → pedido expired, 0 tenant. */
    public function testScenarioBRefusedPaymentExpiresTheOrderWithoutTenant(): void
    {
        $tenantsBefore = $this->countTenants();
        $checkout = $this->startCheckout('starter', BillingOrder::METHOD_PIX);

        [$response] = $this->post(($this->fixtures['event'])('checkout.session.async_payment_failed', [
            'id' => $checkout['session'],
            'object' => 'checkout.session',
            'mode' => 'payment',
            'payment_status' => 'unpaid',
        ], $this->created()));

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        Assert::same(BillingOrder::STATUS_EXPIRED, $this->order($checkout['orderId'])->status());
        Assert::same($tenantsBefore, $this->countTenants());
        Assert::null($this->subscriptions->findByBillingOrderId($checkout['orderId']));
        Assert::count(0, $this->email->deliveries());
    }

    /** (c) pendente (Pix com checkout aberto, sem evento) → checkout_open, 0 tenant, sem acesso. */
    public function testScenarioCPendingPixKeepsCheckoutOpenWithoutAccess(): void
    {
        $tenantsBefore = $this->countTenants();

        $checkout = $this->startCheckout('pro', BillingOrder::METHOD_PIX);

        Assert::same(201, $checkout['response']->status);
        $order = $this->order($checkout['orderId']);
        Assert::same(BillingOrder::STATUS_CHECKOUT_OPEN, $order->status());
        Assert::null($order->tenantId());
        Assert::null($order->paidAt());
        Assert::same($tenantsBefore, $this->countTenants());
        Assert::null($this->subscriptions->findByBillingOrderId($checkout['orderId']));
        Assert::same(0, $this->count('SELECT COUNT(*) FROM system_users WHERE login = ?', [$checkout['email']]), 'no login exists for a pending order');
        Assert::count(0, $this->email->deliveries());
        $session = $this->stripe->calls[1]['params'];
        Assert::same('payment', $session['mode']);
        Assert::same(['pix'], $session['payment_method_types']);
    }

    /** (d) webhook inválido → 400, 0 billing_event. */
    public function testScenarioDInvalidWebhookIsRejectedWithoutWritingEvents(): void
    {
        $checkout = $this->startCheckout('pro', BillingOrder::METHOD_CARD);
        $body = $this->body(($this->fixtures['checkoutCompleted'])($checkout['session'], 'sub_t22_x', $this->created()));
        $eventsBefore = $this->countEvents();
        $callsBefore = count($this->stripe->calls);

        $wrongSecret = StripeSignatureVerifier::header($body, 'whsec_test_outro_segredo', $this->now->getTimestamp());
        $this->assertJson(400, ['error' => 'invalid_signature'], $this->webhook()->handle('POST', ['stripe-signature' => $wrongSecret], $body, $this->now));

        $valid = StripeSignatureVerifier::header($body, self::SECRET, $this->now->getTimestamp());
        $tampered = str_replace('"payment_status":"paid"', '"payment_status":"unpaid"', $body);
        Assert::true($tampered !== $body, 'the tampered body must differ');
        $this->assertJson(400, ['error' => 'invalid_signature'], $this->webhook()->handle('POST', ['stripe-signature' => $valid], $tampered, $this->now));

        $this->assertJson(400, ['error' => 'invalid_signature'], $this->webhook()->handle('POST', [], $body, $this->now));

        $old = StripeSignatureVerifier::header($body, self::SECRET, $this->now->getTimestamp() - 3600);
        $this->assertJson(400, ['error' => 'invalid_signature'], $this->webhook()->handle('POST', ['stripe-signature' => $old], $body, $this->now));

        Assert::same($eventsBefore, $this->countEvents(), 'no billing_event row for an invalid webhook');
        Assert::same($callsBefore, count($this->stripe->calls));
        Assert::same(BillingOrder::STATUS_CHECKOUT_OPEN, $this->order($checkout['orderId'])->status());
    }

    /** (e) evento duplicado → duplicate, contagens iguais. */
    public function testScenarioEDuplicateEventIsAcknowledgedWithoutChanges(): void
    {
        $clinic = $this->provisionedClinic('starter');
        $counts = $this->snapshotCounts();
        $calls = count($this->stripe->calls);

        $response = $this->webhook()->handle(
            'POST',
            ['stripe-signature' => StripeSignatureVerifier::header($clinic['checkoutBody'], self::SECRET, $this->now->getTimestamp())],
            $clinic['checkoutBody'],
            $this->now,
        );

        $this->assertJson(200, ['received' => true, 'result' => 'duplicate'], $response);
        Assert::same($counts, $this->snapshotCounts(), 'tenants, users, subscriptions, invoices and events stay the same');
        Assert::count(1, $this->email->deliveries(), 'no second invite');
        Assert::same($calls, count($this->stripe->calls), 'a duplicate must not call Stripe');
    }

    /** (f) fora de ordem: invoice.paid antes do checkout, depois reenvio → active. */
    public function testScenarioFOutOfOrderInvoicePaidSucceedsOnRedelivery(): void
    {
        $checkout = $this->startCheckout('pro', BillingOrder::METHOD_CARD);
        $stripeSubscriptionId = 'sub_t22_' . bin2hex(random_bytes(5));
        $created = $this->created();
        $periodEnd = $this->now->modify('+1 month');
        [$early, $invoiceBody] = $this->post(($this->fixtures['invoice'])(
            'invoice.paid',
            'in_t22_' . bin2hex(random_bytes(5)),
            $stripeSubscriptionId,
            'paid',
            9700,
            $this->now->getTimestamp() - 3600,
            $periodEnd->getTimestamp(),
            $created + 5,
        ));

        $this->assertJson(500, ['error' => 'processing_failed'], $early);
        Assert::same('failed', $this->eventStatus($invoiceBody));

        $this->queueSubscription($stripeSubscriptionId);
        [$checkoutResponse] = $this->post(($this->fixtures['checkoutCompleted'])($checkout['session'], $stripeSubscriptionId, $created));
        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $checkoutResponse);

        $redelivery = $this->postBody($invoiceBody, $this->now);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $redelivery);
        Assert::same('processed', $this->eventStatus($invoiceBody));
        $subscription = $this->subscriptions->findByBillingOrderId($checkout['orderId']);
        Assert::same(Subscription::STATUS_ACTIVE, $subscription?->status());
        $invoices = $this->invoices->listBySubscription((int) $subscription?->id(), 10);
        Assert::count(1, $invoices);
        Assert::same(SubscriptionInvoice::STATUS_PAID, $invoices[0]->status());
        Assert::same(BillingOrder::STATUS_PROVISIONED, $this->order($checkout['orderId'])->status());
    }

    /** (g) falha do gateway no checkout → 502, pedido pending, 0 cobrança no fake. */
    public function testScenarioGGatewayFailureAnswers502AndKeepsTheOrderPending(): void
    {
        $this->stripe->fail('POST', '/v1/customers', new StripeApiException('Stripe unavailable', 503));
        $first = $this->checkoutPost('pro', BillingOrder::METHOD_CARD, 'gw1');

        $this->assertJson(502, ['error' => 'gateway_unavailable'], $first['response']);
        $order = $this->orders->findByPublicId($first['publicId']);
        Assert::same(BillingOrder::STATUS_PENDING, $order?->status());
        Assert::null($order?->stripeCheckoutSessionId());

        $this->stripe->queue('POST', '/v1/customers', ['id' => 'cus_t22_gw2']);
        $this->stripe->fail('POST', '/v1/checkout/sessions', new StripeApiException('Stripe unavailable', 503));
        $second = $this->checkoutPost('starter', BillingOrder::METHOD_PIX, 'gw2');

        $this->assertJson(502, ['error' => 'gateway_unavailable'], $second['response']);
        Assert::same(BillingOrder::STATUS_PENDING, $this->orders->findByPublicId($second['publicId'])?->status());

        $charges = array_filter($this->stripe->calls, static fn (array $call): bool => in_array($call['path'], ['/v1/payment_intents', '/v1/subscriptions'], true));
        Assert::count(0, $charges, 'no charge in the fake');
        Assert::count(1, array_filter($this->stripe->calls, static fn (array $call): bool => $call['path'] === '/v1/checkout/sessions'), 'only the failed session attempt');
        Assert::count(0, $this->email->deliveries());
    }

    /** (h) aprovado com provisionamento interrompido → provisioning_failed; o reconciliador conclui sem POST ao Stripe. */
    public function testScenarioHInterruptedProvisioningIsFinishedByTheReconcilerWithoutStripeWrites(): void
    {
        $tenantsBefore = $this->countTenants();
        $checkout = $this->startCheckout('starter', BillingOrder::METHOD_CARD);
        $stripeSubscriptionId = 'sub_t22_' . bin2hex(random_bytes(5));
        $this->queueSubscription($stripeSubscriptionId);
        $interrupt = static function (string $step): void {
            if ($step === 'user') {
                throw new \RuntimeException('provisioning interrupted');
            }
        };

        [$response, $body] = $this->post(($this->fixtures['checkoutCompleted'])($checkout['session'], $stripeSubscriptionId, $this->created()), null, $interrupt);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        Assert::same('processed', $this->eventStatus($body));
        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $this->order($checkout['orderId'])->status());
        Assert::same($tenantsBefore, $this->countTenants());
        Assert::count(0, $this->email->deliveries());

        $callsBefore = count($this->stripe->calls);
        $result = $this->reconcile($this->now->modify('+15 minutes'));

        Assert::same(1, $result['orders_fulfilled']);
        Assert::same(0, $result['orders_failed']);
        $order = $this->order($checkout['orderId']);
        Assert::same(BillingOrder::STATUS_PROVISIONED, $order->status());
        Assert::same($tenantsBefore + 1, $this->countTenants());
        Assert::count(1, $this->email->deliveries());
        $after = array_slice($this->stripe->calls, $callsBefore);
        Assert::true($after !== [], 'the reconciler reads /v1/events');
        foreach ($after as $call) {
            Assert::same('GET', $call['method'], 'the reconciler must not write to Stripe (' . $call['path'] . ')');
        }
    }

    /** (i) renovação aprovada (invoice.paid do 2º período) → current_period_end avança. */
    public function testScenarioIApprovedRenewalAdvancesThePeriod(): void
    {
        $clinic = $this->provisionedClinic('pro');
        $before = $this->subscription($clinic);
        $start = (int) $before->currentPeriodEnd()?->getTimestamp();
        $end = (int) $before->currentPeriodEnd()?->modify('+1 month')->getTimestamp();
        $at = $before->currentPeriodEnd()?->modify('+1 minute') ?? $this->now;

        [$response] = $this->post(($this->fixtures['invoice'])('invoice.paid', 'in_t22_' . bin2hex(random_bytes(5)), $clinic['stripeSubscriptionId'], 'paid', 9700, $start, $end, $at->getTimestamp()), $at);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        $after = $this->subscription($clinic);
        Assert::same(Subscription::STATUS_ACTIVE, $after->status());
        Assert::true($after->currentPeriodEnd() > $before->currentPeriodEnd(), 'current_period_end must advance');
        Assert::same($end, $after->currentPeriodEnd()?->getTimestamp());
    }

    /** (j) renovação recusada → past_due, grace até 7 dias, depois suspended pelo reconciliador e blocked_status. */
    public function testScenarioJRefusedRenewalGoesPastDueThenSuspended(): void
    {
        $clinic = $this->provisionedClinic('pro');
        $before = $this->subscription($clinic);
        $at = $before->currentPeriodEnd()?->modify('+1 minute') ?? $this->now;
        $start = (int) $before->currentPeriodEnd()?->getTimestamp();

        [$response] = $this->post(($this->fixtures['invoice'])('invoice.payment_failed', 'in_t22_' . bin2hex(random_bytes(5)), $clinic['stripeSubscriptionId'], 'open', 9700, $start, $start + 86400 * 30, $at->getTimestamp()), $at);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        $pastDue = $this->subscription($clinic);
        Assert::same(Subscription::STATUS_PAST_DUE, $pastDue->status());
        Assert::same($at->modify('+7 days')->format('Y-m-d H:i:s'), $pastDue->graceUntil()?->format('Y-m-d H:i:s'), 'grace of 7 days');
        Assert::same(AccessDecision::REASON_GRACE, $this->guard()->decide($clinic['tenantId'], self::ALLOWED_PROGRAM, $at->modify('+6 days'))->reason());

        $stillGrace = $this->reconcile($at->modify('+6 days'));
        Assert::same(0, $stillGrace['subscriptions_suspended']);
        Assert::same(Subscription::STATUS_PAST_DUE, $this->subscription($clinic)->status());

        $late = $at->modify('+7 days +1 hour');
        $result = $this->reconcile($late);

        Assert::same(1, $result['subscriptions_suspended']);
        Assert::same(Subscription::STATUS_SUSPENDED, $this->subscription($clinic)->status());
        $decision = $this->guard()->decide($clinic['tenantId'], self::ALLOWED_PROGRAM, $late);
        Assert::same(AccessDecision::REASON_BLOCKED_STATUS, $decision->reason());
        Assert::false($decision->allowed());
        Assert::true($this->guard()->decide($clinic['tenantId'], 'SubscriptionStatusView', $late)->allowed(), 'the subscription screen stays open');
    }

    /** (k) cancelamento (customer.subscription.deleted) → cancelled e blocked_status. */
    public function testScenarioKCancellationBlocksAccess(): void
    {
        $clinic = $this->provisionedClinic('business');
        $at = $this->now->modify('+3 days');

        [$response] = $this->post(($this->fixtures['event'])('customer.subscription.deleted', [
            'id' => $clinic['stripeSubscriptionId'],
            'object' => 'subscription',
            'status' => 'canceled',
        ], $at->getTimestamp()), $at);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        $subscription = $this->subscription($clinic);
        Assert::same(Subscription::STATUS_CANCELLED, $subscription->status());
        Assert::notNull($subscription->cancelledAt());
        Assert::same(AccessDecision::REASON_BLOCKED_STATUS, $this->guard()->decide($clinic['tenantId'], self::ALLOWED_PROGRAM, $at)->reason());
    }

    /** (l) estorno (charge.refunded total) → fatura refunded. */
    public function testScenarioLFullRefundMarksTheInvoiceRefunded(): void
    {
        $clinic = $this->provisionedClinic('pro');
        $before = $this->subscription($clinic);
        $start = (int) $before->currentPeriodEnd()?->getTimestamp();
        $at = $before->currentPeriodEnd()?->modify('+1 minute') ?? $this->now;
        $stripeInvoiceId = 'in_t22_' . bin2hex(random_bytes(5));
        $this->post(($this->fixtures['invoice'])('invoice.paid', $stripeInvoiceId, $clinic['stripeSubscriptionId'], 'paid', 9700, $start, $start + 86400 * 30, $at->getTimestamp()), $at);
        Assert::same(SubscriptionInvoice::STATUS_PAID, $this->invoices->findByStripeInvoiceIdForUpdate($stripeInvoiceId)?->status());

        $refundAt = $at->modify('+2 days');
        [$response] = $this->post(($this->fixtures['chargeRefunded'])($stripeInvoiceId, null, 9700, 9700, $refundAt->getTimestamp()), $refundAt);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        Assert::same(SubscriptionInvoice::STATUS_REFUNDED, $this->invoices->findByStripeInvoiceIdForUpdate($stripeInvoiceId)?->status());
        Assert::same(Subscription::STATUS_ACTIVE, $this->subscription($clinic)->status(), 'a refund does not change the access');
    }

    /** (m) recurso de outro plano → blocked_plan; assinatura de B suspensa não afeta A. */
    public function testScenarioMPlanAndTenantBoundariesOfTheAccessGuard(): void
    {
        $a = $this->provisionedClinic('starter');
        $b = $this->provisionedClinic('pro');

        Assert::same(AccessDecision::REASON_BLOCKED_PLAN, $this->guard()->decide($a['tenantId'], 'SaleList', $this->now)->reason());
        Assert::same(AccessDecision::REASON_FULL, $this->guard()->decide($b['tenantId'], 'SaleList', $this->now)->reason());

        $at = $this->now->modify('+1 hour');
        $start = (int) $this->subscription($b)->currentPeriodStart()?->getTimestamp();
        $this->post(($this->fixtures['invoice'])('invoice.payment_failed', 'in_t22_' . bin2hex(random_bytes(5)), $b['stripeSubscriptionId'], 'open', 9700, $start, $start + 86400 * 30, $at->getTimestamp()), $at);
        $late = $at->modify('+8 days');
        $this->reconcile($late);

        Assert::same(Subscription::STATUS_SUSPENDED, $this->subscription($b)->status());
        Assert::same(AccessDecision::REASON_BLOCKED_STATUS, $this->guard()->decide($b['tenantId'], self::ALLOWED_PROGRAM, $late)->reason());
        Assert::same(Subscription::STATUS_ACTIVE, $this->subscription($a)->status());
        Assert::same(AccessDecision::REASON_FULL, $this->guard()->decide($a['tenantId'], self::ALLOWED_PROGRAM, $late)->reason(), 'A keeps full access');
    }

    /** (n) renovação Pix: o reconciliador emite a fatura com PaymentIntent e payment_intent.succeeded avança o período. */
    public function testScenarioNPixRenewalIsIssuedByTheReconcilerAndPaid(): void
    {
        $clinic = $this->provisionedClinic('pro', BillingOrder::METHOD_PIX);
        $before = $this->subscription($clinic);
        $periodEnd = $before->currentPeriodEnd() ?? $this->now;
        Assert::same(Subscription::METHOD_PIX, $before->paymentMethod());
        Assert::null($before->stripeSubscriptionId());

        $issueAt = $periodEnd->modify('-2 days');
        $paymentIntentId = 'pi_t22_' . bin2hex(random_bytes(5));
        $this->queuePaymentIntent($paymentIntentId, $periodEnd->modify('+30 days'));
        $issued = $this->reconcile($issueAt);

        Assert::same(1, $issued['pix_invoices_issued']);
        $invoice = $this->openPixInvoice($clinic);
        Assert::same($paymentIntentId, $invoice->stripePaymentIntentId());
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $invoice->status());
        $post = array_values(array_filter($this->stripe->calls, static fn (array $call): bool => $call['path'] === '/v1/payment_intents'));
        Assert::count(1, $post);
        Assert::same('pix-invoice-' . $invoice->localKey() . '-1', $post[0]['idempotencyKey']);
        Assert::count(2, $this->email->deliveries(), 'the invite and the Pix link');
        Assert::same($clinic['email'], $this->email->deliveries()[1]->recipient);

        $paidAt = $periodEnd->modify('-1 day');
        [$response] = $this->post(($this->fixtures['paymentIntentSucceeded'])($paymentIntentId, $invoice->amountDueCents(), ['subscription_invoice' => (string) $invoice->id()], $paidAt->getTimestamp()), $paidAt);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        $after = $this->subscription($clinic);
        Assert::same(Subscription::STATUS_ACTIVE, $after->status());
        Assert::same($periodEnd->modify('+1 month')->format('Y-m-d H:i:s'), $after->currentPeriodEnd()?->format('Y-m-d H:i:s'), 'current_period_end advances one month');
        Assert::same(SubscriptionInvoice::STATUS_PAID, $this->invoices->findByIdForUpdate((int) $invoice->id())?->status());
    }

    /** (o) renovação Pix sem pagamento: past_due, grace 7 dias, suspended; pagamento tardio volta a active. */
    public function testScenarioOUnpaidPixRenewalIsSuspendedAndALatePaymentReactivates(): void
    {
        $clinic = $this->provisionedClinic('starter', BillingOrder::METHOD_PIX);
        $periodEnd = $this->subscription($clinic)->currentPeriodEnd() ?? $this->now;
        $paymentIntentId = 'pi_t22_' . bin2hex(random_bytes(5));
        $this->queuePaymentIntent($paymentIntentId, $periodEnd->modify('+30 days'));
        Assert::same(1, $this->reconcile($periodEnd->modify('-2 days'))['pix_invoices_issued']);
        $invoice = $this->openPixInvoice($clinic);

        $overdueAt = $periodEnd->modify('+1 hour');
        $overdue = $this->reconcile($overdueAt);

        Assert::same(1, $overdue['pix_invoices_overdue']);
        $pastDue = $this->subscription($clinic);
        Assert::same(Subscription::STATUS_PAST_DUE, $pastDue->status());
        Assert::same($overdueAt->modify('+7 days')->format('Y-m-d H:i:s'), $pastDue->graceUntil()?->format('Y-m-d H:i:s'));
        Assert::same(AccessDecision::REASON_GRACE, $this->guard()->decide($clinic['tenantId'], self::ALLOWED_PROGRAM, $overdueAt)->reason());

        $suspendAt = $overdueAt->modify('+7 days +1 hour');
        $suspended = $this->reconcile($suspendAt);

        Assert::same(1, $suspended['subscriptions_suspended']);
        Assert::same(Subscription::STATUS_SUSPENDED, $this->subscription($clinic)->status());
        Assert::same(AccessDecision::REASON_BLOCKED_STATUS, $this->guard()->decide($clinic['tenantId'], self::ALLOWED_PROGRAM, $suspendAt)->reason());
        Assert::count(1, array_filter($this->stripe->calls, static fn (array $call): bool => $call['method'] === 'POST' && $call['path'] === '/v1/payment_intents'), 'no second PaymentIntent');

        $paidAt = $suspendAt->modify('+2 hours');
        [$response] = $this->post(($this->fixtures['paymentIntentSucceeded'])($paymentIntentId, $invoice->amountDueCents(), ['subscription_invoice' => (string) $invoice->id()], $paidAt->getTimestamp()), $paidAt);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        $active = $this->subscription($clinic);
        Assert::same(Subscription::STATUS_ACTIVE, $active->status());
        Assert::null($active->graceUntil());
        Assert::same($periodEnd->modify('+1 month')->format('Y-m-d H:i:s'), $active->currentPeriodEnd()?->format('Y-m-d H:i:s'));
        Assert::same(AccessDecision::REASON_FULL, $this->guard()->decide($clinic['tenantId'], self::ALLOWED_PROGRAM, $paidAt)->reason());
    }

    /** (p) equipe e unidades da clínica A provisionada em Starter: quota e fronteira de tenant com B. */
    public function testScenarioPClinicTeamAndUnitsRespectQuotaAndTenantBoundary(): void
    {
        $a = $this->provisionedClinic('starter');
        $b = $this->provisionedClinic('pro');
        $admin = $this->adminOf($a['tenantId']);
        $team = $this->teamService($a['tenantId'], $admin['user'], $admin['unit']);
        $units = $this->unitService($a['tenantId'], $admin['user'], $admin['unit']);
        $bAdmin = $this->adminOf($b['tenantId']);
        $invitesBefore = count($this->email->deliveries());

        $invited = 't22-equipe-' . bin2hex(random_bytes(4)) . '@example.test';
        $userId = $team->invite('Dra. Equipe A', $invited, $this->groups['starter'], $admin['unit'], $this->now);

        Assert::true($userId > 0);
        Assert::count($invitesBefore + 1, $this->email->deliveries(), 'the invite goes to the FakeEmailProvider');
        Assert::same($invited, $this->email->deliveries()[$invitesBefore]->recipient);

        $usersBefore = $this->count('SELECT COUNT(*) FROM system_users');
        $this->expect(QuotaExceededException::class, 'Your plan allows up to 2 users',
            fn () => $team->invite('Terceira', 't22-extra-' . bin2hex(random_bytes(4)) . '@example.test', $this->groups['starter'], $admin['unit'], $this->now));
        Assert::same($usersBefore, $this->count('SELECT COUNT(*) FROM system_users'));

        $listed = array_column($team->listUsers(), 'id');
        sort($listed);
        $expected = [$admin['user'], $userId];
        sort($expected);
        Assert::same($expected, $listed, 'A lists only its own users');
        Assert::false(in_array($bAdmin['user'], $listed, true));
        Assert::same([$admin['unit']], array_column($units->listUnits(), 'id'), 'A lists only its own unit');

        $userBefore = $this->userSnapshot($bAdmin['user']);
        $unitBefore = $this->value('SELECT name FROM system_unit WHERE id = ?', [$bAdmin['unit']]);
        $this->expect(TenantBoundaryViolation::class, 'Resource does not belong to the authenticated tenant',
            fn () => $team->update($bAdmin['user'], 'Invasor', $this->groups['starter'], $admin['unit']));
        $this->expect(TenantBoundaryViolation::class, 'Resource does not belong to the authenticated tenant',
            fn () => $team->deactivate($bAdmin['user']));
        $this->expect(TenantBoundaryViolation::class, 'Resource does not belong to the authenticated tenant',
            fn () => $units->rename($bAdmin['unit'], 'Invadida'));
        Assert::same($userBefore, $this->userSnapshot($bAdmin['user']), 'the user of B is unchanged');
        Assert::same($unitBefore, $this->value('SELECT name FROM system_unit WHERE id = ?', [$bAdmin['unit']]), 'the unit of B is unchanged');

        Assert::same([$this->groups['starter'] => 'Central Vet - Plano Starter'], $team->allowedGroups());
    }

    /** (q) e-mail vira login entre o checkout e o webhook → provisioning_failed; o reconciliador cancela, estorna e marca refunded uma vez. */
    public function testScenarioQLoginTakenAfterCheckoutIsCancelledAndRefundedOnceByTheReconciler(): void
    {
        $tenantsBefore = $this->countTenants();
        $checkout = $this->startCheckout('pro', BillingOrder::METHOD_CARD);
        $userId = $this->count('SELECT COALESCE(MAX(id), 0) + 1 FROM system_users FOR UPDATE');
        $this->pdo->prepare("INSERT INTO system_users (id, name, login, email, active) VALUES (?, 'Já cadastrado', ?, ?, 'Y')")
            ->execute([$userId, $checkout['email'], $checkout['email']]);

        $stripeSubscriptionId = 'sub_t31_' . bin2hex(random_bytes(5));
        $this->queueSubscription($stripeSubscriptionId);
        [$response] = $this->post(($this->fixtures['checkoutCompleted'])($checkout['session'], $stripeSubscriptionId, $this->created()));

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        $failed = $this->order($checkout['orderId']);
        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $failed->status());
        Assert::same(ProvisioningConflict::LOGIN_CONFLICT, $failed->provisioningError());
        Assert::same(Subscription::STATUS_ACTIVE, $this->subscriptions->findByBillingOrderId($checkout['orderId'])?->status());

        $invoiceId = 'in_t31_' . bin2hex(random_bytes(5));
        $paymentIntent = 'pi_t31_' . bin2hex(random_bytes(5));
        $refundId = 're_t31_' . bin2hex(random_bytes(5));
        $this->stripe->queue('GET', '/v1/subscriptions/' . $stripeSubscriptionId, ['id' => $stripeSubscriptionId, 'object' => 'subscription', 'status' => 'active']);
        $this->stripe->queue('DELETE', '/v1/subscriptions/' . $stripeSubscriptionId, ['id' => $stripeSubscriptionId, 'object' => 'subscription', 'status' => 'canceled']);
        $this->stripe->queue('GET', '/v1/checkout/sessions/' . $checkout['session'], ['id' => $checkout['session'], 'object' => 'checkout.session', 'invoice' => $invoiceId, 'payment_intent' => null]);
        $this->stripe->queue('GET', '/v1/invoices/' . $invoiceId, ['id' => $invoiceId, 'object' => 'invoice', 'payment_intent' => $paymentIntent]);
        $this->stripe->queue('POST', '/v1/refunds', ['id' => $refundId, 'object' => 'refund', 'status' => 'succeeded']);
        $callsBefore = count($this->stripe->calls);

        $result = $this->reconcile($this->now->modify('+15 minutes'));

        Assert::same(1, $result['orders_refunded']);
        Assert::same(0, $result['orders_failed']);
        $after = array_slice($this->stripe->calls, $callsBefore);
        Assert::count(1, array_filter($after, static fn (array $call): bool => $call['method'] === 'DELETE' && $call['path'] === '/v1/subscriptions/' . $stripeSubscriptionId));
        $refunds = array_values(array_filter($after, static fn (array $call): bool => $call['method'] === 'POST' && $call['path'] === '/v1/refunds'));
        Assert::count(1, $refunds);
        Assert::same($paymentIntent, $refunds[0]['params']['payment_intent'] ?? null);
        $order = $this->order($checkout['orderId']);
        Assert::same(BillingOrder::STATUS_REFUNDED, $order->status());
        Assert::same($refundId, $order->stripeRefundId());
        Assert::same(Subscription::STATUS_CANCELLED, $this->subscriptions->findByBillingOrderId($checkout['orderId'])?->status());
        Assert::same($tenantsBefore, $this->countTenants(), 'no tenant for the refunded order');

        $callsBefore = count($this->stripe->calls);
        $second = $this->reconcile($this->now->modify('+30 minutes'));

        Assert::same(0, $second['orders_refunded']);
        Assert::same(
            ['GET /v1/events'],
            array_map(static fn (array $call): string => $call['method'] . ' ' . $call['path'], array_slice($this->stripe->calls, $callsBefore)),
            'the second run makes no new call besides listing events',
        );
        Assert::same(BillingOrder::STATUS_REFUNDED, $this->order($checkout['orderId'])->status());
    }

    // ---------------------------------------------------------------- montagem

    private function checkoutHandler(): CheckoutStartHandler
    {
        return new CheckoutStartHandler(
            new LeadFormToken($this->redis),
            new LoginRateLimiter($this->redis, 100, 3600, 'centralvet:checkout-throttle:'),
            $this->orders,
            $this->stripe,
            new StripeConfig('placeholder-secret', self::SECRET, [
                'starter' => 'price_starter_placeholder',
                'pro' => 'price_pro_placeholder',
                'business' => 'price_business_placeholder',
                'enterprise' => 'price_enterprise_placeholder',
            ]),
            new PublicUrl('https://app.test'),
            function (): string {
                $this->lastPublicId = $this->uuid();

                return $this->lastPublicId;
            },
            new AccountEmailReader($this->pdo),
        );
    }

    private function webhook(?\Closure $faultInjector = null): BillingWebhookHandler
    {
        $tx = new TransactionRunner($this->pdo);

        return new BillingWebhookHandler(
            new StripeSignatureVerifier(self::SECRET),
            new BillingEventIngestor(
                $tx,
                $this->events,
                new BillingEventProcessor($this->orders, $this->subscriptions, $this->invoices, $this->stripe),
                $this->provisioning($tx, $faultInjector),
            ),
            new JsonLogger('billing-webhook-test', 'test', 'php://memory'),
        );
    }

    /** @return array<string, int> */
    private function reconcile(DateTimeImmutable $at): array
    {
        $this->stripe->queue('GET', '/v1/events', ['object' => 'list', 'data' => [], 'has_more' => false]);
        $tx = new TransactionRunner($this->pdo);
        $provisioning = $this->provisioning($tx);

        return (new BillingReconciler(
            $this->stripe,
            $this->events,
            new BillingEventIngestor(
                $tx,
                $this->events,
                new BillingEventProcessor($this->orders, $this->subscriptions, $this->invoices, $this->stripe),
                $provisioning,
            ),
            $this->orders,
            $this->subscriptions,
            $this->invoices,
            new PixInvoiceIssuer($this->stripe, $this->invoices, $this->email, $this->translate()),
            $provisioning,
            $tx,
            new LoginConflictRefundService($tx, $this->orders, $this->subscriptions, $this->stripe, $this->email, ''),
        ))->run($at);
    }

    private function provisioning(TransactionRunner $tx, ?\Closure $faultInjector = null): TenantProvisioningService
    {
        return new TenantProvisioningService(
            $this->pdo,
            $tx,
            $this->orders,
            $this->subscriptions,
            new AccountInviteService(new PasswordResetToken(self::KEY), new PublicUrl('https://app.test'), $this->email, $this->translate()),
            $faultInjector,
        );
    }

    private function guard(): SubscriptionAccessGuard
    {
        return new SubscriptionAccessGuard($this->subscriptions);
    }

    private function teamService(int $tenantId, int $userId, int $unitId): ClinicTeamService
    {
        return new ClinicTeamService(
            $this->pdo,
            new TransactionRunner($this->pdo),
            TenantContext::authenticated($tenantId, $userId, $unitId),
            $this->subscriptions,
            new QuotaGuard($this->subscriptions, new TenantUsageReader($this->pdo)),
            $this->guard(),
            new AccountInviteService(new PasswordResetToken(self::KEY), new PublicUrl('https://app.test'), $this->email, $this->translate()),
        );
    }

    private function unitService(int $tenantId, int $userId, int $unitId): ClinicUnitService
    {
        return new ClinicUnitService(
            $this->pdo,
            new TransactionRunner($this->pdo),
            TenantContext::authenticated($tenantId, $userId, $unitId),
            new QuotaGuard($this->subscriptions, new TenantUsageReader($this->pdo)),
            $this->guard(),
        );
    }

    private function translate(): \Closure
    {
        return \Closure::fromCallable(static fn (string $key, string ...$params): string => '[' . implode('|', [$key, ...$params]) . ']');
    }

    // ---------------------------------------------------------------- fluxos

    /**
     * POST /checkout.php com o Stripe respondendo.
     *
     * @return array{response: LeadResponse, publicId: string, orderId: int, session: string, email: string}
     */
    private function startCheckout(string $planId, string $method): array
    {
        $suffix = bin2hex(random_bytes(5));
        $session = 'cs_t22_' . $suffix;
        $this->stripe->queue('POST', '/v1/customers', ['id' => 'cus_t22_' . $suffix]);
        $this->stripe->queue('POST', '/v1/checkout/sessions', ['id' => $session, 'url' => 'https://checkout.stripe.test/' . $session]);

        $result = $this->checkoutPost($planId, $method, $suffix);
        Assert::same(201, $result['response']->status, 'checkout must open (body ' . json_encode($result['response']->body) . ')');
        $order = $this->orders->findByPublicId($result['publicId']);
        Assert::same($session, $order?->stripeCheckoutSessionId());

        return $result + ['orderId' => (int) $order?->id(), 'session' => $session];
    }

    /** @return array{response: LeadResponse, publicId: string, email: string} */
    private function checkoutPost(string $planId, string $method, string $suffix): array
    {
        $tokens = new LeadFormToken($this->redis);
        $email = 't22-' . $suffix . '@example.test';
        $payload = [
            'form_token' => $tokens->issue($this->now->getTimestamp() - 30),
            'plan_id' => $planId,
            'payment_method' => $method,
            'responsible_name' => 'Dra. T-22 ' . $suffix,
            'responsible_email' => $email,
            'responsible_phone' => '(85) 99999-1234',
            'clinic_name' => 'Clínica T-22 ' . $suffix,
            'terms_accepted' => true,
            'terms_version' => 'assinatura-2026-10',
            'contact_consent' => false,
            'website' => '',
            'price_cents' => 1,
        ];
        $headers = [
            'content-type' => 'application/json',
            'origin' => 'https://app.test',
            'host' => 'app.test',
            'sec-fetch-site' => 'same-origin',
        ];

        $response = $this->checkoutHandler()->handle('POST', $headers, (string) json_encode($payload, JSON_UNESCAPED_UNICODE), self::IP, $this->now);

        return ['response' => $response, 'publicId' => $this->lastPublicId, 'email' => $email];
    }

    /**
     * Compra concluída pelo webhook (cartão: checkout.session.completed;
     * Pix: checkout.session.async_payment_succeeded) e provisionada.
     *
     * @return array{orderId: int, tenantId: int, email: string, stripeSubscriptionId: ?string, checkoutBody: string}
     */
    private function provisionedClinic(string $planId, string $method = BillingOrder::METHOD_CARD): array
    {
        $checkout = $this->startCheckout($planId, $method);
        $stripeSubscriptionId = null;

        if ($method === BillingOrder::METHOD_CARD) {
            $stripeSubscriptionId = 'sub_t22_' . bin2hex(random_bytes(5));
            $this->queueSubscription($stripeSubscriptionId);
            $event = ($this->fixtures['checkoutCompleted'])($checkout['session'], $stripeSubscriptionId, $this->created());
        } else {
            $event = ($this->fixtures['checkoutAsyncSucceeded'])(
                $checkout['session'],
                'pi_t22_first_' . bin2hex(random_bytes(4)),
                PlanEntitlements::forPlan($planId)->priceCents(),
                $this->created(),
            );
        }

        [$response, $body] = $this->post($event);
        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        $order = $this->order($checkout['orderId']);
        Assert::same(BillingOrder::STATUS_PROVISIONED, $order->status());

        return [
            'orderId' => $checkout['orderId'],
            'tenantId' => (int) $order->tenantId(),
            'email' => $checkout['email'],
            'stripeSubscriptionId' => $stripeSubscriptionId,
            'checkoutBody' => $body,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0: LeadResponse, 1: string}
     */
    private function post(array $event, ?DateTimeImmutable $at = null, ?\Closure $faultInjector = null): array
    {
        $body = $this->body($event);

        return [$this->postBody($body, $at ?? $this->now, $faultInjector), $body];
    }

    private function postBody(string $body, DateTimeImmutable $at, ?\Closure $faultInjector = null): LeadResponse
    {
        $header = StripeSignatureVerifier::header($body, self::SECRET, $at->getTimestamp());

        return $this->webhook($faultInjector)->handle('POST', ['stripe-signature' => $header], $body, $at);
    }

    /** @param array<string, mixed> $event */
    private function body(array $event): string
    {
        $event['id'] = 'evt_t22_' . bin2hex(random_bytes(8));

        return (string) json_encode($event, JSON_UNESCAPED_SLASHES);
    }

    private function created(): int
    {
        return $this->now->getTimestamp() - 60;
    }

    private function queueSubscription(string $stripeSubscriptionId): void
    {
        $this->stripe->queue('GET', '/v1/subscriptions/' . $stripeSubscriptionId, ($this->fixtures['stripeSubscription'])(
            $stripeSubscriptionId,
            'active',
            $this->now->getTimestamp() - 3600,
            $this->now->modify('+1 month')->getTimestamp(),
        ));
    }

    private function queuePaymentIntent(string $id, DateTimeImmutable $expiresAt): void
    {
        $this->stripe->queue('POST', '/v1/payment_intents', [
            'id' => $id,
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'pix_display_qr_code',
                'pix_display_qr_code' => [
                    'hosted_instructions_url' => 'https://pay.stripe.test/' . $id,
                    'expires_at' => $expiresAt->getTimestamp(),
                ],
            ],
        ]);
    }

    // ---------------------------------------------------------------- leitura

    private function order(int $orderId): BillingOrder
    {
        $order = $this->orders->findByIdForUpdate($orderId);
        Assert::notNull($order, 'billing order ' . $orderId);

        return $order;
    }

    /** @param array{orderId: int} $clinic */
    private function subscription(array $clinic): Subscription
    {
        $subscription = $this->subscriptions->findByBillingOrderId($clinic['orderId']);
        Assert::notNull($subscription, 'subscription of order ' . $clinic['orderId']);

        return $subscription;
    }

    /** @param array{orderId: int} $clinic */
    private function openPixInvoice(array $clinic): SubscriptionInvoice
    {
        $open = array_values(array_filter(
            $this->invoices->listBySubscription((int) $this->subscription($clinic)->id(), 10),
            static fn (SubscriptionInvoice $invoice): bool => $invoice->status() === SubscriptionInvoice::STATUS_OPEN,
        ));
        Assert::count(1, $open, 'one open Pix invoice');

        return $open[0];
    }

    /** @return array{user: int, unit: int} */
    private function adminOf(int $tenantId): array
    {
        return [
            'user' => (int) $this->value('SELECT system_user_id FROM tenant_user WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId]),
            'unit' => (int) $this->value('SELECT id FROM system_unit WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId]),
        ];
    }

    /** @return array<string, mixed> */
    private function userSnapshot(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.name, u.login, u.email, u.active, u.system_unit_id, tu.tenant_id, tu.status, '
            . '(SELECT GROUP_CONCAT(system_group_id ORDER BY system_group_id) FROM system_user_group WHERE system_user_id = u.id) AS user_groups '
            . 'FROM system_users u JOIN tenant_user tu ON tu.system_user_id = u.id WHERE u.id = ?',
        );
        $statement->execute([$userId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC)[0] ?? [];
    }

    /** @return array<string, int> */
    private function snapshotCounts(): array
    {
        return [
            'tenant' => $this->countTenants(),
            'system_users' => $this->count('SELECT COUNT(*) FROM system_users'),
            'subscription' => $this->count('SELECT COUNT(*) FROM subscription'),
            'subscription_invoice' => $this->count('SELECT COUNT(*) FROM subscription_invoice'),
            'billing_event' => $this->countEvents(),
        ];
    }

    private function eventStatus(string $body): ?string
    {
        $value = $this->value("SELECT status FROM billing_event WHERE provider = 'stripe' AND event_id = ?", [(string) json_decode($body, true)['id']]);

        return $value === null ? null : (string) $value;
    }

    /** @param array<string, mixed> $expected */
    private function assertJson(int $status, array $expected, LeadResponse $response): void
    {
        Assert::same($status, $response->status, 'HTTP status (body ' . json_encode($response->body) . ')');
        Assert::same($expected, $response->body);
    }

    /** @param class-string<\Throwable> $class */
    private function expect(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof AssertionFailedException) {
                throw $e;
            }
            Assert::true($e instanceof $class, sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()));
            Assert::same($message, $e->getMessage());

            return;
        }

        throw new AssertionFailedException("Expected {$class}('{$message}') was not thrown");
    }

    private function createPlanGroups(): void
    {
        foreach (PlanEntitlements::PLAN_IDS as $planId) {
            $name = TenantProvisioningService::GROUP_NAME_PREFIX . PlanEntitlements::forPlan($planId)->name();
            $id = (int) $this->value('SELECT id FROM system_group WHERE name = ? ORDER BY id LIMIT 1', [$name]);
            if ($id === 0) {
                $id = (int) $this->value('SELECT COALESCE(MAX(id), 0) + 1 FROM system_group FOR UPDATE');
                $this->pdo->prepare('INSERT INTO system_group (id, name) VALUES (?, ?)')->execute([$id, $name]);
            }
            $this->groups[$planId] = $id;
        }
    }

    private function countTenants(): int
    {
        return $this->count('SELECT COUNT(*) FROM tenant');
    }

    private function countEvents(): int
    {
        return $this->count('SELECT COUNT(*) FROM billing_event');
    }

    /** @param list<int|string|null> $params */
    private function count(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params);
    }

    /** @param list<int|string|null> $params */
    private function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
