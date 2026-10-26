<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\BillingEventOutcome;
use CentralVet\Billing\BillingEventOutOfOrder;
use CentralVet\Billing\BillingEventProcessor;
use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionInvoice;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeBillingOrderRepository;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\FakeSubscriptionInvoiceRepository;
use CentralVet\Tests\Support\FakeSubscriptionRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Etapa 3, T-14: eventos do Stripe → pedido, assinatura e fatura, com
 * idempotência e guarda contra evento antigo. Cartão por Subscription do
 * Stripe; Pix por PaymentIntent avulso por fatura, sem chamada ao Stripe.
 */
final class BillingEventProcessorTest
{
    private const DATE = 'Y-m-d H:i:s';

    private FakeBillingOrderRepository $orders;
    private FakeSubscriptionInvoiceRepository $invoices;
    private FakeSubscriptionRepository $subscriptions;
    private FakeStripeClient $stripe;
    private BillingEventProcessor $processor;
    private DateTimeImmutable $now;

    /** @var array<string, \Closure> */
    private array $events;

    public function setUp(): void
    {
        $this->orders = new FakeBillingOrderRepository();
        $this->invoices = new FakeSubscriptionInvoiceRepository();
        $this->subscriptions = new FakeSubscriptionRepository($this->invoices);
        $this->stripe = new FakeStripeClient();
        $this->processor = new BillingEventProcessor($this->orders, $this->subscriptions, $this->invoices, $this->stripe);
        $this->now = new DateTimeImmutable('2026-10-21 12:00:00');
        $this->events = require __DIR__ . '/../Support/Fixtures/stripe-events.php';
    }

    public function testCardCheckoutPaidStartsActiveSubscriptionAndReturnsOrderToFulfill(): void
    {
        $orderId = $this->placeOrder('cs_card_1', 'card');
        $this->stripe->queue('GET', '/v1/subscriptions/sub_card_1', ($this->events['stripeSubscription'])('sub_card_1', 'active', 1_790_000_000, 1_792_678_400));

        $outcome = $this->processor->process(($this->events['checkoutCompleted'])('cs_card_1', 'sub_card_1', 1_790_000_100), $this->now);

        Assert::instanceOf(BillingEventOutcome::class, $outcome);
        Assert::same('processed', $outcome->status());
        Assert::same($orderId, $outcome->orderToFulfill());
        Assert::count(1, $this->stripe->calls);

        $order = $this->orders->findByIdForUpdate($orderId);
        Assert::same(BillingOrder::STATUS_PAID, $order->status());
        Assert::same('sub_card_1', $order->stripeSubscriptionId());
        Assert::same($this->at(1_790_000_100), $order->paidAt()->format(self::DATE));

        $subscription = $this->subscriptions->findByBillingOrderId($orderId);
        Assert::notNull($subscription);
        Assert::same(Subscription::STATUS_ACTIVE, $subscription->status());
        Assert::same(Subscription::METHOD_CARD, $subscription->paymentMethod());
        Assert::same('sub_card_1', $subscription->stripeSubscriptionId());
        Assert::same('cus_test_1', $subscription->stripeCustomerId());
        Assert::same($this->at(1_790_000_000), $subscription->currentPeriodStart()->format(self::DATE));
        Assert::same($this->at(1_792_678_400), $subscription->currentPeriodEnd()->format(self::DATE));
        Assert::same(1_790_000_100, $subscription->lastEventCreated());
        Assert::count(0, $this->invoices->rows, 'Card invoices come from invoice.* events');
    }

