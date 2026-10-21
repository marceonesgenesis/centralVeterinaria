<?php
declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\PixInvoiceIssuer;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionInvoice;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\FakeSubscriptionInvoiceRepository;
use DateTimeImmutable;

final class PixInvoiceIssuerTest
{
    private const SUBSCRIPTION_ID = 42;
    private const PAY_URL = 'https://payments.stripe.test/pix/instructions/abc';
    private const NOW = '2026-11-01 10:00:00';

    public function testIssuesInvoiceWithPixPaymentIntentAndEmailThreeDaysBeforePeriodEnd(): void
    {
        [$issuer, $stripe, $invoices, $email] = $this->issuer();
        $now = new DateTimeImmutable(self::NOW);
        $periodEnd = $now->modify('+2 days');
        $stripe->queue('POST', '/v1/payment_intents', $this->paymentIntent('pi_1', $now->modify('+10 days')));

        $invoice = $issuer->issueNext($this->subscription(Subscription::METHOD_PIX, $periodEnd), 9700, 'ana@clinica.test', $now);

        Assert::count(0, $email->deliveries(), 'the e-mail waits for deliverPending() after the commit');
        Assert::same(1, $issuer->deliverPending());
        Assert::same(3, PixInvoiceIssuer::ISSUE_DAYS_BEFORE);
        Assert::same(864000, PixInvoiceIssuer::PIX_EXPIRES_AFTER_SECONDS);
        Assert::notNull($invoice);
        $localKey = 'pix:' . self::SUBSCRIPTION_ID . ':' . $periodEnd->format('Y-m-d');
        Assert::same($localKey, $invoice->localKey());
        Assert::count(1, $invoices->rows);
        $stored = $invoices->findByLocalKey($localKey);
        Assert::notNull($stored);
        Assert::same(self::PAY_URL, $stored->hostedInvoiceUrl());
        Assert::same('pi_1', $stored->stripePaymentIntentId());
        Assert::same(1, $stored->paymentIntentAttempts());
        Assert::same($periodEnd->format('Y-m-d'), $stored->periodStart()?->format('Y-m-d'));
        Assert::same($periodEnd->modify('+1 month')->format('Y-m-d'), $stored->periodEnd()?->format('Y-m-d'));
        Assert::same($periodEnd->format('Y-m-d'), $stored->dueAt()?->format('Y-m-d'));

        Assert::count(1, $stripe->calls);
        $call = $stripe->calls[0];
        Assert::same('POST', $call['method']);
        Assert::same('/v1/payment_intents', $call['path']);
        Assert::same('pix-invoice-' . $localKey . '-1', $call['idempotencyKey']);
        $params = $call['params'];
        Assert::same(9700, $params['amount']);
        Assert::same('brl', $params['currency']);
        Assert::same('cus_123', $params['customer']);
        Assert::same('pix', $params['payment_method_types'][0]);
        Assert::same('pix', $params['payment_method_data']['type']);
        Assert::same(true, $params['confirm']);
        Assert::same(864000, $params['payment_method_options']['pix']['expires_after_seconds']);
        Assert::same((string) $stored->id(), (string) $params['metadata']['subscription_invoice']);

        Assert::count(1, $email->deliveries());
        $message = $email->deliveries()[0];
        Assert::same('ana@clinica.test', $message->recipient);
        Assert::same('[Central Vet Pro invoice]', $message->subject);
        Assert::same('pix-invoice', $message->reference);
        Assert::same(
            '[Your Central Vet Pro invoice of ^1 is available. Pay with Pix by ^2: ^3|R$ 97,00|' . $periodEnd->format('d/m/Y') . '|' . self::PAY_URL . ']',
            $message->body,
        );
    }

    public function testPeriodEndingInFiveDaysIssuesNothing(): void
    {
        [$issuer, $stripe, $invoices, $email] = $this->issuer();
        $now = new DateTimeImmutable(self::NOW);

        $result = $issuer->issueNext($this->subscription(Subscription::METHOD_PIX, $now->modify('+5 days')), 9700, 'ana@clinica.test', $now);

        Assert::null($result);
        Assert::count(0, $stripe->calls);
        Assert::count(0, $invoices->rows);
        Assert::count(0, $email->deliveries());
    }

    public function testSecondIssueForSamePeriodReturnsNullWithoutCalls(): void
    {
        [$issuer, $stripe, $invoices, $email] = $this->issuer();
        $now = new DateTimeImmutable(self::NOW);
        $subscription = $this->subscription(Subscription::METHOD_PIX, $now->modify('+2 days'));
        $stripe->queue('POST', '/v1/payment_intents', $this->paymentIntent('pi_1', $now->modify('+10 days')));
        Assert::notNull($issuer->issueNext($subscription, 9700, 'ana@clinica.test', $now));
        $issuer->deliverPending();

        $second = $issuer->issueNext($subscription, 9700, 'ana@clinica.test', $now->modify('+1 hour'));

        Assert::null($second);
        Assert::count(1, $stripe->calls);
        Assert::count(1, $invoices->rows);
        Assert::count(1, $email->deliveries());
    }

