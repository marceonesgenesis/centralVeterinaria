<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\CashSessionService;
use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PaymentService;
use CentralVet\Domain\Exception\CashSessionRequiredException;
use CentralVet\Domain\Payment;
use CentralVet\Domain\Receivable;
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
 * Etapa 3, T-03 against the real schema: closing the till and registering a
 * payment both read the cash session with FOR UPDATE, so they serialize.
 * A (the test transaction) holds the lock; B (second connection, lock wait
 * timeout 1 s) ends in 1205. Everything is rolled back; nothing commits.
 *
 * Base data cannot be committed (MysqlIntegrationTestCase), so the race
 * tests run in a tenant that already exists committed in the test database
 * (MIN(id) of `tenant`): A opens the cash session there and holds it; B
 * creates its OWN tutor/sale/receivable in the same tenant inside its own
 * transaction, so the receivable lock never waits on A. The only row B can
 * wait for is A's cash_session, and only because B reads it FOR UPDATE: a
 * plain consistent read would not see A's uncommitted session and answer
 * "Cash session N not found" instead of 1205. Each race test also asserts
 * that the 1205 was raised inside CashSessionRepository::findByIdForUpdate().
 */
final class CashSessionCloseRaceIntegrationTest extends MysqlIntegrationTestCase
{
    private const ACTION = 'CashSessionCloseRace::test';

    private int $userId;
    private int $unitId;
    private int $tenantId;
    private int $tutorId;
    private int $baseTenantId;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->baseTenantId = (int) $this->pdo->query('SELECT MIN(id) FROM tenant')->fetchColumn();
        Assert::true($this->baseTenantId > 0, 'Fixture requires at least one committed tenant row');

