<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\BillingOrder;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Etapa 3, T-07: pedido de compra da landing (preço do servidor, checkout,
 * pagamento, expiração e provisionamento).
 */
final class BillingOrderTest
{
    public function testPlaceTakesPriceFromPlanEntitlements(): void
    {
        $order = $this->order('business');

        Assert::null($order->id());
        Assert::same('7f1c2b9e-4a3d-4c8e-9b1a-2d3e4f5a6b7c', $order->publicId());
        Assert::same('business', $order->planId());
        Assert::same(13700, $order->priceCents());
        Assert::same('card', $order->paymentMethod());
        Assert::same('Ana Souza', $order->responsibleName());
        Assert::same('ana@clinica.test', $order->responsibleEmail());
        Assert::same('Clínica Bicho Feliz', $order->clinicName());
        Assert::same(BillingOrder::STATUS_PENDING, $order->status());
        Assert::same(0, $order->provisioningAttempts());
        Assert::null($order->paidAt());
        Assert::throws(InvalidArgumentException::class, fn () => $this->order('gold'));
        Assert::throws(InvalidArgumentException::class, fn () => $this->order('pro', 'boleto'));
    }

    public function testCheckoutAndMarkPaidOnlyOnce(): void
    {
        $order = $this->order();
        $order->attachCheckout('cs_test_1', 'cus_1');

        Assert::same(BillingOrder::STATUS_CHECKOUT_OPEN, $order->status());
        Assert::same('cs_test_1', $order->stripeCheckoutSessionId());
        Assert::same('cus_1', $order->stripeCustomerId());

        $paidAt = new DateTimeImmutable('2026-10-21 10:00:00');
        Assert::true($order->markPaid('sub_1', $paidAt));
        Assert::false($order->markPaid('sub_2', new DateTimeImmutable('2026-10-22 10:00:00')));
        Assert::same(BillingOrder::STATUS_PAID, $order->status());
        Assert::same('sub_1', $order->stripeSubscriptionId());
        Assert::same('2026-10-21 10:00:00', $order->paidAt()?->format('Y-m-d H:i:s'));
    }

    public function testExpireRefusesPaidOrder(): void
    {
        $paid = $this->order();
        $paid->markPaid(null, new DateTimeImmutable('2026-10-21 10:00:00'));
        Assert::false($paid->expire());
        Assert::same(BillingOrder::STATUS_PAID, $paid->status());

        $open = $this->order();
        Assert::true($open->expire());
        Assert::same(BillingOrder::STATUS_EXPIRED, $open->status());
        Assert::false($open->expire());
    }

    public function testProvisioningFailureThenSuccess(): void
    {
        $order = $this->order();
        Assert::throws(InvalidStatusTransitionException::class, static fn () => $order->markProvisioned(4));

        $order->markPaid(null, new DateTimeImmutable('2026-10-21 10:00:00'));
        $order->markProvisioningFailed('tenant_insert_failed');
        $order->markProvisioningFailed('tenant_insert_failed');

        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $order->status());
        Assert::same(2, $order->provisioningAttempts());
        Assert::false($order->markPaid(null, new DateTimeImmutable('2026-10-22 10:00:00')));

        $order->markProvisioned(4);
        Assert::same(BillingOrder::STATUS_PROVISIONED, $order->status());
        Assert::same(4, $order->tenantId());
        Assert::false($order->markPaid(null, new DateTimeImmutable('2026-10-22 10:00:00')));
        Assert::false($order->expire());

