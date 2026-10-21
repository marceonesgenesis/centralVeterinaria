<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\StockService;
use CentralVet\Domain\Exception\InsufficientStockException;
use CentralVet\Domain\StockBatch;
use CentralVet\Domain\StockMovement;
use CentralVet\Persistence\StockBatchRepository;
use CentralVet\Persistence\StockMovementRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\TestDatabase;
use DateTimeImmutable;

/**
 * F2 (Etapa 2): stock consumption under concurrency against the real MySQL
 * schema. Connection A is $this->pdo (test transaction, rolled back in
 * tearDown()); connection B is a second session with
 * innodb_lock_wait_timeout = 1 that is always rolled back in a finally
 * block. Neither connection ever commits.
 */
final class StockConcurrencyIntegrationTest extends MysqlIntegrationTestCase
{
    private const SALE_REFERENCE = 'sale';

    private int $userId;
    private int $unitId;
    private int $tenantId;
    private int $productId;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $slug = 'e2-stock-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        )->execute(['slug' => $slug, 'legal_name' => 'E2 teste tenant (' . $slug . ')', 'status' => 'active']);
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product (tenant_id, name, category, unit_of_measure, unit_cost_cents, minimum_stock_quantity) '
            . 'VALUES (:t, :n, :c, :u, 100, 0)',
        )->execute(['t' => $this->tenantId, 'n' => 'E2 teste Ração', 'c' => 'material', 'u' => 'un']);
        $this->productId = (int) $this->pdo->lastInsertId();
    }

    public function testSecondConcurrentConsumeOfTheLastUnitWaitsAndFailsWithLockTimeout(): void
    {
        $batch = $this->createBatch(1);

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        try {
            // B's first consistent read fixes its snapshot before A's sale.
            $other->query('SELECT COUNT(*) FROM stock_batch')->fetchColumn();

            // A sells the last unit and does not commit.
            $this->serviceOn($this->pdo)->consume(
                $this->tenantId,
                $this->unitId,
                $this->productId,
                1,
                StockMovement::REASON_SALE_CONSUMPTION,
                self::SALE_REFERENCE,
                910001,
                $this->userId,
            );

            $outcome = 'no exception';
            $lockWait = false;

            try {
                $this->serviceOn($other)->consume(
                    $this->tenantId,
                    $this->unitId,
                    $this->productId,
                    1,
                    StockMovement::REASON_SALE_CONSUMPTION,
                    self::SALE_REFERENCE,
                    910002,
                    $this->userId,
                );
            } catch (\PDOException $e) {
                $lockWait = (int) ($e->errorInfo[1] ?? 0) === 1205;
                $outcome = 'PDOException ' . ($e->errorInfo[1] ?? '?');
            } catch (InsufficientStockException $e) {
                $outcome = 'InsufficientStockException (read without lock)';
            }

            Assert::true($lockWait, "The second sale must wait for the pending one and time out with 1205 (got: {$outcome})");

            $count = $other->prepare('SELECT COUNT(*) FROM stock_movement WHERE reference_type = :t AND reference_id = :id');
            $count->execute(['t' => self::SALE_REFERENCE, 'id' => 910002]);
            Assert::same(0, (int) $count->fetchColumn(), 'The second sale must not write any stock_movement');
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }

        Assert::same(0, $this->quantityOf((int) $batch->id()), 'Only the first sale took the last unit');
    }

    public function testLockByProductOutsideATransactionThrowsLogicException(): void
    {
        $other = $this->secondConnection();
        $repository = new StockBatchRepository($this->context(), $other);

        $caught = null;

        try {
            $repository->lockByProductOrderedByExpiry($this->productId, $this->unitId);
        } catch (\LogicException $e) {
            $caught = $e;
        }

        Assert::notNull($caught, 'lockByProductOrderedByExpiry() must refuse to run outside a transaction');
        Assert::stringContains('requires an open transaction', $caught->getMessage());
    }

    public function testLockByProductReturnsTheSameOrderAsTheListing(): void
    {
        $this->createBatch(3, '2031-06-01');
        $this->createBatch(2, null);
        $this->createBatch(4, '2031-01-01');

        $repository = new StockBatchRepository($this->context(), $this->pdo);
        $ids = static fn (array $batches): array => array_map(static fn (StockBatch $b): int => (int) $b->id(), $batches);

        Assert::same(
            $ids($repository->listByProductOrderedByExpiry($this->productId, $this->unitId)),
            $ids($repository->lockByProductOrderedByExpiry($this->productId, $this->unitId)),
        );
    }

    public function testDecreaseQuantityRefusesToGoBelowZero(): void
    {
        $batch = $this->createBatch(1);
        $repository = new StockBatchRepository($this->context(), $this->pdo);

        Assert::false($repository->decreaseQuantity((int) $batch->id(), 2), 'Decreasing 2 from a batch with 1 must be refused');
        Assert::same(1, $this->quantityOf((int) $batch->id()));

        Assert::true($repository->decreaseQuantity((int) $batch->id(), 1));
        Assert::same(0, $this->quantityOf((int) $batch->id()));

        $repository->increaseQuantity((int) $batch->id(), 5);
        Assert::same(5, $this->quantityOf((int) $batch->id()));
    }

    public function testDecreaseQuantityIgnoresBatchesOfAnotherTenant(): void
    {
        $batch = $this->createBatch(3);
        $repository = new StockBatchRepository(TenantContext::authenticated($this->tenantId + 100000, $this->userId, $this->unitId), $this->pdo);

        Assert::false($repository->decreaseQuantity((int) $batch->id(), 1));
        $repository->increaseQuantity((int) $batch->id(), 1);
        Assert::same(3, $this->quantityOf((int) $batch->id()));
    }

    public function testReverseReferenceConsumptionRestoresStockOnceWithSaleCancellationMovements(): void
    {
        $first = $this->createBatch(2, '2031-01-01');
        $second = $this->createBatch(5, '2031-06-01');
        $service = $this->serviceOn($this->pdo);

        $service->consume(
            $this->tenantId,
            $this->unitId,
            $this->productId,
            4,
            StockMovement::REASON_SALE_CONSUMPTION,
            self::SALE_REFERENCE,
            910010,
            $this->userId,
        );
        Assert::same(0, $this->quantityOf((int) $first->id()));
        Assert::same(3, $this->quantityOf((int) $second->id()));

        $restored = $service->reverseReferenceConsumption(
            $this->tenantId,
            self::SALE_REFERENCE,
            910010,
            StockMovement::REASON_SALE_CONSUMPTION,
            StockMovement::REASON_SALE_CANCELLATION,
            $this->userId,
        );

        Assert::same(4, $restored);
        Assert::same(2, $this->quantityOf((int) $first->id()));
        Assert::same(5, $this->quantityOf((int) $second->id()));

        $movements = (new StockMovementRepository($this->context(), $this->pdo))->listByReference(self::SALE_REFERENCE, 910010);
        Assert::count(4, $movements);
        $reversals = array_values(array_filter(
            $movements,
            static fn (StockMovement $m): bool => $m->reason() === StockMovement::REASON_SALE_CANCELLATION,
        ));
        Assert::count(2, $reversals);
        Assert::same(StockMovement::TYPE_IN, $reversals[0]->movementType());
        Assert::same((int) $first->id(), $reversals[0]->stockBatchId());
        Assert::same(2, $reversals[0]->quantity());
        Assert::same((int) $second->id(), $reversals[1]->stockBatchId());
        Assert::same(2, $reversals[1]->quantity());

        $again = $service->reverseReferenceConsumption(
            $this->tenantId,
            self::SALE_REFERENCE,
            910010,
            StockMovement::REASON_SALE_CONSUMPTION,
            StockMovement::REASON_SALE_CANCELLATION,
            $this->userId,
        );

        Assert::same(0, $again, 'A second reversal of the same reference restores nothing');
        Assert::same(5, $this->quantityOf((int) $second->id()));
        Assert::count(4, (new StockMovementRepository($this->context(), $this->pdo))->listByReference(self::SALE_REFERENCE, 910010));
    }

    private function serviceOn(\PDO $connection): StockService
    {
        $context = $this->context();

        return new StockService(
            new StockBatchRepository($context, $connection),
            new StockMovementRepository($context, $connection),
            new FakeAuthorizationPolicy(allowed: true),
            $context,
        );
    }

    private function createBatch(int $quantity, ?string $expiry = '2031-01-01'): StockBatch
    {
        $batch = StockBatch::receive(
            tenantId: $this->tenantId,
            systemUnitId: $this->unitId,
            productId: $this->productId,
            lot: 'E2-' . bin2hex(random_bytes(3)),
            expiryDate: $expiry !== null ? new DateTimeImmutable($expiry) : null,
            quantity: $quantity,
            receivedAt: new DateTimeImmutable('2030-12-01 10:00:00'),
        );

        /** @var StockBatch $saved */
        $saved = (new StockBatchRepository($this->context(), $this->pdo))->save($batch);

        return $saved;
    }

    private function quantityOf(int $batchId): int
    {
        $statement = $this->pdo->prepare('SELECT quantity FROM stock_batch WHERE id = :id');
        $statement->execute(['id' => $batchId]);

        return (int) $statement->fetchColumn();
    }

    private function context(): TenantContext
    {
        return TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId);
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
