<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionCancellationException;
use CentralVet\Billing\SubscriptionCancellationService;
use CentralVet\Billing\SubscriptionInvoice;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\FakeSubscriptionInvoiceRepository;
use CentralVet\Tests\Support\FakeSubscriptionRepository;
use DateTimeImmutable;

/**
 * Cancelamento pelo cliente no fim do período (Etapa 5, T-08): cartão
 * agenda no Stripe (`cancel_at_period_end=true`) antes de gravar; Pix só
 * marca local; falha do Stripe não grava nada. O TransactionRunner roda
 * sobre um SQLite em memória (só begin/commit; os dados ficam no fake).
 */
final class SubscriptionCancellationServiceTest
{
    private const TENANT_ID = 41;
    private const USER_ID = 7;

    private FakeSubscriptionRepository $subscriptions;
    private FakeStripeClient $stripe;
    private FakeSubscriptionInvoiceRepository $invoices;
    private DateTimeImmutable $now;

    public function setUp(): void
    {
        $this->subscriptions = new FakeSubscriptionRepository();
        $this->stripe = new FakeStripeClient();
        $this->invoices = new FakeSubscriptionInvoiceRepository();
        $this->now = new DateTimeImmutable('2031-01-15 10:00:00');
    }

    public function testCardSchedulesTheCancellationOnStripeBeforeSaving(): void
    {
        $id = $this->insert($this->card('sub_t08_card'));
        $this->stripe->queue('POST', '/v1/subscriptions/sub_t08_card', ['id' => 'sub_t08_card', 'cancel_at_period_end' => true]);

        $result = $this->service()->request(self::TENANT_ID, self::USER_ID, $this->now);

        Assert::same(SubscriptionCancellationService::RESULT_SCHEDULED, $result);
        Assert::count(1, $this->stripe->calls);
        Assert::same('POST', $this->stripe->calls[0]['method']);
        Assert::same('/v1/subscriptions/sub_t08_card', $this->stripe->calls[0]['path']);
        Assert::same(['cancel_at_period_end' => 'true'], $this->stripe->calls[0]['params']);
        Assert::same('cancel-subscription-' . $id, $this->stripe->calls[0]['idempotencyKey']);

        $saved = $this->subscriptions->findByTenantId(self::TENANT_ID);
        Assert::true($saved?->cancelAtPeriodEnd() === true, 'the cancellation must be scheduled');
        Assert::same(self::USER_ID, $saved?->cancelRequestedBy());
        Assert::same('2031-01-15 10:00:00', $saved?->cancelRequestedAt()?->format('Y-m-d H:i:s'));
        Assert::same(Subscription::STATUS_ACTIVE, $saved?->status(), 'access stays until the period ends');
        Assert::null($saved?->cancelledAt());
        Assert::true(in_array('findByIdForUpdate', $this->subscriptions->lockCalls, true), 'saves with the row locked');
    }

    public function testSecondRequestIsAlreadyScheduledWithoutCallingStripeAgain(): void
    {
        $this->insert($this->card('sub_t08_twice'));
        $this->stripe->queue('POST', '/v1/subscriptions/sub_t08_twice', ['id' => 'sub_t08_twice']);

        $this->service()->request(self::TENANT_ID, self::USER_ID, $this->now);
        $second = $this->service()->request(self::TENANT_ID, 99, $this->now->modify('+1 hour'));

        Assert::same(SubscriptionCancellationService::RESULT_ALREADY_SCHEDULED, $second);
        Assert::count(1, $this->stripe->calls, 'no second Stripe call');
        Assert::same(self::USER_ID, $this->subscriptions->findByTenantId(self::TENANT_ID)?->cancelRequestedBy());
    }

    public function testStripeFailureThrowsAndLeavesTheSubscriptionUntouched(): void
    {
        $id = $this->insert($this->card('sub_t08_fail'));
        $before = $this->subscriptions->rows[$id];
        $this->stripe->fail('POST', '/v1/subscriptions/sub_t08_fail', new StripeApiException('timeout', 0));

        $message = '';
        try {
            $this->service()->request(self::TENANT_ID, self::USER_ID, $this->now);
        } catch (SubscriptionCancellationException $e) {
            $message = $e->getMessage();
        }

        Assert::same('The payment provider did not confirm the cancellation. Try again in a few minutes', $message);
        Assert::same($before, $this->subscriptions->rows[$id], 'nothing is saved when Stripe fails');
        Assert::false((bool) $this->subscriptions->findByTenantId(self::TENANT_ID)?->cancelAtPeriodEnd());
    }

