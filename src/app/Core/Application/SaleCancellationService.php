<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\ReceivableRepositoryInterface;
use CentralVet\Domain\Contract\SaleRepositoryInterface;
use CentralVet\Domain\Receivable;
use CentralVet\Domain\Sale;
use CentralVet\Domain\StockMovement;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Traceable sale cancellation (Etapa 2, T-14): returns the stock the sale
 * consumed, reverses the payments of its receivable (in the open cash
 * session of the SALE's unit) and cancels the receivable, then records
 * reason, user and date on the sale.
 *
 * The caller owns the transaction (TTransaction in the form); the sale is
 * read with findByIdForUpdate(), which requires it and serializes
 * concurrent cancellations of the same sale. Cancelling an already
 * cancelled sale returns alreadyCancelled=true without writing, but only
 * after authorization, so a denied user never learns more than before.
 * A credit sale without payments cancels without an open cash session.
 */
final class SaleCancellationService
{
    public function __construct(
        private readonly SaleRepositoryInterface $sales,
        private readonly ReceivableRepositoryInterface $receivables,
        private readonly StockService $stock,
        private readonly PaymentReversalService $reversals,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the sale does not exist in the
     *         tenant or the reason is empty/too long (nothing is written).
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied before any write.
     * @throws \CentralVet\Domain\Exception\CashSessionRequiredException when
     *         there is a payment to reverse and the sale unit has no open
     *         cash session (the caller rolls the transaction back).
     */
    public function cancel(int $saleId, string $reason, int $systemUserId, string $action): SaleCancellationResult
    {
        /** @var Sale|null $sale */
        $sale = $this->sales->findByIdForUpdate($saleId);

        if ($sale === null) {
            throw new InvalidArgumentException("Sale {$saleId} not found");
        }

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $sale->systemUnitId(),
            entityType: 'sale',
            entityId: $saleId,
        ))->assertAllowed();

        if ($sale->isCancelled()) {
            return new SaleCancellationResult($sale, true, 0, []);
        }

        $sale->cancel($reason, $systemUserId, new DateTimeImmutable());

        $returnedUnits = $this->stock->reverseReferenceConsumption(
            $this->context->tenantId(),
            'sale',
            $saleId,
            StockMovement::REASON_SALE_CONSUMPTION,
            StockMovement::REASON_SALE_CANCELLATION,
            $systemUserId,
        );

        $reversals = [];

        /** @var Receivable|null $receivable */
        $receivable = $this->receivables->findBySaleId($saleId);

        if ($receivable !== null) {
            $reversals = $this->reversals->cancelReceivable(
                (int) $receivable->id(),
                $sale->systemUnitId(),
                $reason,
                $systemUserId,
                $action,
            );
        }

        $this->sales->save($sale);

        return new SaleCancellationResult($sale, false, $returnedUnits, $reversals);
    }
}
