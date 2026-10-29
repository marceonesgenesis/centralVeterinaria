<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\PaymentReversal;
use CentralVet\Domain\Sale;

/**
 * Outcome of SaleCancellationService::cancel() (Etapa 2, T-14).
 * $alreadyCancelled is true when the sale was cancelled before this call;
 * then nothing was written, $returnedUnits is 0 and $reversals is [].
 */
final class SaleCancellationResult
{
    /**
     * @param list<PaymentReversal> $reversals reversals written by this call
     */
    public function __construct(
        public readonly Sale $sale,
        public readonly bool $alreadyCancelled,
        public readonly int $returnedUnits,
        public readonly array $reversals,
    ) {
    }
}