    public function testPixIsScheduledLocallyWithoutCallingStripe(): void
    {
        $this->insert($this->pix());

        $result = $this->service()->request(self::TENANT_ID, self::USER_ID, $this->now);

        Assert::same(SubscriptionCancellationService::RESULT_SCHEDULED, $result);
        Assert::same([], $this->stripe->calls, 'Pix has no Stripe subscription');
        $saved = $this->subscriptions->findByTenantId(self::TENANT_ID);
        Assert::true($saved?->cancelAtPeriodEnd() === true);
        Assert::same(Subscription::STATUS_ACTIVE, $saved?->status());
    }

    public function testWithoutSubscriptionThrowsNoActiveSubscription(): void
    {
        $message = '';
        try {
            $this->service()->request(self::TENANT_ID, self::USER_ID, $this->now);
        } catch (SubscriptionCancellationException $e) {
            $message = $e->getMessage();
        }

        Assert::same('No active subscription to cancel', $message);
        Assert::same([], $this->stripe->calls);
    }

    public function testCancelledSubscriptionThrowsNoActiveSubscription(): void
    {
        $subscription = $this->card('sub_t08_gone');
        $subscription->applyProviderStatus('canceled', null, null, 200, $this->now);
        $this->insert($subscription);

        Assert::throws(
            SubscriptionCancellationException::class,
            fn () => $this->service()->request(self::TENANT_ID, self::USER_ID, $this->now),
        );
        Assert::same([], $this->stripe->calls);
        Assert::true(new SubscriptionCancellationException('x') instanceof \DomainException);
    }

    public function testEntityRulesForRequestAndPixFinalization(): void
    {
        $pix = $this->pix();
        Assert::false($pix->cancelAtPeriodEnd());
        Assert::false($pix->finalizeScheduledCancellation(new DateTimeImmutable('2031-03-01 00:00:00')), 'not scheduled');

        Assert::true($pix->requestCancellation(self::USER_ID, $this->now));
        Assert::false($pix->requestCancellation(self::USER_ID, $this->now), 'already scheduled');

        Assert::false($pix->finalizeScheduledCancellation(new DateTimeImmutable('2031-01-31 23:59:59')), 'period not over');
        $end = new DateTimeImmutable('2031-02-01 00:00:00');
        Assert::true($pix->finalizeScheduledCancellation($end));
        Assert::same(Subscription::STATUS_CANCELLED, $pix->status());
        Assert::same('2031-02-01 00:00:00', $pix->cancelledAt()?->format('Y-m-d H:i:s'));
        Assert::same(Subscription::ACCESS_BLOCKED, $pix->accessLevel($end));
        Assert::false($pix->finalizeScheduledCancellation($end->modify('+1 day')), 'already cancelled');
        Assert::false($pix->requestCancellation(self::USER_ID, $end), 'cancelled cannot be scheduled');

        $card = $this->card('sub_t08_entity');
        Assert::true($card->requestCancellation(self::USER_ID, $this->now));
        Assert::false($card->finalizeScheduledCancellation(new DateTimeImmutable('2031-03-01 00:00:00')), 'card ends by the Stripe webhook');

        $row = $card->withId(5)->toRow();
        Assert::same(1, $row['cancel_at_period_end']);
        Assert::same('2031-01-15 10:00:00.000000', $row['cancel_requested_at']);
        Assert::same(self::USER_ID, $row['cancel_requested_by']);
        Assert::same($row, Subscription::fromRow($row)->toRow());
    }

    /**
     * T-21 (reprodução da revisão final): Pix com `current_period_end`
     * 2026-11-26 e a fatura do período seguinte já emitida com PaymentIntent.
     * Agendar o cancelamento cancela o PI no Stripe e anula a fatura.
     */
    public function testPixCancelsTheFutureInvoicePaymentIntentAndVoidsIt(): void
    {
        $subscriptionId = $this->insert($this->pixT21());
        $invoiceId = $this->insertInvoice($subscriptionId, '2026-11-26', 'pi_zz_t21');
        $this->stripe->queue('POST', '/v1/payment_intents/pi_zz_t21/cancel', ['id' => 'pi_zz_t21', 'status' => 'canceled']);

        $result = $this->service()->request(self::TENANT_ID, self::USER_ID, $this->t21Now());

        Assert::same(SubscriptionCancellationService::RESULT_SCHEDULED, $result);
        Assert::count(1, $this->stripe->calls);
        Assert::same('POST', $this->stripe->calls[0]['method']);
        Assert::same('/v1/payment_intents/pi_zz_t21/cancel', $this->stripe->calls[0]['path']);
        Assert::same('cancel-pix-intent-' . $invoiceId, $this->stripe->calls[0]['idempotencyKey']);
        Assert::same(SubscriptionInvoice::STATUS_VOID, $this->invoiceStatus($invoiceId));
        Assert::true(in_array('findByIdForUpdate', $this->invoices->lockCalls, true), 'voids with the invoice row locked');
        Assert::true($this->subscriptions->findByTenantId(self::TENANT_ID)?->cancelAtPeriodEnd() === true);
    }