    public function testCardCheckoutReadsPeriodFromFirstItemWhenSubscriptionHasNone(): void
    {
        $orderId = $this->placeOrder('cs_card_2', 'card');
        $this->stripe->queue('GET', '/v1/subscriptions/sub_card_2', ($this->events['stripeSubscription'])('sub_card_2', 'active', 1_790_000_000, 1_792_678_400, true));

        $this->processor->process(($this->events['checkoutCompleted'])('cs_card_2', 'sub_card_2', 1_790_000_100), $this->now);

        $subscription = $this->subscriptions->findByBillingOrderId($orderId);
        Assert::same($this->at(1_790_000_000), $subscription->currentPeriodStart()->format(self::DATE));
        Assert::same($this->at(1_792_678_400), $subscription->currentPeriodEnd()->format(self::DATE));
    }

    public function testCheckoutCompletedStillUnpaidIsIgnored(): void
    {
        $orderId = $this->placeOrder('cs_pix_wait', 'pix');

        $outcome = $this->processor->process(($this->events['checkoutCompleted'])('cs_pix_wait', null, 1_790_000_100, 'unpaid'), $this->now);

        Assert::same('ignored', $outcome->status());
        Assert::null($outcome->orderToFulfill());
        Assert::same(BillingOrder::STATUS_CHECKOUT_OPEN, $this->orders->findByIdForUpdate($orderId)->status());
        Assert::count(0, $this->subscriptions->rows);
    }

    public function testPixCheckoutCreatesActiveSubscriptionAndPaidFirstInvoiceWithoutStripe(): void
    {
        $orderId = $this->placeOrder('cs_pix_1', 'pix');
        $price = $this->orders->findByIdForUpdate($orderId)->priceCents();

        $outcome = $this->processor->process(($this->events['checkoutAsyncSucceeded'])('cs_pix_1', 'pi_pix_first', $price, 1_790_000_200), $this->now);

        Assert::same('processed', $outcome->status());
        Assert::same($orderId, $outcome->orderToFulfill());
        Assert::same([], $this->stripe->calls, 'Pix checkout must not call Stripe');
        Assert::same(BillingOrder::STATUS_PAID, $this->orders->findByIdForUpdate($orderId)->status());
        Assert::null($this->orders->findByIdForUpdate($orderId)->stripeSubscriptionId());

        $subscription = $this->subscriptions->findByBillingOrderId($orderId);
        Assert::same(Subscription::STATUS_ACTIVE, $subscription->status());
        Assert::same(Subscription::METHOD_PIX, $subscription->paymentMethod());
        Assert::null($subscription->stripeSubscriptionId());
        $paidAt = $this->date(1_790_000_200);
        Assert::same($paidAt->format(self::DATE), $subscription->currentPeriodStart()->format(self::DATE));
        Assert::same($paidAt->modify('+1 month')->format(self::DATE), $subscription->currentPeriodEnd()->format(self::DATE));

        $invoices = $this->invoices->listBySubscription($subscription->id(), 10);
        Assert::count(1, $invoices);
        Assert::same(SubscriptionInvoice::STATUS_PAID, $invoices[0]->status());
        Assert::same('pi_pix_first', $invoices[0]->stripePaymentIntentId());
        Assert::same($price, $invoices[0]->amountDueCents());
        Assert::same($price, $invoices[0]->amountPaidCents());
        Assert::same(sprintf('pix:%d:%s', $subscription->id(), $paidAt->format('Y-m-d')), $invoices[0]->localKey());
        Assert::same($paidAt->modify('+1 month')->format(self::DATE), $invoices[0]->periodEnd()->format(self::DATE));
    }

