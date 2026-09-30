<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PaymentService;
use CentralVet\Domain\CashSession;
use CentralVet\Domain\Exception\OverpaymentException;
use CentralVet\Domain\FinancialEntry;
use CentralVet\Domain\Payment;
use CentralVet\Domain\Receivable;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCashSessionRepository;
use CentralVet\Tests\Support\FakeFinancialEntryRepository;
use CentralVet\Tests\Support\FakePaymentRepository;
use CentralVet\Tests\Support\FakeReceivableRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for PaymentService::register() (T-06), against fakes of every
 * repository it consumes — no database involved, since `payment`/
 * `receivable`/`cash_session`/`financial_entry` do not exist yet (migration
 * T-01 not applied). Mirrors the FakeAuthorizationPolicy pattern already
 * used by ProcedureExecutionServiceTest/SaleServiceTest (Phase 4).
 *
 * Covers T-06's own acceptance criteria (T-13's mandate):
 *   5. register() with an amount exceeding the balance due throws
 *      OverpaymentException without writing anything (Payment, Receivable
 *      or FinancialEntry).
 *   6. Two sequential partial payments: after the first, receivable.status
 *      is 'partially_paid'; after the second, which completes the total,
 *      'paid'.
 */
final class PaymentServiceTest
{
    private const ACTION = 'test::payment';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const TOTAL_CENTS = 10000;

    /**
     * @return array{0: PaymentService, 1: int, 2: int, 3: FakePaymentRepository, 4: FakeReceivableRepository, 5: FakeFinancialEntryRepository}
     */
    private function buildService(): array
    {
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::UNIT_ID);

        $cashSessions = new FakeCashSessionRepository(self::TENANT_ID);
        $cashSession = CashSession::open(
            tenantId: self::TENANT_ID,
            systemUnitId: self::UNIT_ID,
            openingBalanceCents: 0,
            openedBySystemUserId: 1,
            openedAt: new DateTimeImmutable(),
        );
        $cashSessions->save($cashSession);

        $receivables = new FakeReceivableRepository(self::TENANT_ID);
        $receivable = Receivable::open(
            tenantId: self::TENANT_ID,
            encounterAccountId: 1,
            tutorId: 3,
            totalCents: self::TOTAL_CENTS,
        );
        $receivables->save($receivable);

        $payments = new FakePaymentRepository(self::TENANT_ID);
        $financialEntries = new FakeFinancialEntryRepository(self::TENANT_ID);
        $authorization = new FakeAuthorizationPolicy(allowed: true);
        $financialEntryService = new FinancialEntryService($financialEntries, $authorization, $context);

        $service = new PaymentService(
            $payments,
            $receivables,
            $cashSessions,
            $financialEntryService,
            $authorization,
            $context,
        );