    public function testEnsurePaymentIntentWithExpiredPixUsesNextAttemptKey(): void
    {
        [$issuer, $stripe, $invoices, $email] = $this->issuer();
        $now = new DateTimeImmutable(self::NOW);
        $invoice = $this->persistedInvoiceWithExpiredPix($invoices, $now);
        $stripe->queue('POST', '/v1/payment_intents', $this->paymentIntent('pi_2', $now->modify('+10 days')));

        Assert::true($issuer->ensurePaymentIntent($invoice, 'cus_123', 'ana@clinica.test', $now));
        $issuer->deliverPending();

        Assert::count(1, $stripe->calls);
        Assert::same('pix-invoice-' . $invoice->localKey() . '-2', $stripe->calls[0]['idempotencyKey']);
        $stored = $invoices->findByLocalKey((string) $invoice->localKey());
        Assert::same('pi_2', $stored?->stripePaymentIntentId());
        Assert::same(2, $stored?->paymentIntentAttempts());
        Assert::count(1, $email->deliveries());
    }

    public function testEnsurePaymentIntentWithValidPixDoesNothing(): void
    {
        [$issuer, $stripe, $invoices, $email] = $this->issuer();
        $now = new DateTimeImmutable(self::NOW);
        // Pix expira em $now - 1 minuto: dois minutos antes ainda vale.
        $invoice = $this->persistedInvoiceWithExpiredPix($invoices, $now);

        Assert::false($issuer->ensurePaymentIntent($invoice, 'cus_123', 'ana@clinica.test', $now->modify('-2 minutes')));
        Assert::count(0, $stripe->calls);
        Assert::count(0, $email->deliveries());
    }

    public function testStripeFailurePropagatesWithoutSavingOrEmail(): void
    {
        [$issuer, $stripe, $invoices, $email] = $this->issuer();
        $now = new DateTimeImmutable(self::NOW);
        $invoice = $this->persistedInvoiceWithExpiredPix($invoices, $now);
        $before = $invoices->rows;
        $stripe->fail('POST', '/v1/payment_intents', new StripeApiException('boom', 500));

        Assert::throws(StripeApiException::class, fn () => $issuer->ensurePaymentIntent($invoice, 'cus_123', 'ana@clinica.test', $now));
        $issuer->deliverPending();

        Assert::same($before, $invoices->rows);
        Assert::count(0, $email->deliveries());
    }

    public function testCardSubscriptionIssuesNothing(): void
    {
        [$issuer, $stripe, $invoices, $email] = $this->issuer();
        $now = new DateTimeImmutable(self::NOW);

        $result = $issuer->issueNext($this->subscription(Subscription::METHOD_CARD, $now->modify('+2 days')), 9700, 'ana@clinica.test', $now);

        Assert::null($result);
        Assert::count(0, $stripe->calls);
        Assert::count(0, $invoices->rows);
        Assert::count(0, $email->deliveries());
    }

    public function testSuspendedPixSubscriptionIssuesNothing(): void
    {
        [$issuer, $stripe, $invoices] = $this->issuer();
        $now = new DateTimeImmutable(self::NOW);

        $result = $issuer->issueNext(
            $this->subscription(Subscription::METHOD_PIX, $now->modify('+2 days'), Subscription::STATUS_SUSPENDED),
            9700,
            'ana@clinica.test',
            $now,
        );

        Assert::null($result);
        Assert::count(0, $stripe->calls);
        Assert::count(0, $invoices->rows);
    }

    /** @return array{PixInvoiceIssuer, FakeStripeClient, FakeSubscriptionInvoiceRepository, FakeEmailProvider} */
    private function issuer(): array
    {
        $stripe = new FakeStripeClient();
        $invoices = new FakeSubscriptionInvoiceRepository();
        $email = new FakeEmailProvider();
        $translate = static fn (string $key, string ...$params): string => '[' . implode('|', [$key, ...$params]) . ']';

        return [new PixInvoiceIssuer($stripe, $invoices, $email, \Closure::fromCallable($translate)), $stripe, $invoices, $email];
    }

    private function subscription(string $method, DateTimeImmutable $periodEnd, string $status = Subscription::STATUS_ACTIVE): Subscription
    {
        return Subscription::fromRow([
            'id' => self::SUBSCRIPTION_ID,
            'billing_order_id' => 7,
            'tenant_id' => 3,
            'plan_id' => 'pro',
            'status' => $status,
            'payment_method' => $method,
            'stripe_customer_id' => 'cus_123',
            'stripe_subscription_id' => $method === Subscription::METHOD_CARD ? 'sub_123' : null,
            'current_period_start' => $periodEnd->modify('-1 month')->format('Y-m-d H:i:s'),
            'current_period_end' => $periodEnd->format('Y-m-d H:i:s'),
            'grace_until' => null,
            'cancelled_at' => null,
            'last_event_created' => 0,
        ]);
    }

    /** Fatura gravada com o 1º PaymentIntent já expirado em $now. */
    private function persistedInvoiceWithExpiredPix(FakeSubscriptionInvoiceRepository $invoices, DateTimeImmutable $now): SubscriptionInvoice
    {
        $periodStart = $now->modify('+1 day');
        $invoice = SubscriptionInvoice::issuePix(self::SUBSCRIPTION_ID, 9700, $periodStart, $periodStart->modify('+1 month'), $periodStart);
        $invoice = $invoice->withId($invoices->insert($invoice));
        $invoice->attachPaymentIntent('pi_old', 'https://payments.stripe.test/old', $now->modify('-1 minute'));
        $invoices->save($invoice);

        return $invoice;
    }

    /** @return array<string, mixed> */
    private function paymentIntent(string $id, DateTimeImmutable $expiresAt): array
    {
        return [
            'id' => $id,
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'pix_display_qr_code',
                'pix_display_qr_code' => [
                    'hosted_instructions_url' => self::PAY_URL,
                    'expires_at' => $expiresAt->getTimestamp(),
                ],
            ],
        ];
    }
}
