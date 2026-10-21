<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PaymentReversalService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\CashSession;
use CentralVet\Domain\Exception\CashSessionRequiredException;
use CentralVet\Domain\FinancialEntry;
use CentralVet\Domain\Payment;
use CentralVet\Domain\PaymentReversal;
use CentralVet\Domain\Receivable;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCashSessionRepository;
use CentralVet\Tests\Support\FakeFinancialEntryRepository;
use CentralVet\Tests\Support\FakePaymentRepository;
use CentralVet\Tests\Support\FakePaymentReversalRepository;
use CentralVet\Tests\Support\FakeReceivableRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for PaymentReversalService::cancelReceivable() (Etapa 2,
 * T-12), against fakes: every payment of the receivable is reversed in the
 * unit's currently open cash session (a closed session is never
 * rewritten), each reversal gets an `expense` financial_entry referencing
 * it, and the receivable ends `cancelled`. A second call is a no-op.
 */
final class PaymentReversalServiceTest
{
    private const ACTION = 'test::payment-reversal';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 7;
    private const OPEN_SESSION_ID = 77;
    private const CLOSED_SESSION_ID = 50;
    private const REASON = 'Venda cancelada a pedido do tutor';

    private FakePaymentRepository $payments;
    private FakePaymentReversalRepository $reversals;
    private FakeReceivableRepository $receivables;
    private FakeCashSessionRepository $cashSessions;
    private FakeFinancialEntryRepository $entries;
    private FakeAuthorizationPolicy $authorization;

    private function buildService(): PaymentReversalService
    {
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);

        $this->payments = new FakePaymentRepository(self::TENANT_ID);
        $this->reversals = new FakePaymentReversalRepository();
        $this->receivables = new FakeReceivableRepository(self::TENANT_ID);
        $this->cashSessions = new FakeCashSessionRepository(self::TENANT_ID);
        $this->entries = new FakeFinancialEntryRepository(self::TENANT_ID);
        $this->authorization = new FakeAuthorizationPolicy(allowed: true);

