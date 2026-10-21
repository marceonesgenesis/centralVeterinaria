<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use RuntimeException;

/**
 * Inserção de fatura que repete uma chave única de `subscription_invoice`
 * (`local_key`, `stripe_invoice_id` ou `stripe_payment_intent_id`): a fatura
 * já existe. A transação do chamador continua utilizável (o MySQL desfaz só
 * a instrução).
 */
final class DuplicateInvoice extends RuntimeException
{
    public static function forInvoice(SubscriptionInvoice $invoice, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Subscription invoice already exists (key=%s, local_key=%s, stripe_invoice_id=%s, stripe_payment_intent_id=%s)',
                self::collidedKey($previous) ?? '-',
                $invoice->localKey() ?? '-',
                $invoice->stripeInvoiceId() ?? '-',
                $invoice->stripePaymentIntentId() ?? '-',
            ),
            0,
            $previous,
        );
    }

    /** Nome da UNIQUE na mensagem do MySQL ("... for key 'subscription_invoice.subscription_invoice_pi_uq'"). */
    private static function collidedKey(?\Throwable $previous): ?string
    {
        $message = $previous instanceof \PDOException ? (string) ($previous->errorInfo[2] ?? $previous->getMessage()) : '';

        if (preg_match("/for key '(?:[^'.]+\\.)?([^']+)'/", $message, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
