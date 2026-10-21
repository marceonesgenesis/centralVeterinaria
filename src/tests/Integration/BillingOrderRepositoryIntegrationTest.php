<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\BillingOrder;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\TestDatabase;
use DateTimeImmutable;
use LogicException;

/**
 * BillingOrderRepository (T-09) against `billing_order` (migration 0017) in
 * the real MySQL test database. Everything runs inside the rolled-back test
 * transaction; the lock test opens a second connection (lock wait timeout
 * 1 s) that only reads and is always rolled back.
 *
 * The lock proof: B cannot see A's uncommitted order, so a plain read in B
 * would answer "not found"; only the FOR UPDATE read waits on A's row lock
 * and ends in 1205, raised inside findByCheckoutSessionIdForUpdate().
 */
final class BillingOrderRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private BillingOrderRepository $orders;

    public function setUp(): void
    {
        parent::setUp();

        $billingTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'billing_order'")->fetchColumn();
        Assert::true($billingTable, 'Migration 0017 (billing_order) must be applied to the test database');

        $this->orders = new BillingOrderRepository($this->pdo);
    }

    public function testInsertAndFindByPublicIdRoundTripEveryColumn(): void
    {
        $tenantId = (int) $this->pdo->query('SELECT MIN(id) FROM tenant')->fetchColumn();
        Assert::true($tenantId > 0, 'Fixture requires at least one tenant row');

        $order = $this->place('pix', 'business');
        $order->attachCheckout('cs_test_' . bin2hex(random_bytes(8)), 'cus_test_' . bin2hex(random_bytes(4)));
        $order->markPaid('sub_test_rt', new DateTimeImmutable('2026-10-21 10:11:12.345678'));
        $order->markProvisioningFailed('tenant_insert');
        $order->markProvisioned($tenantId);
        $order->markInviteSent(new DateTimeImmutable('2026-10-21 11:00:00.000001'));

        $id = $this->orders->insert($order);
        Assert::true($id > 0, 'insert() returns the new id');

        $found = $this->orders->findByPublicId($order->publicId());
        Assert::notNull($found, 'Order is found by public id');

        $expected = $order->withId($id)->toRow();
        Assert::same($expected, $found->toRow(), 'Every column survives the round trip');
        Assert::same('2026-10-21 10:11:12.345678', $found->toRow()['paid_at']);
        Assert::same(1, $found->provisioningAttempts());
        Assert::null($this->orders->findByPublicId('00000000-0000-4000-8000-000000000000'));
    }

    public function testSavePersistsTheMutableColumns(): void
    {
        $order = $this->place('card', 'pro');
        $id = $this->orders->insert($order);
        $stored = $this->orders->findByIdForUpdate($id);
        Assert::notNull($stored);

        $stored->attachCheckout('cs_test_' . bin2hex(random_bytes(8)), 'cus_test_save');
        $stored->markPaid('sub_test_save', new DateTimeImmutable('2026-10-21 12:00:00.5'));
        $this->orders->save($stored);

        $reloaded = $this->orders->findByIdForUpdate($id);
        Assert::same($stored->toRow(), $reloaded?->toRow(), 'save() writes status and Stripe references');
        Assert::same(BillingOrder::STATUS_PAID, $reloaded?->status());

        $session = $stored->stripeCheckoutSessionId();
        Assert::same($id, $this->orders->findByCheckoutSessionIdForUpdate((string) $session)?->id());
        Assert::null($this->orders->findByCheckoutSessionIdForUpdate('cs_test_missing'));
    }

    public function testFindByIdForUpdateRequiresAnOpenTransaction(): void
    {
        $other = $this->secondConnection();
        Assert::throws(LogicException::class, fn () => (new BillingOrderRepository($other))->findByIdForUpdate(1));
    }

    public function testCheckoutSessionLockMakesTheSecondConnectionWait(): void
    {
        $order = $this->place('card', 'starter');
        $session = 'cs_test_lock_' . bin2hex(random_bytes(8));
        $order->attachCheckout($session, 'cus_test_lock');
        $id = $this->orders->insert($order);

        Assert::same($id, $this->orders->findByCheckoutSessionIdForUpdate($session)?->id(), 'A holds the order');

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        $errorCode = 0;
        $answer = 'no exception';
        $inLockingRead = false;

        try {
            $found = (new BillingOrderRepository($other))->findByCheckoutSessionIdForUpdate($session);
            $answer = $found === null ? 'not found' : 'found';
        } catch (\PDOException $e) {
            $errorCode = (int) ($e->errorInfo[1] ?? 0);
            $answer = $e->getMessage();

            foreach ($e->getTrace() as $frame) {
                if (($frame['class'] ?? '') === BillingOrderRepository::class
                    && ($frame['function'] ?? '') === 'findByCheckoutSessionIdForUpdate') {
                    $inLockingRead = true;
                }
            }
        } finally {
            $other->rollBack();
        }

        Assert::same(1205, $errorCode, "B must end in lock wait timeout (answered: {$answer})");
        Assert::true($inLockingRead, 'B waits inside findByCheckoutSessionIdForUpdate()');
    }

    public function testListPendingFulfillmentSelectsOnlyOrdersThatStillNeedWork(): void
    {
        $tenantId = (int) $this->pdo->query('SELECT MIN(id) FROM tenant')->fetchColumn();

        $paid = $this->paid();
        $paidId = $this->orders->insert($paid);

        $retry = $this->paid();
        $retry->markProvisioningFailed('boom');
        $retryId = $this->orders->insert($retry);

        $exhausted = $this->paid();
        for ($i = 0; $i < 10; $i++) {
            $exhausted->markProvisioningFailed('boom');
        }
        $exhaustedId = $this->orders->insert($exhausted);

        $noInvite = $this->paid();
        $noInvite->markProvisioned($tenantId);
        $noInviteId = $this->orders->insert($noInvite);

        $done = $this->paid();
        $done->markProvisioned($tenantId);
        $done->markInviteSent(new DateTimeImmutable());
        $doneId = $this->orders->insert($done);

        $pendingId = $this->orders->insert($this->place('card', 'pro'));

        $ids = $this->orders->listPendingFulfillment(100000);

        foreach ($ids as $listed) {
            Assert::true(is_int($listed), 'ids are ints');
        }
        $attempts = [];
        foreach ($ids as $listed) {
            $attempts[] = [(int) $this->pdo->query('SELECT provisioning_attempts FROM billing_order WHERE id = ' . $listed)->fetchColumn(), $listed];
        }
        $sorted = $attempts;
        sort($sorted);
        Assert::same($sorted, $attempts, 'ids come ordered by provisioning_attempts, then id');
        Assert::true(array_search($paidId, $ids, true) < array_search($retryId, $ids, true), 'a new paid order comes before a retried one');

        Assert::true(in_array($paidId, $ids, true), 'paid order is listed');
        Assert::true(in_array($retryId, $ids, true), 'provisioning_failed below 10 attempts is listed');
        Assert::true(in_array($noInviteId, $ids, true), 'provisioned without invite is listed');
        Assert::false(in_array($exhaustedId, $ids, true), 'provisioning_failed with 10 attempts is not listed');
        Assert::false(in_array($doneId, $ids, true), 'provisioned with invite sent is not listed');
        Assert::false(in_array($pendingId, $ids, true), 'pending order is not listed');

        Assert::count(1, $this->orders->listPendingFulfillment(1), 'limit is honoured');
    }

    private function paid(): BillingOrder
    {
        $order = $this->place('card', 'pro');
        $order->attachCheckout('cs_test_' . bin2hex(random_bytes(8)), 'cus_test_paid');
        $order->markPaid('sub_test_' . bin2hex(random_bytes(4)), new DateTimeImmutable());

        return $order;
    }

    private function place(string $method, string $plan): BillingOrder
    {
        $hex = bin2hex(random_bytes(16));
        $uuid = sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));

        return BillingOrder::place(
            $uuid,
            $plan,
            $method,
            'Responsável Teste',
            'resp-' . substr($hex, 0, 6) . '@example.com',
            '+55 11 90000-0000',
            'Clínica Teste T-09',
            '2026-10',
            new DateTimeImmutable('2026-10-21 09:08:07.654321'),
            '203.0.113.9',
            true,
        );
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
