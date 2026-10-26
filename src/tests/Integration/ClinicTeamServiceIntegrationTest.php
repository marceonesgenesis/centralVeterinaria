<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Billing\QuotaExceededException;
use CentralVet\Billing\QuotaGuard;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionAccessGuard;
use CentralVet\Billing\TenantProvisioningService;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Persistence\TenantUsageReader;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Tenancy\ClinicRoleProfiles;
use CentralVet\Tenancy\ClinicTeamService;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * ClinicTeamService (Etapa 3, T-24) contra o centralvet_test com a 0017.
 * Fixture: os 4 grupos de plano, o tenant A (Starter, assinatura active,
 * 1 administrador) e o tenant B (Pro, 1 usuário), cada um com a própria
 * unidade e o próprio grupo em tenant_group. A sessão é sempre a do
 * administrador de A. O TransactionRunner usa SAVEPOINT dentro da
 * transação do teste, desfeita no tearDown() — nada é commitado.
 * Etapa 5, T-12: os 3 grupos globais de perfil (ClinicRoleProfiles) também
 * entram na fixture, pelo nome exato, se ainda não existirem.
 */
final class ClinicTeamServiceIntegrationTest extends MysqlIntegrationTestCase
{
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';

    private FakeEmailProvider $email;
    private DateTimeImmutable $now;

    /** @var array<string, int> */
    private array $groups = [];

    /** @var array<string, int> papel → id do grupo de perfil */
    private array $profiles = [];

    /** @var array{tenant: int, unit: int, user: int} */
    private array $a;

    /** @var array{tenant: int, unit: int, user: int} */
    private array $b;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'subscription'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0017 must be applied to the test database');

