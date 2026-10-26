<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionCancellationException;
use CentralVet\Billing\SubscriptionCancellationService;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeStripeClient;
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
    private DateTimeImmutable $now;

    public function setUp(): void
    {
        $this->subscriptions = new FakeSubscriptionRepository();
        $this->stripe = new FakeStripeClient();
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

    private function service(): SubscriptionCancellationService
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new SubscriptionCancellationService($this->subscriptions, $this->stripe, new TransactionRunner($pdo));
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
