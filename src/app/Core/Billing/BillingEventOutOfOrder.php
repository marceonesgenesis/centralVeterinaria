<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use RuntimeException;

/**
 * O evento depende de algo que ainda não foi registrado (pedido, assinatura
 * ou fatura). O chamador desfaz a transação e marca o evento como `failed`
 * para nova tentativa (webhook reenviado pelo Stripe ou reconciliador).
 */
final class BillingEventOutOfOrder extends RuntimeException
{
    public static function missing(string $what, string $key, string $eventType): self
    {
        return new self(sprintf('%s "%s" not registered yet for %s', $what, $key, $eventType));
    }
}
