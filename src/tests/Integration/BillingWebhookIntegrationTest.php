<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\BillingEventIngestor;
use CentralVet\Billing\BillingEventProcessor;
use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\BillingWebhookHandler;
use CentralVet\Billing\Contract\BillingEventRepositoryInterface;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeSignatureVerifier;
use CentralVet\Billing\TenantProvisioningService;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Landing\LeadResponse;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Persistence\BillingEventRepository;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Persistence\SubscriptionInvoiceRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Webhook do Stripe (T-17) contra o centralvet_test com a 0017: assinatura
 * Stripe-Signature (segredo de teste gerado aqui), deduplicação por
 * event_id, evento fora de ordem com reenvio, rollback em falha do Stripe
 * depois do save do pedido, deadlock 1213 retentado e provisionamento que
 * falha sem falhar o evento. O TransactionRunner usa SAVEPOINT dentro da
 * transação do teste, desfeita no tearDown() — nada é commitado.
 */
final class BillingWebhookIntegrationTest extends MysqlIntegrationTestCase
{
    private const SECRET = 'whsec_test_etapa3';
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';

    private BillingOrderRepository $orders;
    private SubscriptionRepository $subscriptions;
    private BillingEventRepositoryInterface $events;
    private FakeStripeClient $stripe;
    private FakeEmailProvider $email;
    private DateTimeImmutable $now;

    /** @var array<string, \Closure> */
    private array $fixtures;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'billing_event'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0017 must be applied to the test database');