        $order->markInviteSent(new DateTimeImmutable('2026-10-21 10:05:00'));
        Assert::same('2026-10-21 10:05:00', $order->inviteSentAt()?->format('Y-m-d H:i:s'));
    }

    public function testRowRoundTrip(): void
    {
        $order = $this->order('pro', 'pix');
        $order->attachCheckout('cs_test_2', 'cus_2');
        $row = $order->withId(12)->toRow();

        Assert::same([
            'id', 'public_id', 'plan_id', 'price_cents', 'currency', 'payment_method', 'responsible_name',
            'responsible_email', 'responsible_phone', 'clinic_name', 'terms_version', 'terms_accepted_at',
            'terms_ip', 'contact_consent', 'status', 'stripe_customer_id', 'stripe_checkout_session_id',
            'stripe_subscription_id', 'paid_at', 'tenant_id', 'provisioning_attempts', 'provisioning_error',
            'invite_sent_at', 'subscription_cancelled_at', 'stripe_refund_id', 'refunded_at',
        ], array_keys($row));
        Assert::same(12, $row['id']);
        Assert::same(9700, $row['price_cents']);
        Assert::same(1, $row['contact_consent']);

        $copy = BillingOrder::fromRow($row);
        Assert::same($row, $copy->toRow());
        Assert::same(12, $copy->id());
        Assert::null($order->id(), 'withId returns a copy');
    }

    public function testLoginConflictCardOrderIsRefundedOnlyThroughTheNewSteps(): void
    {
        $order = $this->loginConflict('card');

        Assert::true($order->isLoginConflict());
        Assert::null($order->subscriptionCancelledAt());
        Assert::null($order->stripeRefundId());
        Assert::null($order->refundedAt());

        Assert::throws(InvalidStatusTransitionException::class, fn () => $order->markRefunded(new DateTimeImmutable('2026-10-26 10:00:00')));

        $order->markSubscriptionCancelled(new DateTimeImmutable('2026-10-26 09:00:00.000001'));
        $order->markSubscriptionCancelled(new DateTimeImmutable('2026-10-26 09:30:00'));
        Assert::same('2026-10-26 09:00:00.000001', $order->subscriptionCancelledAt()?->format('Y-m-d H:i:s.u'), 'second call keeps the first date');

        Assert::throws(InvalidStatusTransitionException::class, fn () => $order->markRefunded(new DateTimeImmutable('2026-10-26 10:00:00')), 'refund id is required');
        Assert::throws(InvalidArgumentException::class, fn () => $order->recordRefund(''));
        $order->recordRefund('re_test_1');
        Assert::same('re_test_1', $order->stripeRefundId());

        $order->markRefunded(new DateTimeImmutable('2026-10-26 10:00:00'));
        Assert::same(BillingOrder::STATUS_REFUNDED, $order->status());
        Assert::same('2026-10-26 10:00:00', $order->refundedAt()?->format('Y-m-d H:i:s'));
        Assert::same('login_conflict', $order->provisioningError(), 'provisioning_error is kept');
        Assert::false($order->isLoginConflict());

        Assert::false($order->markPaid('sub_x', new DateTimeImmutable('2026-10-27 10:00:00')));
        Assert::false($order->expire());
        Assert::same(BillingOrder::STATUS_REFUNDED, $order->status());
        Assert::throws(InvalidStatusTransitionException::class, fn () => $order->recordRefund('re_test_2'));
        Assert::throws(InvalidStatusTransitionException::class, fn () => $order->markRefunded(new DateTimeImmutable()));
    }

    public function testCardRefundNeedsTheSubscriptionCancelledFirst(): void
    {
        $order = $this->loginConflict('card');
        $order->recordRefund('re_test_1');

        Assert::throws(InvalidStatusTransitionException::class, fn () => $order->markRefunded(new DateTimeImmutable()));
        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $order->status());
    }

    public function testPixRefundSkipsTheSubscriptionCancellation(): void
    {
        $order = $this->loginConflict('pix');

        Assert::throws(InvalidStatusTransitionException::class, fn () => $order->markSubscriptionCancelled(new DateTimeImmutable()));

        $order->recordRefund('re_test_pix');
        $order->markRefunded(new DateTimeImmutable('2026-10-26 10:00:00'));
        Assert::same(BillingOrder::STATUS_REFUNDED, $order->status());
        Assert::null($order->subscriptionCancelledAt());
    }

    public function testOtherOrdersRefuseTheRefundSteps(): void
    {
        $group = $this->order();
        $group->markPaid('sub_1', new DateTimeImmutable('2026-10-21 10:00:00'));
        $group->markProvisioningFailed('group_missing');

        Assert::false($group->isLoginConflict());
        Assert::throws(InvalidStatusTransitionException::class, fn () => $group->recordRefund('re_test_1'));
        Assert::throws(InvalidStatusTransitionException::class, fn () => $group->markSubscriptionCancelled(new DateTimeImmutable()));
        Assert::throws(InvalidStatusTransitionException::class, fn () => $group->markRefunded(new DateTimeImmutable()));

        $paid = $this->order();
        $paid->markPaid('sub_1', new DateTimeImmutable('2026-10-21 10:00:00'));
        Assert::false($paid->isLoginConflict());
        Assert::throws(InvalidStatusTransitionException::class, fn () => $paid->recordRefund('re_test_1'));
    }

    public function testRowRoundTripKeepsTheRefundColumns(): void
    {
        $order = $this->loginConflict('card');
        $order->markSubscriptionCancelled(new DateTimeImmutable('2026-10-26 09:00:00.123456'));
        $order->recordRefund('re_test_1');
        $order->markRefunded(new DateTimeImmutable('2026-10-26 10:00:00.654321'));
        $row = $order->withId(7)->toRow();

        Assert::same('2026-10-26 09:00:00.123456', $row['subscription_cancelled_at']);
        Assert::same('re_test_1', $row['stripe_refund_id']);
        Assert::same('2026-10-26 10:00:00.654321', $row['refunded_at']);
        Assert::same('refunded', $row['status']);
        Assert::same($row, BillingOrder::fromRow($row)->toRow());

        $fresh = $this->order()->toRow();
        Assert::null($fresh['subscription_cancelled_at']);
        Assert::null($fresh['stripe_refund_id']);
        Assert::null($fresh['refunded_at']);
    }

    private function loginConflict(string $method): BillingOrder
    {
        $order = $this->order('pro', $method);
        $order->attachCheckout('cs_test_lc', 'cus_lc');
        $order->markPaid($method === 'card' ? 'sub_lc' : null, new DateTimeImmutable('2026-10-21 10:00:00'));
        $order->markProvisioningFailed('login_conflict');

        return $order;
    }

    private function order(string $planId = 'pro', string $method = 'card'): BillingOrder
    {
        return BillingOrder::place(
            '7f1c2b9e-4a3d-4c8e-9b1a-2d3e4f5a6b7c',
            $planId,
            $method,
            'Ana Souza',
            'ana@clinica.test',
            '+55 11 99999-0000',
            'Clínica Bicho Feliz',
            'termos-2026-10',
            new DateTimeImmutable('2026-10-21 09:00:00'),
            '203.0.113.7',
            true,
        );
    }
}
