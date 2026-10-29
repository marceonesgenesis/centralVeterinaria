<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\LoginConflictRefundService;
use CentralVet\Billing\ProvisioningConflict;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Estorno do pedido pago cujo e-mail já é login (T-31) contra o
 * centralvet_test com a 0017 e a 0018: cartão (cancela a Subscription do
 * Stripe e estorna o PaymentIntent da fatura), Pix (estorna o PaymentIntent
 * da sessão), retomada depois de falha no estorno, idempotência e alerta ao
 * operador. O Stripe é o FakeStripeClient; o TransactionRunner usa SAVEPOINT
 * dentro da transação do teste, desfeita no tearDown().
 */
final class LoginConflictRefundIntegrationTest extends MysqlIntegrationTestCase
{
    private const OPERATOR = 'operador@example.test';

    private BillingOrderRepository $orders;
    private SubscriptionRepository $subscriptions;
    private FakeStripeClient $stripe;
    private FakeEmailProvider $email;
    private DateTimeImmutable $now;
    private ?string $previousErrorLog = null;

    public function setUp(): void
    {
        parent::setUp();

        $hasColumn = (bool) $this->pdo->query("SHOW COLUMNS FROM billing_order LIKE 'stripe_refund_id'")->fetchColumn();
        Assert::true($hasColumn, 'Migration 0018 must be applied to the test database');

        // O serviço registra o estorno e as falhas esperadas com error_log.
        $this->previousErrorLog = (string) ini_set('error_log', '/dev/null');

        $this->orders = new BillingOrderRepository($this->pdo);
        $this->subscriptions = new SubscriptionRepository($this->pdo);
        $this->stripe = new FakeStripeClient();
        $this->email = new FakeEmailProvider();
        $this->now = new DateTimeImmutable('2031-02-01 12:00:00');
    }

    public function tearDown(): void
    {
        if ($this->previousErrorLog !== null) {
            ini_set('error_log', $this->previousErrorLog);
        }

        parent::tearDown();
    }

    public function testCardOrderCancelsTheSubscriptionRefundsTheInvoiceAndAlertsTheOperator(): void
    {
        $order = $this->conflictOrder(BillingOrder::METHOD_CARD);
        $this->queueCardRefund($order);

        $result = $this->service()->refund($order['id'], $this->now);

        Assert::same(LoginConflictRefundService::RESULT_REFUNDED, $result);
        Assert::same([
            'GET /v1/subscriptions/' . $order['sub'],
            'DELETE /v1/subscriptions/' . $order['sub'],
            'GET /v1/checkout/sessions/' . $order['session'],
            'GET /v1/invoices/' . $order['invoice'],
            'POST /v1/refunds',
        ], $this->callKeys());
        Assert::same('cancel-' . $order['publicId'], $this->stripe->calls[1]['idempotencyKey']);
        Assert::same('refund-' . $order['publicId'], $this->stripe->calls[4]['idempotencyKey']);
        Assert::same([
            'payment_intent' => $order['pi'],
            'reason' => 'requested_by_customer',
            'metadata' => ['billing_order' => $order['publicId'], 'reason' => 'login_conflict'],
        ], $this->stripe->calls[4]['params']);

        $saved = $this->order($order['id']);
        Assert::same(BillingOrder::STATUS_REFUNDED, $saved->status());
        Assert::same($order['refund'], $saved->stripeRefundId());
        Assert::notNull($saved->subscriptionCancelledAt());
        Assert::notNull($saved->refundedAt());
        Assert::same(ProvisioningConflict::LOGIN_CONFLICT, $saved->provisioningError(), 'provisioning_error is kept');
        Assert::same(Subscription::STATUS_CANCELLED, $this->subscriptions->findByBillingOrderId($order['id'])?->status());

        Assert::count(1, $this->email->deliveries(), 'one alert to the operator');
        $alert = $this->email->deliveries()[0];
        Assert::same(self::OPERATOR, $alert->recipient);
        Assert::same('Central Vet Pro: pedido ' . $order['publicId'] . ' estornado (e-mail já cadastrado)', $alert->subject);
        Assert::same('login-conflict-refund-' . $order['publicId'], $alert->reference);
        foreach ([$order['publicId'], 'Pro', 'R$ 97,00', $order['clinic'], $order['email'], $order['refund']] as $fragment) {
            Assert::stringContains($fragment, $alert->body);
        }

        $calls = count($this->stripe->calls);
        Assert::same(LoginConflictRefundService::RESULT_ALREADY_REFUNDED, $this->service()->refund($order['id'], $this->now));
        Assert::count($calls, $this->stripe->calls, 'no Stripe call for an already refunded order');
        Assert::count(1, $this->email->deliveries(), 'no second alert');
    }

