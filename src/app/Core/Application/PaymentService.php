<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\CashSession;
use CentralVet\Domain\Contract\CashSessionRepositoryInterface;
use CentralVet\Domain\Contract\PaymentRepositoryInterface;
use CentralVet\Domain\Contract\ReceivableRepositoryInterface;
use CentralVet\Domain\Contract\SubmissionKeyRepositoryInterface;
use CentralVet\Domain\Exception\CashSessionRequiredException;
use CentralVet\Domain\Exception\DuplicateSubmissionException;
use CentralVet\Domain\Exception\OverpaymentException;
use CentralVet\Domain\Payment;
use CentralVet\Domain\Receivable;
use CentralVet\Domain\SubmissionToken;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use case for the Payment aggregate (T-06): registering a payment against
 * a Receivable, captured within a CashSession.
 *
 * register() loads the real Receivable and CashSession first (repository
 * reads are already tenant-scoped, so a cross-tenant id resolves to null
 * below, same convention as PayableService::pay()/VaccineProtocolService::
 * findById()) and authorizes against the CashSession's own real
 * system_unit_id — NEVER a caller-supplied unit id, exactly the pattern
 * PayableService::pay() already uses for its own resourceUnitId. A closed
 * cash session refuses the payment (CashSessionRequiredException since
 * Etapa 3 T-03, thrown before any mutation; the session is read FOR UPDATE) and an amount that would push
 * Receivable::paidCents() past Receivable::totalCents() refuses it too
 * (OverpaymentException, thrown by Receivable::recordPayment() itself
 * before any field on the Receivable is mutated) — in both cases nothing is
 * ever written.
 *
 * Only once both checks pass does this method write anything: it saves the
 * new Payment row, saves the Receivable with its now-incremented
 * paid_cents/status, and finally calls
 * CentralVet\Application\FinancialEntryService::record() internally
 * (entry_type='income', reference_type='payment', reference_id=the new
 * payment's id) — reusing the caller's own already-decided $action, same
 * pattern as PayableService::pay() reusing its $action for its own
 * FinancialEntryService::record() call. This project has no cross-service
 * database transaction primitive (documented limitation, see
 * PayableService/Phase 4's ProcedureExecutionService/SaleService): if
 * FinancialEntryService::record()'s own authorization or validation were to
 * fail after the Payment/Receivable have already been saved, the
 * financial_entry would be missing for an already-registered payment. This
 * is an accepted, documented gap — not silently ignored — mirroring the
 * same project-wide limitation those services already carry.
 *
 * $tenantId is never accepted as a parameter (not part of this task's
 * Interface spec, and none of the repositories consumed here take one
 * either, per ADR 0002) — the tenant is always resolved from the injected
 * TenantContext.
 *
 * Etapa 2 (T-11): with a submission token, register() validates it and
 * claims it in submission_key (scope 'payment') before reading anything; a
 * token already claimed throws DuplicateSubmissionException pointing at the
 * payment of the first submission. The receivable is always read with
 * findByIdForUpdate(), so two concurrent payments on the same receivable are
 * serialized by the database (the caller owns the transaction). Without a
 * token (internal call from the sale checkout) there is no deduplication,
 * but the same lock applies. A cancelled receivable refuses the payment
 * (Receivable::recordPayment) before anything is written.
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly ReceivableRepositoryInterface $receivables,
        private readonly CashSessionRepositoryInterface $cashSessions,
        private readonly FinancialEntryService $financialEntries,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        private readonly SubmissionKeyRepositoryInterface $submissionKeys,
    ) {
    }

    /**
     * Registers a payment of $amountCents, via $paymentMethod, against
     * $receivableId, captured within $cashSessionId.
     *
     * @throws InvalidArgumentException when $receivableId or $cashSessionId
     *         does not resolve to a row in the current tenant.
     * @throws CashSessionRequiredException when the cash session (read FOR
     *         UPDATE) is not `status='open'`. Nothing is written.
     * @throws OverpaymentException when `receivable.paid_cents +
     *         $amountCents` would exceed `receivable.total_cents`. Nothing
     *         is written.
     * @throws InvalidArgumentException when $submissionToken is given but is
     *         not a valid token ('Invalid submission token').
     * @throws DuplicateSubmissionException when $submissionToken was already
     *         claimed; nothing is read or written.
     */
    public function register(
        int $receivableId,
        int $cashSessionId,
        string $paymentMethod,
        int $amountCents,
        int $systemUserId,
        string $action,
        ?string $submissionToken = null,
    ): Payment {
        if ($submissionToken !== null) {
            $submissionToken = SubmissionToken::assertValid($submissionToken);

            if (!$this->submissionKeys->claim(SubmissionToken::SCOPE_PAYMENT, $submissionToken, $systemUserId)) {
                throw DuplicateSubmissionException::forReference(
                    SubmissionToken::SCOPE_PAYMENT,
                    $this->submissionKeys->findReference(SubmissionToken::SCOPE_PAYMENT, $submissionToken),
                );
            }
        }

        // FOR UPDATE: a concurrent payment on the same receivable waits here
        // until this transaction ends (T-11).
        /** @var Receivable|null $receivable */
        $receivable = $this->receivables->findByIdForUpdate($receivableId);

        if ($receivable === null) {
            throw new InvalidArgumentException("Receivable {$receivableId} not found");
        }

        // FOR UPDATE (Etapa 3, T-03): a concurrent close of this till waits
        // until this payment's transaction ends, and a payment arriving
        // after a close sees status 'closed' below.
        /** @var CashSession|null $cashSession */
        $cashSession = $this->cashSessions->findByIdForUpdate($cashSessionId);

        if ($cashSession === null) {
            throw new InvalidArgumentException("Cash session {$cashSessionId} not found");
        }

        // Unit-scope authorization against the cash session's own real
        // system_unit_id, resolved from the loaded aggregate — never from a
        // caller-supplied unit id — before any read of business state or
        // any write. Mirrors CashSessionService::close()/PayableService::
        // pay()'s "resolve the real unit, then authorize" ordering.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $cashSession->systemUnitId(),
            entityType: 'payment',
            entityId: null,
        ))->assertAllowed();

        if (!$cashSession->isOpen()) {
            throw CashSessionRequiredException::forPayment();
        }

        // Mutates only the in-memory Receivable. Throws OverpaymentException
        // before touching paid_cents/status when $amountCents would exceed
        // the balance due — so nothing below (Payment/Receivable saves,
        // FinancialEntryService::record()) ever runs in that case.
        $receivable->recordPayment($amountCents);

        $payment = Payment::register(
            tenantId: $this->context->tenantId(),
            receivableId: $receivableId,
            paymentMethod: $paymentMethod,
            amountCents: $amountCents,
            cashSessionId: $cashSessionId,
            systemUserId: $systemUserId,
            paidAt: new DateTimeImmutable(),
        );

        /** @var Payment $savedPayment */
        $savedPayment = $this->payments->save($payment);

        $this->receivables->save($receivable);

        $this->financialEntries->record(
            systemUnitId: $cashSession->systemUnitId(),
            entryType: 'income',
            category: $paymentMethod,
            amountCents: $amountCents,
            referenceType: 'payment',
            referenceId: $savedPayment->id(),
            systemUserId: $systemUserId,
            action: $action,
            paymentMethod: $paymentMethod,
        );

        if ($submissionToken !== null) {
            $this->submissionKeys->attach(SubmissionToken::SCOPE_PAYMENT, $submissionToken, (int) $savedPayment->id());
        }

        return $savedPayment;
    }

    /**
     * Lists every receivable still owed (status 'open' or
     * 'partially_paid') for the current tenant. Delegates 100% to
     * ReceivableRepositoryInterface::listOpen(), already tenant-scoped —
     * same plain-delegation pattern as PayableService::listOpen(): no
     * unit-scope authorization is run here, since Receivable carries no
     * system_unit_id of its own and this list is not tied to a single
     * caller-known unit the way register() is.
     *
     * @return list<Receivable>
     */
    public function listOpenReceivables(): array
    {
        /** @var list<Receivable> $receivables */
        $receivables = $this->receivables->listOpen();

        return $receivables;
    }
}
