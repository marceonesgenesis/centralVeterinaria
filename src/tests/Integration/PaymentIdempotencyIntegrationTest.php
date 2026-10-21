<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PaymentService;
use CentralVet\Domain\Exception\DuplicateSubmissionException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Payment;
use CentralVet\Domain\Receivable;
use CentralVet\Domain\SubmissionToken;
use CentralVet\Persistence\CashSessionRepository;
use CentralVet\Persistence\FinancialEntryRepository;
use CentralVet\Persistence\PaymentRepository;
use CentralVet\Persistence\ReceivableRepository;
use CentralVet\Persistence\SubmissionKeyRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\TestDatabase;

/**
 * Etapa 2, T-11 against the real schema (migration 0016): PaymentService::
 * register() deduplicates by submission token (submission_key) and reads the
 * receivable with FOR UPDATE. Everything runs inside the test transaction
 * and is rolled back; the second connection never commits either.
 */
final class PaymentIdempotencyIntegrationTest extends MysqlIntegrationTestCase
{
    private const ACTION = 'PaymentForm::onSave';

    private int $userId;
    private int $unitId;
    private int $tenantId;
    private int $tutorId;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantId = $this->createTenant('e2-t11');
        $this->tutorId = $this->createTutor();
    }

    public function testRepeatedTokenRegistersOnePaymentAndThrowsDuplicate(): void
    {
        $cashSessionId = $this->openCashSession();
        $receivableId = $this->saleReceivable(10000);
        $service = $this->service($this->pdo);
        $token = SubmissionToken::generate();

        $first = $service->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 3000, $this->userId, self::ACTION, $token);
        Assert::notNull($first->id());

        $duplicate = null;

        try {
            $service->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 3000, $this->userId, self::ACTION, $token);
        } catch (DuplicateSubmissionException $e) {
            $duplicate = $e;
        }

        Assert::notNull($duplicate, 'Second register with the same token must throw DuplicateSubmissionException');
        Assert::same('payment', $duplicate->scope());
        Assert::same($first->id(), $duplicate->referenceId(), 'Duplicate points at the payment of the first submission');

        Assert::same(1, $this->paymentCount($receivableId));
        Assert::same(3000, $this->paidCents($receivableId));
        Assert::same($first->id(), (new SubmissionKeyRepository($this->context(), $this->pdo))->findReference(SubmissionToken::SCOPE_PAYMENT, $token));
    }

    public function testRegisterWaitsForTheReceivableHeldByAnotherTransaction(): void
    {
        $cashSessionId = $this->openCashSession();
        $receivableId = $this->saleReceivable(10000);

        $locked = (new ReceivableRepository($this->context(), $this->pdo))->findByIdForUpdate($receivableId);
        Assert::notNull($locked, 'A holds the receivable');

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        try {
            $errorCode = 0;
            $answer = 'no exception';

            try {
                $this->service($other)->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 3000, $this->userId, self::ACTION, SubmissionToken::generate());
            } catch (\PDOException $e) {
                $errorCode = (int) ($e->errorInfo[1] ?? 0);
                $answer = $e->getMessage();
            } catch (\Throwable $e) {
                $answer = get_class($e) . ': ' . $e->getMessage();
            }

            Assert::same(1205, $errorCode, "B must end in lock wait timeout (answered: {$answer})");
        } finally {
            $other->rollBack();
        }

        Assert::same(0, $this->paymentCount($receivableId));
    }

    public function testCancelledReceivableRefusesPaymentAndWritesNothing(): void
    {
        $cashSessionId = $this->openCashSession();
        $receivableId = $this->saleReceivable(10000);

        $repository = new ReceivableRepository($this->context(), $this->pdo);
        $receivable = $repository->findById($receivableId);
        $receivable->cancel();
        $repository->save($receivable);

        $message = null;

        try {
            $this->service($this->pdo)->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 3000, $this->userId, self::ACTION, SubmissionToken::generate());
        } catch (InvalidStatusTransitionException $e) {
            $message = $e->getMessage();
        }

        Assert::same('This receivable is cancelled and cannot receive payments', $message);
        Assert::same(0, $this->paymentCount($receivableId));
    }

    private function service(\PDO $connection): PaymentService
    {
        $context = $this->context();
        $authorization = new FakeAuthorizationPolicy();

        return new PaymentService(
            new PaymentRepository($context, $connection),
            new ReceivableRepository($context, $connection),
            new CashSessionRepository($context, $connection),
            new FinancialEntryService(new FinancialEntryRepository($context, $connection), $authorization, $context),
            $authorization,
            $context,
            new SubmissionKeyRepository($context, $connection),
        );
    }

    private function paymentCount(int $receivableId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM payment WHERE receivable_id = ?');
        $statement->execute([$receivableId]);

        return (int) $statement->fetchColumn();
    }

    private function paidCents(int $receivableId): int
    {
        $statement = $this->pdo->prepare('SELECT paid_cents FROM receivable WHERE id = ?');
        $statement->execute([$receivableId]);

        return (int) $statement->fetchColumn();
    }

    private function saleReceivable(int $totalCents): int
    {
        $receivable = Receivable::openForSale($this->tenantId, $this->createSale($totalCents), $this->tutorId, $totalCents);
        (new ReceivableRepository($this->context(), $this->pdo))->save($receivable);

        return (int) $receivable->id();
    }

    private function context(): TenantContext
    {
        return TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId);
    }

    private function openCashSession(): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO cash_session (tenant_id, system_unit_id, opened_by_system_user_id, opening_balance_cents, status)
             VALUES (:tenant_id, :unit_id, :user_id, 0, 'open')",
        );
        $statement->execute(['tenant_id' => $this->tenantId, 'unit_id' => $this->unitId, 'user_id' => $this->userId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSale(int $totalCents): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO sale (tenant_id, system_unit_id, tutor_id, patient_id, system_user_id, status, total_amount_cents, sold_at)
             VALUES (:tenant_id, :unit_id, :tutor_id, NULL, :user_id, 'completed', :total, '2031-06-01 09:00:00')",
        );
        $statement->execute([
            'tenant_id' => $this->tenantId,
            'unit_id' => $this->unitId,
            'tutor_id' => $this->tutorId,
            'user_id' => $this->userId,
            'total' => $totalCents,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTutor(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :full_name, :phone)',
        );
        $statement->execute(['tenant_id' => $this->tenantId, 'full_name' => 'E2 Tutor', 'phone' => '11999990000']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'E2 teste tenant (' . $slug . ')', 'status' => 'active']);

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
