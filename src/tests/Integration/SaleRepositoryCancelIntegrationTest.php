<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Sale;
use CentralVet\Persistence\SaleRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\TestDatabase;
use DateTimeImmutable;

/**
 * Etapa 2 / T-09: SaleRepository against the real `sale` table (0016).
 * A cancelled sale is saved and read back with cancelled_at,
 * cancelled_by_system_user_id and cancellation_reason filled;
 * findByIdForUpdate() holds the row (a second connection waits and ends in
 * 1205) and refuses to run outside a transaction.
 *
 * Everything lives in a throwaway tenant inside the test transaction and is
 * rolled back in tearDown(); the second connection never commits.
 */
final class SaleRepositoryCancelIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantId;
    private int $unitId;
    private int $tutorId;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $slug = 't09-sale-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, 'active')",
        )->execute(['slug' => $slug, 'legal_name' => 'T-09 test tenant (' . $slug . ')']);
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $this->unitId = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit')->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO system_unit (id, tenant_id, name, custom_code) VALUES (:id, :tenant_id, :name, :custom_code)',
        )->execute(['id' => $this->unitId, 'tenant_id' => $this->tenantId, 'name' => 'T-09 unit', 'custom_code' => 'T09-' . $this->unitId]);

        $this->pdo->prepare(
            'INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :full_name, :phone)',
        )->execute(['tenant_id' => $this->tenantId, 'full_name' => 'T-09 tutor', 'phone' => '11900000009']);
        $this->tutorId = (int) $this->pdo->lastInsertId();
    }

    public function testCancelledSaleIsSavedAndReadBackWithTheThreeColumns(): void
    {
        $repository = $this->repository($this->pdo);
        $sale = $this->createSale($repository);

        $cancelledAt = new DateTimeImmutable('2026-10-21 11:45:30.123456');
        $sale->cancel('  Cliente desistiu ', $this->userId, $cancelledAt);
        $repository->save($sale);

        $row = $this->pdo->prepare(
            'SELECT status, cancelled_at, cancelled_by_system_user_id, cancellation_reason FROM sale WHERE id = :id',
        );
        $row->execute(['id' => $sale->id()]);
        $columns = $row->fetch(\PDO::FETCH_ASSOC);

        Assert::same('cancelled', $columns['status']);
        Assert::same('2026-10-21 11:45:30.123456', $columns['cancelled_at']);
        Assert::same($this->userId, (int) $columns['cancelled_by_system_user_id']);
        Assert::same('Cliente desistiu', $columns['cancellation_reason']);

        $reloaded = $repository->findById((int) $sale->id());
        Assert::instanceOf(Sale::class, $reloaded);
        Assert::true($reloaded->isCancelled());
        Assert::same('2026-10-21 11:45:30.123456', $reloaded->cancelledAt()?->format('Y-m-d H:i:s.u'));
        Assert::same($this->userId, $reloaded->cancelledBySystemUserId());
        Assert::same('Cliente desistiu', $reloaded->cancellationReason());

        $locked = $repository->findByIdForUpdate((int) $sale->id());
        Assert::instanceOf(Sale::class, $locked);
        Assert::true($locked->isCancelled(), 'findByIdForUpdate hydrates the cancellation columns too');
    }

    public function testFindByIdForUpdateHoldsTheRowUntilTheTransactionEnds(): void
    {
        $repository = $this->repository($this->pdo);
        $sale = $this->createSale($repository);
        $saleId = (int) $sale->id();

        $lockedByA = $repository->findByIdForUpdate($saleId);
        Assert::instanceOf(Sale::class, $lockedByA);
        Assert::same($saleId, $lockedByA->id());

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        try {
            $errorCode = 0;
            $answer = 'no answer';

            try {
                $answer = $this->repository($other)->findByIdForUpdate($saleId) === null ? 'null' : 'sale';
            } catch (\PDOException $e) {
                $errorCode = (int) ($e->errorInfo[1] ?? 0);
            }

            Assert::same(1205, $errorCode, "B must wait for A's lock on the sale row (answered: {$answer})");
        } finally {
            $other->rollBack();
        }
    }

    public function testFindByIdForUpdateRequiresAnOpenTransaction(): void
    {
        $other = $this->secondConnection();
        $message = null;

        try {
            $this->repository($other)->findByIdForUpdate(1);
        } catch (\LogicException $e) {
            $message = $e->getMessage();
        }

        Assert::same('findByIdForUpdate() requires an open transaction', $message);
    }

    private function createSale(SaleRepository $repository): Sale
    {
        $sale = Sale::create(
            $this->tenantId,
            $this->unitId,
            $this->tutorId,
            null,
            null,
            $this->userId,
            2500,
            new DateTimeImmutable('2026-10-21 09:00:00'),
        );
        $repository->save($sale);
        Assert::true((int) $sale->id() > 0, 'sale insert assigns the generated id');

        return $sale;
    }

    private function repository(\PDO $connection): SaleRepository
    {
        return new SaleRepository(TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId), $connection);
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
