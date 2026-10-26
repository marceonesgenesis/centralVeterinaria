<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\CancelledPixRefundService;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionInvoice;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\FakeSubscriptionInvoiceRepository;
use CentralVet\Tests\Support\FakeSubscriptionRepository;
use DateTimeImmutable;

/**
 * Estorno do Pix pago depois do cancelamento (Etapa 5, T-22): fatura Pix
 * `paid` de período posterior ao cancelamento (agendado ou já `cancelled`)
 * é estornada no Stripe com Idempotency-Key `refund-cancelled-pix-<id>`,
 * fica `refunded` e o operador é avisado. O TransactionRunner roda sobre um
 * SQLite em memória (só begin/commit; os dados ficam nos fakes).
 */
final class CancelledPixRefundServiceTest
{
    private const OPERATOR = 'operador@example.test';
    private const AMOUNT = 9700;
    private const PI = 'pi_zz_t22';

    private FakeSubscriptionInvoiceRepository $invoices;
    private FakeSubscriptionRepository $subscriptions;
    private FakeStripeClient $stripe;
    private FakeEmailProvider $email;
    private DateTimeImmutable $now;
    private string $previousErrorLog = '';
    private int $nextOrderId = 12;

    public function setUp(): void
    {
        $this->invoices = new FakeSubscriptionInvoiceRepository();
        $this->subscriptions = new FakeSubscriptionRepository($this->invoices);
        $this->invoices->subscriptions = $this->subscriptions;
        $this->stripe = new FakeStripeClient();
        $this->email = new FakeEmailProvider();
        $this->now = new DateTimeImmutable('2026-11-27 10:00:00');
        $this->previousErrorLog = (string) ini_set('error_log', '/dev/null');
    }