    public function testSameCheckoutEventTwiceKeepsOneSubscriptionAndOneInvoice(): void
    {
        $orderId = $this->placeOrder('cs_pix_dup', 'pix');
        $event = ($this->events['checkoutAsyncSucceeded'])('cs_pix_dup', 'pi_pix_dup', 9700, 1_790_000_300);

        Assert::same('processed', $this->processor->process($event, $this->now)->status());
        $again = $this->processor->process($event, $this->now);

        Assert::same('ignored', $again->status());
        Assert::count(1, $this->subscriptions->rows);
        Assert::count(1, $this->invoices->rows);
        Assert::same(BillingOrder::STATUS_PAID, $this->orders->findByIdForUpdate($orderId)->status());

        $cardOrder = $this->placeOrder('cs_card_dup', 'card');
        $this->stripe->queue('GET', '/v1/subscriptions/sub_card_dup', ($this->events['stripeSubscription'])('sub_card_dup', 'active', 1_790_000_000, 1_792_678_400));
        $cardEvent = ($this->events['checkoutCompleted'])('cs_card_dup', 'sub_card_dup', 1_790_000_400);

        $this->processor->process($cardEvent, $this->now);
        Assert::same('ignored', $this->processor->process($cardEvent, $this->now)->status());
        Assert::count(2, $this->subscriptions->rows);
        Assert::count(1, $this->stripe->calls, 'A repeated card checkout must not query Stripe again');
        Assert::notNull($this->subscriptions->findByBillingOrderId($cardOrder));
    }

    public function testPixPaymentIntentPaysInvoiceAndAdvancesPeriodEvenWhenOverdue(): void
    {
        // Review Focus: past_due por markOverdue com lastEventCreated() = 500
        // recebe payment_intent.succeeded com created 400 e volta a active.
        $orderId = $this->placeOrder('cs_pix_cycle', 'pix');
        $price = $this->orders->findByIdForUpdate($orderId)->priceCents();
        $this->processor->process(($this->events['checkoutAsyncSucceeded'])('cs_pix_cycle', 'pi_pix_cycle_1', $price, 500), $this->now);

        $subscription = $this->subscriptions->findByBillingOrderId($orderId);
        Assert::same(500, $subscription->lastEventCreated());
        $periodEnd = $subscription->currentPeriodEnd();

        $next = SubscriptionInvoice::issuePix($subscription->id(), $price, $periodEnd, $periodEnd->modify('+1 month'), $periodEnd);
        $next->attachPaymentIntent('pi_pix_cycle_2', 'https://pay.stripe.test/pi_pix_cycle_2', $periodEnd->modify('+10 days'));
        $nextId = $this->invoices->insert($next);

        $locked = $this->subscriptions->findByIdForUpdate($subscription->id());
        Assert::true($locked->markOverdue($this->now));
        $this->subscriptions->save($locked);
        Assert::same(Subscription::STATUS_PAST_DUE, $this->subscriptions->findByIdForUpdate($subscription->id())->status());
        Assert::same(500, $this->subscriptions->findByIdForUpdate($subscription->id())->lastEventCreated());

        $outcome = $this->processor->process(
            ($this->events['paymentIntentSucceeded'])('pi_pix_cycle_2', $price, ['subscription_invoice' => (string) $nextId], 400),
            $this->now,
        );

        Assert::same('processed', $outcome->status());
        Assert::null($outcome->orderToFulfill());

        $invoice = $this->invoices->findByIdForUpdate($nextId);
        Assert::same(SubscriptionInvoice::STATUS_PAID, $invoice->status());
        Assert::same($price, $invoice->amountPaidCents());

        $after = $this->subscriptions->findByIdForUpdate($subscription->id());
        Assert::same(Subscription::STATUS_ACTIVE, $after->status());
        Assert::null($after->graceUntil());
        Assert::same($periodEnd->format(self::DATE), $after->currentPeriodStart()->format(self::DATE));
        Assert::same($periodEnd->modify('+1 month')->format(self::DATE), $after->currentPeriodEnd()->format(self::DATE));
        Assert::true($after->lastEventCreated() >= 500, 'The local guard never moves backwards');

        // Repetido: a fatura já está paga.
        Assert::same('ignored', $this->processor->process(
            ($this->events['paymentIntentSucceeded'])('pi_pix_cycle_2', $price, ['subscription_invoice' => (string) $nextId], 400),
            $this->now,
        )->status());
    }