    public function testPixOrderRefundsThePaymentIntentOfTheSessionWithoutCancelling(): void
    {
        $order = $this->conflictOrder(BillingOrder::METHOD_PIX);
        $this->stripe->queue('GET', '/v1/checkout/sessions/' . $order['session'], ['id' => $order['session'], 'invoice' => null, 'payment_intent' => $order['pi']]);
        $this->stripe->queue('POST', '/v1/refunds', ['id' => $order['refund'], 'object' => 'refund', 'status' => 'succeeded']);

        Assert::same(LoginConflictRefundService::RESULT_REFUNDED, $this->service()->refund($order['id'], $this->now));

        Assert::same(['GET /v1/checkout/sessions/' . $order['session'], 'POST /v1/refunds'], $this->callKeys());
        Assert::same($order['pi'], $this->stripe->calls[1]['params']['payment_intent'] ?? null);
        Assert::same('refund-' . $order['publicId'], $this->stripe->calls[1]['idempotencyKey']);
        $saved = $this->order($order['id']);
        Assert::same(BillingOrder::STATUS_REFUNDED, $saved->status());
        Assert::null($saved->subscriptionCancelledAt(), 'Pix has no Stripe subscription to cancel');
        Assert::same(Subscription::STATUS_CANCELLED, $this->subscriptions->findByBillingOrderId($order['id'])?->status(), 'no Pix invoice is issued for it');
        Assert::count(1, $this->email->deliveries());
    }

    public function testFailedRefundKeepsTheCancellationAndTheNextCallResumesWithoutDeletingAgain(): void
    {
        $order = $this->conflictOrder(BillingOrder::METHOD_CARD);
        $this->stripe->queue('GET', '/v1/subscriptions/' . $order['sub'], ['id' => $order['sub'], 'status' => 'active']);
        $this->stripe->queue('DELETE', '/v1/subscriptions/' . $order['sub'], ['id' => $order['sub'], 'status' => 'canceled']);
        $this->stripe->queue('GET', '/v1/checkout/sessions/' . $order['session'], ['id' => $order['session'], 'invoice' => $order['invoice'], 'payment_intent' => null]);
        $this->stripe->queue('GET', '/v1/invoices/' . $order['invoice'], ['id' => $order['invoice'], 'payment_intent' => $order['pi']]);
        $this->stripe->fail('POST', '/v1/refunds', new StripeApiException('Service unavailable', 503));

        Assert::throws(StripeApiException::class, fn () => $this->service()->refund($order['id'], $this->now));

        $failed = $this->order($order['id']);
        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $failed->status());
        Assert::notNull($failed->subscriptionCancelledAt(), 'the cancellation step is kept');
        Assert::null($failed->stripeRefundId());
        Assert::count(0, $this->email->deliveries());

        $calls = count($this->stripe->calls);
        $this->stripe->queue('GET', '/v1/checkout/sessions/' . $order['session'], ['id' => $order['session'], 'invoice' => $order['invoice'], 'payment_intent' => null]);
        $this->stripe->queue('GET', '/v1/invoices/' . $order['invoice'], ['id' => $order['invoice'], 'payment_intent' => $order['pi']]);
        $this->stripe->queue('POST', '/v1/refunds', ['id' => $order['refund'], 'object' => 'refund', 'status' => 'succeeded']);

