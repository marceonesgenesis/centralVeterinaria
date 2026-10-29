<?php

declare(strict_types=1);

namespace CentralVet\Billing\Contract;

use CentralVet\Billing\DuplicateInvoice;
use CentralVet\Billing\SubscriptionInvoice;
use DateTimeImmutable;

/**
 * Persistência de `subscription_invoice` (T-02). Os métodos *ForUpdate
 * exigem transação aberta (LogicException).
 */
interface SubscriptionInvoiceRepositoryInterface
{
    public function findByStripeInvoiceIdForUpdate(string $stripeInvoiceId): ?SubscriptionInvoice;

    /**
     * Grava a fatura nova e devolve o id (a entidade não muda; use withId).
     *
     * @throws DuplicateInvoice quando `local_key` (ou outra chave única da fatura) já existe
     */
    public function insert(SubscriptionInvoice $invoice): int;

    public function save(SubscriptionInvoice $invoice): void;

    /** @return list<SubscriptionInvoice> por `period_start` decrescente */
    public function listBySubscription(int $subscriptionId, int $limit): array;

    public function findByIdForUpdate(int $id): ?SubscriptionInvoice;

    public function findByPaymentIntentIdForUpdate(string $paymentIntentId): ?SubscriptionInvoice;

    public function findByLocalKey(string $localKey): ?SubscriptionInvoice;

    /**
     * @return list<int> faturas `open` com `local_key`, de assinatura `active`
     *                   ou `past_due` (suspensa/cancelada fica de fora, para não
     *                   ocupar o lote para sempre), e (`due_at <= now` ou
     *                   `pix_expires_at <= now` ou `stripe_payment_intent_id` nulo)
     */
    public function listOpenPix(DateTimeImmutable $now, int $limit): array;

    /**
     * Pix pago depois do cancelamento (Etapa 5, T-22), para o estorno.
     *
     * @return list<int> faturas com `local_key`, `paid`, sem estorno
     *                   (`amount_refunded_cents = 0`) e com PaymentIntent,
     *                   de assinatura com `cancel_at_period_end = 1` ou
     *                   `cancelled`, e `period_start >= current_period_end`
     *                   da assinatura; por id ascendente
     */
    public function listPaidPixAfterCancellation(int $limit): array;
}
