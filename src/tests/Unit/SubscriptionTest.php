<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\Subscription;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Etapa 3, T-07: ciclo da assinatura (status do Stripe, tolerância de 7 dias,
 * vencimento local do Pix e guarda contra evento antigo).
 */
final class SubscriptionTest
{
    public function testStartMapsProviderStatusAndKeepsEventCreated(): void
    {
        $subscription = $this->card();

        Assert::null($subscription->id());
        Assert::same(10, $subscription->billingOrderId());
        Assert::null($subscription->tenantId());
        Assert::same('pro', $subscription->planId());
        Assert::same(Subscription::STATUS_ACTIVE, $subscription->status());
        Assert::same('card', $subscription->paymentMethod());
        Assert::same('cus_1', $subscription->stripeCustomerId());
        Assert::same('sub_1', $subscription->stripeSubscriptionId());
        Assert::same(100, $subscription->lastEventCreated());
        Assert::same('full', $subscription->accessLevel(new DateTimeImmutable('2026-10-05 00:00:00')));
    }

    public function testPixStartsWithoutStripeSubscription(): void
    {
        $subscription = Subscription::start(
            11, 'starter', 'pix', 'cus_2', null, 'active',
            new DateTimeImmutable('2026-10-01 00:00:00'), new DateTimeImmutable('2026-11-01 00:00:00'), 50,
        );

        Assert::null($subscription->stripeSubscriptionId());
        Assert::same(Subscription::STATUS_ACTIVE, $subscription->status());
        Assert::throws(InvalidArgumentException::class, static fn () => Subscription::start(
            11, 'starter', 'pix', 'cus_2', 'sub_x', 'active',
            new DateTimeImmutable('2026-10-01 00:00:00'), new DateTimeImmutable('2026-11-01 00:00:00'), 50,
        ));
        Assert::throws(InvalidArgumentException::class, static fn () => Subscription::start(
            11, 'gold', 'card', 'cus_2', 'sub_x', 'active',
            new DateTimeImmutable('2026-10-01 00:00:00'), new DateTimeImmutable('2026-11-01 00:00:00'), 50,
        ));
    }