    public function testPixPaymentIntentOfAnOlderPeriodDoesNotMovePeriodBack(): void
    {
        $orderId = $this->placeOrder('cs_pix_old', 'pix');
        $price = $this->orders->findByIdForUpdate($orderId)->priceCents();
        $this->processor->process(($this->events['checkoutAsyncSucceeded'])('cs_pix_old', 'pi_pix_old_1', $price, 1_790_000_000), $this->now);
        $subscription = $this->subscriptions->findByBillingOrderId($orderId);
        $start = $subscription->currentPeriodStart();

        $older = SubscriptionInvoice::issuePix($subscription->id(), $price, $start->modify('-1 month'), $start, $start->modify('-1 month'));
        $older->attachPaymentIntent('pi_pix_old_0', null, null);
        $olderId = $this->invoices->insert($older);

        $this->processor->process(
            ($this->events['paymentIntentSucceeded'])('pi_pix_old_0', $price, ['subscription_invoice' => (string) $olderId], 1_789_000_000),
            $this->now,
        );

        $after = $this->subscriptions->findByIdForUpdate($subscription->id());
        Assert::same($start->format(self::DATE), $after->currentPeriodStart()->format(self::DATE));
    }

    public function testPixPaidAfterScheduledCancellationMarksTheVoidInvoicePaidWithoutAdvancingTheSubscription(): void
    {
        // T-22: pagamento que chega depois de o cancelamento anular a fatura futura.
        [$subscriptionId, $invoiceId, $price] = $this->pixWithFutureInvoice('cs_pix_t22_sched', 'pi_pix_t22_sched', void: true);
        $locked = $this->subscriptions->findByIdForUpdate($subscriptionId);
        Assert::true($locked->requestCancellation(7, $this->now));
        $this->subscriptions->save($locked);
        $periodEnd = $locked->currentPeriodEnd()->format(self::DATE);

        $previousErrorLog = (string) ini_set('error_log', '/dev/null');
        try {
            $outcome = $this->processor->process(
                ($this->events['paymentIntentSucceeded'])('pi_pix_t22_sched', $price, ['subscription_invoice' => (string) $invoiceId], 1_790_100_000),
                $this->now,
            );
        } finally {
            ini_set('error_log', $previousErrorLog);
        }

        Assert::same('processed', $outcome->status());
        $invoice = $this->invoices->findByIdForUpdate($invoiceId);
        Assert::same(SubscriptionInvoice::STATUS_PAID, $invoice->status(), 'the money came in');
        Assert::same($price, $invoice->amountPaidCents());

        $after = $this->subscriptions->findByIdForUpdate($subscriptionId);
        Assert::same(Subscription::STATUS_ACTIVE, $after->status());
        Assert::same($periodEnd, $after->currentPeriodEnd()->format(self::DATE), 'the cancelled period must not extend the subscription');
        Assert::true($after->cancelAtPeriodEnd(), 'the cancellation stays scheduled');
    }

    public function testPixPaidAfterTheSubscriptionIsCancelledKeepsItCancelled(): void
    {
        [$subscriptionId, $invoiceId, $price] = $this->pixWithFutureInvoice('cs_pix_t22_done', 'pi_pix_t22_done', void: false);
        $locked = $this->subscriptions->findByIdForUpdate($subscriptionId);
        Assert::true($locked->requestCancellation(7, $this->now));
        Assert::true($locked->finalizeScheduledCancellation($locked->currentPeriodEnd()->modify('+1 minute')));
        $this->subscriptions->save($locked);
        $periodEnd = $locked->currentPeriodEnd()->format(self::DATE);

        $previousErrorLog = (string) ini_set('error_log', '/dev/null');
        try {
            $outcome = $this->processor->process(
                ($this->events['paymentIntentSucceeded'])('pi_pix_t22_done', $price, ['subscription_invoice' => (string) $invoiceId], 1_790_100_000),
                $this->now,
            );
        } finally {
            ini_set('error_log', $previousErrorLog);
        }

        Assert::same('processed', $outcome->status());
        Assert::same(SubscriptionInvoice::STATUS_PAID, $this->invoices->findByIdForUpdate($invoiceId)->status());
        $after = $this->subscriptions->findByIdForUpdate($subscriptionId);
        Assert::same(Subscription::STATUS_CANCELLED, $after->status());
        Assert::same($periodEnd, $after->currentPeriodEnd()->format(self::DATE));
    }

