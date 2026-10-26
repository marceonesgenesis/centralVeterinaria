<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\SubscriptionInvoice;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;

/**
 * Etapa 3, T-07: fatura da assinatura (Stripe no cartão, fatura local com
 * PaymentIntent no Pix), estorno e guarda contra evento antigo.
 */
final class SubscriptionInvoiceTest
{
    public function testFromStripeReadsInvoiceFields(): void
    {
        $invoice = SubscriptionInvoice::fromStripe(5, $this->stripeInvoice('open', 0), 100);

        Assert::null($invoice->id());
        Assert::same(5, $invoice->subscriptionId());
        Assert::same('in_1', $invoice->stripeInvoiceId());
        Assert::null($invoice->localKey());
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $invoice->status());
        Assert::same(9700, $invoice->amountDueCents());
        Assert::same(0, $invoice->amountPaidCents());
        Assert::same(0, $invoice->amountRefundedCents());
        Assert::same('https://invoice.stripe.test/in_1', $invoice->hostedInvoiceUrl());
        Assert::same(1790000000, $invoice->periodStart()?->getTimestamp());
        Assert::same(1792592000, $invoice->periodEnd()?->getTimestamp());
    }

    public function testApplyStripeIgnoresOlderEvent(): void
    {
        $invoice = SubscriptionInvoice::fromStripe(5, $this->stripeInvoice('open', 0), 100);

        Assert::true($invoice->applyStripe($this->stripeInvoice('paid', 9700), 200));
        Assert::same(SubscriptionInvoice::STATUS_PAID, $invoice->status());
        Assert::same(9700, $invoice->amountPaidCents());

        Assert::false($invoice->applyStripe($this->stripeInvoice('open', 0), 150));
        Assert::same(SubscriptionInvoice::STATUS_PAID, $invoice->status());
        Assert::same(9700, $invoice->amountPaidCents());
    }

    public function testRefundMarksRefundedOnlyWhenFullyRefunded(): void
    {
        $invoice = SubscriptionInvoice::fromStripe(5, $this->stripeInvoice('paid', 9700), 100);

        Assert::true($invoice->applyRefund(1000, 200));
        Assert::same(SubscriptionInvoice::STATUS_PAID, $invoice->status());
        Assert::same(1000, $invoice->amountRefundedCents());

        Assert::false($invoice->applyRefund(9700, 150));
        Assert::true($invoice->applyRefund(9700, 300));
        Assert::same(SubscriptionInvoice::STATUS_REFUNDED, $invoice->status());
    }

    public function testIssuePixBuildsLocalKeyAndOpenStatus(): void
    {
        $invoice = $this->pix();

        Assert::same('pix:5:2026-11-01', $invoice->localKey());
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $invoice->status());
        Assert::same('brl', $invoice->toRow()['currency']);
        Assert::same(5700, $invoice->amountDueCents());
        Assert::null($invoice->stripeInvoiceId());
        Assert::null($invoice->stripePaymentIntentId());
        Assert::same(0, $invoice->paymentIntentAttempts());
        Assert::same('2026-11-01 00:00:00', $invoice->dueAt()?->format('Y-m-d H:i:s'));
    }

    public function testNeedsPaymentIntentWithoutOneOrWhenExpired(): void
    {
        $invoice = $this->pix();
        $now = new DateTimeImmutable('2026-10-29 00:00:00');

        Assert::true($invoice->needsPaymentIntent($now), 'no PaymentIntent yet');

        $invoice->attachPaymentIntent('pi_1', 'https://pix.stripe.test/pi_1', new DateTimeImmutable('2026-11-08 00:00:00'));
        Assert::same(1, $invoice->paymentIntentAttempts());
        Assert::same('pi_1', $invoice->stripePaymentIntentId());
        Assert::same('https://pix.stripe.test/pi_1', $invoice->hostedInvoiceUrl());
        Assert::false($invoice->needsPaymentIntent($now));
        Assert::true($invoice->needsPaymentIntent(new DateTimeImmutable('2026-11-08 00:00:00')), 'expired Pix');

        $invoice->attachPaymentIntent('pi_2', null, new DateTimeImmutable('2026-11-18 00:00:00'));
        Assert::same(2, $invoice->paymentIntentAttempts());
    }

    public function testMarkPaidByPaymentIntentOnlyOnce(): void
    {
        $invoice = $this->pix();
        $invoice->attachPaymentIntent('pi_1', null, new DateTimeImmutable('2026-11-08 00:00:00'));

        Assert::true($invoice->markPaidByPaymentIntent(5700, 200));
        Assert::same(SubscriptionInvoice::STATUS_PAID, $invoice->status());
        Assert::same(5700, $invoice->amountPaidCents());
        Assert::false($invoice->markPaidByPaymentIntent(5700, 300));
        Assert::false($invoice->needsPaymentIntent(new DateTimeImmutable('2026-12-01')));
        Assert::false($invoice->isOverdue(new DateTimeImmutable('2026-12-01')));
    }

    public function testIsOverdueWhenOpenAndDue(): void
    {
        $invoice = $this->pix();

        Assert::false($invoice->isOverdue(new DateTimeImmutable('2026-10-31 23:59:59')));
        Assert::true($invoice->isOverdue(new DateTimeImmutable('2026-11-01 00:00:00')));
    }

    public function testRowRoundTrip(): void
    {
        $invoice = $this->pix();
        $invoice->attachPaymentIntent('pi_1', 'https://pix.stripe.test/pi_1', new DateTimeImmutable('2026-11-08 00:00:00'));
        $row = $invoice->withId(9)->toRow();

        Assert::same([
            'id', 'subscription_id', 'stripe_invoice_id', 'local_key', 'stripe_payment_intent_id', 'due_at',
            'pix_expires_at', 'payment_intent_attempts', 'status', 'amount_due_cents', 'amount_paid_cents',
            'amount_refunded_cents', 'currency', 'period_start', 'period_end', 'hosted_invoice_url',
            'last_event_created',
        ], array_keys($row));
        Assert::same(9, $row['id']);

        $copy = SubscriptionInvoice::fromRow($row);
        Assert::same($row, $copy->toRow());
        Assert::same(9, $copy->id());
        Assert::null($invoice->id(), 'withId returns a copy');
    }

    public function testVoidForCancellationOnlyVoidsAnOpenLocalInvoice(): void
    {
        $open = $this->pix();
        Assert::true($open->voidForCancellation());
        Assert::same(SubscriptionInvoice::STATUS_VOID, $open->status());
        Assert::false($open->voidForCancellation(), 'already void');

        $paid = $this->pix();
        $paid->markPaidByPaymentIntent(5700, 100);
        Assert::false($paid->voidForCancellation());
        Assert::same(SubscriptionInvoice::STATUS_PAID, $paid->status());

        $stripe = SubscriptionInvoice::fromStripe(5, $this->stripeInvoice('open', 0), 100);
        Assert::false($stripe->voidForCancellation(), 'Stripe invoice is not local');
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $stripe->status());
    }

    private function pix(): SubscriptionInvoice
    {
        return SubscriptionInvoice::issuePix(
            5,
            5700,
            new DateTimeImmutable('2026-11-01 00:00:00'),
            new DateTimeImmutable('2026-12-01 00:00:00'),
            new DateTimeImmutable('2026-11-01 00:00:00'),
        );
    }

    /** @return array<string, mixed> */
    private function stripeInvoice(string $status, int $amountPaid): array
    {
        return [
            'id' => 'in_1',
            'object' => 'invoice',
            'status' => $status,
            'amount_due' => 9700,
            'amount_paid' => $amountPaid,
            'currency' => 'brl',
            'hosted_invoice_url' => 'https://invoice.stripe.test/in_1',
            'lines' => ['data' => [['period' => ['start' => 1790000000, 'end' => 1792592000]]]],
        ];
    }
}