    public function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
    }

    public function testPaidPixAfterScheduledCancellationIsRefundedAndTheOperatorAlerted(): void
    {
        $invoiceId = $this->paidAfterCancellation(scheduled: true);
        $this->stripe->queue('POST', '/v1/refunds', ['id' => 're_zz_t22', 'object' => 'refund']);

        $result = $this->service(self::OPERATOR)->refund($invoiceId, $this->now);

        Assert::same(CancelledPixRefundService::RESULT_REFUNDED, $result);
        Assert::count(1, $this->stripe->calls);
        $call = $this->stripe->calls[0];
        Assert::same('POST', $call['method']);
        Assert::same('/v1/refunds', $call['path']);
        Assert::same('refund-cancelled-pix-' . $invoiceId, $call['idempotencyKey']);
        Assert::same(self::PI, $call['params']['payment_intent'] ?? null);
        Assert::same('requested_by_customer', $call['params']['reason'] ?? null);
        Assert::same((string) $invoiceId, (string) ($call['params']['metadata']['subscription_invoice'] ?? ''));
        Assert::same('subscription_cancelled', $call['params']['metadata']['reason'] ?? null);

        $saved = $this->invoices->findByIdForUpdate($invoiceId);
        Assert::same(SubscriptionInvoice::STATUS_REFUNDED, $saved?->status());
        Assert::same(self::AMOUNT, $saved?->amountRefundedCents());

        $deliveries = $this->email->deliveries();
        Assert::count(1, $deliveries);
        Assert::same(self::OPERATOR, $deliveries[0]->recipient);
        Assert::same('Central Vet Pro: pagamento Pix estornado (assinatura cancelada)', $deliveries[0]->subject);
        Assert::same('cancelled-pix-refund-' . $invoiceId, $deliveries[0]->reference);
    }

    public function testSecondCallIsNotApplicableWithoutCallingStripeAgain(): void
    {
        $invoiceId = $this->paidAfterCancellation(scheduled: true);
        $this->stripe->queue('POST', '/v1/refunds', ['id' => 're_zz_t22_twice', 'object' => 'refund']);
        Assert::same(CancelledPixRefundService::RESULT_REFUNDED, $this->service(self::OPERATOR)->refund($invoiceId, $this->now));

        $again = $this->service(self::OPERATOR)->refund($invoiceId, $this->now);

        Assert::same(CancelledPixRefundService::RESULT_NOT_APPLICABLE, $again);
        Assert::count(1, $this->stripe->calls);
        Assert::count(1, $this->email->deliveries());
    }

    public function testGenericStripeFailureIsRethrownAndTheInvoiceStaysPaid(): void
    {
        $invoiceId = $this->paidAfterCancellation(scheduled: true);
        $this->stripe->fail('POST', '/v1/refunds', new StripeApiException('boom', 500, 'api_error'));

        $thrown = null;
        try {
            $this->service(self::OPERATOR)->refund($invoiceId, $this->now);
        } catch (StripeApiException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown, 'the Stripe failure must be rethrown');
        $saved = $this->invoices->findByIdForUpdate($invoiceId);
        Assert::same(SubscriptionInvoice::STATUS_PAID, $saved?->status());
        Assert::same(0, $saved?->amountRefundedCents());
        Assert::count(0, $this->email->deliveries());
    }

    public function testChargeAlreadyRefundedReusesTheExistingRefund(): void
    {
        $invoiceId = $this->paidAfterCancellation(scheduled: false);
        $this->stripe->fail('POST', '/v1/refunds', new StripeApiException('already refunded', 400, 'charge_already_refunded'));
        $this->stripe->queue('GET', '/v1/refunds', ['object' => 'list', 'data' => [['id' => 're_zz_t22_old', 'object' => 'refund']]]);

        $result = $this->service(self::OPERATOR)->refund($invoiceId, $this->now);

        Assert::same(CancelledPixRefundService::RESULT_REFUNDED, $result);
        Assert::count(2, $this->stripe->calls);
        Assert::same('GET', $this->stripe->calls[1]['method']);
        Assert::same(self::PI, $this->stripe->calls[1]['params']['payment_intent'] ?? null);
        Assert::same(SubscriptionInvoice::STATUS_REFUNDED, $this->invoices->findByIdForUpdate($invoiceId)?->status());
    }

    public function testWithoutOperatorEmailRefundsWithoutAlert(): void
    {
        $invoiceId = $this->paidAfterCancellation(scheduled: true);
        $this->stripe->queue('POST', '/v1/refunds', ['id' => 're_zz_t22_quiet', 'object' => 'refund']);

        $result = $this->service('')->refund($invoiceId, $this->now);

        Assert::same(CancelledPixRefundService::RESULT_REFUNDED, $result);
        Assert::same(SubscriptionInvoice::STATUS_REFUNDED, $this->invoices->findByIdForUpdate($invoiceId)?->status());
        Assert::count(0, $this->email->deliveries());
    }

    public function testInvoiceOfTheCurrentPeriodOrWithoutCancellationIsNotApplicable(): void
    {
        $current = $this->subscription(scheduled: true);
        $currentId = $this->paidInvoice($current, new DateTimeImmutable('2026-10-26 00:00:00'), 'pi_zz_t22_current');

        $plain = $this->subscription(scheduled: false, cancel: false);
        $plainId = $this->paidInvoice($plain, new DateTimeImmutable('2026-11-26 00:00:00'), 'pi_zz_t22_plain');

        Assert::same(CancelledPixRefundService::RESULT_NOT_APPLICABLE, $this->service(self::OPERATOR)->refund($currentId, $this->now));
        Assert::same(CancelledPixRefundService::RESULT_NOT_APPLICABLE, $this->service(self::OPERATOR)->refund($plainId, $this->now));
        Assert::same(CancelledPixRefundService::RESULT_NOT_APPLICABLE, $this->service(self::OPERATOR)->refund(999, $this->now));
        Assert::same([], $this->stripe->calls);
        Assert::same(SubscriptionInvoice::STATUS_PAID, $this->invoices->findByIdForUpdate($currentId)?->status());
    }

    public function testFakeListsOnlyPaidPixAfterCancellation(): void
    {
        $afterId = $this->paidAfterCancellation(scheduled: true);
        $current = $this->subscription(scheduled: true);
        $this->paidInvoice($current, new DateTimeImmutable('2026-10-26 00:00:00'), 'pi_zz_t22_list_current');
        $plain = $this->subscription(scheduled: false, cancel: false);
        $this->paidInvoice($plain, new DateTimeImmutable('2026-11-26 00:00:00'), 'pi_zz_t22_list_plain');

        Assert::same([$afterId], $this->invoices->listPaidPixAfterCancellation(10));
    }

    private function service(string $operatorEmail): CancelledPixRefundService
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new CancelledPixRefundService(
            new TransactionRunner($pdo),
            $this->invoices,
            $this->subscriptions,
            $this->stripe,
            $this->email,
            $operatorEmail,
        );
    }

    /** Fatura Pix paga com `period_start` igual ao `current_period_end` (2026-11-26). */
    private function paidAfterCancellation(bool $scheduled): int
    {
        $subscriptionId = $this->subscription(scheduled: $scheduled);

        return $this->paidInvoice($subscriptionId, new DateTimeImmutable('2026-11-26 00:00:00'), self::PI);
    }

    /** Assinatura Pix 2026-10-26 → 2026-11-26: agendada, `cancelled` ou sem cancelamento. */
    private function subscription(bool $scheduled, bool $cancel = true): int
    {
        $subscription = Subscription::start(
            $this->nextOrderId++,
            'pro',
            Subscription::METHOD_PIX,
            'cus_t22',
            null,
            'active',
            new DateTimeImmutable('2026-10-26 00:00:00'),
            new DateTimeImmutable('2026-11-26 00:00:00'),
            100,
        );
        $subscription->assignTenant(30 + $this->nextOrderId);
        if ($cancel) {
            Assert::true($subscription->requestCancellation(7, new DateTimeImmutable('2026-11-20 09:00:00')));
            if (!$scheduled) {
                Assert::true($subscription->finalizeScheduledCancellation(new DateTimeImmutable('2026-11-26 00:01:00')));
            }
        }

        return $this->subscriptions->insert($subscription);
    }

    private function paidInvoice(int $subscriptionId, DateTimeImmutable $periodStart, string $paymentIntent): int
    {
        $invoice = SubscriptionInvoice::issuePix($subscriptionId, self::AMOUNT, $periodStart, $periodStart->modify('+1 month'), $periodStart);
        $invoice->attachPaymentIntent($paymentIntent, null, $periodStart->modify('+7 days'));
        Assert::true($invoice->markPaidByPaymentIntent(self::AMOUNT, 1_795_000_000));

        return $this->invoices->insert($invoice);
    }
}
