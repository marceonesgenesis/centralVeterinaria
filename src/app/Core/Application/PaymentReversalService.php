<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\CashSession;
use CentralVet\Domain\Contract\CashSessionRepositoryInterface;
use CentralVet\Domain\Contract\PaymentRepositoryInterface;
use CentralVet\Domain\Contract\PaymentReversalRepositoryInterface;
use CentralVet\Domain\Contract\ReceivableRepositoryInterface;
use CentralVet\Domain\Exception\CashSessionRequiredException;
use CentralVet\Domain\Payment;
use CentralVet\Domain\PaymentReversal;
use CentralVet\Domain\Receivable;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Cancels a receivable reversing every payment it received (Etapa 2, T-12).
 *
 * Each reversal is recorded in the cash session currently OPEN for the unit
 * — a closed session is never rewritten, its payments stay as they were —
 * with the same method and amount as the reversed payment, plus an
 * `expense` financial_entry (reference_type `payment_reversal`). Cash
 * totals and the open balance net out reversals of the same session (T-08).
 *
 * The caller owns the transaction (TTransaction in the form); the
 * receivable is read with findByIdForUpdate(), which requires it. Calling
 * again on an already cancelled receivable returns [] without writing, so
 * a repeated cancellation is harmless. A receivable with no payment to
 * reverse cancels without requiring an open cash session.
 */
final class PaymentReversalService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly PaymentReversalRepositoryInterface $reversals,
        private readonly ReceivableRepositoryInterface $receivables,
        private readonly CashSessionRepositoryInterface $cashSessions,
        private readonly FinancialEntryService $financialEntries,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @return list<PaymentReversal> reversals written by this call ([] when
     *         the receivable was already cancelled or had no payment left
     *         to reverse)
     *
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied before any read or write.
     * @throws InvalidArgumentException when the receivable does not exist in the tenant.
     * @throws CashSessionRequiredException when there is a payment to reverse and
     *         $systemUnitId has no open cash session. Nothing is written.
     */
    public function cancelReceivable(
        int $receivableId,
        int $systemUnitId,
        string $reason,
        int $systemUserId,
        string $action,
    ): array {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $systemUnitId,
            entityType: 'receivable',
            entityId: $receivableId,
        ))->assertAllowed();

        /** @var Receivable|null $receivable */
        $receivable = $this->receivables->findByIdForUpdate($receivableId);

        if ($receivable === null) {
            throw new InvalidArgumentException("Receivable {$receivableId} not found");
        }

        if ($receivable->isCancelled()) {
            return [];
        }

        /** @var list<Payment> $pending */
        $pending = array_values(array_filter(
            $this->payments->listByReceivable($receivableId),
            fn (Payment $payment): bool => !$this->reversals->existsForPayment((int) $payment->id()),
        ));

        $reversed = [];

        if ($pending !== []) {
            /** @var CashSession|null $openSession */
            $openSession = $this->cashSessions->findOpenBySystemUnit($systemUnitId);

            if ($openSession === null) {
                throw CashSessionRequiredException::forReversal();
            }

            $now = new DateTimeImmutable();

            foreach ($pending as $payment) {
                $reversal = PaymentReversal::record(
                    tenantId: $this->context->tenantId(),
                    paymentId: (int) $payment->id(),
                    cashSessionId: (int) $openSession->id(),
                    paymentMethod: $payment->paymentMethod(),
                    amountCents: $payment->amountCents(),
                    reason: $reason,
                    systemUserId: $systemUserId,
                    reversedAt: $now,
                );

                /** @var PaymentReversal $saved */
                $saved = $this->reversals->save($reversal);

                $this->financialEntries->record(
                    systemUnitId: $systemUnitId,
                    entryType: 'expense',
                    category: $payment->paymentMethod(),
                    amountCents: $payment->amountCents(),
                    referenceType: 'payment_reversal',
                    referenceId: $saved->id(),
                    systemUserId: $systemUserId,
                    action: $action,
                    paymentMethod: $payment->paymentMethod(),
                );

                $reversed[] = $saved;
            }
        }

        $receivable->cancel();
        $this->receivables->save($receivable);

        return $reversed;
    }
}
