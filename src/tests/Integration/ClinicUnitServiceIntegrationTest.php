<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\QuotaExceededException;
use CentralVet\Billing\QuotaGuard;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionAccessGuard;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Persistence\TenantUsageReader;
use CentralVet\Tenancy\ClinicUnitService;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * ClinicUnitService (Etapa 3, T-25) contra o centralvet_test com a 0017.
 * Fixture: tenant A (Starter, assinatura active, 1 unidade, 1 administrador)
 * e tenant B (Business, 1 unidade, 1 usuário). O TransactionRunner usa
 * SAVEPOINT dentro da transação do teste, desfeita no tearDown().
 */
final class ClinicUnitServiceIntegrationTest extends MysqlIntegrationTestCase
{
    private DateTimeImmutable $now;

    /** @var array{tenant: int, unit: int, user: int} */
    private array $a;

    /** @var array{tenant: int, unit: int, user: int} */
    private array $b;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'subscription'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0017 must be applied to the test database');

        $this->now = new DateTimeImmutable('2031-01-10 12:00:00');
        $this->a = $this->clinic('starter', 'A');
        $this->b = $this->clinic('business', 'B');
    }

    public function testListUnitsOfANeverBringsUnitsOfB(): void
    {
        $units = $this->service($this->a)->listUnits();

        Assert::same([['id' => $this->a['unit'], 'name' => 'Unidade A', 'users' => 1]], $units);
        Assert::false(in_array($this->b['unit'], array_column($units, 'id'), true), 'unit of B must not be listed for A');
    }

    public function testRenameOfUnitOfBIsRefusedWithoutWrites(): void
    {
        $this->expect(TenantBoundaryViolation::class, 'Resource does not belong to the authenticated tenant',
            fn () => $this->service($this->a)->rename($this->b['unit'], 'Invasora'));

        Assert::same('Unidade B', $this->value('SELECT name FROM system_unit WHERE id = ?', [$this->b['unit']]));
    }

    public function testRenameWithinTheClinicTrimsTheName(): void
    {
        $this->service($this->a)->rename($this->a['unit'], '  Matriz  ');

        Assert::same('Matriz', $this->value('SELECT name FROM system_unit WHERE id = ?', [$this->a['unit']]));
        $this->expect(\InvalidArgumentException::class, 'Unit name is required',
            fn () => $this->service($this->a)->rename($this->a['unit'], '   '));
        Assert::same('Matriz', $this->value('SELECT name FROM system_unit WHERE id = ?', [$this->a['unit']]));
    }

    public function testCreateInStarterHitsTheUnitQuotaWithoutWriting(): void
    {
        $before = $this->count('SELECT COUNT(*) FROM system_unit');

        $this->expect(QuotaExceededException::class, 'Your plan allows up to 1 units',
            fn () => $this->service($this->a)->create('Filial', $this->now));

        Assert::same($before, $this->count('SELECT COUNT(*) FROM system_unit'));
    }

    public function testCreateInBusinessStoresTheTenantOfTheSession(): void
    {
        $id = $this->service($this->b)->create('  Filial Sul  ', $this->now);

        Assert::same(1, $this->count('SELECT COUNT(*) FROM system_unit WHERE id = ? AND tenant_id = ? AND name = ?', [$id, $this->b['tenant'], 'Filial Sul']));
        $listedB = array_column($this->service($this->b)->listUnits(), 'name', 'id');
        Assert::same('Filial Sul', $listedB[$id] ?? null);
        Assert::same(['Filial Sul', 'Unidade B'], array_values($listedB), 'units ordered by name');
        Assert::false(in_array($id, array_column($this->service($this->a)->listUnits(), 'id'), true), 'new unit of B must not be listed for A');
    }

    public function testCreateWithEmptyOrLongNameIsRefused(): void
    {
        $before = $this->count('SELECT COUNT(*) FROM system_unit');

        $this->expect(\InvalidArgumentException::class, 'Unit name is required',
            fn () => $this->service($this->b)->create('   ', $this->now));
        $this->expect(\InvalidArgumentException::class, 'Unit name must have at most 120 characters',
            fn () => $this->service($this->b)->create(str_repeat('á', 121), $this->now));

        Assert::same($before, $this->count('SELECT COUNT(*) FROM system_unit'));
    }

    public function testCreateWithSuspendedSubscriptionIsRefused(): void
    {
        $this->pdo->prepare("UPDATE subscription SET status = 'suspended' WHERE tenant_id = ?")->execute([$this->b['tenant']]);
        $before = $this->count('SELECT COUNT(*) FROM system_unit');

        $this->expect(\RuntimeException::class, 'Your subscription is suspended. Regularize the payment to continue',
            fn () => $this->service($this->b)->create('Filial', $this->now));

        Assert::same($before, $this->count('SELECT COUNT(*) FROM system_unit'));
    }

    /** @param array{tenant: int, unit: int, user: int} $clinic */
    private function service(array $clinic): ClinicUnitService
    {
        $subscriptions = new SubscriptionRepository($this->pdo);

        return new ClinicUnitService(
            $this->pdo,
            new TransactionRunner($this->pdo),
            TenantContext::authenticated($clinic['tenant'], $clinic['user'], $clinic['unit']),
            new QuotaGuard($subscriptions, new TenantUsageReader($this->pdo)),
            new SubscriptionAccessGuard($subscriptions),
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
            ->execute([$publicId, 't25-' . strtolower($label) . '-' . $suffix, 'Clínica ' . $label]);
        $tenantId = (int) $this->pdo->lastInsertId();

        $unitId = $this->nextId('system_unit');
        $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (?, ?, ?)')
            ->execute([$unitId, $tenantId, 'Unidade ' . $label]);

        $login = 't25-' . strtolower($label) . '-' . $suffix . '@example.test';
        $userId = $this->nextId('system_users');
        $this->pdo->prepare("INSERT INTO system_users (id, name, login, email, password, active, system_unit_id) VALUES (?, ?, ?, ?, 'x', 'Y', ?)")
            ->execute([$userId, 'Admin ' . $label, $login, $login, $unitId]);
        $this->pdo->prepare('INSERT INTO system_user_unit (id, system_user_id, system_unit_id) VALUES (?, ?, ?)')
            ->execute([$this->nextId('system_user_unit'), $userId, $unitId]);
        $this->pdo->prepare("INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (?, ?, 'active')")
            ->execute([$tenantId, $userId]);

        $orders = new BillingOrderRepository($this->pdo);
        $order = BillingOrder::place($publicId, $planId, BillingOrder::METHOD_CARD, 'Admin ' . $label, $login, '11999990000',
            'Clínica ' . $label, '2026-10', new DateTimeImmutable('2031-01-01 11:00:00'), '127.0.0.1', false);
        $order->markPaid('sub_t25_' . $suffix, new DateTimeImmutable('2031-01-01 11:30:00'));
        $orderId = $orders->insert($order);

        $subscription = Subscription::start($orderId, $planId, Subscription::METHOD_CARD, 'cus_t25_' . $suffix, 'sub_t25_' . $suffix,
            'active', new DateTimeImmutable('2031-01-01 00:00:00'), new DateTimeImmutable('2031-02-01 00:00:00'), 100);
        $subscription->assignTenant($tenantId);
        (new SubscriptionRepository($this->pdo))->insert($subscription);

        return ['tenant' => $tenantId, 'unit' => $unitId, 'user' => $userId];
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
