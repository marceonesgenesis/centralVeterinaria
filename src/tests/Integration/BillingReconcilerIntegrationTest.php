<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\BillingEventIngestor;
use CentralVet\Billing\BillingEventProcessor;
use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\BillingReconciler;
use CentralVet\Billing\PixInvoiceIssuer;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionInvoice;
use CentralVet\Billing\TenantProvisioningService;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Persistence\BillingEventRepository;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Persistence\SubscriptionInvoiceRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Reconciliação da cobrança (T-18) contra o centralvet_test com a 0017:
 * evento perdido recuperado em 2 páginas de /v1/events, retomada do
 * provisionamento, fim da tolerância e o ciclo da fatura Pix (emissão,
 * vencimento e PaymentIntent expirado). O TransactionRunner usa SAVEPOINT
 * dentro da transação do teste, desfeita no tearDown() — nada é commitado.
 * Toda chamada ao Stripe é GET, exceto POST /v1/payment_intents.
 */
final class BillingReconcilerIntegrationTest extends MysqlIntegrationTestCase
{
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';

    private BillingOrderRepository $orders;
    private SubscriptionRepository $subscriptions;
    private SubscriptionInvoiceRepository $invoices;
    private BillingEventRepository $events;
    private FakeStripeClient $stripe;
    private FakeEmailProvider $email;
    private DateTimeImmutable $now;
    private bool $failProvisioning = false;

    /** @var array<string, \Closure> */
    private array $fixtures;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'billing_event'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0017 must be applied to the test database');