    public function testOlderEventIsIgnoredAfterPaymentFailure(): void
    {
        $subscription = $this->card();
        $now = new DateTimeImmutable('2026-10-10 12:00:00');

        Assert::true($subscription->markPaymentFailed(200, $now));
        Assert::same(Subscription::STATUS_PAST_DUE, $subscription->status());

        $applied = $subscription->applyProviderStatus('active', null, null, 100, $now);

        Assert::false($applied, 'older event must be ignored');
        Assert::same(Subscription::STATUS_PAST_DUE, $subscription->status());
        Assert::same(200, $subscription->lastEventCreated());
        Assert::false($subscription->markInvoicePaid(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-12-01'), 150));
        Assert::same(Subscription::STATUS_PAST_DUE, $subscription->status());
    }

    public function testPastDueGivesGraceForSevenDaysThenBlocks(): void
    {
        $subscription = $this->card();
        $now = new DateTimeImmutable('2026-10-10 12:00:00');

        $subscription->markPaymentFailed(200, $now);

        Assert::same('2026-10-17 12:00:00', $subscription->graceUntil()?->format('Y-m-d H:i:s'));
        Assert::same('grace', $subscription->accessLevel($now));
        Assert::same('grace', $subscription->accessLevel(new DateTimeImmutable('2026-10-17 11:59:59')));
        Assert::same('blocked', $subscription->accessLevel(new DateTimeImmutable('2026-10-17 12:00:00')));

        // Segunda falha não estende a tolerância.
        $subscription->markPaymentFailed(300, new DateTimeImmutable('2026-10-12 12:00:00'));
        Assert::same('2026-10-17 12:00:00', $subscription->graceUntil()?->format('Y-m-d H:i:s'));

        Assert::false($subscription->suspendIfGraceExpired(new DateTimeImmutable('2026-10-16 00:00:00')));
        Assert::true($subscription->suspendIfGraceExpired(new DateTimeImmutable('2026-10-17 12:00:00')));
        Assert::same(Subscription::STATUS_SUSPENDED, $subscription->status());
        Assert::same('blocked', $subscription->accessLevel(new DateTimeImmutable('2026-10-17 12:00:00')));
    }

    public function testProviderStatusMapping(): void
    {
        $now = new DateTimeImmutable('2026-10-10 12:00:00');
        $cases = [
            'trialing' => Subscription::STATUS_ACTIVE,
            'active' => Subscription::STATUS_ACTIVE,
            'past_due' => Subscription::STATUS_PAST_DUE,
            'unpaid' => Subscription::STATUS_SUSPENDED,
            'paused' => Subscription::STATUS_SUSPENDED,
            'canceled' => Subscription::STATUS_CANCELLED,
            'incomplete_expired' => Subscription::STATUS_CANCELLED,
            'incomplete' => Subscription::STATUS_INCOMPLETE,
        ];

        foreach ($cases as $stripeStatus => $expected) {
            $subscription = $this->card();
            Assert::true($subscription->applyProviderStatus($stripeStatus, null, null, 300, $now));
            Assert::same($expected, $subscription->status(), 'stripe status ' . $stripeStatus);
        }

        $pastDue = $this->card();
        $pastDue->applyProviderStatus('past_due', null, null, 300, $now);
        Assert::same('2026-10-17 12:00:00', $pastDue->graceUntil()?->format('Y-m-d H:i:s'));

        $periods = $this->card();
        $periods->applyProviderStatus('active', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-12-01'), 300, $now);
        Assert::same('2026-12-01', $periods->currentPeriodEnd()?->format('Y-m-d'));
        Assert::same(300, $periods->lastEventCreated());
    }

    public function testMarkOverdueMovesActivePixToPastDueWithoutTouchingLastEvent(): void
    {
        $subscription = Subscription::start(
            11, 'starter', 'pix', 'cus_2', null, 'active',
            new DateTimeImmutable('2026-10-01 00:00:00'), new DateTimeImmutable('2026-11-01 00:00:00'), 50,
        );
        $now = new DateTimeImmutable('2026-11-01 00:00:00');

        Assert::true($subscription->markOverdue($now));
        Assert::same(Subscription::STATUS_PAST_DUE, $subscription->status());
        Assert::same('2026-11-08 00:00:00', $subscription->graceUntil()?->format('Y-m-d H:i:s'));
        Assert::same(50, $subscription->lastEventCreated());
        Assert::false($subscription->markOverdue($now), 'only active subscriptions become overdue');
    }

    public function testMarkInvoicePaidReactivatesSuspendedSubscription(): void
    {
        $subscription = $this->card();
        $subscription->markPaymentFailed(200, new DateTimeImmutable('2026-10-10 12:00:00'));
        $subscription->suspendIfGraceExpired(new DateTimeImmutable('2026-10-20 00:00:00'));
        Assert::same(Subscription::STATUS_SUSPENDED, $subscription->status());

        Assert::true($subscription->markInvoicePaid(new DateTimeImmutable('2026-11-01 00:00:00'), new DateTimeImmutable('2026-12-01 00:00:00'), 400));

        Assert::same(Subscription::STATUS_ACTIVE, $subscription->status());
        Assert::null($subscription->graceUntil());
        Assert::same('2026-11-01', $subscription->currentPeriodStart()?->format('Y-m-d'));
        Assert::same('2026-12-01', $subscription->currentPeriodEnd()?->format('Y-m-d'));
        Assert::same(400, $subscription->lastEventCreated());
        Assert::same('full', $subscription->accessLevel(new DateTimeImmutable('2026-11-02')));
    }

    public function testAssignTenantAndRowRoundTrip(): void
    {
        $subscription = $this->card();
        $subscription->assignTenant(7);
        $subscription->markPaymentFailed(200, new DateTimeImmutable('2026-10-10 12:00:00'));

        $row = $subscription->withId(3)->toRow();

        Assert::same([
            'id', 'billing_order_id', 'tenant_id', 'plan_id', 'status', 'payment_method',
            'stripe_customer_id', 'stripe_subscription_id', 'current_period_start', 'current_period_end',
            'grace_until', 'cancelled_at', 'last_event_created',
        ], array_keys($row));
        Assert::same(3, $row['id']);
        Assert::same(7, $row['tenant_id']);
        Assert::same('past_due', $row['status']);

        $copy = Subscription::fromRow($row);
        Assert::same($row, $copy->toRow());
        Assert::same(7, $copy->tenantId());
        Assert::same('2026-10-17 12:00:00', $copy->graceUntil()?->format('Y-m-d H:i:s'));
        Assert::null($subscription->id(), 'withId returns a copy');
        Assert::throws(InvalidArgumentException::class, static fn () => $copy->assignTenant(8));
    }

    private function card(): Subscription
    {
        return Subscription::start(
            10, 'pro', 'card', 'cus_1', 'sub_1', 'active',
            new DateTimeImmutable('2026-10-01 00:00:00'), new DateTimeImmutable('2026-11-01 00:00:00'), 100,
        );
    }
}
