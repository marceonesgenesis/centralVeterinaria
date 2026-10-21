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
                'Subscription invoice already exists (local_key=%s, stripe_invoice_id=%s)',
                $invoice->localKey() ?? '-',
                $invoice->stripeInvoiceId() ?? '-',
            ),
            0,
            $previous,
        );
    }
}
