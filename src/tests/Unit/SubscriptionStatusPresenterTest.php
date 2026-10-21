<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionInvoice;
use CentralVet\Presentation\SubscriptionStatusPresenter;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;

/**
 * T-21 (Etapa 3): resumo da assinatura e linhas de fatura da
 * SubscriptionStatusView. O presenter não traduz: devolve as chaves em
 * inglês da T-01 e a view passa por _t(). O link de pagamento só aparece em
 * fatura `open` com URL e Pix ainda válido.
 */
final class SubscriptionStatusPresenterTest
{
    private const NOW = '2026-10-21 12:00:00';

    public function testActiveSubscriptionSummary(): void
    {
        $summary = SubscriptionStatusPresenter::summary($this->subscription('active', null), $this->now());

        Assert::same('Pro', $summary['plan']);
        Assert::same('Active', $summary['status_key']);
        Assert::same('01/10/2026 – 01/11/2026', $summary['period']);
        Assert::null($summary['grace_until']);
        Assert::null($summary['message_key']);
    }

    public function testPastDueInGraceIsOverdueWithFormattedGraceUntil(): void
    {
        $summary = SubscriptionStatusPresenter::summary($this->subscription('past_due', '2026-10-25 08:00:00'), $this->now());

        Assert::same('Overdue', $summary['status_key']);
        Assert::same('25/10/2026', $summary['grace_until']);
        Assert::null($summary['message_key']);
    }

    public function testPastDueWithExpiredGraceIsSuspendedWithRegularizeMessage(): void
    {
        $summary = SubscriptionStatusPresenter::summary($this->subscription('past_due', '2026-10-20 08:00:00'), $this->now());

        Assert::same('Suspended', $summary['status_key']);
        Assert::same('Your subscription is suspended. Regularize the payment to continue', $summary['message_key']);
    }

    public function testSuspendedCancelledAndIncompleteKeys(): void
    {
        $now = $this->now();

        $suspended = SubscriptionStatusPresenter::summary($this->subscription('suspended', '2026-10-10 08:00:00'), $now);
        Assert::same('Suspended', $suspended['status_key']);
        Assert::same('Your subscription is suspended. Regularize the payment to continue', $suspended['message_key']);

        Assert::same('Cancelled', SubscriptionStatusPresenter::summary($this->subscription('cancelled', null), $now)['status_key']);
        Assert::same('Incomplete', SubscriptionStatusPresenter::summary($this->subscription('incomplete', null), $now)['status_key']);
    }

    public function testNoSubscriptionGivesT01MessageKey(): void
    {
        $summary = SubscriptionStatusPresenter::summary(null, $this->now());

        Assert::same('No subscription registered for this clinic', $summary['message_key']);
        Assert::null($summary['plan']);
        Assert::null($summary['status_key']);
        Assert::null($summary['period']);
        Assert::null($summary['grace_until']);
    }

    public function testOpenInvoiceWithUrlHasPayUrlAndPaidDoesNot(): void
    {
        $rows = SubscriptionStatusPresenter::invoiceRows([
            $this->invoice('open', 'https://invoice.stripe.com/i/acct_x/test_open', null),
            $this->invoice('paid', 'https://invoice.stripe.com/i/acct_x/test_paid', null),
        ], $this->now());

        Assert::count(2, $rows);
        Assert::same('https://invoice.stripe.com/i/acct_x/test_open', $rows[0]['pay_url']);
        Assert::same('01/10/2026 – 01/11/2026', $rows[0]['period']);
        Assert::same('R$ 199,90', $rows[0]['amount_due']);
        Assert::same('Open (pending status)', $rows[0]['status_key']);
        Assert::null($rows[1]['pay_url']);
        Assert::same('Paid', $rows[1]['status_key']);
        Assert::same('R$ 199,90', $rows[1]['amount_paid']);
    }

    public function testPixInvoiceWithExpiredPixHasNoPayUrl(): void
    {
        $now = $this->now();
        $rows = SubscriptionStatusPresenter::invoiceRows([
            $this->invoice('open', 'https://payments.stripe.com/pix/expired', '2026-10-20 12:00:00'),
            $this->invoice('open', 'https://payments.stripe.com/pix/valid', '2026-10-28 12:00:00'),
        ], $now);

        Assert::null($rows[0]['pay_url']);
        Assert::same('https://payments.stripe.com/pix/valid', $rows[1]['pay_url']);
    }

    public function testOpenInvoiceWithoutUrlOrWithNonHttpsUrlHasNoPayUrl(): void
    {
        $rows = SubscriptionStatusPresenter::invoiceRows([
            $this->invoice('open', null, null),
            $this->invoice('open', 'javascript:alert(1)', null),
        ], $this->now());

        Assert::null($rows[0]['pay_url']);
        Assert::null($rows[1]['pay_url']);
    }

    public function testSeedsContainSubscriptionStatusView(): void
    {
        $seeds = dirname(__DIR__, 2) . '/app/database/seeds/';

        $initial = (string) file_get_contents($seeds . 'initial-application-programs.sql');
        Assert::stringContains("'SubscriptionStatusView'", $initial);
        Assert::true(
            preg_match("/system_group_id\\), 1, p\\.id\\s+FROM system_program p WHERE p\\.controller='SubscriptionStatusView'/", $initial) === 1,
            'SubscriptionStatusView linked to group 1',
        );

        $plans = (string) file_get_contents($seeds . 'plan-groups.sql');
        foreach (['Starter', 'Pro', 'Business', 'Enterprise'] as $plan) {
            Assert::true(
                preg_match("/g\\.name='Central Vet - Plano {$plan}'\\s+WHERE p\\.controller='SubscriptionStatusView'/", $plans) === 1,
                'SubscriptionStatusView linked to plan ' . $plan,
            );
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private function subscription(string $status, ?string $graceUntil): Subscription
    {
        return Subscription::fromRow([
            'id' => 7,
            'billing_order_id' => 3,
            'tenant_id' => 11,
            'plan_id' => 'pro',
            'status' => $status,
            'payment_method' => 'card',
            'stripe_customer_id' => 'cus_test_t21',
            'stripe_subscription_id' => 'sub_test_t21',
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-11-01 00:00:00',
            'grace_until' => $graceUntil,
            'cancelled_at' => null,
            'last_event_created' => 100,
        ]);
    }

    private function invoice(string $status, ?string $url, ?string $pixExpiresAt): SubscriptionInvoice
    {
        return SubscriptionInvoice::fromRow([
            'id' => 21,
            'subscription_id' => 7,
            'stripe_invoice_id' => $pixExpiresAt === null ? 'in_test_t21' : null,
            'local_key' => $pixExpiresAt === null ? null : 'pix:7:2026-10-01',
            'stripe_payment_intent_id' => $pixExpiresAt === null ? null : 'pi_test_t21',
            'due_at' => '2026-10-25 00:00:00',
            'pix_expires_at' => $pixExpiresAt,
            'payment_intent_attempts' => $pixExpiresAt === null ? 0 : 1,
            'status' => $status,
            'amount_due_cents' => 19990,
            'amount_paid_cents' => $status === 'paid' ? 19990 : 0,
            'amount_refunded_cents' => 0,
            'currency' => 'brl',
            'period_start' => '2026-10-01 00:00:00',
            'period_end' => '2026-11-01 00:00:00',
            'hosted_invoice_url' => $url,
            'last_event_created' => 100,
        ]);
    }
}