        $this->tenantId = $this->createTenant('e3-t03');
        $this->tutorId = $this->createTutor();
    }

    public function testRegisterWaitsForTheCashSessionHeldByAnotherTransaction(): void
    {
        $tenantId = $this->baseTenantId;
        $cashSessionId = $this->openCashSession($tenantId);

        $locked = (new CashSessionRepository($this->context($tenantId), $this->pdo))->findByIdForUpdate($cashSessionId);
        Assert::notNull($locked, 'A holds the cash session');

        $receivableId = 0;

        [$errorCode, $answer, $inCashSessionLock] = $this->inSecondConnection(
            function (\PDO $other) use ($tenantId, $cashSessionId, &$receivableId): void {
                // B's own receivable: free for B, never touched by A.
                $receivableId = $this->saleReceivableOn($other, $tenantId, 10000);
                $this->paymentService($other, $tenantId)->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 3000, $this->userId, self::ACTION);
            },
        );

        Assert::true($receivableId > 0, "B created its own receivable before registering (answered: {$answer})");
        Assert::same(1205, $errorCode, "B must end in lock wait timeout (answered: {$answer})");
        Assert::true($inCashSessionLock, 'B must wait on CashSessionRepository::findByIdForUpdate(), not on the receivable');
        Assert::same(0, $this->paymentCount($receivableId));
    }

    public function testCloseWaitsForThePaymentHeldByAnotherTransaction(): void
    {
        $tenantId = $this->baseTenantId;
        $cashSessionId = $this->openCashSession($tenantId);
        $receivableId = $this->saleReceivableOn($this->pdo, $tenantId, 10000);

        $payment = $this->paymentService($this->pdo, $tenantId)->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 3000, $this->userId, self::ACTION);
        Assert::notNull($payment->id(), 'A registered the payment and still holds the cash session');

        [$errorCode, $answer, $inCashSessionLock] = $this->inSecondConnection(
            fn (\PDO $other) => $this->cashSessionService($other, $tenantId)->close($cashSessionId, 3000, $this->userId, self::ACTION),
        );

        Assert::same(1205, $errorCode, "B must end in lock wait timeout (answered: {$answer})");
        Assert::true($inCashSessionLock, 'B must wait on CashSessionRepository::findByIdForUpdate()');
        Assert::same('open', $this->sessionStatus($cashSessionId));
    }

    public function testRegisterOnASessionClosedAndReleasedInASavepointIsRefused(): void
    {
        $cashSessionId = $this->openCashSession();
        $receivableId = $this->saleReceivable(10000);

        $this->pdo->exec('SAVEPOINT t03_close');
        $this->cashSessionService($this->pdo)->close($cashSessionId, 0, $this->userId, self::ACTION);
        $this->pdo->exec('RELEASE SAVEPOINT t03_close');

        $caught = null;

        try {
            $this->paymentService($this->pdo)->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 3000, $this->userId, self::ACTION);
        } catch (CashSessionRequiredException $e) {
            $caught = $e;
        }

        Assert::notNull($caught, 'A payment in a closed cash session must throw CashSessionRequiredException');
        Assert::same('Open a cash session for this unit before receiving payment', $caught->getMessage());
        Assert::same(0, $this->paymentCount($receivableId));
        Assert::same(0, $this->paidCents($receivableId));
    }

    public function testLockedReadsRequireAnOpenTransaction(): void
    {
        $repository = new CashSessionRepository($this->context(), $this->secondConnection());

        $messages = [];

        foreach ([
            fn () => $repository->findByIdForUpdate(1),
            fn () => $repository->findOpenBySystemUnitForUpdate($this->unitId),
        ] as $call) {
            try {
                $call();
                $messages[] = 'no exception';
            } catch (\LogicException $e) {
                $messages[] = $e->getMessage();
            }
        }

        Assert::same([
            'findByIdForUpdate requires an open transaction',
            'findOpenBySystemUnitForUpdate requires an open transaction',
        ], $messages);
    }

    public function testFindOpenBySystemUnitForUpdateIsTenantScoped(): void
    {
        $cashSessionId = $this->openCashSession();
        $repository = new CashSessionRepository($this->context(), $this->pdo);

        Assert::same($cashSessionId, $repository->findOpenBySystemUnitForUpdate($this->unitId)?->id());
        Assert::same($cashSessionId, $repository->findByIdForUpdate($cashSessionId)?->id());

        $otherTenant = $this->createTenant('e3-t03-other');
        $foreign = new CashSessionRepository(TenantContext::authenticated($otherTenant, $this->userId, $this->unitId), $this->pdo);

        Assert::null($foreign->findOpenBySystemUnitForUpdate($this->unitId));
        Assert::null($foreign->findByIdForUpdate($cashSessionId));
    }

    /**
     * Runs $work in a second connection with a 1 s lock wait timeout and
     * always rolls it back.
     *
     * @return array{0: int, 1: string, 2: bool} MySQL error code (0 when
     *         none), what B answered, and whether the error was raised inside
     *         CashSessionRepository::findByIdForUpdate()
     */
    private function inSecondConnection(callable $work): array
    {
        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        try {
            $work($other);

            return [0, 'no exception', false];
        } catch (\PDOException $e) {
            $inCashSessionLock = false;

            foreach ($e->getTrace() as $frame) {
                if (($frame['class'] ?? '') === CashSessionRepository::class && ($frame['function'] ?? '') === 'findByIdForUpdate') {
                    $inCashSessionLock = true;
                }
            }

            return [(int) ($e->errorInfo[1] ?? 0), $e->getMessage(), $inCashSessionLock];
        } catch (\Throwable $e) {
            return [0, get_class($e) . ': ' . $e->getMessage(), false];
        } finally {
            $other->rollBack();
        }
    }

    private function paymentService(\PDO $connection, ?int $tenantId = null): PaymentService
    {
        $context = $this->context($tenantId);
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

    private function cashSessionService(\PDO $connection, ?int $tenantId = null): CashSessionService
    {
        $context = $this->context($tenantId);

        return new CashSessionService(
            new CashSessionRepository($context, $connection),
            new FakeAuthorizationPolicy(),
            $context,
            $connection,
        );
    }

    private function context(?int $tenantId = null): TenantContext
    {
        return TenantContext::authenticated($tenantId ?? $this->tenantId, $this->userId, $this->unitId);
    }

    private function sessionStatus(int $cashSessionId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM cash_session WHERE id = ?');
        $statement->execute([$cashSessionId]);

        return (string) $statement->fetchColumn();
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

    /**
     * Tutor, sale and receivable of $tenantId written through $connection
     * (uncommitted in that connection's transaction).
     */
    private function saleReceivableOn(\PDO $connection, int $tenantId, int $totalCents): int
    {
        $tutor = $connection->prepare(
            'INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :full_name, :phone)',
        );
        $tutor->execute(['tenant_id' => $tenantId, 'full_name' => 'E3 Tutor B', 'phone' => '11999990001']);
        $tutorId = (int) $connection->lastInsertId();

        $sale = $connection->prepare(
            "INSERT INTO sale (tenant_id, system_unit_id, tutor_id, patient_id, system_user_id, status, total_amount_cents, sold_at)
             VALUES (:tenant_id, :unit_id, :tutor_id, NULL, :user_id, 'completed', :total, '2031-06-01 09:00:00')",
        );
        $sale->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $this->unitId,
            'tutor_id' => $tutorId,
            'user_id' => $this->userId,
            'total' => $totalCents,
        ]);

        $receivable = Receivable::openForSale($tenantId, (int) $connection->lastInsertId(), $tutorId, $totalCents);
        (new ReceivableRepository($this->context($tenantId), $connection))->save($receivable);

        return (int) $receivable->id();
    }

    private function openCashSession(?int $tenantId = null): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO cash_session (tenant_id, system_unit_id, opened_by_system_user_id, opening_balance_cents, status)
             VALUES (:tenant_id, :unit_id, :user_id, 0, 'open')",
        );
        $statement->execute(['tenant_id' => $tenantId ?? $this->tenantId, 'unit_id' => $this->unitId, 'user_id' => $this->userId]);

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
        $statement->execute(['tenant_id' => $this->tenantId, 'full_name' => 'E3 Tutor', 'phone' => '11999990000']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'E3 teste tenant (' . $slug . ')', 'status' => 'active']);

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
