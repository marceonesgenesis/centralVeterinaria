<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Payment;
use CentralVet\Domain\Receivable;
use CentralVet\Domain\Sale;

/**
 * Outcome of SaleCheckoutService::checkout() (Etapa 2, T-13): the recorded
 * sale, its receivable as re-read after the payment, and the cash payment
 * (null for a credit sale or a zero-total sale).
 */
final class SaleCheckoutResult
{
    public function __construct(
        public readonly Sale $sale,
        public readonly Receivable $receivable,
        public readonly ?Payment $payment,
    ) {
    }
}
