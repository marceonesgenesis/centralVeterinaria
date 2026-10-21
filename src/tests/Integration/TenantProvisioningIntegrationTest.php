<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\TenantProvisioningService;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * TenantProvisioningService (T-13) contra o centralvet_test com a 0017:
 * criação, idempotência, retomada depois de falha num passo, conflito de
 * login e convite reenviado. O TransactionRunner usa SAVEPOINT dentro da
 * transação do teste, desfeita no tearDown() — nada é commitado.
 */
final class TenantProvisioningIntegrationTest extends MysqlIntegrationTestCase
{
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';

    private BillingOrderRepository $orders;
    private SubscriptionRepository $subscriptions;
    private FakeEmailProvider $email;
    private DateTimeImmutable $now;
    private ?string $failAt = null;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'billing_order'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0017 must be applied to the test database');

        $this->orders = new BillingOrderRepository($this->pdo);
        $this->subscriptions = new SubscriptionRepository($this->pdo);
        $this->email = new FakeEmailProvider();
        $this->now = new DateTimeImmutable('2031-01-01 12:00:00');
        $this->failAt = null;
        $this->createPlanGroups();
    }

    public function testFulfillCreatesTenantOnceAndSecondCallIsAlreadyProvisioned(): void
    {
        [$orderId, $publicId, $email] = $this->paidOrder('starter');

        $result = $this->service()->fulfill($orderId, $this->now);

        Assert::same('provisioned', $result->status());
        Assert::notNull($result->tenantId());
        $this->assertProvisionedOnce($orderId, $publicId, $email, 'starter');
        Assert::count(1, $this->email->deliveries(), 'one invite must be sent');
        Assert::same($email, $this->email->deliveries()[0]->recipient);

        $order = $this->orders->findByIdForUpdate($orderId);
        Assert::same(BillingOrder::STATUS_PROVISIONED, $order?->status());
        Assert::same($result->tenantId(), $order?->tenantId());
        Assert::notNull($order?->inviteSentAt());

        $again = $this->service()->fulfill($orderId, $this->now);

        Assert::same('already_provisioned', $again->status());
        Assert::same($result->tenantId(), $again->tenantId());
        $this->assertProvisionedOnce($orderId, $publicId, $email, 'starter');
        Assert::count(1, $this->email->deliveries(), 'the invite must not be sent again');
    }

    public function testFailureInMembershipsRollsBackAndNextCallCompletes(): void
    {
        [$orderId, $publicId, $email] = $this->paidOrder('pro');
        $tenantsBefore = $this->countTenants();

        $this->failAt = 'memberships';
        $failed = $this->service()->fulfill($orderId, $this->now);

        Assert::same('failed', $failed->status());
        $order = $this->orders->findByIdForUpdate($orderId);
        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $order?->status());
        Assert::same(1, $order?->provisioningAttempts());
        Assert::same($tenantsBefore, $this->countTenants(), 'no tenant may survive the rollback');
        Assert::same(0, $this->count('SELECT COUNT(*) FROM system_users WHERE login = ?', [$email]));
        Assert::count(0, $this->email->deliveries());

        $this->failAt = null;
        $result = $this->service()->fulfill($orderId, $this->now);

        Assert::same('provisioned', $result->status());
        Assert::same($tenantsBefore + 1, $this->countTenants());
        $this->assertProvisionedOnce($orderId, $publicId, $email, 'pro');
        Assert::count(1, $this->email->deliveries());
    }

    public function testExistingLoginFailsWithLoginConflictAndCountsEachAttempt(): void
    {
        [$orderId, , $email] = $this->paidOrder('business');
        $this->existingUser($email);
        $tenantsBefore = $this->countTenants();

        $first = $this->service()->fulfill($orderId, $this->now);
        $second = $this->service()->fulfill($orderId, $this->now);

        Assert::same('failed', $first->status());
        Assert::same('failed', $second->status());
        Assert::null($second->tenantId());
        $order = $this->orders->findByIdForUpdate($orderId);
        Assert::same(BillingOrder::STATUS_PROVISIONING_FAILED, $order?->status());
        Assert::same('login_conflict', $order?->provisioningError());
        Assert::same(2, $order?->provisioningAttempts());
        Assert::same($tenantsBefore, $this->countTenants(), 'login conflict must not create a tenant');
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_users WHERE login = ?', [$email]));
        Assert::count(0, $this->email->deliveries());
    }

    public function testInviteFailureKeepsOrderProvisionedAndNextCallOnlySendsInvite(): void
    {
        [$orderId, $publicId, $email] = $this->paidOrder('enterprise');

        $this->failAt = 'invite';
        $result = $this->service()->fulfill($orderId, $this->now);

        Assert::same('provisioned', $result->status());
        $order = $this->orders->findByIdForUpdate($orderId);
        Assert::same(BillingOrder::STATUS_PROVISIONED, $order?->status());
        Assert::null($order?->inviteSentAt(), 'invite_sent_at must stay null when the invite fails');
        Assert::count(0, $this->email->deliveries());
        $this->assertProvisionedOnce($orderId, $publicId, $email, 'enterprise');

        $this->failAt = null;
        $retry = $this->service()->fulfill($orderId, $this->now);

        Assert::same('already_provisioned', $retry->status());
        Assert::same($result->tenantId(), $retry->tenantId());
        Assert::count(1, $this->email->deliveries(), 'the retry must send only the invite');
        Assert::notNull($this->orders->findByIdForUpdate($orderId)?->inviteSentAt());
        $this->assertProvisionedOnce($orderId, $publicId, $email, 'enterprise');
    }

    public function testSlugCollisionWithAnotherTenantStillProvisionsAndStaysIdempotent(): void
    {
        [$orderId, $publicId, $email] = $this->paidOrder('pro');
        $baseSlug = 'clinica-' . substr($publicId, 0, 8);
        $this->pdo->prepare("INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), ?, 'Outra clínica', 'active')")
            ->execute([$baseSlug]);
        $tenantsBefore = $this->countTenants();

        $result = $this->service()->fulfill($orderId, $this->now);

        Assert::same('provisioned', $result->status(), 'a slug collision must not block a paid order');
        Assert::same(BillingOrder::STATUS_PROVISIONED, $this->orders->findByIdForUpdate($orderId)?->status());
        Assert::same($tenantsBefore + 1, $this->countTenants());
        $statement = $this->pdo->prepare('SELECT slug FROM tenant WHERE id = ?');
        $statement->execute([$result->tenantId()]);
        $slug = (string) $statement->fetchColumn();
        Assert::true($slug !== $baseSlug && str_starts_with($slug, 'clinica-'), "new tenant needs its own slug (got {$slug})");
        $this->assertProvisionedOnce($orderId, $publicId, $email, 'pro');

        $again = $this->service()->fulfill($orderId, $this->now);

        Assert::same('already_provisioned', $again->status());
        Assert::same($result->tenantId(), $again->tenantId());
        Assert::same($tenantsBefore + 1, $this->countTenants());
        $this->assertProvisionedOnce($orderId, $publicId, $email, 'pro');
        Assert::count(1, $this->email->deliveries());
    }

    public function testOrderNotPaidIsNotProvisioned(): void
    {
        $order = $this->placeOrder('starter');
        $orderId = $this->orders->insert($order);
        $tenantsBefore = $this->countTenants();

        $result = $this->service()->fulfill($orderId, $this->now);

        Assert::same('not_paid', $result->status());
        Assert::null($result->tenantId());
        Assert::same($tenantsBefore, $this->countTenants());
        Assert::same(BillingOrder::STATUS_PENDING, $this->orders->findByIdForUpdate($orderId)?->status());
    }

    private function assertProvisionedOnce(int $orderId, string $publicId, string $email, string $planId): void
    {
        $tenantId = $this->count('SELECT id FROM tenant WHERE public_id = ?', [$publicId]);
        Assert::true($tenantId > 0, 'tenant must exist');
        Assert::same(1, $this->count('SELECT COUNT(*) FROM tenant WHERE public_id = ?', [$publicId]));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM tenant WHERE slug = ?', ['clinica-' . substr($publicId, 0, 8)]));
        Assert::same(1, $this->count(
            'SELECT COUNT(*) FROM system_unit WHERE tenant_id = ? AND custom_code = ?',
            [$tenantId, 'order:' . $publicId],
        ));
        Assert::same(1, $this->count("SELECT COUNT(*) FROM system_users WHERE login = ? AND email = ? AND active = 'Y'", [$email, $email]));
        $userId = $this->count('SELECT id FROM system_users WHERE login = ?', [$email]);
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_unit WHERE system_user_id = ?', [$userId]));
        Assert::same(1, $this->count(
            "SELECT COUNT(*) FROM tenant_user WHERE tenant_id = ? AND system_user_id = ? AND status = 'invited'",
            [$tenantId, $userId],
        ));
        $groupName = TenantProvisioningService::GROUP_NAME_PREFIX . PlanEntitlements::forPlan($planId)->name();
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ?', [$userId]));
        Assert::same(1, $this->count(
            'SELECT COUNT(*) FROM system_user_group ug JOIN system_group g ON g.id = ug.system_group_id '
            . 'WHERE ug.system_user_id = ? AND g.name = ?',
            [$userId, $groupName],
        ));
        Assert::same(1, $this->count(
            'SELECT COUNT(*) FROM tenant_group tg JOIN system_group g ON g.id = tg.system_group_id '
            . 'WHERE tg.tenant_id = ? AND g.name = ?',
            [$tenantId, $groupName],
        ));
        Assert::same($tenantId, $this->subscriptions->findByBillingOrderId($orderId)?->tenantId());
    }

    private function service(): TenantProvisioningService
    {
        $translate = static fn (string $key, string ...$params): string => '[' . implode('|', [$key, ...$params]) . ']';
        $invites = new AccountInviteService(
            new PasswordResetToken(self::KEY),
            new PublicUrl('https://app.test'),
            $this->email,
            \Closure::fromCallable($translate),
        );
        $fault = function (string $step): void {
            if ($step === $this->failAt) {
                throw new \RuntimeException('injected failure at ' . $step);
            }
        };

        return new TenantProvisioningService(
            $this->pdo,
            new TransactionRunner($this->pdo),
            $this->orders,
            $this->subscriptions,
            $invites,
            $fault,
        );
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function paidOrder(string $planId): array
    {
        $order = $this->placeOrder($planId);
        $order->markPaid('sub_t13_' . bin2hex(random_bytes(6)), $this->now);
        $orderId = $this->orders->insert($order);

        $this->subscriptions->insert(Subscription::start(
            $orderId,
            $planId,
            Subscription::METHOD_CARD,
            'cus_t13_' . bin2hex(random_bytes(6)),
            $order->stripeSubscriptionId(),
            'active',
            new DateTimeImmutable('2031-01-01 00:00:00'),
            new DateTimeImmutable('2031-02-01 00:00:00'),
            100,
        ));

        return [$orderId, $order->publicId(), $order->responsibleEmail()];
    }

    private function placeOrder(string $planId): BillingOrder
    {
        $suffix = bin2hex(random_bytes(5));

        return BillingOrder::place(
            $this->uuid(),
            $planId,
            BillingOrder::METHOD_CARD,
            'Dra. T-13 ' . $suffix,
            't13-' . $suffix . '@example.test',
            '11999990000',
            'Clínica T-13 ' . $suffix,
            '2026-10',
            new DateTimeImmutable('2031-01-01 11:00:00'),
            '127.0.0.1',
            false,
        );
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

    /** @param list<int|string> $params */
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
