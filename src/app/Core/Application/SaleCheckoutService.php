<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\CashSession;
use CentralVet\Domain\Contract\CashSessionRepositoryInterface;
use CentralVet\Domain\Contract\ReceivableRepositoryInterface;
use CentralVet\Domain\Contract\SubmissionKeyRepositoryInterface;
use CentralVet\Domain\Exception\CashSessionRequiredException;
use CentralVet\Domain\Exception\DuplicateSubmissionException;
use CentralVet\Domain\Payment;
use CentralVet\Domain\Receivable;
use CentralVet\Domain\SubmissionToken;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sale checkout (Etapa 2, T-13 / F2 + F3): records the sale, opens its
 * receivable (receivable.sale_id) and, for a cash sale (payment method
 * informed), receives the whole total in the unit's open cash session — all
 * inside the caller's transaction (the form owns TTransaction; claim(),
 * the stock FOR UPDATE and the receivable FOR UPDATE refuse to run outside
 * one). A failure anywhere rolls the whole checkout back.
 *
 * Order (Interface of T-13):
 *   1. SubmissionToken::assertValid — nothing read or written on a bad token.
 *   2. claim('sale', token); an already claimed token throws
 *      DuplicateSubmissionException pointing at the first sale.
 *   3. Payment method outside Payment::METHODS → InvalidArgumentException.
 *   4. Cash sale without an open cash session in the unit →
 *      CashSessionRequiredException::forPayment(), before the sale exists.
 *   5. SaleService::create (authorization, references, stock).
 *   6. Receivable::openForSale saved ('paid' already when the total is 0).
 *   7. Cash sale with total > 0 → PaymentService::register without token
 *      (the sale token already deduplicates the whole checkout).
 *   8. Receivable re-read by findById; attach('sale', token, saleId).
 *
 * A zero-total sale never gets a payment row (payment_amount_ck), even with
 * a payment method: its receivable is born 'paid'.
 */
final class SaleCheckoutService
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly ReceivableRepositoryInterface $receivables,
        private readonly PaymentService $payments,
        private readonly CashSessionRepositoryInterface $cashSessions,
        private readonly SubmissionKeyRepositoryInterface $submissionKeys,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @param list<array{type: string, referenceId: int|string, quantity: int|string}> $items
     *
     * @throws InvalidArgumentException 'Invalid submission token', or an
     *         unknown payment method, or any SaleService::create validation.
     * @throws DuplicateSubmissionException when the token was already claimed.
     * @throws CashSessionRequiredException when a payment method is informed
     *         and the unit has no open cash session.
     */
    public function checkout(
        int $tenantId,
        int $systemUnitId,
        int $tutorId,
        ?int $patientId,
        ?int $encounterId,
        int $systemUserId,
        array $items,
        ?string $paymentMethod,
        string $submissionToken,
        string $action,
    ): SaleCheckoutResult {
        $submissionToken = SubmissionToken::assertValid($submissionToken);
        $this->context->assertTenant($tenantId);

        if (!$this->submissionKeys->claim(SubmissionToken::SCOPE_SALE, $submissionToken, $systemUserId)) {
            throw DuplicateSubmissionException::forReference(
                SubmissionToken::SCOPE_SALE,
                $this->submissionKeys->findReference(SubmissionToken::SCOPE_SALE, $submissionToken),
            );
        }

        if ($paymentMethod !== null && !in_array($paymentMethod, Payment::METHODS, true)) {
            throw new InvalidArgumentException("Unknown payment method \"{$paymentMethod}\"");
        }

        $cashSession = null;

        if ($paymentMethod !== null) {
            $cashSession = $this->cashSessions->findOpenBySystemUnit($systemUnitId);

            if (!$cashSession instanceof CashSession) {
                throw CashSessionRequiredException::forPayment();
            }
        }

        $sale = $this->sales->create(
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            tutorId: $tutorId,
            patientId: $patientId,
            encounterId: $encounterId,
            systemUserId: $systemUserId,
            items: $items,
            action: $action,
        );
        $saleId = (int) $sale->id();
        $totalCents = $sale->totalAmountCents();

        /** @var Receivable $receivable */
        $receivable = $this->receivables->save(
            Receivable::openForSale($tenantId, $saleId, $tutorId, $totalCents),
        );
        $receivableId = (int) $receivable->id();

        $payment = null;

        if ($cashSession instanceof CashSession && $paymentMethod !== null && $totalCents > 0) {
            $payment = $this->payments->register(
                $receivableId,
                (int) $cashSession->id(),
                $paymentMethod,
                $totalCents,
                $systemUserId,
                $action,
            );
        }

        /** @var Receivable|null $reloaded */
        $reloaded = $this->receivables->findById($receivableId);

        if (!$reloaded instanceof Receivable) {
            throw new RuntimeException("Receivable {$receivableId} of sale {$saleId} could not be re-read");
        }

        $this->submissionKeys->attach(SubmissionToken::SCOPE_SALE, $submissionToken, $saleId);

        return new SaleCheckoutResult($sale, $reloaded, $payment);
    }
}