        $this->orders = new BillingOrderRepository($this->pdo);
        $this->subscriptions = new SubscriptionRepository($this->pdo);
        $this->events = new BillingEventRepository($this->pdo);
        $this->stripe = new FakeStripeClient();
        $this->email = new FakeEmailProvider();
        $this->now = new DateTimeImmutable('2031-01-01 12:00:00');
        $this->fixtures = require __DIR__ . '/../Support/Fixtures/stripe-events.php';
        $this->createPlanGroups();
    }

    public function testSignedCheckoutIsProcessedProvisionsTenantAndRepeatIsDuplicate(): void
    {
        [$orderId, $sessionId, $subscriptionId] = $this->pendingCardOrder('starter');
        $this->queueSubscription($subscriptionId);
        $body = $this->body(($this->fixtures['checkoutCompleted'])($sessionId, $subscriptionId, $this->created()));
        $tenantsBefore = $this->countTenants();
        $eventsBefore = $this->countEvents();

        $response = $this->post($body);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        Assert::same($tenantsBefore + 1, $this->countTenants(), 'the paid checkout must provision one tenant');
        Assert::same(BillingOrder::STATUS_PROVISIONED, $this->orders->findByIdForUpdate($orderId)?->status());
        Assert::notNull($this->subscriptions->findByBillingOrderId($orderId)?->tenantId());
        Assert::count(1, $this->email->deliveries(), 'one invite');
        Assert::same($eventsBefore + 1, $this->countEvents());
        Assert::same('processed', $this->eventStatus($body));

        $again = $this->post($body);

        $this->assertJson(200, ['received' => true, 'result' => 'duplicate'], $again);
        Assert::same($tenantsBefore + 1, $this->countTenants());
        Assert::same($eventsBefore + 1, $this->countEvents());
        Assert::same(1, $this->count('SELECT COUNT(*) FROM subscription WHERE billing_order_id = ?', [$orderId]));
        Assert::count(1, $this->email->deliveries());
        Assert::count(1, $this->stripe->calls, 'a duplicate must not call Stripe');
    }

    public function testBodyChangedAfterSigningIsRejectedWithoutWritingEvents(): void
    {
        [, $sessionId, $subscriptionId] = $this->pendingCardOrder('pro');
        $body = $this->body(($this->fixtures['checkoutCompleted'])($sessionId, $subscriptionId, $this->created()));
        $header = StripeSignatureVerifier::header($body, self::SECRET, $this->now->getTimestamp());
        $tampered = str_replace('"amount_total":9700', '"amount_total":1', $body);
        Assert::true($tampered !== $body, 'the tampered body must differ');
        $eventsBefore = $this->countEvents();

        $response = $this->handler()->handle('POST', ['stripe-signature' => $header], $tampered, $this->now);

        $this->assertJson(400, ['error' => 'invalid_signature'], $response);
        Assert::same($eventsBefore, $this->countEvents(), 'no billing_event row for an invalid signature');
        Assert::count(0, $this->stripe->calls);
    }

    public function testMissingSignatureWrongMethodAndInvalidPayload(): void
    {
        $eventsBefore = $this->countEvents();

        $this->assertJson(400, ['error' => 'invalid_signature'], $this->handler()->handle('POST', [], '{}', $this->now));
        Assert::same(405, $this->handler()->handle('GET', [], '', $this->now)->status);

        $noCreated = '{"id":"evt_t17_x","type":"invoice.paid"}';
        $this->assertJson(400, ['error' => 'invalid_payload'], $this->post($noCreated));
        $this->assertJson(400, ['error' => 'invalid_payload'], $this->post('not json'));
        Assert::same($eventsBefore, $this->countEvents());
    }

    public function testInvoicePaidBeforeCheckoutFailsThenSucceedsOnRedelivery(): void
    {
        [$orderId, $sessionId, $subscriptionId] = $this->pendingCardOrder('pro');
        $created = $this->created();
        $invoice = $this->body(($this->fixtures['invoice'])(
            'invoice.paid',
            'in_t17_' . bin2hex(random_bytes(5)),
            $subscriptionId,
            'paid',
            9700,
            $this->now->getTimestamp() - 3600,
            $this->now->modify('+1 month')->getTimestamp(),
            $created + 5,
        ));

        $early = $this->post($invoice);

        $this->assertJson(500, ['error' => 'processing_failed'], $early);
        Assert::same('failed', $this->eventStatus($invoice));

        $this->queueSubscription($subscriptionId);
        $checkout = $this->post($this->body(($this->fixtures['checkoutCompleted'])($sessionId, $subscriptionId, $created)));
        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $checkout);

        $redelivery = $this->post($invoice);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $redelivery);
        Assert::same('processed', $this->eventStatus($invoice));
        $subscription = $this->subscriptions->findByBillingOrderId($orderId);
        Assert::same(1, $this->count('SELECT COUNT(*) FROM subscription_invoice WHERE subscription_id = ?', [$subscription?->id()]));
    }

    public function testStripeFailureAfterOrderSaveRollsBackAndRedeliveryProcesses(): void
    {
        [$orderId, $sessionId, $subscriptionId] = $this->pendingCardOrder('business');
        $this->stripe->fail('GET', '/v1/subscriptions/' . $subscriptionId, new StripeApiException('Stripe unavailable', 503));
        $body = $this->body(($this->fixtures['checkoutCompleted'])($sessionId, $subscriptionId, $this->created()));
        $tenantsBefore = $this->countTenants();

        $failed = $this->post($body);

        $this->assertJson(500, ['error' => 'processing_failed'], $failed);
        Assert::same(BillingOrder::STATUS_PENDING, $this->orders->findByIdForUpdate($orderId)?->status(), 'markPaid must be rolled back');
        Assert::null($this->subscriptions->findByBillingOrderId($orderId));
        Assert::same('failed', $this->eventStatus($body));
        Assert::same($tenantsBefore, $this->countTenants());

        $this->queueSubscription($subscriptionId);
        $retry = $this->post($body);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $retry);
        Assert::same(BillingOrder::STATUS_PROVISIONED, $this->orders->findByIdForUpdate($orderId)?->status());
        Assert::same($tenantsBefore + 1, $this->countTenants());
    }

    public function testDeadlockOnClaimIsRetriedAndAppliedOnce(): void
    {
        [$orderId, $sessionId, $subscriptionId] = $this->pendingCardOrder('starter');
        $this->queueSubscription($subscriptionId);
        $body = $this->body(($this->fixtures['checkoutCompleted'])($sessionId, $subscriptionId, $this->created()));
        $deadlocks = 2;
        $this->events = $this->decoratedEvents(function () use (&$deadlocks): void {
            if ($deadlocks-- > 0) {
                $e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
                $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
                throw $e;
            }
        });
        $tenantsBefore = $this->countTenants();

        $response = $this->post($body);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        Assert::same($tenantsBefore + 1, $this->countTenants());
        Assert::same(BillingOrder::STATUS_PROVISIONED, $this->orders->findByIdForUpdate($orderId)?->status());
        Assert::count(1, $this->stripe->calls);
    }

    public function testEventFinishedByAnotherDeliveryBetweenClaimAndProcessIsNotAppliedAgain(): void
    {
        [$orderId, $sessionId, $subscriptionId] = $this->pendingCardOrder('pro');
        $body = $this->body(($this->fixtures['checkoutCompleted'])($sessionId, $subscriptionId, $this->created()));
        $claims = 0;
        $real = $this->events;
        $this->events = $this->decoratedEvents(null, function (?int $id) use (&$claims, $real): void {
            if (++$claims === 1 && $id !== null) {
                $real->markDone($id, 'processed', $this->now);
            }
        });

        $response = $this->post($body);

        $this->assertJson(200, ['received' => true, 'result' => 'duplicate'], $response);
        Assert::same(BillingOrder::STATUS_PENDING, $this->orders->findByIdForUpdate($orderId)?->status());
        Assert::count(0, $this->stripe->calls, 'the processor must not run for an event already finished');
    }

    public function testLoginConflictKeepsEventProcessedAndOrderProvisioningFailed(): void
    {
        [$orderId, $sessionId, $subscriptionId, $email] = $this->pendingCardOrder('enterprise');
        $this->existingUser($email);
        $this->queueSubscription($subscriptionId);
        $body = $this->body(($this->fixtures['checkoutCompleted'])($sessionId, $subscriptionId, $this->created()));
        $tenantsBefore = $this->countTenants();

        $response = $this->post($body);

        $this->assertJson(200, ['received' => true, 'result' => 'processed'], $response);
        Assert::same('processed', $this->eventStatus($body));
        $order = $this->orders->findByIdForUpdate($orderId);
        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $order?->status());
        Assert::same('login_conflict', $order?->provisioningError());
        Assert::same($tenantsBefore, $this->countTenants());
        Assert::count(0, $this->email->deliveries());
    }

    private function post(string $body): LeadResponse
    {
        $header = StripeSignatureVerifier::header($body, self::SECRET, $this->now->getTimestamp());

        return $this->handler()->handle('POST', ['stripe-signature' => $header], $body, $this->now);
    }

    private function handler(): BillingWebhookHandler
    {
        $tx = new TransactionRunner($this->pdo);
        $invoices = new SubscriptionInvoiceRepository($this->pdo);
        $translate = static fn (string $key, string ...$params): string => '[' . implode('|', [$key, ...$params]) . ']';
        $provisioning = new TenantProvisioningService(
            $this->pdo,
            $tx,
            $this->orders,
            $this->subscriptions,
            new AccountInviteService(
                new PasswordResetToken(self::KEY),
                new PublicUrl('https://app.test'),
                $this->email,
                \Closure::fromCallable($translate),
            ),
        );
        $ingestor = new BillingEventIngestor(
            $tx,
            $this->events,
            new BillingEventProcessor($this->orders, $this->subscriptions, $invoices, $this->stripe),
            $provisioning,
        );

        return new BillingWebhookHandler(
            new StripeSignatureVerifier(self::SECRET),
            $ingestor,
            new JsonLogger('billing-webhook-test', 'test', 'php://memory'),
        );
    }

    /**
     * Real repository with hooks around claim(): $before runs before each
     * claim (may throw), $after receives the id each claim returned.
     */
    private function decoratedEvents(?\Closure $before, ?\Closure $after = null): BillingEventRepositoryInterface
    {
        return new class ($this->events, $before, $after) implements BillingEventRepositoryInterface {
            public function __construct(
                private readonly BillingEventRepositoryInterface $inner,
                private readonly ?\Closure $before,
                private readonly ?\Closure $after,
            ) {
            }

            public function claim(string $eventId, string $eventType, int $eventCreated, bool $livemode, string $payload): ?int
            {
                if ($this->before !== null) {
                    ($this->before)();
                }
                $id = $this->inner->claim($eventId, $eventType, $eventCreated, $livemode, $payload);
                if ($this->after !== null) {
                    ($this->after)($id);
                }

                return $id;
            }

            public function markDone(int $id, string $status, DateTimeImmutable $at): void
            {
                $this->inner->markDone($id, $status, $at);
            }

            public function markFailed(int $id, string $error): void
            {
                $this->inner->markFailed($id, $error);
            }

            public function latestEventCreated(): ?int
            {
                return $this->inner->latestEventCreated();
            }
        };
    }

    /** @param array<string, mixed> $expected */
    private function assertJson(int $status, array $expected, LeadResponse $response): void
    {
        Assert::same($status, $response->status, 'HTTP status (body ' . json_encode($response->body) . ')');
        Assert::same($expected, $response->body);
    }

    /** @param array<string, mixed> $event */
    private function body(array $event): string
    {
        $event['id'] = 'evt_t17_' . bin2hex(random_bytes(8));

        return (string) json_encode($event, JSON_UNESCAPED_SLASHES);
    }

    private function created(): int
    {
        return $this->now->getTimestamp() - 60;
    }

    private function queueSubscription(string $subscriptionId): void
    {
        $this->stripe->queue('GET', '/v1/subscriptions/' . $subscriptionId, ($this->fixtures['stripeSubscription'])(
            $subscriptionId,
            'active',
            $this->now->getTimestamp() - 3600,
            $this->now->modify('+1 month')->getTimestamp(),
        ));
    }

    /** @return array{0: int, 1: string, 2: string, 3: string} */
    private function pendingCardOrder(string $planId): array
    {
        $suffix = bin2hex(random_bytes(5));
        $order = BillingOrder::place(
            $this->uuid(),
            $planId,
            BillingOrder::METHOD_CARD,
            'Dra. T-17 ' . $suffix,
            't17-' . $suffix . '@example.test',
            '11999990000',
            'Clínica T-17 ' . $suffix,
            '2026-10',
            new DateTimeImmutable('2031-01-01 11:00:00'),
            '127.0.0.1',
            false,
        );
        $sessionId = 'cs_t17_' . $suffix;
        $order->attachCheckout($sessionId, 'cus_t17_' . $suffix);

        return [$this->orders->insert($order), $sessionId, 'sub_t17_' . $suffix, $order->responsibleEmail()];
    }

    private function eventStatus(string $body): ?string
    {
        $eventId = (string) json_decode($body, true)['id'];
        $statement = $this->pdo->prepare("SELECT status FROM billing_event WHERE provider = 'stripe' AND event_id = ?");
        $statement->execute([$eventId]);
        $status = $statement->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    private function createPlanGroups(): void
    {
        foreach (PlanEntitlements::PLAN_IDS as $planId) {
            $name = TenantProvisioningService::GROUP_NAME_PREFIX . PlanEntitlements::forPlan($planId)->name();
            if ($this->count('SELECT COUNT(*) FROM system_group WHERE name = ?', [$name]) > 0) {
                continue;
            }

            $id = $this->count('SELECT COALESCE(MAX(id), 0) + 1 FROM system_group FOR UPDATE');
            $this->pdo->prepare('INSERT INTO system_group (id, name) VALUES (?, ?)')->execute([$id, $name]);
        }
    }

    private function existingUser(string $login): void
    {
        $id = $this->count('SELECT COALESCE(MAX(id), 0) + 1 FROM system_users FOR UPDATE');
        $this->pdo->prepare("INSERT INTO system_users (id, name, login, email, active) VALUES (?, 'Já existe', ?, ?, 'Y')")
            ->execute([$id, $login, $login]);
    }

    private function countTenants(): int
    {
        return $this->count('SELECT COUNT(*) FROM tenant');
    }

    private function countEvents(): int
    {
        return $this->count('SELECT COUNT(*) FROM billing_event');
    }

    /** @param list<int|string|null> $params */
    private function count(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
