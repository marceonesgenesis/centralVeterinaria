<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\CashSessionService;
use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PaymentReversalService;
use CentralVet\Application\PaymentService;
use CentralVet\Domain\CashSession;
use CentralVet\Domain\Exception\CashSessionRequiredException;
use CentralVet\Domain\Payment;
use CentralVet\Domain\Receivable;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCashSessionRepository;
use CentralVet\Tests\Support\FakeFinancialEntryRepository;
use CentralVet\Tests\Support\FakePaymentRepository;
use CentralVet\Tests\Support\FakePaymentReversalRepository;
use CentralVet\Tests\Support\FakeReceivableRepository;
use CentralVet\Tests\Support\FakeSubmissionKeyRepository;
use CentralVet\Tests\Support\NullPdo;
use DateTimeImmutable;

/**
 * Etapa 3, T-03 (pendência (a) da Etapa 2): closing the till and moving
 * money through it (payment, reversal) read the cash session with the
 * `...ForUpdate` method — the only read of the session they do — so a close
 * and a payment on the same till are serialized by the database. Against
 * fakes; the MySQL side is CashSessionCloseRaceIntegrationTest.
 */
final class CashSessionLockTest
{
    private const ACTION = 'test::cash_lock';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 7;

    private FakeCashSessionRepository $sessions;
    private FakePaymentRepository $payments;
    private FakeReceivableRepository $receivables;
    private FakeFinancialEntryRepository $entries;
    private FakePaymentReversalRepository $reversals;

    private function context(): TenantContext
    {
        return TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
    }

    private function setUpRepositories(): void
    {
        $this->sessions = new FakeCashSessionRepository(self::TENANT_ID);
        $this->payments = new FakePaymentRepository(self::TENANT_ID);
        $this->receivables = new FakeReceivableRepository(self::TENANT_ID);
        $this->entries = new FakeFinancialEntryRepository(self::TENANT_ID);
        $this->reversals = new FakePaymentReversalRepository();
    }

    private function addSession(bool $open): CashSession
    {
        $session = CashSession::open(
            tenantId: self::TENANT_ID,
            systemUnitId: self::UNIT_ID,
            openingBalanceCents: 0,
            openedBySystemUserId: self::USER_ID,
            openedAt: new DateTimeImmutable('-1 hour'),
        );

        if (!$open) {
            $session->close(0, self::USER_ID, new DateTimeImmutable('-1 minute'));
        }

        $this->sessions->save($session);

        return $session;
    }

    private function addReceivable(int $totalCents): Receivable
    {
        $receivable = Receivable::openForSale(self::TENANT_ID, 9, 3, $totalCents);
        $this->receivables->save($receivable);

        return $receivable;
    }

    private function paymentService(): PaymentService
    {
        $authorization = new FakeAuthorizationPolicy(allowed: true);

        return new PaymentService(
            $this->payments,
            $this->receivables,
            $this->sessions,
            new FinancialEntryService($this->entries, $authorization, $this->context()),
            $authorization,
            $this->context(),
            new FakeSubmissionKeyRepository(),
        );
    }

    /** @return list<\CentralVet\Domain\FinancialEntry> */
    private function allEntries(): array
    {
        return $this->entries->listBySystemUnitAndPeriod(self::UNIT_ID, '2000-01-01', '2999-12-31');
    }

    public function testCloseLocksTheSessionBeforeSaving(): void
    {
        $this->setUpRepositories();
        $session = $this->addSession(true);
        $this->sessions->lockCalls = [];

        $service = new CashSessionService($this->sessions, new FakeAuthorizationPolicy(allowed: true), $this->context(), new NullPdo());
        $closed = $service->close((int) $session->id(), 1500, self::USER_ID, self::ACTION);

        Assert::same(['findByIdForUpdate', 'save'], $this->sessions->lockCalls);
        Assert::same(CashSession::STATUS_CLOSED, $closed->status());
    }

    public function testRegisterReadsTheSessionOnlyWithTheLock(): void
    {
        $this->setUpRepositories();
        $session = $this->addSession(true);
        $receivable = $this->addReceivable(5000);
        $this->sessions->lockCalls = [];

        $this->paymentService()->register((int) $receivable->id(), (int) $session->id(), Payment::METHOD_CASH, 2000, self::USER_ID, self::ACTION);

        Assert::same(['findByIdForUpdate'], $this->sessions->lockCalls);
        Assert::count(1, $this->payments->listByReceivable((int) $receivable->id()));
    }