    public function testPixStripeFailureOnTheIntentCancelSavesNothing(): void
    {
        $subscriptionId = $this->insert($this->pixT21());
        $invoiceId = $this->insertInvoice($subscriptionId, '2026-11-26', 'pi_zz_t21');
        $this->stripe->fail('POST', '/v1/payment_intents/pi_zz_t21/cancel', new StripeApiException('timeout', 0));

        $message = '';
        try {
            $this->service()->request(self::TENANT_ID, self::USER_ID, $this->t21Now());
        } catch (SubscriptionCancellationException $e) {
            $message = $e->getMessage();
        }

        Assert::same(SubscriptionCancellationService::PROVIDER_FAILED, $message);
        Assert::false((bool) $this->subscriptions->findByTenantId(self::TENANT_ID)?->cancelAtPeriodEnd(), 'nothing scheduled');
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $this->invoiceStatus($invoiceId));
    }

    public function testPixUnexpectedStateWithIntentAlreadyCanceledStillSchedules(): void
    {
        $subscriptionId = $this->insert($this->pixT21());
        $invoiceId = $this->insertInvoice($subscriptionId, '2026-11-26', 'pi_zz_t21');
        $this->stripe->fail(
            'POST',
            '/v1/payment_intents/pi_zz_t21/cancel',
            new StripeApiException('unexpected state', 400, 'payment_intent_unexpected_state'),
        );
        $this->stripe->queue('GET', '/v1/payment_intents/pi_zz_t21', ['id' => 'pi_zz_t21', 'status' => 'canceled']);

        $result = $this->service()->request(self::TENANT_ID, self::USER_ID, $this->t21Now());

        Assert::same(SubscriptionCancellationService::RESULT_SCHEDULED, $result);
        Assert::count(2, $this->stripe->calls);
        Assert::same('GET', $this->stripe->calls[1]['method']);
        Assert::same(SubscriptionInvoice::STATUS_VOID, $this->invoiceStatus($invoiceId));
    }

    public function testPixUnexpectedStateWithIntentInAnotherStatusFails(): void
    {
        $subscriptionId = $this->insert($this->pixT21());
        $invoiceId = $this->insertInvoice($subscriptionId, '2026-11-26', 'pi_zz_t21');
        $this->stripe->fail(
            'POST',
            '/v1/payment_intents/pi_zz_t21/cancel',
            new StripeApiException('unexpected state', 400, 'payment_intent_unexpected_state'),
        );
        $this->stripe->queue('GET', '/v1/payment_intents/pi_zz_t21', ['id' => 'pi_zz_t21', 'status' => 'processing']);

        Assert::throws(
            SubscriptionCancellationException::class,
            fn () => $this->service()->request(self::TENANT_ID, self::USER_ID, $this->t21Now()),
        );
        Assert::false((bool) $this->subscriptions->findByTenantId(self::TENANT_ID)?->cancelAtPeriodEnd());
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $this->invoiceStatus($invoiceId));
    }

    public function testPixFutureInvoiceWithoutIntentIsVoidedWithoutStripe(): void
    {
        $subscriptionId = $this->insert($this->pixT21());
        $invoiceId = $this->insertInvoice($subscriptionId, '2026-11-26', null);

        $result = $this->service()->request(self::TENANT_ID, self::USER_ID, $this->t21Now());

        Assert::same(SubscriptionCancellationService::RESULT_SCHEDULED, $result);
        Assert::same([], $this->stripe->calls);
        Assert::same(SubscriptionInvoice::STATUS_VOID, $this->invoiceStatus($invoiceId));
    }

    public function testPixCurrentPeriodInvoiceIsNotTouched(): void
    {
        $subscriptionId = $this->insert($this->pixT21());
        $currentId = $this->insertInvoice($subscriptionId, '2026-10-26', 'pi_zz_t21_current');

        $result = $this->service()->request(self::TENANT_ID, self::USER_ID, $this->t21Now());

        Assert::same(SubscriptionCancellationService::RESULT_SCHEDULED, $result);
        Assert::same([], $this->stripe->calls);
        Assert::same(SubscriptionInvoice::STATUS_OPEN, $this->invoiceStatus($currentId));
    }

    public function testPixSecondRequestIsAlreadyScheduledWithoutNewStripeCall(): void
    {
        $subscriptionId = $this->insert($this->pixT21());
        $invoiceId = $this->insertInvoice($subscriptionId, '2026-11-26', 'pi_zz_t21');
        $this->stripe->queue('POST', '/v1/payment_intents/pi_zz_t21/cancel', ['id' => 'pi_zz_t21', 'status' => 'canceled']);

        $this->service()->request(self::TENANT_ID, self::USER_ID, $this->t21Now());
        $second = $this->service()->request(self::TENANT_ID, self::USER_ID, $this->t21Now()->modify('+1 hour'));

        Assert::same(SubscriptionCancellationService::RESULT_ALREADY_SCHEDULED, $second);
        Assert::count(1, $this->stripe->calls, 'no second Stripe call');
        Assert::same(SubscriptionInvoice::STATUS_VOID, $this->invoiceStatus($invoiceId));
    }

    public function testAlreadyScheduledPixStillVoidsTheLeftoverFutureInvoice(): void
    {
        $subscription = $this->pixT21();
        $subscription->requestCancellation(self::USER_ID, $this->t21Now()->modify('-1 day'));
        $subscriptionId = $this->insert($subscription);
        $invoiceId = $this->insertInvoice($subscriptionId, '2026-11-26', 'pi_zz_t21');
        $this->stripe->queue('POST', '/v1/payment_intents/pi_zz_t21/cancel', ['id' => 'pi_zz_t21', 'status' => 'canceled']);

        $result = $this->service()->request(self::TENANT_ID, self::USER_ID, $this->t21Now());

        Assert::same(SubscriptionCancellationService::RESULT_ALREADY_SCHEDULED, $result);
        Assert::count(1, $this->stripe->calls);
        Assert::same('cancel-pix-intent-' . $invoiceId, $this->stripe->calls[0]['idempotencyKey']);
        Assert::same(SubscriptionInvoice::STATUS_VOID, $this->invoiceStatus($invoiceId));
    }

    public function testCardStillCallsOnlyTheSubscriptionEndpoint(): void
    {
        $id = $this->insert($this->card('sub_t21_card'));
        $this->insertInvoice($id, '2031-02-01', null);
        $this->stripe->queue('POST', '/v1/subscriptions/sub_t21_card', ['id' => 'sub_t21_card']);

        $this->service()->request(self::TENANT_ID, self::USER_ID, $this->now);

        Assert::count(1, $this->stripe->calls);
        Assert::same('/v1/subscriptions/sub_t21_card', $this->stripe->calls[0]['path']);
    }

    private function service(): SubscriptionCancellationService
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new SubscriptionCancellationService($this->subscriptions, $this->stripe, new TransactionRunner($pdo), $this->invoices);
    }

    private function t21Now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-24 10:00:00');
    }

    private function pixT21(): Subscription
    {
        return Subscription::start(
            13,
            'starter',
            Subscription::METHOD_PIX,
            'cus_t21_pix',
            null,
            'active',
            new DateTimeImmutable('2026-10-26 00:00:00'),
            new DateTimeImmutable('2026-11-26 00:00:00'),
            100,
        );
    }

    private function insertInvoice(int $subscriptionId, string $periodStart, ?string $paymentIntentId): int
    {
        $start = new DateTimeImmutable($periodStart . ' 00:00:00');
        $invoice = SubscriptionInvoice::issuePix($subscriptionId, 9700, $start, $start->modify('+1 month'), $start);
        $invoice = $invoice->withId($this->invoices->insert($invoice));
        if ($paymentIntentId !== null) {
            $invoice->attachPaymentIntent($paymentIntentId, 'https://payments.stripe.test/pix/' . $paymentIntentId, $start->modify('+7 days'));
            $this->invoices->save($invoice);
        }

        return (int) $invoice->id();
    }

    private function invoiceStatus(int $invoiceId): string
    {
        return (string) $this->invoices->rows[$invoiceId]['status'];
    }

    private function insert(Subscription $subscription): int
    {
        $subscription->assignTenant(self::TENANT_ID);

        return $this->subscriptions->insert($subscription);
    }

    private function card(string $stripeSubscriptionId): Subscription
    {
        return Subscription::start(
            11,
            'pro',
            Subscription::METHOD_CARD,
            'cus_t08',
            $stripeSubscriptionId,
            'active',
            new DateTimeImmutable('2031-01-01 00:00:00'),
            new DateTimeImmutable('2031-02-01 00:00:00'),
            100,
        );
    }

    private function pix(): Subscription
    {
        return Subscription::start(
            12,
            'starter',
            Subscription::METHOD_PIX,
            'cus_t08_pix',
            null,
            'active',
            new DateTimeImmutable('2031-01-01 00:00:00'),
            new DateTimeImmutable('2031-02-01 00:00:00'),
            100,
        );
    }
}
