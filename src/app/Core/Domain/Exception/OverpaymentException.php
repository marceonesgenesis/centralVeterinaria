<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Thrown by `CentralVet\Domain\Receivable::recordPayment()` (invoked from
 * `CentralVet\Application\PaymentService::register()`, T-06) when
 * `paid_cents + $amountCents` would exceed `total_cents`. Nothing is
 * persisted when this is thrown: `Receivable::recordPayment()` validates
 * before mutating `paid_cents`/`status`, so `PaymentService::register()`
 * never reaches `PaymentRepositoryInterface::save()`/
 * `ReceivableRepositoryInterface::save()` in that case.
 *
 * Kept in `Domain\Exception` alongside `DiscountExceedsSubtotalException`
 * (same rationale: a business rule owned by the Domain layer, reusable from
 * any presentation — Adianti today, REST/worker later).
 */
final class OverpaymentException extends RuntimeException
{
    private function __construct(
        public readonly int $totalCents,
        public readonly int $paidCents,
        public readonly int $amountCents,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forExcess(int $totalCents, int $paidCents, int $amountCents): self
    {
        return new self(
            $totalCents,
            $paidCents,
            $amountCents,
            "Payment of {$amountCents} cent(s) would raise paid_cents to " .
                ($paidCents + $amountCents) . ", exceeding total_cents of {$totalCents} cent(s)",
        );
    }
}