        return new PaymentReversalService(
            $this->payments,
            $this->reversals,
            $this->receivables,
            $this->cashSessions,
            new FinancialEntryService($this->entries, $this->authorization, $context),
            $this->authorization,
            $context,
        );
    }

    private function addCashSession(int $id, bool $open): CashSession
    {
        $session = CashSession::open(
            tenantId: self::TENANT_ID,
            systemUnitId: self::UNIT_ID,
            openingBalanceCents: 0,
            openedBySystemUserId: self::USER_ID,
            openedAt: new DateTimeImmutable('-2 days'),
        );
        $session->assignId($id);

        if (!$open) {
            $session->close(0, self::USER_ID, new DateTimeImmutable('-1 day'));
        }

        $this->cashSessions->save($session);

        return $session;
    }

    /**
     * Receivable of a sale with the given payments (method => cents), all
     * recorded in $cashSessionId.
     *
     * @param list<array{0: string, 1: int}> $paid
     */
    private function addReceivable(int $totalCents, array $paid, int $cashSessionId): Receivable
    {
        $receivable = Receivable::openForSale(self::TENANT_ID, 9, 3, $totalCents);

        foreach ($paid as [, $cents]) {
            $receivable->recordPayment($cents);
        }

        $this->receivables->save($receivable);

        foreach ($paid as [$method, $cents]) {
            $payment = Payment::register(
                tenantId: self::TENANT_ID,
                receivableId: (int) $receivable->id(),
                paymentMethod: $method,
                amountCents: $cents,
                cashSessionId: $cashSessionId,
                systemUserId: self::USER_ID,
                paidAt: new DateTimeImmutable('-1 day'),
            );
            $this->payments->save($payment);
            $this->reversals->linkPayment((int) $payment->id(), (int) $receivable->id());
        }

        return $receivable;
    }

    /** @return list<FinancialEntry> */
    private function allEntries(): array
    {
        return $this->entries->listBySystemUnitAndPeriod(self::UNIT_ID, '2000-01-01', '2999-12-31');
    }

    public function testCancelReversesEachPaymentInTheOpenCashSessionAndCancelsReceivable(): void
    {
        $service = $this->buildService();
        $this->addCashSession(self::OPEN_SESSION_ID, true);
        $receivable = $this->addReceivable(5000, [[Payment::METHOD_CASH, 3000], [Payment::METHOD_PIX, 2000]], self::OPEN_SESSION_ID);
        $receivableId = (int) $receivable->id();

        $result = $service->cancelReceivable($receivableId, self::UNIT_ID, self::REASON, self::USER_ID, self::ACTION);

        Assert::count(2, $result);

        $byMethod = [];
        foreach ($result as $reversal) {
            Assert::instanceOf(PaymentReversal::class, $reversal);
            Assert::notNull($reversal->id(), 'Reversal must be persisted');
            Assert::same(self::OPEN_SESSION_ID, $reversal->cashSessionId());
            Assert::same(self::REASON, $reversal->reason());
            $byMethod[$reversal->paymentMethod()] = $reversal;
        }

        Assert::same(3000, $byMethod[Payment::METHOD_CASH]->amountCents());
        Assert::same(2000, $byMethod[Payment::METHOD_PIX]->amountCents());
        Assert::count(2, $this->reversals->all());

        $entries = $this->allEntries();
        Assert::count(2, $entries);

        $reversalIds = array_map(static fn (PaymentReversal $r): int => (int) $r->id(), $result);
        foreach ($entries as $entry) {
            Assert::same('expense', $entry->entryType());
            Assert::same('payment_reversal', $entry->referenceType());
            Assert::true(in_array($entry->referenceId(), $reversalIds, true), 'financial_entry must reference the reversal id');
            Assert::same($byMethod[(string) $entry->paymentMethod()]->amountCents(), $entry->amountCents());
            Assert::same((string) $entry->paymentMethod(), $entry->category());
        }

        $reloaded = $this->receivables->findById($receivableId);
        Assert::notNull($reloaded);
        Assert::same(Receivable::STATUS_CANCELLED, $reloaded->status());
    }

    public function testSecondCallReturnsEmptyAndWritesNothing(): void
    {
        $service = $this->buildService();
        $this->addCashSession(self::OPEN_SESSION_ID, true);
        $receivable = $this->addReceivable(5000, [[Payment::METHOD_CASH, 3000], [Payment::METHOD_PIX, 2000]], self::OPEN_SESSION_ID);
        $receivableId = (int) $receivable->id();

        $service->cancelReceivable($receivableId, self::UNIT_ID, self::REASON, self::USER_ID, self::ACTION);
        $second = $service->cancelReceivable($receivableId, self::UNIT_ID, self::REASON, self::USER_ID, self::ACTION);

        Assert::same([], $second);
        Assert::count(2, $this->reversals->all(), 'Second call must not write reversals');
        Assert::count(2, $this->allEntries(), 'Second call must not write financial entries');
    }

    public function testWithoutOpenCashSessionThrowsAndWritesNothing(): void
    {
        $service = $this->buildService();
        $this->addCashSession(self::CLOSED_SESSION_ID, false);
        $receivable = $this->addReceivable(5000, [[Payment::METHOD_CASH, 3000], [Payment::METHOD_PIX, 2000]], self::CLOSED_SESSION_ID);
        $receivableId = (int) $receivable->id();

        $message = null;
        try {
            $service->cancelReceivable($receivableId, self::UNIT_ID, self::REASON, self::USER_ID, self::ACTION);
        } catch (CashSessionRequiredException $e) {
            $message = $e->getMessage();
        }

        Assert::same('Open a cash session for this unit before reversing payments', $message);
        Assert::count(0, $this->reversals->all());
        Assert::count(0, $this->allEntries());
        $reloaded = $this->receivables->findById($receivableId);
        Assert::notNull($reloaded);
        Assert::false($reloaded->isCancelled(), 'Receivable must not be cancelled without an open cash session');
    }

    public function testPaymentFromClosedSessionIsReversedInTheOpenOneWithoutRewritingTheClosed(): void
    {
        $service = $this->buildService();
        $this->addCashSession(self::CLOSED_SESSION_ID, false);
        $this->addCashSession(self::OPEN_SESSION_ID, true);
        $receivable = $this->addReceivable(5000, [[Payment::METHOD_CASH, 3000], [Payment::METHOD_PIX, 2000]], self::CLOSED_SESSION_ID);

        $before = array_map(
            static fn (Payment $p): array => [$p->id(), $p->paymentMethod(), $p->amountCents(), $p->cashSessionId()],
            $this->payments->listByCashSession(self::CLOSED_SESSION_ID),
        );
        Assert::count(2, $before);

        $result = $service->cancelReceivable((int) $receivable->id(), self::UNIT_ID, self::REASON, self::USER_ID, self::ACTION);

        Assert::count(2, $result);
        foreach ($result as $reversal) {
            Assert::same(self::OPEN_SESSION_ID, $reversal->cashSessionId(), 'Reversal goes to the currently open session');
        }

        $after = array_map(
            static fn (Payment $p): array => [$p->id(), $p->paymentMethod(), $p->amountCents(), $p->cashSessionId()],
            $this->payments->listByCashSession(self::CLOSED_SESSION_ID),
        );
        Assert::same($before, $after, 'Payments of the closed session must stay untouched');
        Assert::count(0, $this->payments->listByCashSession(self::OPEN_SESSION_ID));
    }

    public function testReceivableWithoutPaymentsCancelsWithoutRequiringCashSession(): void
    {
        $service = $this->buildService();
        $receivable = $this->addReceivable(5000, [], self::OPEN_SESSION_ID);
        $receivableId = (int) $receivable->id();

        $result = $service->cancelReceivable($receivableId, self::UNIT_ID, self::REASON, self::USER_ID, self::ACTION);

        Assert::same([], $result);
        Assert::count(0, $this->allEntries());
        $reloaded = $this->receivables->findById($receivableId);
        Assert::notNull($reloaded);
        Assert::same(Receivable::STATUS_CANCELLED, $reloaded->status());
    }

    public function testAlreadyReversedPaymentIsSkipped(): void
    {
        $service = $this->buildService();
        $this->addCashSession(self::OPEN_SESSION_ID, true);
        $receivable = $this->addReceivable(5000, [[Payment::METHOD_CASH, 3000], [Payment::METHOD_PIX, 2000]], self::OPEN_SESSION_ID);
        $paymentList = $this->payments->listByReceivable((int) $receivable->id());
        $first = $paymentList[0];
        $this->reversals->save(PaymentReversal::record(
            self::TENANT_ID,
            (int) $first->id(),
            self::OPEN_SESSION_ID,
            $first->paymentMethod(),
            $first->amountCents(),
            'previous',
            self::USER_ID,
            new DateTimeImmutable(),
        ));

        $result = $service->cancelReceivable((int) $receivable->id(), self::UNIT_ID, self::REASON, self::USER_ID, self::ACTION);

        Assert::count(1, $result);
        Assert::same((int) $paymentList[1]->id(), $result[0]->paymentId());
        Assert::count(1, $this->allEntries());
    }

    public function testAuthorizationDeniedThrowsWithoutWriting(): void
    {
        $service = $this->buildService();
        $this->addCashSession(self::OPEN_SESSION_ID, true);
        $receivable = $this->addReceivable(5000, [[Payment::METHOD_CASH, 3000]], self::OPEN_SESSION_ID);
        $receivableId = (int) $receivable->id();
        $this->authorization->setAllowed(false);

        Assert::throws(
            AuthorizationDenied::class,
            fn () => $service->cancelReceivable($receivableId, self::UNIT_ID, self::REASON, self::USER_ID, self::ACTION),
        );

        $request = $this->authorization->requests[0];
        Assert::same(self::UNIT_ID, $request->resourceUnitId());
        Assert::same('receivable', $request->entityType());
        Assert::same($receivableId, $request->entityId());
        Assert::count(0, $this->reversals->all());
        Assert::count(0, $this->allEntries());
        $reloaded = $this->receivables->findById($receivableId);
        Assert::notNull($reloaded);
        Assert::false($reloaded->isCancelled());
    }

    public function testUnknownReceivableThrows(): void
    {
        $service = $this->buildService();

        Assert::throws(
            InvalidArgumentException::class,
            fn () => $service->cancelReceivable(999, self::UNIT_ID, self::REASON, self::USER_ID, self::ACTION),
        );
    }
}
