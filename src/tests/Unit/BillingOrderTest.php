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
            'invite_sent_at',
        ], array_keys($row));
        Assert::same(12, $row['id']);
        Assert::same(9700, $row['price_cents']);
        Assert::same(1, $row['contact_consent']);

        $copy = BillingOrder::fromRow($row);
        Assert::same($row, $copy->toRow());
        Assert::same(12, $copy->id());
        Assert::null($order->id(), 'withId returns a copy');
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