    public function testPaymentIntentWithoutInvoiceMetadataOrWithAnotherIntentIsIgnored(): void
    {
        $orderId = $this->placeOrder('cs_pix_first', 'pix');
        $this->processor->process(($this->events['checkoutAsyncSucceeded'])('cs_pix_first', 'pi_first_month', 9700, 1_790_000_000), $this->now);
        $before = $this->invoices->rows;

        $first = $this->processor->process(
            ($this->events['paymentIntentSucceeded'])('pi_first_month', 9700, ['billing_order' => (string) $orderId], 1_790_000_010),
            $this->now,
        );
        Assert::same('ignored', $first->status());
        Assert::null($first->orderToFulfill());

        $subscription = $this->subscriptions->findByBillingOrderId($orderId);
        $end = $subscription->currentPeriodEnd();
        $next = SubscriptionInvoice::issuePix($subscription->id(), 9700, $end, $end->modify('+1 month'), $end);
        $next->attachPaymentIntent('pi_current', null, null);
        $nextId = $this->invoices->insert($next);

        $stale = $this->processor->process(
            ($this->events['paymentIntentSucceeded'])('pi_superseded', 9700, ['subscription_invoice' => (string) $nextId], 1_790_000_020),
            $this->now,
        );
        Assert::same('ignored', $stale->status());
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $this->invoices->findByIdForUpdate($nextId)->status());
        Assert::same($before[1], $this->invoices->rows[1]);

