<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\CashSessionService;
use CentralVet\Domain\Payment;
use CentralVet\Domain\PaymentReversal;
use CentralVet\Domain\Receivable;
use CentralVet\Persistence\CashSessionRepository;
use CentralVet\Persistence\FinancialOverviewReader;
use CentralVet\Persistence\PaymentRepository;
use CentralVet\Persistence\PaymentReversalRepository;
use CentralVet\Persistence\ReceivableRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\TestDatabase;
use DateTimeImmutable;

/**
 * Etapa 2, T-08 against the real schema (migration 0016): sale receivables
 * (sale_id, encounter_account_id NULL), FOR UPDATE on the receivable and
 * payment_reversal netted out of the cash session totals and of the open
 * cash balance. Everything runs inside the test transaction and is rolled
 * back; the second connection never commits either.
 */
final class PaymentReversalIntegrationTest extends MysqlIntegrationTestCase
{
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

        $this->tenantId = $this->createTenant('e2-t08');
        $this->tutorId = $this->createTutor();
    }

    public function testReversalNetsCashSessionTotalsAndOpenBalance(): void
    {
        $context = $this->context();
        $cashSessionId = $this->openCashSession(1000);
        $receivable = $this->saleReceivable(5000);

        $payments = new PaymentRepository($context, $this->pdo);
        $payment = Payment::register($this->tenantId, (int) $receivable->id(), 'cash', 5000, $cashSessionId, $this->userId, new DateTimeImmutable());
        $payments->save($payment);

        $service = new CashSessionService(new CashSessionRepository($context, $this->pdo), new FakeAuthorizationPolicy(), $context, $this->pdo);
        $reader = new FinancialOverviewReader($context, $this->pdo);

        Assert::same(['cash' => 5000], $service->totalsByPaymentMethod($cashSessionId));
        Assert::same(6000, $reader->openCashBalance($this->unitId));

        $reversals = new PaymentReversalRepository($context, $this->pdo);
        Assert::false($reversals->existsForPayment((int) $payment->id()));

        $reversal = PaymentReversal::record(
            $this->tenantId,
            (int) $payment->id(),
            $cashSessionId,
            'cash',
            5000,
            'Venda cancelada',
            $this->userId,
            new DateTimeImmutable('2031-06-01 10:00:00'),
        );
        $reversals->save($reversal);

        Assert::notNull($reversal->id());
        Assert::true($reversals->existsForPayment((int) $payment->id()));
        Assert::same(['cash' => 0], $service->totalsByPaymentMethod($cashSessionId), 'Reversal is subtracted from its method total');
        Assert::same(1000, $reader->openCashBalance($this->unitId), 'Open balance = opening + payments - reversals');

        $listed = $reversals->listByReceivable((int) $receivable->id());
        Assert::count(1, $listed);
        Assert::same((int) $payment->id(), $listed[0]->paymentId());
        Assert::same('Venda cancelada', $listed[0]->reason());
        Assert::same(5000, $listed[0]->amountCents());

        $reloaded = $reversals->findById((int) $reversal->id());
        Assert::notNull($reloaded);
        Assert::same($cashSessionId, $reloaded->cashSessionId());
        Assert::same('2031-06-01 10:00:00', $reloaded->reversedAt()->format('Y-m-d H:i:s'));
    }

    public function testSaleReceivableRoundTripsBySaleId(): void
    {
        $receivable = $this->saleReceivable(7000, $saleId);
        $repository = new ReceivableRepository($this->context(), $this->pdo);

        $found = $repository->findBySaleId($saleId);

        Assert::notNull($found);
        Assert::same($receivable->id(), $found->id());
        Assert::null($found->encounterAccountId());
        Assert::same($saleId, $found->saleId());
        Assert::same(7000, $found->totalCents());

        $found->cancel();
        $repository->save($found);
        Assert::true($repository->findBySaleId($saleId)->isCancelled());
    }

    public function testEncounterReceivableStillStoresEncounterAccountAndNullSale(): void
    {
        $accountId = $this->createEncounterAccount(3000);
        $repository = new ReceivableRepository($this->context(), $this->pdo);
        $receivable = Receivable::open($this->tenantId, $accountId, $this->tutorId, 3000);
        $repository->save($receivable);

        $row = $this->pdo->query('SELECT encounter_account_id, sale_id FROM receivable WHERE id = ' . (int) $receivable->id())
            ->fetch(\PDO::FETCH_ASSOC);

        Assert::same($accountId, (int) $row['encounter_account_id']);
        Assert::null($row['sale_id']);
        Assert::same($accountId, $repository->findByEncounterAccountId($accountId)->encounterAccountId());
    }

    public function testFindByIdForUpdateWaitsForTheRowHeldByAnotherTransaction(): void
    {
        $receivable = $this->saleReceivable(5000);

        $locked = (new ReceivableRepository($this->context(), $this->pdo))->findByIdForUpdate((int) $receivable->id());
        Assert::notNull($locked, 'A locks its own receivable');

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        try {
            $lockWait = false;
            $answer = 'no answer';

            try {
                $found = (new ReceivableRepository($this->context(), $other))->findByIdForUpdate((int) $receivable->id());
                $answer = $found === null ? 'null' : 'row';
            } catch (\PDOException $e) {
                $lockWait = (int) ($e->errorInfo[1] ?? 0) === 1205;
            }

            Assert::true($lockWait, "B must wait for A's lock on the receivable (answered: {$answer})");
        } finally {
            $other->rollBack();
        }
    }

    public function testFindByIdForUpdateRequiresAnOpenTransaction(): void
    {
        $other = $this->secondConnection();
        $message = null;

        try {
            (new ReceivableRepository($this->context(), $other))->findByIdForUpdate(1);
        } catch (\LogicException $e) {
            $message = $e->getMessage();
        }

        Assert::same('findByIdForUpdate() requires an open transaction', $message);
    }

    private function saleReceivable(int $totalCents, ?int &$saleId = null): Receivable
    {
        $saleId = $this->createSale($totalCents);
        $receivable = Receivable::openForSale($this->tenantId, $saleId, $this->tutorId, $totalCents);
        (new ReceivableRepository($this->context(), $this->pdo))->save($receivable);

        return $receivable;
    }

    private function context(): TenantContext
    {
        return TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId);
    }

    private function openCashSession(int $openingCents): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO cash_session (tenant_id, system_unit_id, opened_by_system_user_id, opening_balance_cents, status)
             VALUES (:tenant_id, :unit_id, :user_id, :opening, 'open')",
        );
        $statement->execute(['tenant_id' => $this->tenantId, 'unit_id' => $this->unitId, 'user_id' => $this->userId, 'opening' => $openingCents]);

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

    private function createEncounterAccount(int $totalCents): int
    {
        $patient = $this->pdo->prepare(
            "INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:tenant_id, :tutor_id, 'E2 Paciente', 'Canina')",
        );
        $patient->execute(['tenant_id' => $this->tenantId, 'tutor_id' => $this->tutorId]);
        $patientId = (int) $this->pdo->lastInsertId();

        $encounter = $this->pdo->prepare(
            'INSERT INTO encounter (tenant_id, system_unit_id, patient_id, professional_system_user_id)
             VALUES (:tenant_id, :unit_id, :patient_id, :user_id)',
        );
        $encounter->execute(['tenant_id' => $this->tenantId, 'unit_id' => $this->unitId, 'patient_id' => $patientId, 'user_id' => $this->userId]);
        $encounterId = (int) $this->pdo->lastInsertId();

        $account = $this->pdo->prepare(
            "INSERT INTO encounter_account (tenant_id, encounter_id, patient_id, tutor_id, system_unit_id, status, subtotal_cents, total_cents)
             VALUES (:tenant_id, :encounter_id, :patient_id, :tutor_id, :unit_id, 'closed', :total, :total2)",
        );
        $account->execute([
            'tenant_id' => $this->tenantId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'tutor_id' => $this->tutorId,
            'unit_id' => $this->unitId,
            'total' => $totalCents,
            'total2' => $totalCents,
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