        Assert::same(LoginConflictRefundService::RESULT_REFUNDED, $this->service()->refund($order['id'], $this->now->modify('+15 minutes')));

        Assert::same([
            'GET /v1/checkout/sessions/' . $order['session'],
            'GET /v1/invoices/' . $order['invoice'],
            'POST /v1/refunds',
        ], array_slice($this->callKeys(), $calls), 'the resumed run does not cancel again');
        Assert::same(BillingOrder::STATUS_REFUNDED, $this->order($order['id'])->status());
        Assert::count(1, $this->email->deliveries());
    }

    public function testAlreadyRefundedChargeUsesTheExistingRefund(): void
    {
        $order = $this->conflictOrder(BillingOrder::METHOD_PIX);
        $this->stripe->queue('GET', '/v1/checkout/sessions/' . $order['session'], ['id' => $order['session'], 'payment_intent' => $order['pi']]);
        $this->stripe->fail('POST', '/v1/refunds', new StripeApiException('Charge already refunded', 400, 'charge_already_refunded'));
        $this->stripe->queue('GET', '/v1/refunds', ['object' => 'list', 'data' => [['id' => $order['refund'], 'object' => 'refund']], 'has_more' => false]);

        Assert::same(LoginConflictRefundService::RESULT_REFUNDED, $this->service()->refund($order['id'], $this->now));

        Assert::same(['payment_intent' => $order['pi'], 'limit' => 1], $this->stripe->calls[2]['params']);
        Assert::same($order['refund'], $this->order($order['id'])->stripeRefundId());
    }

    public function testSubscriptionAlreadyCanceledAtStripeIsNotDeleted(): void
    {
        $order = $this->conflictOrder(BillingOrder::METHOD_CARD);
        $this->stripe->queue('GET', '/v1/subscriptions/' . $order['sub'], ['id' => $order['sub'], 'status' => 'canceled']);
        $this->stripe->queue('GET', '/v1/checkout/sessions/' . $order['session'], ['id' => $order['session'], 'invoice' => $order['invoice']]);
        $this->stripe->queue('GET', '/v1/invoices/' . $order['invoice'], ['id' => $order['invoice'], 'payment_intent' => $order['pi']]);
        $this->stripe->queue('POST', '/v1/refunds', ['id' => $order['refund'], 'object' => 'refund']);

        Assert::same(LoginConflictRefundService::RESULT_REFUNDED, $this->service()->refund($order['id'], $this->now));

        Assert::count(0, array_filter($this->stripe->calls, static fn (array $call): bool => $call['method'] === 'DELETE'));
        Assert::same(BillingOrder::STATUS_REFUNDED, $this->order($order['id'])->status());
    }

    public function testEmptyOperatorEmailSendsNoAlert(): void
    {
        $order = $this->conflictOrder(BillingOrder::METHOD_CARD);
        $this->queueCardRefund($order);

        Assert::same(LoginConflictRefundService::RESULT_REFUNDED, $this->service('')->refund($order['id'], $this->now));

        Assert::count(0, $this->email->deliveries());
    }

    public function testFailingAlertDoesNotUndoTheRefund(): void
    {
        $order = $this->conflictOrder(BillingOrder::METHOD_CARD);
        $this->queueCardRefund($order);
        $this->email->failWith('smtp_down');

        Assert::same(LoginConflictRefundService::RESULT_REFUNDED, $this->service()->refund($order['id'], $this->now));

        Assert::same(BillingOrder::STATUS_REFUNDED, $this->order($order['id'])->status());
    }

    public function testOrderWithoutLoginConflictIsNotApplicable(): void
    {
        $order = $this->conflictOrder(BillingOrder::METHOD_CARD, ProvisioningConflict::GROUP_MISSING);

        Assert::same(LoginConflictRefundService::RESULT_NOT_APPLICABLE, $this->service()->refund($order['id'], $this->now));
        Assert::same(LoginConflictRefundService::RESULT_NOT_APPLICABLE, $this->service()->refund(PHP_INT_MAX, $this->now));

        Assert::count(0, $this->stripe->calls);
        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $this->order($order['id'])->status());
        Assert::same(Subscription::STATUS_ACTIVE, $this->subscriptions->findByBillingOrderId($order['id'])?->status());
        Assert::count(0, $this->email->deliveries());
    }

    private function service(string $operatorEmail = self::OPERATOR): LoginConflictRefundService
    {
        return new LoginConflictRefundService(
            new TransactionRunner($this->pdo),
            $this->orders,
            $this->subscriptions,
            $this->stripe,
            $this->email,
            $operatorEmail,
        );
    }

    /** @param array{sub: string, session: string, invoice: string, pi: string, refund: string} $order */
    private function queueCardRefund(array $order): void
    {
        $this->stripe->queue('GET', '/v1/subscriptions/' . $order['sub'], ['id' => $order['sub'], 'object' => 'subscription', 'status' => 'active']);
        $this->stripe->queue('DELETE', '/v1/subscriptions/' . $order['sub'], ['id' => $order['sub'], 'object' => 'subscription', 'status' => 'canceled']);
        $this->stripe->queue('GET', '/v1/checkout/sessions/' . $order['session'], ['id' => $order['session'], 'object' => 'checkout.session', 'invoice' => $order['invoice'], 'payment_intent' => null]);
        $this->stripe->queue('GET', '/v1/invoices/' . $order['invoice'], ['id' => $order['invoice'], 'object' => 'invoice', 'payment_intent' => $order['pi']]);
        $this->stripe->queue('POST', '/v1/refunds', ['id' => $order['refund'], 'object' => 'refund', 'status' => 'succeeded']);
    }

    /** @return list<string> */
    private function callKeys(): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . $call['path'], $this->stripe->calls);
    }

    /**
     * Pedido pago do plano Pro que caiu no provisionamento, com a assinatura
     * local active (a do webhook de checkout).
     *
     * @return array{id: int, publicId: string, email: string, clinic: string, sub: string, session: string, invoice: string, pi: string, refund: string}
     */
    private function conflictOrder(string $method, string $error = ProvisioningConflict::LOGIN_CONFLICT): array
    {
        $suffix = bin2hex(random_bytes(5));
        $publicId = $this->uuid();
        $sub = $method === BillingOrder::METHOD_CARD ? 'sub_t31_' . $suffix : null;
        $order = BillingOrder::place(
            $publicId,
            'pro',
            $method,
            'Dra. T-31 ' . $suffix,
            't31-' . $suffix . '@example.test',
            '11999990000',
            'Clínica T-31 ' . $suffix,
            '2026-10',
            $this->now->modify('-1 hour'),
            '127.0.0.1',
            false,
        );
        $order->attachCheckout('cs_t31_' . $suffix, 'cus_t31_' . $suffix);
        $order->markPaid($sub, $this->now->modify('-30 minutes'));
        $order->markProvisioningFailed($error);
        $id = $this->orders->insert($order);

        $this->subscriptions->insert(Subscription::start(
            $id,
            'pro',
            $method,
            'cus_t31_' . $suffix,
            $sub,
            'active',
            $this->now->modify('-30 minutes'),
            $this->now->modify('+1 month'),
            $this->now->getTimestamp() - 1800,
        ));

        return [
            'id' => $id,
            'publicId' => $publicId,
            'email' => $order->responsibleEmail(),
            'clinic' => $order->clinicName(),
            'sub' => (string) $sub,
            'session' => 'cs_t31_' . $suffix,
            'invoice' => 'in_t31_' . $suffix,
            'pi' => 'pi_t31_' . $suffix,
            'refund' => 're_t31_' . $suffix,
        ];
    }

    private function order(int $id): BillingOrder
    {
        $order = $this->orders->findByIdForUpdate($id);
        Assert::notNull($order, 'billing order ' . $id);

        return $order;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