        $this->email = new FakeEmailProvider();
        $this->now = new DateTimeImmutable('2031-01-10 12:00:00');
        $this->createPlanGroups();
        $this->createProfileGroups();
        $this->a = $this->clinic('starter', 'A');
        $this->b = $this->clinic('pro', 'B');
    }

    public function testListUsersOfANeverBringsUsersOfB(): void
    {
        $users = $this->service()->listUsers();

        $ids = array_column($users, 'id');
        Assert::same([$this->a['user']], $ids, 'A lists only its own users');
        Assert::same('active', $users[0]['status']);
        Assert::same('Central Vet - Plano Starter', $users[0]['group']);
        Assert::same($this->a['unit'], $users[0]['unit_id']);
        Assert::false(in_array($this->b['user'], $ids, true), 'user of B must not be listed for A');
    }

    public function testUpdateDeactivateAndReactivateOfUserOfBAreRefusedWithoutWrites(): void
    {
        $before = $this->snapshot($this->b['user']);
        $service = $this->service();

        $this->expect(TenantBoundaryViolation::class, 'Resource does not belong to the authenticated tenant',
            fn () => $service->update($this->b['user'], 'Invasor', $this->groups['starter'], $this->a['unit']));
        $this->expect(TenantBoundaryViolation::class, 'Resource does not belong to the authenticated tenant',
            fn () => $service->deactivate($this->b['user']));
        $this->expect(TenantBoundaryViolation::class, 'Resource does not belong to the authenticated tenant',
            fn () => $service->reactivate($this->b['user'], $this->now));

        Assert::same($before, $this->snapshot($this->b['user']), 'system_users/tenant_user of B must stay the same');
    }

    public function testAllowedGroupsOfAIsTheStarterGroupPlusTheThreeProfiles(): void
    {
        $expected = [$this->groups['starter'] => 'Central Vet - Plano Starter'];
        foreach (ClinicRoleProfiles::GROUP_NAMES as $role => $name) {
            $expected[$this->profiles[$role]] = $name;
        }
        ksort($expected);

        $allowed = $this->service()->allowedGroups();

        Assert::same($expected, $allowed, 'plan group of A plus the 3 profile groups, ordered by id');
        Assert::false(isset($allowed[1]), 'group 1 is never allowed');
        Assert::false(isset($allowed[$this->groups['pro']]), 'plan group of another tenant is not allowed');
    }

    public function testInviteWithProfileGroupStoresOnlyTheProfileAndUpdateSwitchesIt(): void
    {
        $service = $this->service();
        $userId = $service->invite('Recepção Teste', 'recepcao-' . bin2hex(random_bytes(4)) . '@example.test',
            $this->profiles[ClinicRoleProfiles::RECEPTION], $this->a['unit'], $this->now);

        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ?', [$userId]), 'only one group link');
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ? AND system_group_id = ?',
            [$userId, $this->profiles[ClinicRoleProfiles::RECEPTION]]));
        Assert::same(0, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ? AND system_group_id = ?',
            [$userId, $this->groups['starter']]), 'the plan group is not linked');

        $found = $service->findUser($userId);
        Assert::same('Central Vet - Perfil Recepção', $found['group']);
        Assert::same($this->profiles[ClinicRoleProfiles::RECEPTION], $found['group_id']);
        $listed = array_column($service->listUsers(), 'group', 'id');
        Assert::same('Central Vet - Perfil Recepção', $listed[$userId] ?? null);

        $service->update($userId, 'Recepção Teste', $this->profiles[ClinicRoleProfiles::FINANCE], $this->a['unit']);
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ?', [$userId]));
        Assert::same('Central Vet - Perfil Financeiro', $service->findUser($userId)['group']);

        $service->update($userId, 'Recepção Teste', $this->groups['starter'], $this->a['unit']);
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ?', [$userId]));
        Assert::same('Central Vet - Plano Starter', $service->findUser($userId)['group']);
    }

    public function testInviteWithPlatformGroupOrAnotherPlanGroupIsRefused(): void
    {
        $service = $this->service();
        $usersBefore = $this->count('SELECT COUNT(*) FROM system_users');

        $this->expect(TenantBoundaryViolation::class, 'Group not allowed for this clinic',
            fn () => $service->invite('Nova', $this->newEmail(), 1, $this->a['unit'], $this->now));
        $this->expect(TenantBoundaryViolation::class, 'Group not allowed for this clinic',
            fn () => $service->invite('Nova', $this->newEmail(), $this->groups['pro'], $this->a['unit'], $this->now));

        Assert::same($usersBefore, $this->count('SELECT COUNT(*) FROM system_users'));
        Assert::count(0, $this->email->deliveries());
    }

    public function testInviteWithUnitOfBIsRefused(): void
    {
        $usersBefore = $this->count('SELECT COUNT(*) FROM system_users');

        $this->expect(TenantBoundaryViolation::class, 'Resource does not belong to the authenticated tenant',
            fn () => $this->service()->invite('Nova', $this->newEmail(), $this->groups['starter'], $this->b['unit'], $this->now));

        Assert::same($usersBefore, $this->count('SELECT COUNT(*) FROM system_users'));
    }

    public function testValidInviteCreatesUserMembershipsAndSendsTheResetLink(): void
    {
        $email = $this->newEmail();

        $userId = $this->service()->invite('Dra. Nova', $email, $this->groups['starter'], $this->a['unit'], $this->now);

        Assert::same(1, $this->count("SELECT COUNT(*) FROM system_users WHERE id = ? AND login = ? AND email = ? AND active = 'Y' AND system_unit_id = ?", [$userId, $email, $email, $this->a['unit']]));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_unit WHERE system_user_id = ? AND system_unit_id = ?', [$userId, $this->a['unit']]));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_unit WHERE system_user_id = ?', [$userId]));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ? AND system_group_id = ?', [$userId, $this->groups['starter']]));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ?', [$userId]));
        Assert::same(1, $this->count("SELECT COUNT(*) FROM tenant_user WHERE tenant_id = ? AND system_user_id = ? AND status = 'invited'", [$this->a['tenant'], $userId]));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM tenant_user WHERE system_user_id = ?', [$userId]));
        $hash = (string) $this->value('SELECT password FROM system_users WHERE id = ?', [$userId]);
        Assert::true(password_get_info($hash)['algo'] !== null && $hash !== '', 'a random password hash must be stored');

        Assert::count(1, $this->email->deliveries());
        $message = $this->email->deliveries()[0];
        Assert::same($email, $message->recipient);
        Assert::stringContains('https://app.test/index.php?class=SystemPasswordResetForm&method=onLoad&jwt=', $message->body);
        Assert::stringContains('Clínica A', $message->body);

        $listed = array_column($this->service()->listUsers(), 'status', 'id');
        Assert::same('invited', $listed[$userId] ?? null);
    }

    public function testInviteWhenAAlreadyHasTwoUsersHitsTheQuotaWithoutWriting(): void
    {
        $this->service()->invite('Segunda', $this->newEmail(), $this->groups['starter'], $this->a['unit'], $this->now);
        $usersBefore = $this->count('SELECT COUNT(*) FROM system_users');
        $membersBefore = $this->count('SELECT COUNT(*) FROM tenant_user WHERE tenant_id = ?', [$this->a['tenant']]);

        $this->expect(QuotaExceededException::class, 'Your plan allows up to 2 users',
            fn () => $this->service()->invite('Terceira', $this->newEmail(), $this->groups['starter'], $this->a['unit'], $this->now));

        Assert::same($usersBefore, $this->count('SELECT COUNT(*) FROM system_users'));
        Assert::same($membersBefore, $this->count('SELECT COUNT(*) FROM tenant_user WHERE tenant_id = ?', [$this->a['tenant']]));
        Assert::count(1, $this->email->deliveries(), 'only the first invite is sent');
    }

    public function testInviteWithSuspendedSubscriptionIsRefused(): void
    {
        $this->pdo->prepare("UPDATE subscription SET status = 'suspended' WHERE tenant_id = ?")->execute([$this->a['tenant']]);
        $usersBefore = $this->count('SELECT COUNT(*) FROM system_users');

        $this->expect(\RuntimeException::class, 'Your subscription is suspended. Regularize the payment to continue',
            fn () => $this->service()->invite('Nova', $this->newEmail(), $this->groups['starter'], $this->a['unit'], $this->now));

        Assert::same($usersBefore, $this->count('SELECT COUNT(*) FROM system_users'));
        Assert::count(0, $this->email->deliveries());
    }

    public function testInviteWithExistingLoginOrInvalidEmailIsRefused(): void
    {
        $taken = (string) $this->value('SELECT login FROM system_users WHERE id = ?', [$this->b['user']]);

        $this->expect(\InvalidArgumentException::class, 'A user with this e-mail already exists',
            fn () => $this->service()->invite('Nova', $taken, $this->groups['starter'], $this->a['unit'], $this->now));
        $this->expect(\InvalidArgumentException::class, 'Invalid e-mail',
            fn () => $this->service()->invite('Nova', 'not-an-email', $this->groups['starter'], $this->a['unit'], $this->now));
    }

    public function testDeactivateOwnUserIsRefused(): void
    {
        $this->expect(\InvalidArgumentException::class, 'You cannot deactivate your own user',
            fn () => $this->service()->deactivate($this->a['user']));

        Assert::same('active', $this->value('SELECT status FROM tenant_user WHERE system_user_id = ?', [$this->a['user']]));
    }

    public function testUpdateDeactivateAndReactivateWithinTheClinic(): void
    {
        $service = $this->service();
        $userId = $service->invite('Antes', $this->newEmail(), $this->groups['starter'], $this->a['unit'], $this->now);
        $login = $this->value('SELECT login FROM system_users WHERE id = ?', [$userId]);

        $service->update($userId, 'Depois', $this->groups['starter'], $this->a['unit']);
        Assert::same('Depois', $this->value('SELECT name FROM system_users WHERE id = ?', [$userId]));
        Assert::same($login, $this->value('SELECT login FROM system_users WHERE id = ?', [$userId]), 'login never changes');
        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ?', [$userId]));

        $this->expect(TenantBoundaryViolation::class, 'Group not allowed for this clinic',
            fn () => $service->update($userId, 'Depois', 1, $this->a['unit']));
        Assert::same(0, $this->count('SELECT COUNT(*) FROM system_user_group WHERE system_user_id = ? AND system_group_id = 1', [$userId]));

        $service->deactivate($userId);
        Assert::same('inactive', $this->value('SELECT status FROM tenant_user WHERE system_user_id = ?', [$userId]));
        Assert::same('N', $this->value('SELECT active FROM system_users WHERE id = ?', [$userId]));

        $service->reactivate($userId, $this->now);
        Assert::same('active', $this->value('SELECT status FROM tenant_user WHERE system_user_id = ?', [$userId]));
        Assert::same('Y', $this->value('SELECT active FROM system_users WHERE id = ?', [$userId]));
    }

    public function testReactivateRespectsTheQuota(): void
    {
        $service = $this->service();
        $userId = $service->invite('Sai', $this->newEmail(), $this->groups['starter'], $this->a['unit'], $this->now);
        $service->deactivate($userId);
        $service->invite('Entra', $this->newEmail(), $this->groups['starter'], $this->a['unit'], $this->now);

        $this->expect(QuotaExceededException::class, 'Your plan allows up to 2 users',
            fn () => $service->reactivate($userId, $this->now));

        Assert::same('inactive', $this->value('SELECT status FROM tenant_user WHERE system_user_id = ?', [$userId]));
    }

    private function service(): ClinicTeamService
    {
        $subscriptions = new SubscriptionRepository($this->pdo);
        $translate = static fn (string $key, string ...$params): string => '[' . implode('|', [$key, ...$params]) . ']';

        return new ClinicTeamService(
            $this->pdo,
            new TransactionRunner($this->pdo),
            TenantContext::authenticated($this->a['tenant'], $this->a['user'], $this->a['unit']),
            $subscriptions,
            new QuotaGuard($subscriptions, new TenantUsageReader($this->pdo)),
            new SubscriptionAccessGuard($subscriptions),
            new AccountInviteService(
                new PasswordResetToken(self::KEY),
                new PublicUrl('https://app.test'),
                $this->email,
                \Closure::fromCallable($translate),
            ),
        );
    }

    /** @param class-string<\Throwable> $class */
    private function expect(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof AssertionFailedException) {
                throw $e;
            }
            Assert::true($e instanceof $class, sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()));
            Assert::same($message, $e->getMessage());

            return;
        }

        throw new AssertionFailedException("Expected {$class}('{$message}') was not thrown");
    }

    /** @return array{tenant: int, unit: int, user: int} */
    private function clinic(string $planId, string $label): array
    {
        $suffix = bin2hex(random_bytes(5));
        $publicId = $this->uuid();
        $this->pdo->prepare("INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (?, ?, ?, 'active')")
            ->execute([$publicId, 't24-' . strtolower($label) . '-' . $suffix, 'Clínica ' . $label]);
        $tenantId = (int) $this->pdo->lastInsertId();

        $unitId = $this->nextId('system_unit');
        $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (?, ?, ?)')
            ->execute([$unitId, $tenantId, 'Unidade ' . $label]);

        $login = 't24-' . strtolower($label) . '-' . $suffix . '@example.test';
        $userId = $this->nextId('system_users');
        $this->pdo->prepare("INSERT INTO system_users (id, name, login, email, password, active, system_unit_id) VALUES (?, ?, ?, ?, 'x', 'Y', ?)")
            ->execute([$userId, 'Admin ' . $label, $login, $login, $unitId]);
        $this->pdo->prepare('INSERT INTO system_user_unit (id, system_user_id, system_unit_id) VALUES (?, ?, ?)')
            ->execute([$this->nextId('system_user_unit'), $userId, $unitId]);
        $this->pdo->prepare('INSERT INTO system_user_group (id, system_user_id, system_group_id) VALUES (?, ?, ?)')
            ->execute([$this->nextId('system_user_group'), $userId, $this->groups[$planId]]);
        $this->pdo->prepare("INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (?, ?, 'active')")
            ->execute([$tenantId, $userId]);
        $this->pdo->prepare('INSERT INTO tenant_group (tenant_id, system_group_id) VALUES (?, ?)')
            ->execute([$tenantId, $this->groups[$planId]]);

        $orders = new BillingOrderRepository($this->pdo);
        $order = BillingOrder::place($publicId, $planId, BillingOrder::METHOD_CARD, 'Admin ' . $label, $login, '11999990000',
            'Clínica ' . $label, '2026-10', new DateTimeImmutable('2031-01-01 11:00:00'), '127.0.0.1', false);
        $order->markPaid('sub_t24_' . $suffix, new DateTimeImmutable('2031-01-01 11:30:00'));
        $orderId = $orders->insert($order);

        $subscription = Subscription::start($orderId, $planId, Subscription::METHOD_CARD, 'cus_t24_' . $suffix, 'sub_t24_' . $suffix,
            'active', new DateTimeImmutable('2031-01-01 00:00:00'), new DateTimeImmutable('2031-02-01 00:00:00'), 100);
        $subscription->assignTenant($tenantId);
        (new SubscriptionRepository($this->pdo))->insert($subscription);

        return ['tenant' => $tenantId, 'unit' => $unitId, 'user' => $userId];
    }

    private function createPlanGroups(): void
    {
        foreach (PlanEntitlements::PLAN_IDS as $planId) {
            $name = TenantProvisioningService::GROUP_NAME_PREFIX . PlanEntitlements::forPlan($planId)->name();
            $id = (int) $this->value('SELECT id FROM system_group WHERE name = ? ORDER BY id LIMIT 1', [$name]);
            if ($id === 0) {
                $id = $this->nextId('system_group');
                $this->pdo->prepare('INSERT INTO system_group (id, name) VALUES (?, ?)')->execute([$id, $name]);
            }
            $this->groups[$planId] = $id;
        }
    }

    private function createProfileGroups(): void
    {
        foreach (ClinicRoleProfiles::GROUP_NAMES as $role => $name) {
            $id = (int) $this->value('SELECT id FROM system_group WHERE name = ? AND id <> 1 ORDER BY id LIMIT 1', [$name]);
            if ($id === 0) {
                $id = $this->nextId('system_group');
                $this->pdo->prepare('INSERT INTO system_group (id, name) VALUES (?, ?)')->execute([$id, $name]);
            }
            $this->profiles[$role] = $id;
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.name, u.login, u.email, u.active, u.system_unit_id, tu.tenant_id, tu.status, '
            . '(SELECT GROUP_CONCAT(system_group_id ORDER BY system_group_id) FROM system_user_group WHERE system_user_id = u.id) AS user_groups, '
            . '(SELECT GROUP_CONCAT(system_unit_id ORDER BY system_unit_id) FROM system_user_unit WHERE system_user_id = u.id) AS user_units '
            . 'FROM system_users u JOIN tenant_user tu ON tu.system_user_id = u.id WHERE u.id = ?',
        );
        $statement->execute([$userId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC)[0] ?? [];
    }

    private function newEmail(): string
    {
        return 't24-novo-' . bin2hex(random_bytes(5)) . '@example.test';
    }

    private function nextId(string $table): int
    {
        return (int) $this->value(sprintf('SELECT COALESCE(MAX(id), 0) + 1 FROM %s FOR UPDATE', $table));
    }

    /** @param list<int|string> $params */
    private function count(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params);
    }

    /** @param list<int|string> $params */
    private function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