        Assert::throws(BillingEventOutOfOrder::class, fn () => $this->processor->process(
            ($this->events['paymentIntentSucceeded'])('pi_missing', 9700, ['subscription_invoice' => '999'], 1_790_000_030),
            $this->now,
        ));
    }

    public function testOlderSubscriptionUpdateDoesNotUndoPaymentFailure(): void
    {
        $this->startCardSubscription('cs_card_fail', 'sub_card_fail', 50);

        $failed = $this->processor->process(
            ($this->events['invoice'])('invoice.payment_failed', 'in_fail_1', 'sub_card_fail', 'open', 9700, 1_792_678_400, 1_795_270_400, 200),
            $this->now,
        );
        Assert::same('processed', $failed->status());

        $outcome = $this->processor->process(($this->events['subscriptionUpdated'])('sub_card_fail', 'active', 100), $this->now);

        Assert::same('ignored', $outcome->status());
        $subscription = $this->subscriptions->findByStripeSubscriptionIdForUpdate('sub_card_fail');
        Assert::same(Subscription::STATUS_PAST_DUE, $subscription->status());
        Assert::same($this->now->modify('+7 days')->format(self::DATE), $subscription->graceUntil()->format(self::DATE));
        Assert::same(200, $subscription->lastEventCreated());
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $this->invoices->findByStripeInvoiceIdForUpdate('in_fail_1')->status());
    }

    public function testInvoicePaidRegistersInvoiceAndAdvancesCardPeriod(): void
    {
        $this->startCardSubscription('cs_card_paid', 'sub_card_paid', 50);

        $created = $this->processor->process(
            ($this->events['invoice'])('invoice.created', 'in_paid_1', 'sub_card_paid', 'draft', 9700, 1_792_678_400, 1_795_270_400, 60),
            $this->now,
        );
        Assert::same('processed', $created->status());
        Assert::count(1, $this->invoices->rows);

        $this->processor->process(
            ($this->events['invoice'])('invoice.paid', 'in_paid_1', 'sub_card_paid', 'paid', 9700, 1_792_678_400, 1_795_270_400, 70),
            $this->now,
        );

        Assert::count(1, $this->invoices->rows);
        $invoice = $this->invoices->findByStripeInvoiceIdForUpdate('in_paid_1');
        Assert::same(SubscriptionInvoice::STATUS_PAID, $invoice->status());
        Assert::same(9700, $invoice->amountPaidCents());

        $subscription = $this->subscriptions->findByStripeSubscriptionIdForUpdate('sub_card_paid');
        Assert::same(Subscription::STATUS_ACTIVE, $subscription->status());
        Assert::same($this->at(1_792_678_400), $subscription->currentPeriodStart()->format(self::DATE));
        Assert::same($this->at(1_795_270_400), $subscription->currentPeriodEnd()->format(self::DATE));
        Assert::same(70, $subscription->lastEventCreated());
    }

    public function testInvoiceEventWithoutSubscriptionIsOutOfOrder(): void
    {
        Assert::throws(BillingEventOutOfOrder::class, fn () => $this->processor->process(
            ($this->events['invoice'])('invoice.paid', 'in_orphan', 'sub_unknown', 'paid', 9700, 1_792_678_400, 1_795_270_400, 300),
            $this->now,
        ));
        Assert::count(0, $this->invoices->rows);

        Assert::throws(BillingEventOutOfOrder::class, fn () => $this->processor->process(
            ($this->events['subscriptionUpdated'])('sub_unknown', 'active', 300),
            $this->now,
        ));
    }

    public function testEventsOfAnotherIntegrationWithoutCentralVetMarkAreIgnored(): void
    {
        $foreign = [
            ($this->events['checkoutCompleted'])('cs_foreign', 'sub_foreign', 300, 'paid', null),
            ($this->events['invoice'])('invoice.paid', 'in_foreign', 'sub_foreign', 'paid', 9700, 1_792_678_400, 1_795_270_400, 300, null),
            ($this->events['subscriptionUpdated'])('sub_foreign', 'active', 300, null, null, null),
            ($this->events['event'])('charge.refunded', ['id' => 'ch_foreign', 'object' => 'charge', 'invoice' => 'in_foreign', 'amount_refunded' => 9700], 300),
        ];

        foreach ($foreign as $event) {
            Assert::same('ignored', $this->processor->process($event, $this->now)->status(), $event['type']);
        }
        Assert::count(0, $this->invoices->rows);
        Assert::count(0, $this->subscriptions->rows);
        Assert::count(0, $this->stripe->calls);
    }

    public function testEventsWithCentralVetMarkAndMissingDependencyAreStillOutOfOrder(): void
    {
        $ours = [
            ($this->events['checkoutCompleted'])('cs_foreign', 'sub_foreign', 300),
            ($this->events['checkoutAsyncSucceeded'])('cs_foreign_pix', 'pi_foreign', 9700, 300),
            ($this->events['invoice'])('invoice.paid', 'in_foreign', 'sub_foreign', 'paid', 9700, 1_792_678_400, 1_795_270_400, 300),
            ($this->events['subscriptionUpdated'])('sub_foreign', 'active', 300),
            ($this->events['event'])('charge.refunded', ['id' => 'ch_foreign', 'object' => 'charge', 'invoice' => 'in_foreign', 'amount_refunded' => 9700, 'metadata' => ['billing_order' => 'order_test']], 300),
            ($this->events['event'])('charge.refunded', ['id' => 'ch_foreign_2', 'object' => 'charge', 'invoice' => 'in_foreign', 'amount_refunded' => 9700, 'metadata' => ['subscription_invoice' => '7']], 300),
            ($this->events['event'])('invoice.paid', ['id' => 'in_new_api', 'object' => 'invoice', 'status' => 'paid', 'parent' => ['subscription_details' => ['subscription' => 'sub_foreign', 'metadata' => ['billing_order' => 'order_test']]]], 300),
            ($this->events['event'])('invoice.paid', ['id' => 'in_meta', 'object' => 'invoice', 'subscription' => 'sub_foreign', 'status' => 'paid', 'metadata' => ['billing_order' => 'order_test']], 300),
        ];

        foreach ($ours as $event) {
            Assert::throws(BillingEventOutOfOrder::class, fn () => $this->processor->process($event, $this->now));
        }
        Assert::count(0, $this->invoices->rows);
    }

    public function testSubscriptionDeletedCancels(): void
    {
        $this->startCardSubscription('cs_card_del', 'sub_card_del', 50);

        $deleted = ($this->events['event'])('customer.subscription.deleted', ['id' => 'sub_card_del', 'object' => 'subscription', 'status' => 'canceled'], 90);
        Assert::same('processed', $this->processor->process($deleted, $this->now)->status());

        $subscription = $this->subscriptions->findByStripeSubscriptionIdForUpdate('sub_card_del');
        Assert::same(Subscription::STATUS_CANCELLED, $subscription->status());
        Assert::notNull($subscription->cancelledAt());
    }

    public function testFullChargeRefundMarksCardInvoiceRefundedByInvoice(): void
    {
        $this->startCardSubscription('cs_card_ref', 'sub_card_ref', 50);
        $this->processor->process(
            ($this->events['invoice'])('invoice.paid', 'in_ref_1', 'sub_card_ref', 'paid', 9700, 1_792_678_400, 1_795_270_400, 60),
            $this->now,
        );

        $outcome = $this->processor->process(($this->events['chargeRefunded'])('in_ref_1', 'pi_card_ref', 9700, 9700, 80), $this->now);

        Assert::same('processed', $outcome->status());
        $invoice = $this->invoices->findByStripeInvoiceIdForUpdate('in_ref_1');
        Assert::same(SubscriptionInvoice::STATUS_REFUNDED, $invoice->status());
        Assert::same(9700, $invoice->amountRefundedCents());
        Assert::same('findByStripeInvoiceIdForUpdate', $this->invoices->lockCalls[count($this->invoices->lockCalls) - 1]);
        Assert::false(in_array('findByPaymentIntentIdForUpdate', $this->invoices->lockCalls, true), 'Card refund is found by invoice');
    }

    public function testFullChargeRefundMarksPixInvoiceRefundedByPaymentIntent(): void
    {
        $orderId = $this->placeOrder('cs_pix_ref', 'pix');
        $price = $this->orders->findByIdForUpdate($orderId)->priceCents();
        $this->processor->process(($this->events['checkoutAsyncSucceeded'])('cs_pix_ref', 'pi_pix_ref', $price, 1_790_000_000), $this->now);

        $outcome = $this->processor->process(($this->events['chargeRefunded'])(null, 'pi_pix_ref', $price, $price, 1_790_000_500), $this->now);

        Assert::same('processed', $outcome->status());
        $invoice = $this->invoices->findByPaymentIntentIdForUpdate('pi_pix_ref');
        Assert::same(SubscriptionInvoice::STATUS_REFUNDED, $invoice->status());
        Assert::same($price, $invoice->amountRefundedCents());
        Assert::same('findByPaymentIntentIdForUpdate', $this->invoices->lockCalls[0]);

        Assert::same('ignored', $this->processor->process(($this->events['chargeRefunded'])(null, null, 100, 100, 1_790_000_600), $this->now)->status());
    }

    public function testCheckoutExpiredLeavesPaidOrderUntouched(): void
    {
        $orderId = $this->placeOrder('cs_pix_exp', 'pix');
        $this->processor->process(($this->events['checkoutAsyncSucceeded'])('cs_pix_exp', 'pi_pix_exp', 9700, 1_790_000_000), $this->now);
        $paid = $this->orders->findByIdForUpdate($orderId)->toRow();

        $outcome = $this->processor->process(($this->events['checkoutExpired'])('cs_pix_exp', 1_790_000_900), $this->now);

        Assert::same('ignored', $outcome->status());
        Assert::same($paid, $this->orders->findByIdForUpdate($orderId)->toRow());

        $openId = $this->placeOrder('cs_open_exp', 'card');
        Assert::same('processed', $this->processor->process(($this->events['checkoutExpired'])('cs_open_exp', 1_790_000_900), $this->now)->status());
        Assert::same(BillingOrder::STATUS_EXPIRED, $this->orders->findByIdForUpdate($openId)->status());
    }

    public function testUnhandledAndFailedPaymentIntentEventsAreIgnored(): void
    {
        foreach (['payment_intent.payment_failed', 'payment_intent.canceled', 'customer.created', 'charge.succeeded'] as $type) {
            $outcome = $this->processor->process(($this->events['event'])($type, ['id' => 'obj_1'], 100), $this->now);
            Assert::same('ignored', $outcome->status(), $type);
        }

        Assert::same([], $this->stripe->calls);
    }

    private function placeOrder(string $checkoutSessionId, string $method): int
    {
        $order = BillingOrder::place(
            publicId: sprintf('%08d-0000-4000-8000-%012d', crc32($checkoutSessionId) % 100_000_000, crc32($checkoutSessionId)),
            planId: 'pro',
            paymentMethod: $method,
            responsibleName: 'Ana Souza',
            responsibleEmail: 'ana@clinica.test',
            responsiblePhone: '+55 11 99999-0000',
            clinicName: 'Clínica Bicho Feliz',
            termsVersion: '2026-10',
            termsAcceptedAt: new DateTimeImmutable('2026-10-21 09:00:00'),
            termsIp: '203.0.113.10',
            contactConsent: false,
        );
        $order->attachCheckout($checkoutSessionId, 'cus_test_1');

        return $this->orders->insert($order);
    }

    /**
     * Assinatura Pix ativa (checkout pago em 1_790_000_000) com a fatura do
     * período seguinte (`period_start` = `current_period_end`) e PI anexado;
     * `void` como a deixa o cancelamento agendado (T-21).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function pixWithFutureInvoice(string $sessionId, string $paymentIntent, bool $void): array
    {
        $orderId = $this->placeOrder($sessionId, 'pix');
        $price = $this->orders->findByIdForUpdate($orderId)->priceCents();
        $this->processor->process(($this->events['checkoutAsyncSucceeded'])($sessionId, $paymentIntent . '_first', $price, 1_790_000_000), $this->now);
        $subscription = $this->subscriptions->findByBillingOrderId($orderId);
        $periodEnd = $subscription->currentPeriodEnd();

        $next = SubscriptionInvoice::issuePix($subscription->id(), $price, $periodEnd, $periodEnd->modify('+1 month'), $periodEnd);
        $next->attachPaymentIntent($paymentIntent, null, $periodEnd->modify('+7 days'));
        if ($void) {
            $row = $next->toRow();
            $row['status'] = SubscriptionInvoice::STATUS_VOID;
            $next = SubscriptionInvoice::fromRow($row);
        }

        return [(int) $subscription->id(), $this->invoices->insert($next), $price];
    }

    private function startCardSubscription(string $sessionId, string $subscriptionId, int $created): void
    {
        $this->placeOrder($sessionId, 'card');
        $this->stripe->queue('GET', '/v1/subscriptions/' . $subscriptionId, ($this->events['stripeSubscription'])($subscriptionId, 'active', 1_790_000_000, 1_792_678_400));
        $this->processor->process(($this->events['checkoutCompleted'])($sessionId, $subscriptionId, $created), $this->now);
        $this->invoices->lockCalls = [];
    }

    private function date(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    private function at(int $timestamp): string
    {
        return $this->date($timestamp)->format(self::DATE);
    }
}