        $this->orders = new BillingOrderRepository($this->pdo);
        $this->subscriptions = new SubscriptionRepository($this->pdo);
        $this->invoices = new SubscriptionInvoiceRepository($this->pdo);
        $this->events = new BillingEventRepository($this->pdo);
        $this->stripe = new FakeStripeClient();
        $this->email = new FakeEmailProvider();
        $this->now = new DateTimeImmutable('2031-01-01 12:00:00');
        $this->fixtures = require __DIR__ . '/../Support/Fixtures/stripe-events.php';
        $this->createPlanGroups();
    }

    public function testMissedInvoicePaidIsIngestedAcrossTwoPagesOfEvents(): void
    {
        [$orderId] = $this->order('pro', BillingOrder::METHOD_CARD, false);
        $stripeSubscriptionId = 'sub_t18_' . bin2hex(random_bytes(5));
        $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_CARD, $stripeSubscriptionId, $this->now->modify('+1 month'));

        $processed = ($this->fixtures['event'])('customer.updated', ['id' => 'cus_t18'], $this->now->getTimestamp() - 120);
        $processed['id'] = 'evt_t18_done_' . bin2hex(random_bytes(5));
        $claimed = $this->events->claim($processed['id'], $processed['type'], $processed['created'], false, (string) json_encode($processed));
        $this->events->markDone((int) $claimed, 'processed', $this->now);
        $expectedSince = (int) $this->events->latestEventCreated() - 86400;

        $stripeInvoiceId = 'in_t18_' . bin2hex(random_bytes(5));
        $missed = ($this->fixtures['invoice'])(
            'invoice.paid',
            $stripeInvoiceId,
            $stripeSubscriptionId,
            'paid',
            9700,
            $this->now->getTimestamp() - 3600,
            $this->now->modify('+1 month')->getTimestamp(),
            $this->now->getTimestamp() - 60,
        );
        $missed['id'] = 'evt_t18_missed_' . bin2hex(random_bytes(5));
        $this->stripe->queue('GET', '/v1/events', ['object' => 'list', 'data' => [$missed], 'has_more' => true]);
        $this->stripe->queue('GET', '/v1/events', ['object' => 'list', 'data' => [$processed], 'has_more' => false]);

        $result = $this->reconciler()->run($this->now);

        Assert::same(2, $result['events_fetched']);
        Assert::same(1, $result['events_ingested'], 'only the missed event is new');
        Assert::same(SubscriptionInvoice::STATUS_PAID, $this->invoices->findByStripeInvoiceIdForUpdate($stripeInvoiceId)?->status());
        Assert::same(Subscription::STATUS_ACTIVE, $this->subscriptions->findByIdForUpdate($subscriptionId)?->status());

        Assert::count(2, $this->stripe->calls);
        Assert::same(['created' => ['gte' => $expectedSince], 'limit' => 100], $this->stripe->calls[0]['params']);
        Assert::same(['created' => ['gte' => $expectedSince], 'limit' => 100, 'starting_after' => $missed['id']], $this->stripe->calls[1]['params']);
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testFailedProvisioningIsResumedOnTheNextRunWithOneTenant(): void
    {
        [$orderId] = $this->order('starter', BillingOrder::METHOD_CARD, true);
        $this->subscription($orderId, 'starter', Subscription::METHOD_CARD, 'sub_t18_' . bin2hex(random_bytes(5)), $this->now->modify('+1 month'));
        $tenantsBefore = $this->countTenants();

        $this->failProvisioning = true;
        $this->queueNoEvents();
        $failed = $this->reconciler()->run($this->now);

        Assert::same(1, $failed['orders_failed']);
        Assert::same(0, $failed['orders_fulfilled']);
        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $this->orders->findByIdForUpdate($orderId)?->status());
        Assert::same($tenantsBefore, $this->countTenants());

        $this->failProvisioning = false;
        $this->queueNoEvents();
        $resumed = $this->reconciler()->run($this->now);

        Assert::same(1, $resumed['orders_fulfilled']);
        Assert::same(0, $resumed['orders_failed']);
        $order = $this->orders->findByIdForUpdate($orderId);
        Assert::same(BillingOrder::STATUS_PROVISIONED, $order?->status());
        Assert::notNull($order?->inviteSentAt(), 'the invite goes out after provisioning');
        Assert::same($tenantsBefore + 1, $this->countTenants());
        Assert::count(1, $this->email->deliveries(), 'one invite');
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testPastDueWithGraceInThePastIsSuspended(): void
    {
        [$orderId] = $this->order('business', BillingOrder::METHOD_CARD, false);
        $subscription = Subscription::start(
            $orderId,
            'business',
            Subscription::METHOD_CARD,
            'cus_t18_grace',
            'sub_t18_' . bin2hex(random_bytes(5)),
            'active',
            $this->now->modify('-40 days'),
            $this->now->modify('-10 days'),
            $this->now->getTimestamp() - 86400 * 40,
        );
        $subscription->markPaymentFailed($this->now->getTimestamp() - 86400 * 9, $this->now->modify('-9 days'));
        Assert::same(Subscription::STATUS_PAST_DUE, $subscription->status());
        $subscriptionId = $this->subscriptions->insert($subscription);

        $this->queueNoEvents();
        $result = $this->reconciler()->run($this->now);

        Assert::same(1, $result['subscriptions_suspended']);
        Assert::same(Subscription::STATUS_SUSPENDED, $this->subscriptions->findByIdForUpdate($subscriptionId)?->status());
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testPixPeriodEndingInTwoDaysGetsOneInvoiceWithPaymentIntentOnlyOnce(): void
    {
        [$orderId, $email] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $periodEnd = $this->now->modify('+2 days');
        $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $periodEnd);
        $this->queuePaymentIntent('pi_t18_issue', $this->now->modify('+10 days'));

        $this->queueNoEvents();
        $first = $this->reconciler()->run($this->now);

        Assert::same(1, $first['pix_invoices_issued']);
        $invoices = $this->invoices->listBySubscription($subscriptionId, 10);
        Assert::count(1, $invoices);
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $invoices[0]->status());
        Assert::same('pi_t18_issue', $invoices[0]->stripePaymentIntentId());
        Assert::count(1, $this->email->deliveries());
        Assert::same($email, $this->email->deliveries()[0]->recipient);

        $this->queueNoEvents();
        $second = $this->reconciler()->run($this->now->modify('+1 hour'));

        Assert::same(0, $second['pix_invoices_issued']);
        Assert::same(0, $second['pix_intents_refreshed']);
        Assert::count(1, $this->invoices->listBySubscription($subscriptionId, 10), 'no second invoice');
        Assert::count(1, $this->email->deliveries());
        Assert::count(1, array_filter($this->stripe->calls, static fn (array $c): bool => $c['method'] === 'POST'));
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testOverduePixInvoiceMakesTheSubscriptionPastDue(): void
    {
        [$orderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $periodEnd = $this->now->modify('-1 day');
        $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $periodEnd);
        $invoice = SubscriptionInvoice::issuePix($subscriptionId, 9700, $periodEnd, $periodEnd->modify('+1 month'), $periodEnd);
        $invoice->attachPaymentIntent('pi_t18_overdue', 'https://pay.stripe.test/overdue', $this->now->modify('+6 days'));
        $this->invoices->insert($invoice);

        $this->queueNoEvents();
        $result = $this->reconciler()->run($this->now);

        Assert::same(1, $result['pix_invoices_overdue']);
        Assert::same(0, $result['pix_invoices_issued']);
        $subscription = $this->subscriptions->findByIdForUpdate($subscriptionId);
        Assert::same(Subscription::STATUS_PAST_DUE, $subscription?->status());
        Assert::notNull($subscription?->graceUntil());
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testExpiredPixGetsANewPaymentIntent(): void
    {
        [$orderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $periodEnd = $this->now->modify('+2 days');
        $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $periodEnd);
        $invoice = SubscriptionInvoice::issuePix($subscriptionId, 9700, $periodEnd, $periodEnd->modify('+1 month'), $periodEnd);
        $invoice->attachPaymentIntent('pi_t18_old', 'https://pay.stripe.test/old', $this->now->modify('-1 hour'));
        $invoiceId = $this->invoices->insert($invoice);
        $this->queuePaymentIntent('pi_t18_new', $this->now->modify('+10 days'));

        $this->queueNoEvents();
        $result = $this->reconciler()->run($this->now);

        Assert::same(1, $result['pix_intents_refreshed']);
        Assert::same(0, $result['pix_invoices_issued']);
        $refreshed = $this->invoices->findByIdForUpdate($invoiceId);
        Assert::same('pi_t18_new', $refreshed?->stripePaymentIntentId());
        Assert::same(2, $refreshed?->paymentIntentAttempts());
        $post = array_values(array_filter($this->stripe->calls, static fn (array $c): bool => $c['method'] === 'POST'));
        Assert::count(1, $post);
        Assert::same(sprintf('pix-invoice-%d-2', $invoiceId), $post[0]['idempotencyKey']);
        Assert::count(1, $this->email->deliveries());
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testStripeFailureOnOnePixInvoiceRollsItBackAndTheOthersContinue(): void
    {
        [$failingOrderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $failingId = $this->subscription($failingOrderId, 'pro', Subscription::METHOD_PIX, null, $this->now->modify('+1 day'));
        [$okOrderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $okId = $this->subscription($okOrderId, 'pro', Subscription::METHOD_PIX, null, $this->now->modify('+2 days'));
        $this->stripe->fail('POST', '/v1/payment_intents', new StripeApiException('Stripe unavailable', 503));
        $this->queuePaymentIntent('pi_t18_ok', $this->now->modify('+10 days'));

        $this->queueNoEvents();
        $result = $this->reconciler()->run($this->now);

        Assert::same(1, $result['pix_invoices_issued']);
        Assert::count(0, $this->invoices->listBySubscription($failingId, 10), 'the invoice without PaymentIntent is rolled back');
        Assert::count(1, $this->invoices->listBySubscription($okId, 10));
        Assert::count(1, $this->email->deliveries());
        $this->assertOnlyReadsAndPaymentIntents();
    }

    private function reconciler(): BillingReconciler
    {
        $tx = new TransactionRunner($this->pdo);
        $translate = \Closure::fromCallable(static fn (string $key, string ...$params): string => '[' . implode('|', [$key, ...$params]) . ']');
        $provisioning = new TenantProvisioningService(
            $this->pdo,
            $tx,
            $this->orders,
            $this->subscriptions,
            new AccountInviteService(new PasswordResetToken(self::KEY), new PublicUrl('https://app.test'), $this->email, $translate),
            function (string $step): void {
                if ($this->failProvisioning && $step === 'user') {
                    throw new \RuntimeException('injected provisioning failure');
                }
            },
        );

        return new BillingReconciler(
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
            new PixInvoiceIssuer($this->stripe, $this->invoices, $this->email, $translate),
            $provisioning,
            $tx,
        );
    }

    private function assertOnlyReadsAndPaymentIntents(): void
    {
        foreach ($this->stripe->calls as $call) {
            $allowed = $call['method'] === 'GET' || ($call['method'] === 'POST' && $call['path'] === '/v1/payment_intents');
            Assert::true($allowed, 'unexpected Stripe write ' . $call['method'] . ' ' . $call['path']);
        }
    }

    private function queueNoEvents(): void
    {
        $this->stripe->queue('GET', '/v1/events', ['object' => 'list', 'data' => [], 'has_more' => false]);
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

    /** @return array{0: int, 1: string} */
    private function order(string $planId, string $method, bool $paid): array
    {
        $suffix = bin2hex(random_bytes(5));
        $order = BillingOrder::place(
            $this->uuid(),
            $planId,
            $method,
            'Dra. T-18 ' . $suffix,
            't18-' . $suffix . '@example.test',
            '11999990000',
            'Clínica T-18 ' . $suffix,
            '2026-10',
            $this->now->modify('-2 months'),
            '127.0.0.1',
            false,
        );
        $order->attachCheckout('cs_t18_' . $suffix, 'cus_t18_' . $suffix);
        if ($paid) {
            $order->markPaid(null, $this->now->modify('-1 hour'));
        }

        return [$this->orders->insert($order), $order->responsibleEmail()];
    }

    private function subscription(int $orderId, string $planId, string $method, ?string $stripeSubscriptionId, DateTimeImmutable $periodEnd): int
    {
        return $this->subscriptions->insert(Subscription::start(
            $orderId,
            $planId,
            $method,
            'cus_t18_' . $orderId,
            $stripeSubscriptionId,
            'active',
            $periodEnd->modify('-1 month'),
            $periodEnd,
            $this->now->getTimestamp() - 86400,
        ));
    }

    private function createPlanGroups(): void
    {
        foreach (PlanEntitlements::PLAN_IDS as $planId) {
            $name = TenantProvisioningService::GROUP_NAME_PREFIX . PlanEntitlements::forPlan($planId)->name();
            if ($this->count('SELECT COUNT(*) FROM system_group WHERE name = ?', [$name]) > 0) {
                continue;
            }

            $id = $this->count('SELECT COALESCE(MAX(id), 0) + 1 FROM system_group FOR UPDATE');
            $this->pdo->prepare('INSERT INTO system_group (id, name) VALUES (?, ?)')->execute([$id, $name]);
        }
    }

    private function countTenants(): int
    {
        return $this->count('SELECT COUNT(*) FROM tenant');
    }

    /** @param list<int|string|null> $params */
    private function count(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
