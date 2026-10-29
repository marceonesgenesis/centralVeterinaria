<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Thrown when money would enter or leave the till of a system unit that has
 * no open cash session (Etapa 2, T-08): receiving a payment or reversing
 * one. Nothing is persisted when it is thrown. Messages are the English
 * keys of T-01 (translated by _t()).
 */
final class CashSessionRequiredException extends RuntimeException
{
    public static function forPayment(): self
    {
        return new self('Open a cash session for this unit before receiving payment');
    }

    public static function forReversal(): self
    {
        return new self('Open a cash session for this unit before reversing payments');
    }
}
