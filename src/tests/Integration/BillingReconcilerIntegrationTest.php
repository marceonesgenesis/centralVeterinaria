<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\BillingEventIngestor;
use CentralVet\Billing\BillingEventProcessor;
use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\BillingReconciler;
use CentralVet\Billing\CancelledPixRefundService;
use CentralVet\Billing\LoginConflictRefundService;
use CentralVet\Billing\PixInvoiceIssuer;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Billing\ProvisioningConflict;
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

    public function testOverduePixInvoiceGoesPastDueEvenWhenThePaymentIntentFails(): void
    {
        [$orderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $periodEnd = $this->now->modify('-1 day');
        $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $periodEnd);
        $invoiceId = $this->invoices->insert(SubscriptionInvoice::issuePix($subscriptionId, 9700, $periodEnd, $periodEnd->modify('+1 month'), $periodEnd));
        $this->stripe->fail('POST', '/v1/payment_intents', new StripeApiException('Stripe secret key is not configured', 0));

        $this->queueNoEvents();
        $result = $this->reconciler()->run($this->now);

        Assert::same(1, $result['pix_invoices_overdue']);
        Assert::same(0, $result['pix_intents_refreshed']);
        $subscription = $this->subscriptions->findByIdForUpdate($subscriptionId);
        Assert::same(Subscription::STATUS_PAST_DUE, $subscription?->status(), 'the overdue is recorded even though Stripe failed');
        Assert::notNull($subscription?->graceUntil());
        Assert::null($this->invoices->findByIdForUpdate($invoiceId)?->stripePaymentIntentId());
        Assert::count(1, array_filter($this->stripe->calls, static fn (array $c): bool => $c['method'] === 'POST'));

        $this->queueNoEvents();
        $this->stripe->fail('POST', '/v1/payment_intents', new StripeApiException('Stripe secret key is not configured', 0));
        $late = $this->reconciler()->run($this->now->modify('+8 days'));

        Assert::same(1, $late['subscriptions_suspended']);
        Assert::same(Subscription::STATUS_SUSPENDED, $this->subscriptions->findByIdForUpdate($subscriptionId)?->status());
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
        Assert::same('pix-invoice-' . $refreshed?->localKey() . '-2', $post[0]['idempotencyKey']);
        Assert::count(1, $this->email->deliveries());
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testStripeFailureOnOnePixInvoiceKeepsItWithoutIntentAndRetriesWithTheSameKey(): void
    {
        [$failingOrderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $failingId = $this->subscription($failingOrderId, 'pro', Subscription::METHOD_PIX, null, $this->now->modify('+1 day'));
        [$okOrderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $okId = $this->subscription($okOrderId, 'pro', Subscription::METHOD_PIX, null, $this->now->modify('+2 days'));
        $this->stripe->fail('POST', '/v1/payment_intents', new StripeApiException('Stripe unavailable', 503));
        $this->queuePaymentIntent('pi_t18_ok', $this->now->modify('+10 days'));
        $this->stripe->fail('POST', '/v1/payment_intents', new StripeApiException('Stripe unavailable', 503));

        $this->queueNoEvents();
        $result = $this->reconciler()->run($this->now);

        Assert::same(2, $result['pix_invoices_issued']);
        $kept = $this->invoices->listBySubscription($failingId, 10);
        Assert::count(1, $kept, 'the invoice stays so the retry reuses its id and key');
        Assert::null($kept[0]->stripePaymentIntentId());
        Assert::count(1, $this->invoices->listBySubscription($okId, 10));
        Assert::count(1, $this->email->deliveries());

        $this->queuePaymentIntent('pi_t18_retry', $this->now->modify('+10 days'));
        $this->queueNoEvents();
        $retry = $this->reconciler()->run($this->now->modify('+15 minutes'));

        Assert::same(1, $retry['pix_intents_refreshed']);
        Assert::same('pi_t18_retry', $this->invoices->listBySubscription($failingId, 10)[0]->stripePaymentIntentId());
        $keys = array_values(array_map(
            static fn (array $c): ?string => $c['idempotencyKey'],
            array_filter($this->stripe->calls, static fn (array $c): bool => $c['method'] === 'POST'),
        ));
        $failingKey = 'pix-invoice-' . $kept[0]->localKey() . '-1';
        Assert::same([$failingKey, 'pix-invoice-' . $this->invoices->listBySubscription($okId, 10)[0]->localKey() . '-1', $failingKey, $failingKey], $keys);
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testEmailFailureKeepsInvoiceAndIntentWithoutASecondIntentAndStillGoesPastDue(): void
    {
        [$orderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $periodEnd = $this->now->modify('+2 days');
        $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $periodEnd);
        $this->queuePaymentIntent('pi_t18_mail', $this->now->modify('+10 days'));
        $this->email->failWith('smtp_recipient_rejected');

        $this->queueNoEvents();
        $first = $this->reconciler()->run($this->now);

        Assert::same(1, $first['pix_invoices_issued']);
        $invoices = $this->invoices->listBySubscription($subscriptionId, 10);
        Assert::count(1, $invoices, 'an e-mail failure must not roll the invoice back');
        Assert::same('pi_t18_mail', $invoices[0]->stripePaymentIntentId());

        $this->queueNoEvents();
        $this->reconciler()->run($this->now->modify('+15 minutes'));
        $this->queueNoEvents();
        $late = $this->reconciler()->run($periodEnd->modify('+1 hour'));

        Assert::count(1, array_filter($this->stripe->calls, static fn (array $c): bool => $c['method'] === 'POST'), 'no second PaymentIntent');
        Assert::count(1, $this->invoices->listBySubscription($subscriptionId, 10));
        Assert::same(1, $late['pix_invoices_overdue']);
        Assert::same(Subscription::STATUS_PAST_DUE, $this->subscriptions->findByIdForUpdate($subscriptionId)?->status());
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testInvitesThatAlwaysFailDoNotBlockNewPaidOrders(): void
    {
        $this->email->failWith('smtp_unavailable');
        $stuck = [];
        for ($i = 0; $i < BillingReconciler::FULFILLMENT_BATCH; $i++) {
            [$orderId] = $this->order('starter', BillingOrder::METHOD_CARD, true);
            $this->subscription($orderId, 'starter', Subscription::METHOD_CARD, 'sub_t18_' . bin2hex(random_bytes(6)), $this->now->modify('+1 month'));
            $stuck[] = $orderId;
        }
        $this->queueNoEvents();
        $this->reconciler()->run($this->now);
        foreach ($stuck as $orderId) {
            $order = $this->orders->findByIdForUpdate($orderId);
            Assert::same(BillingOrder::STATUS_PROVISIONED, $order?->status());
            Assert::null($order?->inviteSentAt());
        }

        [$newOrderId] = $this->order('starter', BillingOrder::METHOD_CARD, true);
        $this->subscription($newOrderId, 'starter', Subscription::METHOD_CARD, 'sub_t18_' . bin2hex(random_bytes(6)), $this->now->modify('+1 month'));
        $this->queueNoEvents();
        $this->reconciler()->run($this->now->modify('+15 minutes'));

        Assert::same(BillingOrder::STATUS_PROVISIONED, $this->orders->findByIdForUpdate($newOrderId)?->status(), 'the new paid order is provisioned despite a full batch of failing invites');

        $this->pdo->prepare('UPDATE billing_order SET provisioning_attempts = 10 WHERE id = ?')->execute([$stuck[0]]);
        Assert::false(in_array($stuck[0], $this->orders->listPendingFulfillment(100000), true), 'invite resend stops at the attempt cap');
        Assert::true(in_array($stuck[1], $this->orders->listPendingFulfillment(100000), true));
    }

    public function testStalledInvoicesOfSuspendedOrCancelledSubscriptionsDoNotBlockTheOpenPixBatch(): void
    {
        $dueAt = $this->now->modify('-20 days');
        for ($i = 0; $i < BillingReconciler::PIX_BATCH; $i++) {
            [$orderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
            $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $dueAt);
            $invoice = SubscriptionInvoice::issuePix($subscriptionId, 9700, $dueAt, $dueAt->modify('+1 month'), $dueAt);
            $invoice->attachPaymentIntent('pi_t18_stalled_' . $i, null, $this->now->modify('-10 days'));
            $this->invoices->insert($invoice);
            $this->pdo->prepare('UPDATE subscription SET status = ? WHERE id = ?')
                ->execute([$i % 2 === 0 ? Subscription::STATUS_SUSPENDED : Subscription::STATUS_CANCELLED, $subscriptionId]);
        }

        [$orderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $periodEnd = $this->now->modify('-1 day');
        $activeId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $periodEnd);
        $invoice = SubscriptionInvoice::issuePix($activeId, 9700, $periodEnd, $periodEnd->modify('+1 month'), $periodEnd);
        $invoice->attachPaymentIntent('pi_t18_active', null, $this->now->modify('+6 days'));
        $this->invoices->insert($invoice);

        $this->queueNoEvents();
        $result = $this->reconciler()->run($this->now);

        Assert::same(1, $result['pix_invoices_overdue']);
        Assert::same(Subscription::STATUS_PAST_DUE, $this->subscriptions->findByIdForUpdate($activeId)?->status());
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testScheduledPixCancellationGetsNoInvoiceAndIsFinalizedAfterThePeriod(): void
    {
        [$orderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $periodEnd = $this->now->modify('+2 days');
        $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $periodEnd);
        $locked = $this->subscriptions->findByIdForUpdate($subscriptionId);
        Assert::true($locked?->requestCancellation(7, $this->now->modify('-1 day')) === true);
        $this->subscriptions->save($locked);

        $this->queueNoEvents();
        $before = $this->reconciler()->run($this->now);

        Assert::same(0, $before['pix_invoices_issued'], 'scheduled cancellation gets no new Pix invoice');
        Assert::same(0, $before['cancellations_finalized'], 'the paid period is not over yet');
        Assert::same([], $this->invoices->listBySubscription($subscriptionId, 10));
        Assert::same(Subscription::STATUS_ACTIVE, $this->subscriptions->findByIdForUpdate($subscriptionId)?->status());

        $after = $periodEnd->modify('+1 minute');
        $this->queueNoEvents();
        $result = $this->reconciler()->run($after);

        Assert::same(1, $result['cancellations_finalized']);
        Assert::same(0, $result['pix_invoices_issued']);
        $saved = $this->subscriptions->findByIdForUpdate($subscriptionId);
        Assert::same(Subscription::STATUS_CANCELLED, $saved?->status());
        Assert::same($after->format('Y-m-d H:i:s'), $saved?->cancelledAt()?->format('Y-m-d H:i:s'));
        Assert::same(Subscription::ACCESS_BLOCKED, $saved?->accessLevel($after));
        Assert::same([], $this->invoices->listBySubscription($subscriptionId, 10));
        $this->assertOnlyReadsAndPaymentIntents();
    }

    public function testPaidLoginConflictIsRefundedByTheReconcilerWithoutProvisioningAgain(): void
    {
        $previousErrorLog = (string) ini_set('error_log', '/dev/null');
        $suffix = bin2hex(random_bytes(5));
        $stripeSubscriptionId = 'sub_t31_' . $suffix;
        $order = BillingOrder::place(
            $this->uuid(),
            'pro',
            BillingOrder::METHOD_CARD,
            'Dra. T-31 ' . $suffix,
            't31-' . $suffix . '@example.test',
            '11999990000',
            'Clínica T-31 ' . $suffix,
            '2026-10',
            $this->now->modify('-2 hours'),
            '127.0.0.1',
            false,
        );
        $order->attachCheckout('cs_t31_' . $suffix, 'cus_t31_' . $suffix);
        $order->markPaid($stripeSubscriptionId, $this->now->modify('-1 hour'));
        $order->markProvisioningFailed(ProvisioningConflict::LOGIN_CONFLICT);
        $orderId = $this->orders->insert($order);
        $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_CARD, $stripeSubscriptionId, $this->now->modify('+1 month'));
        $attemptsBefore = $this->orders->findByIdForUpdate($orderId)?->provisioningAttempts();

        $this->queueNoEvents();
        $this->stripe->queue('GET', '/v1/subscriptions/' . $stripeSubscriptionId, ['id' => $stripeSubscriptionId, 'status' => 'active']);
        $this->stripe->queue('DELETE', '/v1/subscriptions/' . $stripeSubscriptionId, ['id' => $stripeSubscriptionId, 'status' => 'canceled']);
        $this->stripe->queue('GET', '/v1/checkout/sessions/cs_t31_' . $suffix, ['id' => 'cs_t31_' . $suffix, 'invoice' => 'in_t31_' . $suffix]);
        $this->stripe->queue('GET', '/v1/invoices/in_t31_' . $suffix, ['id' => 'in_t31_' . $suffix, 'payment_intent' => 'pi_t31_' . $suffix]);
        $this->stripe->queue('POST', '/v1/refunds', ['id' => 're_t31_' . $suffix, 'object' => 'refund']);

        try {
            $result = $this->reconciler()->run($this->now);
        } finally {
            ini_set('error_log', $previousErrorLog);
        }

        Assert::same(1, $result['orders_refunded']);
        Assert::same(0, $result['orders_failed']);
        Assert::same(0, $result['orders_fulfilled']);
        $saved = $this->orders->findByIdForUpdate($orderId);
        Assert::same(BillingOrder::STATUS_REFUNDED, $saved?->status());
        Assert::same('re_t31_' . $suffix, $saved?->stripeRefundId());
        Assert::same($attemptsBefore, $saved?->provisioningAttempts(), 'the conflict is not provisioned again');
        Assert::same(Subscription::STATUS_CANCELLED, $this->subscriptions->findByIdForUpdate($subscriptionId)?->status());
        Assert::count(1, array_filter($this->stripe->calls, static fn (array $call): bool => $call['method'] === 'DELETE'));
        Assert::count(1, array_filter($this->stripe->calls, static fn (array $call): bool => $call['method'] === 'POST' && $call['path'] === '/v1/refunds'));
        Assert::same(
            ['events_fetched', 'events_ingested', 'orders_fulfilled', 'orders_failed', 'orders_refunded', 'pix_invoices_issued', 'pix_invoices_overdue', 'pix_intents_refreshed', 'subscriptions_suspended', 'cancellations_finalized', 'cancelled_pix_refunded'],
            array_keys($result),
        );
    }

    public function testListPaidPixAfterCancellationReturnsOnlyThePaidInvoiceOfALaterPeriod(): void
    {
        $periodEnd = $this->now->modify('+2 days');
        [$scheduledId, $afterId] = $this->paidPixAfterCancellation($periodEnd, 'pi_t22_after');
        $current = SubscriptionInvoice::issuePix($scheduledId, 9700, $periodEnd->modify('-1 month'), $periodEnd, $periodEnd->modify('-1 month'));
        $current->attachPaymentIntent('pi_t22_current_' . bin2hex(random_bytes(4)), null, null);
        Assert::true($current->markPaidByPaymentIntent(9700, 100));
        $currentId = $this->invoices->insert($current);

        [$orderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $plainId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $periodEnd);
        $plainInvoiceId = $this->paidInvoice($plainId, $periodEnd, 'pi_t22_plain');

        [, $refundedId] = $this->paidPixAfterCancellation($periodEnd, 'pi_t22_refunded');
        $locked = $this->invoices->findByIdForUpdate($refundedId);
        Assert::true($locked?->applyRefund(9700, 200) === true);
        $this->invoices->save($locked);

        $listed = $this->invoices->listPaidPixAfterCancellation(BillingReconciler::REFUND_BATCH);

        Assert::true(in_array($afterId, $listed, true), 'the paid invoice after the cancellation is listed');
        Assert::false(in_array($currentId, $listed, true), 'the current period is not listed');
        Assert::false(in_array($plainInvoiceId, $listed, true), 'a subscription without cancellation is not listed');
        Assert::false(in_array($refundedId, $listed, true), 'an already refunded invoice is not listed');
    }

    public function testPaidPixAfterCancellationIsRefundedOnceByTheReconciler(): void
    {
        $previousErrorLog = (string) ini_set('error_log', '/dev/null');
        try {
            [, $invoiceId] = $this->paidPixAfterCancellation($this->now->modify('+2 days'), 'pi_t22_run');
            $this->queueNoEvents();
            $this->stripe->queue('POST', '/v1/refunds', ['id' => 're_t22_run', 'object' => 'refund']);
            $first = $this->reconciler()->run($this->now);

            $this->queueNoEvents();
            $second = $this->reconciler()->run($this->now->modify('+15 minutes'));
        } finally {
            ini_set('error_log', $previousErrorLog);
        }

        Assert::same(1, $first['cancelled_pix_refunded']);
        $saved = $this->invoices->findByIdForUpdate($invoiceId);
        Assert::same(SubscriptionInvoice::STATUS_REFUNDED, $saved?->status());
        Assert::same(9700, $saved?->amountRefundedCents());
        Assert::same(0, $second['cancelled_pix_refunded'], 'the second run does not refund again');
        $refunds = array_values(array_filter($this->stripe->calls, static fn (array $call): bool => $call['method'] === 'POST' && $call['path'] === '/v1/refunds'));
        Assert::count(1, $refunds);
        Assert::same('refund-cancelled-pix-' . $invoiceId, $refunds[0]['idempotencyKey']);
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
            new LoginConflictRefundService($tx, $this->orders, $this->subscriptions, $this->stripe, $this->email, ''),
            new CancelledPixRefundService($tx, $this->invoices, $this->subscriptions, $this->stripe, $this->email, ''),
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

    /**
     * Assinatura Pix com cancelamento agendado e a fatura do período
     * seguinte (`period_start` = `current_period_end`) já paga.
     *
     * @return array{0: int, 1: int}
     */
    private function paidPixAfterCancellation(DateTimeImmutable $periodEnd, string $paymentIntent): array
    {
        [$orderId] = $this->order('pro', BillingOrder::METHOD_PIX, false);
        $subscriptionId = $this->subscription($orderId, 'pro', Subscription::METHOD_PIX, null, $periodEnd);
        $locked = $this->subscriptions->findByIdForUpdate($subscriptionId);
        Assert::true($locked?->requestCancellation(7, $this->now->modify('-1 day')) === true);
        $this->subscriptions->save($locked);

        return [$subscriptionId, $this->paidInvoice($subscriptionId, $periodEnd, $paymentIntent)];
    }

    private function paidInvoice(int $subscriptionId, DateTimeImmutable $periodStart, string $paymentIntent): int
    {
        $invoice = SubscriptionInvoice::issuePix($subscriptionId, 9700, $periodStart, $periodStart->modify('+1 month'), $periodStart);
        $invoice->attachPaymentIntent($paymentIntent . '_' . bin2hex(random_bytes(4)), null, $periodStart->modify('+7 days'));
        Assert::true($invoice->markPaidByPaymentIntent(9700, 100));

        return $this->invoices->insert($invoice);
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
