<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use RuntimeException;

/**
 * Quota do plano alcançada. A mensagem é a chave em inglês de T-01
 * ('Your plan allows up to <n> users|units'), que UserMessage/_t() traduz.
 */
final class QuotaExceededException extends RuntimeException
{
    public static function users(int $limit): self
    {
        return new self(sprintf('Your plan allows up to %d users', $limit));
    }

    public static function units(int $limit): self
    {
        return new self(sprintf('Your plan allows up to %d units', $limit));
    }
}
