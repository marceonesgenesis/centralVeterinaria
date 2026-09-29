<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Thrown by `CentralVet\Domain\EncounterAccount::applyDiscount()` /
 * `refreshSubtotal()` when a discount would exceed the account's current
 * subtotal (the sum of its `encounter_account_item` rows at the time of the
 * call). Nothing is persisted when this is thrown:
 * `CentralVet\Application\EncounterAccountService::applyDiscount()` mutates
 * only the in-memory `EncounterAccount` before ever calling
 * `EncounterAccountRepositoryInterface::save()`, so a thrown exception here
 * means `discount_cents`/`total_cents` are never written.
 *
 * Kept in `Domain\Exception` alongside `InsufficientStockException` /
 * `CrossTenantReferenceException` (same rationale: a business rule owned by
 * the Domain layer, reusable from any presentation — Adianti today,
 * REST/worker later).
 */
final class DiscountExceedsSubtotalException extends RuntimeException
{
    private function __construct(
        public readonly int $subtotalCents,
        public readonly int $discountCents,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forExcess(int $subtotalCents, int $discountCents): self
    {
        return new self(
            $subtotalCents,
            $discountCents,
            "Discount of {$discountCents} cent(s) exceeds subtotal of {$subtotalCents} cent(s)",
        );
    }
}
