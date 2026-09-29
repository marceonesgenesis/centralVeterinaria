<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Thrown by CentralVet\Application\CashSessionService::open() when
 * CashSessionRepositoryInterface::findOpenBySystemUnit() already returns an
 * open cash_session row for the requested system_unit_id. A unit can only
 * ever have one open till session at a time (checked by the Application
 * service before any write, same discipline as
 * StockService::consume()/InsufficientStockException): nothing is persisted
 * when this is thrown.
 *
 * Kept in Domain\Exception alongside InvalidStatusTransitionException /
 * InsufficientStockException (same rationale: a business rule owned by the
 * Domain layer, reusable from any presentation — Adianti today, REST/worker
 * later).
 */
final class CashSessionAlreadyOpenException extends RuntimeException
{
    private function __construct(
        public readonly int $systemUnitId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forSystemUnit(int $systemUnitId): self
    {
        return new self(
            $systemUnitId,
            "System unit {$systemUnitId} already has an open cash session",
        );
    }
}
