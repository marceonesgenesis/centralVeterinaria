<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\DuplicateInvoice;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionInvoice;
use CentralVet\Persistence\SubscriptionInvoiceRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\TestDatabase;
use DateTimeImmutable;

/**
 * SubscriptionRepository e SubscriptionInvoiceRepository (T-10) contra as
 * tabelas da 0017: ida e volta, isolamento por tenant, FOR UPDATE, listas
 * do reconciliador e local_key único. Tudo vive na transação do teste,
 * desfeita no tearDown() — nada é commitado.
 */
final class SubscriptionRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $tenantA;
    private int $tenantB;
    private SubscriptionRepository $subscriptions;
    private SubscriptionInvoiceRepository $invoices;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'subscription_invoice'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0017 must be applied to the test database');

        $this->tenantA = $this->createTenant('e3-sub-a');
        $this->tenantB = $this->createTenant('e3-sub-b');
        $this->subscriptions = new SubscriptionRepository($this->pdo);
        $this->invoices = new SubscriptionInvoiceRepository($this->pdo);
    }

    public function testSubscriptionRoundTripAndSave(): void
    {
        $subscription = $this->cardSubscription('pro');
        $subscription->assignTenant($this->tenantA);

        $id = $this->subscriptions->insert($subscription);
        Assert::true($id > 0, 'insert must return the new id');

        $loaded = $this->subscriptions->findByBillingOrderId($subscription->billingOrderId());
        Assert::notNull($loaded);
        Assert::same($subscription->withId($id)->toRow(), $loaded->toRow());

        $locked = $this->subscriptions->findByIdForUpdate($id);
        Assert::notNull($locked);
        Assert::same($loaded->toRow(), $locked->toRow());

        Assert::true($locked->markPaymentFailed(200, new DateTimeImmutable('2031-01-10 10:00:00')));
        $this->subscriptions->save($locked);

        $reloaded = $this->subscriptions->findByIdForUpdate($id);
        Assert::same($locked->toRow(), $reloaded?->toRow());
        Assert::same(Subscription::STATUS_PAST_DUE, $reloaded?->status());
        Assert::same('2031-01-17 10:00:00.000000', $reloaded?->toRow()['grace_until']);
    }

    public function testInvoiceRoundTripSaveAndListOrder(): void
    {
        $subscriptionId = $this->subscriptions->insert($this->cardSubscription('starter'));

        $january = SubscriptionInvoice::fromStripe($subscriptionId, $this->stripeInvoice('2031-01-01', '2031-02-01'), 100);
        $february = SubscriptionInvoice::fromStripe($subscriptionId, $this->stripeInvoice('2031-02-01', '2031-03-01'), 150);

        $januaryId = $this->invoices->insert($january);
        $februaryId = $this->invoices->insert($february);

        $loaded = $this->invoices->findByStripeInvoiceIdForUpdate((string) $january->stripeInvoiceId());
        Assert::same($january->withId($januaryId)->toRow(), $loaded?->toRow());

        Assert::true($loaded->applyRefund(9900, 300));
        $this->invoices->save($loaded);
        $reloaded = $this->invoices->findByIdForUpdate($januaryId);
        Assert::same($loaded->toRow(), $reloaded?->toRow());
        Assert::same(SubscriptionInvoice::STATUS_REFUNDED, $reloaded?->status());

        $listed = $this->invoices->listBySubscription($subscriptionId, 10);
        Assert::same([$februaryId, $januaryId], array_map(static fn (SubscriptionInvoice $i): ?int => $i->id(), $listed));
        Assert::count(1, $this->invoices->listBySubscription($subscriptionId, 1));
    }

    public function testFindByTenantIdDoesNotCrossTenants(): void
    {
        $subscription = $this->cardSubscription('business');
        $subscription->assignTenant($this->tenantA);
        $id = $this->subscriptions->insert($subscription);

        Assert::null($this->subscriptions->findByTenantId($this->tenantB), 'Tenant B must not see the subscription of tenant A');
        Assert::same($id, $this->subscriptions->findByTenantId($this->tenantA)?->id());
    }

    public function testFindByStripeSubscriptionIdForUpdateWaitsForTheLockHolder(): void
    {
        $subscription = $this->cardSubscription('pro');
        $this->subscriptions->insert($subscription);
        $stripeId = (string) $subscription->stripeSubscriptionId();

        Assert::notNull($this->subscriptions->findByStripeSubscriptionIdForUpdate($stripeId));

        $other = $this->secondConnection();
        Assert::throws(
            \LogicException::class,
            static fn () => (new SubscriptionRepository($other))->findByStripeSubscriptionIdForUpdate($stripeId),
            'FOR UPDATE outside a transaction must be refused',
        );

        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        try {
            $answer = 'no answer';
            $lockWait = false;

            try {
                $found = (new SubscriptionRepository($other))->findByStripeSubscriptionIdForUpdate($stripeId);
                $answer = $found === null ? 'null' : 'a row';
            } catch (\PDOException $e) {
                $lockWait = (int) ($e->errorInfo[1] ?? 0) === 1205;
            }

            Assert::true($lockWait, "B must wait for the row A holds (answered: {$answer})");
        } finally {
            $other->rollBack();
        }
    }

    public function testEveryForUpdateRefusesToRunOutsideATransaction(): void
    {
        $other = $this->secondConnection();
        $subscriptions = new SubscriptionRepository($other);
        $invoices = new SubscriptionInvoiceRepository($other);

        $calls = [
            'findByIdForUpdate (subscription)' => static fn () => $subscriptions->findByIdForUpdate(1),
            'findByStripeInvoiceIdForUpdate' => static fn () => $invoices->findByStripeInvoiceIdForUpdate('in_x'),
            'findByIdForUpdate (invoice)' => static fn () => $invoices->findByIdForUpdate(1),
            'findByPaymentIntentIdForUpdate' => static fn () => $invoices->findByPaymentIntentIdForUpdate('pi_x'),
        ];

        foreach ($calls as $name => $call) {
            Assert::throws(\LogicException::class, $call, "{$name} must be refused outside a transaction");
        }
    }

    public function testListGraceExpiredReturnsOnlyPastGrace(): void
    {
        $expired = $this->cardSubscription('pro');
        $expired->markPaymentFailed(200, new DateTimeImmutable('2030-01-01 00:00:00'));
        $expiredId = $this->subscriptions->insert($expired);

        $inGrace = $this->cardSubscription('pro');
        $inGrace->markPaymentFailed(200, new DateTimeImmutable('2030-06-01 00:00:00'));
        $inGraceId = $this->subscriptions->insert($inGrace);

        $activeId = $this->subscriptions->insert($this->cardSubscription('pro'));

        $ids = $this->subscriptions->listGraceExpired(new DateTimeImmutable('2030-06-02 00:00:00'), 1000);

        Assert::true(in_array($expiredId, $ids, true), 'past_due with grace_until in the past must be listed');
        Assert::false(in_array($inGraceId, $ids, true), 'past_due still in grace must not be listed');
        Assert::false(in_array($activeId, $ids, true), 'active must not be listed');
    }

    public function testListPixDueForIssueStopsAfterNextPeriodInvoice(): void
    {
        $pix = $this->pixSubscription('2031-01-01', '2031-02-01');
        $pixId = $this->subscriptions->insert($pix);
        $laterId = $this->subscriptions->insert($this->pixSubscription('2031-03-01', '2031-04-01'));
        $cardId = $this->subscriptions->insert($this->cardSubscription('pro'));

        // A fatura do período corrente não conta: só a do período seguinte.
        $this->invoices->insert($this->pixInvoice($pixId, '2031-01-01', '2031-02-01'));

        $until = new DateTimeImmutable('2031-02-01 00:00:00');
        $ids = $this->subscriptions->listPixDueForIssue($until, 1000);
        Assert::true(in_array($pixId, $ids, true), 'Pix subscription whose period ends before until must be listed');
        Assert::false(in_array($laterId, $ids, true), 'Pix subscription ending after until must not be listed');
        Assert::false(in_array($cardId, $ids, true), 'Card subscription must not be listed');

        $this->invoices->insert($this->pixInvoice($pixId, '2031-02-01', '2031-03-01'));

        Assert::false(
            in_array($pixId, $this->subscriptions->listPixDueForIssue($until, 1000), true),
            'The next-period invoice must remove the subscription from the list',
        );
    }

    public function testScheduledCancellationColumnsRoundTripAndPixLists(): void
    {
        $scheduled = $this->pixSubscription('2031-01-01', '2031-02-01');
        $scheduled->assignTenant($this->tenantA);
        $scheduledId = $this->subscriptions->insert($scheduled);

        $loaded = $this->subscriptions->findByIdForUpdate($scheduledId);
        Assert::true($loaded?->requestCancellation(77, new DateTimeImmutable('2031-01-15 10:00:00.123456')) === true);
        $this->subscriptions->save($loaded);

        $reloaded = $this->subscriptions->findByTenantId($this->tenantA);
        Assert::same($loaded->toRow(), $reloaded?->toRow(), 'save and findByTenantId keep the three cancellation columns');
        Assert::true($reloaded?->cancelAtPeriodEnd() === true);
        Assert::same('2031-01-15 10:00:00.123456', $reloaded?->toRow()['cancel_requested_at']);
        Assert::same(77, $reloaded?->cancelRequestedBy());

        $notYet = $this->pixSubscription('2031-01-20', '2031-02-20');
        $notYetId = $this->subscriptions->insert($notYet);
        $locked = $this->subscriptions->findByIdForUpdate($notYetId);
        $locked?->requestCancellation(77, new DateTimeImmutable('2031-01-21 00:00:00'));
        $this->subscriptions->save($locked);

        $plainId = $this->subscriptions->insert($this->pixSubscription('2031-01-01', '2031-02-01'));

        $card = $this->cardSubscription('pro');
        $cardId = $this->subscriptions->insert($card);
        $lockedCard = $this->subscriptions->findByIdForUpdate($cardId);
        $lockedCard?->requestCancellation(77, new DateTimeImmutable('2031-01-15 00:00:00'));
        $this->subscriptions->save($lockedCard);

        $until = new DateTimeImmutable('2031-02-01 00:00:00');
        $due = $this->subscriptions->listPixDueForIssue($until, 1000);
        Assert::false(in_array($scheduledId, $due, true), 'Pix with scheduled cancellation gets no new invoice');
        Assert::true(in_array($plainId, $due, true), 'Pix without cancellation is still listed');

        $ids = $this->subscriptions->listScheduledCancellationsDue($until, 1000);
        Assert::true(in_array($scheduledId, $ids, true), 'scheduled Pix whose period ended must be listed');
        Assert::false(in_array($notYetId, $ids, true), 'scheduled Pix still in the paid period must not be listed');
        Assert::false(in_array($plainId, $ids, true), 'Pix without cancellation must not be listed');
        Assert::false(in_array($cardId, $ids, true), 'card ends by the Stripe webhook, not by the reconciler');

        $finalized = $this->subscriptions->findByIdForUpdate($scheduledId);
        Assert::true($finalized?->finalizeScheduledCancellation($until) === true);
        $this->subscriptions->save($finalized);
        Assert::false(
            in_array($scheduledId, $this->subscriptions->listScheduledCancellationsDue($until, 1000), true),
            'cancelled must not be listed again',
        );
    }

    public function testInsertWithRepeatedLocalKeyThrowsDuplicateInvoice(): void
    {
        $subscriptionId = $this->subscriptions->insert($this->pixSubscription('2031-01-01', '2031-02-01'));
        $this->invoices->insert($this->pixInvoice($subscriptionId, '2031-02-01', '2031-03-01'));

        $message = '';
        try {
            $this->invoices->insert($this->pixInvoice($subscriptionId, '2031-02-01', '2031-03-01'));
        } catch (DuplicateInvoice $e) {
            $message = $e->getMessage();
        }
        Assert::stringContains('key=subscription_invoice_local_key_uq', $message, 'DuplicateInvoice must name the UNIQUE that collided');

        $local = $this->invoices->findByLocalKey(sprintf('pix:%d:2031-02-01', $subscriptionId));
        Assert::notNull($local, 'The first invoice must still be there');
    }

    public function testFindByPaymentIntentIdForUpdateFindsThePixInvoice(): void
    {
        $subscriptionId = $this->subscriptions->insert($this->pixSubscription('2031-01-01', '2031-02-01'));
        $invoice = $this->pixInvoice($subscriptionId, '2031-02-01', '2031-03-01');
        $paymentIntentId = 'pi_t10_' . bin2hex(random_bytes(6));
        $invoice->attachPaymentIntent($paymentIntentId, 'https://pay.example.test/pix', new DateTimeImmutable('2031-02-11 00:00:00'));
        $id = $this->invoices->insert($invoice);

        $found = $this->invoices->findByPaymentIntentIdForUpdate($paymentIntentId);
        Assert::same($invoice->withId($id)->toRow(), $found?->toRow());
        Assert::null($this->invoices->findByPaymentIntentIdForUpdate('pi_t10_missing_' . bin2hex(random_bytes(4))));
    }

    public function testListOpenPixReturnsInvoicesThatNeedAction(): void
    {
        $subscriptionId = $this->subscriptions->insert($this->pixSubscription('2031-01-01', '2031-02-01'));

        $withoutIntent = $this->invoices->insert($this->pixInvoice($subscriptionId, '2031-02-01', '2031-03-01', '2031-02-01'));

        $healthy = $this->pixInvoice($subscriptionId, '2031-03-01', '2031-04-01', '2031-03-01');
        $healthy->attachPaymentIntent('pi_t10_' . bin2hex(random_bytes(6)), null, new DateTimeImmutable('2031-03-11 00:00:00'));
        $healthyId = $this->invoices->insert($healthy);

        $expired = $this->pixInvoice($subscriptionId, '2031-04-01', '2031-05-01', '2031-04-01');
        $expired->attachPaymentIntent('pi_t10_' . bin2hex(random_bytes(6)), null, new DateTimeImmutable('2031-01-15 00:00:00'));
        $expiredId = $this->invoices->insert($expired);

        $cardId = $this->invoices->insert(
            SubscriptionInvoice::fromStripe($subscriptionId, $this->stripeInvoice('2031-01-01', '2031-02-01'), 100),
        );

        $ids = $this->invoices->listOpenPix(new DateTimeImmutable('2031-01-20 00:00:00'), 1000);

        Assert::true(in_array($withoutIntent, $ids, true), 'Open Pix invoice without PaymentIntent must be listed');
        Assert::true(in_array($expiredId, $ids, true), 'Open Pix invoice with expired Pix must be listed');
        Assert::false(in_array($healthyId, $ids, true), 'Open Pix invoice with a live PaymentIntent and future due date must not be listed');
        Assert::false(in_array($cardId, $ids, true), 'Stripe invoice (no local_key) must not be listed');
    }

    private function cardSubscription(string $planId): Subscription
    {
        return Subscription::start(
            $this->createBillingOrder($planId, Subscription::METHOD_CARD),
            $planId,
            Subscription::METHOD_CARD,
            'cus_t10_' . bin2hex(random_bytes(6)),
            'sub_t10_' . bin2hex(random_bytes(6)),
            'active',
            new DateTimeImmutable('2031-01-01 00:00:00'),
            new DateTimeImmutable('2031-02-01 00:00:00'),
            100,
        );
    }

    private function pixSubscription(string $start, string $end): Subscription
    {
        return Subscription::start(
            $this->createBillingOrder('starter', Subscription::METHOD_PIX),
            'starter',
            Subscription::METHOD_PIX,
            'cus_t10_' . bin2hex(random_bytes(6)),
            null,
            'active',
            new DateTimeImmutable($start . ' 00:00:00'),
            new DateTimeImmutable($end . ' 00:00:00'),
            100,
        );
    }

    private function pixInvoice(int $subscriptionId, string $start, string $end, ?string $due = null): SubscriptionInvoice
    {
        return SubscriptionInvoice::issuePix(
            $subscriptionId,
            9900,
            new DateTimeImmutable($start . ' 00:00:00'),
            new DateTimeImmutable($end . ' 00:00:00'),
            new DateTimeImmutable(($due ?? '2037-01-01') . ' 00:00:00'),
        );
    }

    /** @return array<string, mixed> */
    private function stripeInvoice(string $start, string $end): array
    {
        return [
            'id' => 'in_t10_' . bin2hex(random_bytes(6)),
            'status' => 'paid',
            'amount_due' => 9900,
            'amount_paid' => 9900,
            'currency' => 'brl',
            'hosted_invoice_url' => 'https://invoice.example.test/' . bin2hex(random_bytes(4)),
            'lines' => ['data' => [['period' => [
                'start' => (new DateTimeImmutable($start . ' 00:00:00'))->getTimestamp(),
                'end' => (new DateTimeImmutable($end . ' 00:00:00'))->getTimestamp(),
            ]]]],
        ];
    }

    private function createBillingOrder(string $planId, string $method): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO billing_order (public_id, plan_id, price_cents, payment_method, responsible_name, '
            . 'responsible_email, responsible_phone, clinic_name, terms_version, terms_ip, status) '
            . "VALUES (UUID(), :plan, 9900, :method, 'T-10 teste', 't10@example.test', '11999990000', "
            . "'T-10 Clínica', '2026-10', '127.0.0.1', 'paid')",
        );
        $statement->execute(['plan' => $planId, 'method' => $method]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'T-10 teste tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }

    private function secondConnection(): \PDO
    {
        return new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                TestDatabase::resolveName(getenv()),
            ),
            (string) (getenv('DB_USERNAME') ?: 'centralvet'),
            (string) (getenv('DB_PASSWORD') ?: ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }
}