        return [$service, $receivable->id(), $cashSession->id(), $payments, $receivables, $financialEntries];
    }

    /**
     * Criterion 5: register() with amount_cents that would push
     * receivable.paid_cents past receivable.total_cents throws
     * OverpaymentException and writes nothing at all — no Payment, no
     * Receivable mutation, no FinancialEntry.
     */
    public function testRegisterExceedingBalanceDueThrowsAndPersistsNothing(): void
    {
        [$service, $receivableId, $cashSessionId, $payments, $receivables, $financialEntries] = $this->buildService();

        Assert::throws(
            OverpaymentException::class,
            fn () => $service->register($receivableId, $cashSessionId, Payment::METHOD_CASH, self::TOTAL_CENTS + 1, 1, self::ACTION),
        );

        Assert::count(0, $payments->listByReceivable($receivableId), 'No payment must be written on overpayment');

        $reloadedReceivable = $receivables->findById($receivableId);
        Assert::notNull($reloadedReceivable);
        Assert::same(0, $reloadedReceivable->paidCents(), 'paid_cents must remain untouched on overpayment');
        Assert::same(Receivable::STATUS_OPEN, $reloadedReceivable->status(), 'status must remain untouched on overpayment');

        Assert::count(0, $financialEntries->listBySystemUnitAndPeriod(self::UNIT_ID, '2000-01-01', '2999-12-31'), 'No financial_entry must be written on overpayment');
    }

    /**
     * Criterion 6: two sequential partial payments move receivable.status
     * from 'open' to 'partially_paid' after the first, then to 'paid' once
     * the second completes the total exactly.
     */
    public function testTwoSequentialPartialPaymentsTransitionStatusCorrectly(): void
    {
        [$service, $receivableId, $cashSessionId, $payments, $receivables] = $this->buildService();

        $firstPayment = $service->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 4000, 1, self::ACTION);
        Assert::notNull($firstPayment->id());

        $afterFirst = $receivables->findById($receivableId);
        Assert::notNull($afterFirst);
        Assert::same(4000, $afterFirst->paidCents());
        Assert::same(Receivable::STATUS_PARTIALLY_PAID, $afterFirst->status(), 'Status must be partially_paid after the first partial payment');

        $secondPayment = $service->register($receivableId, $cashSessionId, Payment::METHOD_PIX, 6000, 1, self::ACTION);
        Assert::notNull($secondPayment->id());

        $afterSecond = $receivables->findById($receivableId);
        Assert::notNull($afterSecond);
        Assert::same(self::TOTAL_CENTS, $afterSecond->paidCents());
        Assert::same(Receivable::STATUS_PAID, $afterSecond->status(), 'Status must be paid once paid_cents reaches total_cents exactly');

        Assert::count(2, $payments->listByReceivable($receivableId));
    }

    /**
     * Proves listOpenReceivables() delegates 100% to
     * ReceivableRepositoryInterface::listOpen(): an 'open' receivable and a
     * 'partially_paid' one both come back, while a receivable already fully
     * 'paid' does not.
     */
    public function testListOpenReceivablesReturnsOpenAndPartiallyPaidButNotPaid(): void
    {
        [$service, $openReceivableId, $cashSessionId, , $receivables] = $this->buildService();

        $partiallyPaidReceivable = Receivable::open(
            tenantId: self::TENANT_ID,
            encounterAccountId: 2,
            tutorId: 3,
            totalCents: self::TOTAL_CENTS,
        );
        $receivables->save($partiallyPaidReceivable);
        $service->register($partiallyPaidReceivable->id(), $cashSessionId, Payment::METHOD_CASH, 1000, 1, self::ACTION);

        $paidReceivable = Receivable::open(
            tenantId: self::TENANT_ID,
            encounterAccountId: 3,
            tutorId: 3,
            totalCents: self::TOTAL_CENTS,
        );
        $receivables->save($paidReceivable);
        $service->register($paidReceivable->id(), $cashSessionId, Payment::METHOD_CASH, self::TOTAL_CENTS, 1, self::ACTION);

        $open = $service->listOpenReceivables();
        $openIds = array_map(static fn (Receivable $receivable): ?int => $receivable->id(), $open);

        Assert::count(2, $open);
        Assert::true(in_array($openReceivableId, $openIds, true), 'Open receivable must be listed');
        Assert::true(in_array($partiallyPaidReceivable->id(), $openIds, true), 'Partially paid receivable must be listed');
        Assert::true(!in_array($paidReceivable->id(), $openIds, true), 'Fully paid receivable must not be listed');
    }

    /**
     * T-14: register() with 'pix' writes one income financial_entry whose
     * payment_method is 'pix', while category keeps the method as before
     * (the donut and the labels read category and must not change).
     */
    public function testRegisterRecordsPaymentMethodOnFinancialEntryKeepingCategory(): void
    {
        [$service, $receivableId, $cashSessionId, , , $financialEntries] = $this->buildService();

        $service->register($receivableId, $cashSessionId, Payment::METHOD_PIX, 2500, 1, self::ACTION);

        $entries = $financialEntries->listBySystemUnitAndPeriod(self::UNIT_ID, '2000-01-01', '2999-12-31');
        Assert::count(1, $entries);
        Assert::same(FinancialEntry::TYPE_INCOME, $entries[0]->entryType());
        Assert::same('pix', $entries[0]->paymentMethod());
        Assert::same('pix', $entries[0]->category());
    }

    /**
     * T-14: FinancialEntry::record() rejects a payment method outside the
     * Payment::METHOD_* list with the exact message.
     */
    public function testFinancialEntryRecordRejectsUnknownPaymentMethod(): void
    {
        $message = null;

        try {
            FinancialEntry::record(
                tenantId: self::TENANT_ID,
                systemUnitId: self::UNIT_ID,
                entryType: FinancialEntry::TYPE_EXPENSE,
                category: 'supplies',
                amountCents: 100,
                referenceType: null,
                referenceId: null,
                occurredAt: new DateTimeImmutable(),
                systemUserId: 1,
                paymentMethod: 'cheque',
            );
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        Assert::same('payment_method must be one of: cash, debit_card, credit_card, pix, bank_transfer', $message);
    }

    /** T-14: payment_method is optional; omitted, it stays null. */
    public function testFinancialEntryRecordWithoutPaymentMethodKeepsNull(): void
    {
        $entry = FinancialEntry::record(
            tenantId: self::TENANT_ID,
            systemUnitId: self::UNIT_ID,
            entryType: FinancialEntry::TYPE_EXPENSE,
            category: 'supplies',
            amountCents: 100,
            referenceType: null,
            referenceId: null,
            occurredAt: new DateTimeImmutable(),
            systemUserId: 1,
        );

        Assert::null($entry->paymentMethod());
    }
}