    public function testRegisterOnAClosedSessionThrowsCashSessionRequiredAndWritesNothing(): void
    {
        $this->setUpRepositories();
        $session = $this->addSession(false);
        $receivable = $this->addReceivable(5000);
        $this->sessions->lockCalls = [];
        $caught = null;

        try {
            $this->paymentService()->register((int) $receivable->id(), (int) $session->id(), Payment::METHOD_CASH, 2000, self::USER_ID, self::ACTION);
        } catch (CashSessionRequiredException $e) {
            $caught = $e;
        }

        Assert::notNull($caught, 'A closed cash session must refuse the payment with CashSessionRequiredException');
        Assert::same('Open a cash session for this unit before receiving payment', $caught->getMessage());
        Assert::same(['findByIdForUpdate'], $this->sessions->lockCalls);
        Assert::count(0, $this->payments->listByReceivable((int) $receivable->id()));
        Assert::count(0, $this->allEntries());
        Assert::same(0, $this->receivables->findById((int) $receivable->id())->paidCents());
    }

    public function testReversalLocksTheOpenSessionBeforeWriting(): void
    {
        $this->setUpRepositories();
        $session = $this->addSession(true);
        $receivable = $this->addReceivable(3000);
        $receivable->recordPayment(3000);
        $this->receivables->save($receivable);
        $payment = Payment::register(
            tenantId: self::TENANT_ID,
            receivableId: (int) $receivable->id(),
            paymentMethod: Payment::METHOD_CASH,
            amountCents: 3000,
            cashSessionId: (int) $session->id(),
            systemUserId: self::USER_ID,
            paidAt: new DateTimeImmutable('-30 minutes'),
        );
        $this->payments->save($payment);
        $this->reversals->linkPayment((int) $payment->id(), (int) $receivable->id());
        $this->sessions->lockCalls = [];

        $authorization = new FakeAuthorizationPolicy(allowed: true);
        $service = new PaymentReversalService(
            $this->payments,
            $this->reversals,
            $this->receivables,
            $this->sessions,
            new FinancialEntryService($this->entries, $authorization, $this->context()),
            $authorization,
            $this->context(),
        );

        $result = $service->cancelReceivable((int) $receivable->id(), self::UNIT_ID, 'Teste do bloqueio', self::USER_ID, self::ACTION);

        Assert::same(['findOpenBySystemUnitForUpdate'], $this->sessions->lockCalls);
        Assert::count(1, $result);
        Assert::same((int) $session->id(), $result[0]->cashSessionId());
    }

    public function testReversalWithoutOpenSessionThrowsAfterTheLockedReadAndWritesNothing(): void
    {
        $this->setUpRepositories();
        $session = $this->addSession(false);
        $receivable = $this->addReceivable(3000);
        $receivable->recordPayment(3000);
        $this->receivables->save($receivable);
        $payment = Payment::register(
            tenantId: self::TENANT_ID,
            receivableId: (int) $receivable->id(),
            paymentMethod: Payment::METHOD_CASH,
            amountCents: 3000,
            cashSessionId: (int) $session->id(),
            systemUserId: self::USER_ID,
            paidAt: new DateTimeImmutable('-30 minutes'),
        );
        $this->payments->save($payment);
        $this->reversals->linkPayment((int) $payment->id(), (int) $receivable->id());
        $this->sessions->lockCalls = [];

        $authorization = new FakeAuthorizationPolicy(allowed: true);
        $service = new PaymentReversalService(
            $this->payments,
            $this->reversals,
            $this->receivables,
            $this->sessions,
            new FinancialEntryService($this->entries, $authorization, $this->context()),
            $authorization,
            $this->context(),
        );

        Assert::throws(
            CashSessionRequiredException::class,
            fn () => $service->cancelReceivable((int) $receivable->id(), self::UNIT_ID, 'Teste do bloqueio', self::USER_ID, self::ACTION),
        );
        Assert::same(['findOpenBySystemUnitForUpdate'], $this->sessions->lockCalls);
        Assert::count(0, $this->reversals->all());
        Assert::count(0, $this->allEntries());
    }
}
