<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\SubscriptionInvoiceRepositoryInterface;
use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeClientInterface;
use CentralVet\Communication\MessageChannelProviderInterface;
use CentralVet\Communication\OutgoingMessage;
use DateTimeImmutable;

/**
 * Estorno do Pix pago depois do cancelamento (Etapa 5, T-22): rede de
 * segurança da anulação da fatura futura (T-21) para o pagamento que chega
 * mesmo assim. O BillingEventProcessor marca a fatura `paid` sem prolongar a
 * assinatura; o reconciliador, fora da requisição do Stripe, chama refund()
 * para cada id de listPaidPixAfterCancellation().
 *
 * Numa transação: fatura relida FOR UPDATE (depois a assinatura, a ordem do
 * BillingEventProcessor) e o critério conferido de novo; sem ele,
 * `not_applicable`. Estorno total do PaymentIntent da fatura com
 * Idempotency-Key `refund-cancelled-pix-<id>`; cobrança já estornada
 * (`charge_already_refunded`) reaproveita o estorno de GET /v1/refunds. A
 * fatura fica `refunded` (applyRefund do valor pago).
 *
 * Depois do commit: linha no error_log e, com $operatorEmail, alerta ao
 * operador (falha do e-mail só vai ao log). StripeApiException é logada e
 * relançada; nada é gravado e a próxima execução repete com a mesma chave.
 */
final class CancelledPixRefundService
{
    public const RESULT_REFUNDED = 'refunded';
    public const RESULT_NOT_APPLICABLE = 'not_applicable';

    public function __construct(
        private readonly TransactionRunner $tx,
        private readonly SubscriptionInvoiceRepositoryInterface $invoices,
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly StripeClientInterface $stripe,
        private readonly MessageChannelProviderInterface $email,
        private readonly string $operatorEmail,
    ) {
    }

    public function refund(int $invoiceId, DateTimeImmutable $now): string
    {
        try {
            $refunded = $this->tx->run(function () use ($invoiceId, $now): ?array {
                $invoice = $this->invoices->findByIdForUpdate($invoiceId);
                if ($invoice === null || !self::isRefundable($invoice)) {
                    return null;
                }

                $subscription = $this->subscriptions->findByIdForUpdate($invoice->subscriptionId());
                if ($subscription === null || !self::isAfterCancellation($subscription, $invoice)) {
                    return null;
                }

                $refundId = $this->createRefund($invoice);
                $invoice->applyRefund($invoice->amountPaidCents(), max($invoice->lastEventCreated(), $now->getTimestamp()));
                $this->invoices->save($invoice);

                return [$invoice, $subscription, $refundId];
            });
        } catch (StripeApiException $e) {
            error_log('[CancelledPixRefund] ' . json_encode([
                'event' => 'cancelled_pix_refund_failed',
                'invoice' => $invoiceId,
                'exception' => $e::class,
            ]));

            throw $e;
        }

        if ($refunded === null) {
            return self::RESULT_NOT_APPLICABLE;
        }

        [$invoice, $subscription, $refundId] = $refunded;
        error_log('[CancelledPixRefund] ' . json_encode([
            'event' => 'cancelled_pix_refunded',
            'invoice' => $invoiceId,
            'subscription' => $subscription->id(),
            'refund' => $refundId,
        ]));
        $this->alertOperator($invoice, $subscription, $refundId);

        return self::RESULT_REFUNDED;
    }

    private static function isRefundable(SubscriptionInvoice $invoice): bool
    {
        return $invoice->localKey() !== null
            && $invoice->status() === SubscriptionInvoice::STATUS_PAID
            && $invoice->amountRefundedCents() === 0
            && $invoice->stripePaymentIntentId() !== null;
    }

    private static function isAfterCancellation(Subscription $subscription, SubscriptionInvoice $invoice): bool
    {
        if (!$subscription->cancelAtPeriodEnd() && $subscription->status() !== Subscription::STATUS_CANCELLED) {
            return false;
        }

        $periodStart = $invoice->periodStart();
        $currentEnd = $subscription->currentPeriodEnd();

        return $periodStart !== null && $currentEnd !== null && $periodStart >= $currentEnd;
    }

    private function createRefund(SubscriptionInvoice $invoice): string
    {
        $paymentIntent = (string) $invoice->stripePaymentIntentId();

        try {
            $refund = $this->stripe->request('POST', '/v1/refunds', [
                'payment_intent' => $paymentIntent,
                'reason' => 'requested_by_customer',
                'metadata' => ['subscription_invoice' => (string) $invoice->id(), 'reason' => 'subscription_cancelled'],
            ], 'refund-cancelled-pix-' . $invoice->id());
        } catch (StripeApiException $e) {
            if ($e->stripeCode() !== 'charge_already_refunded') {
                throw $e;
            }

            $list = $this->stripe->request('GET', '/v1/refunds', ['payment_intent' => $paymentIntent, 'limit' => 1]);
            $refund = is_array($list['data'][0] ?? null) ? $list['data'][0] : [];
        }

        $id = $refund['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new StripeApiException('Stripe refund without id', 0);
        }

        return $id;
    }

    private function alertOperator(SubscriptionInvoice $invoice, Subscription $subscription, string $refundId): void
    {
        if (trim($this->operatorEmail) === '') {
            return;
        }

        $body = implode("\n", [
            'Um pagamento Pix chegou depois do cancelamento da assinatura e foi estornado automaticamente.',
            '',
            'Fatura: ' . $invoice->id(),
            'Assinatura: ' . $subscription->id() . ' (' . ($subscription->status() === Subscription::STATUS_CANCELLED ? 'cancelada' : 'cancelamento agendado') . ')',
            'Clínica (tenant): ' . (string) $subscription->tenantId(),
            'Plano: ' . PlanEntitlements::forPlan($subscription->planId())->name(),
            'Período da fatura: ' . $invoice->periodStart()?->format('d/m/Y') . ' a ' . $invoice->periodEnd()?->format('d/m/Y'),
            'Valor: R$ ' . number_format($invoice->amountPaidCents() / 100, 2, ',', '.'),
            'PaymentIntent: ' . (string) $invoice->stripePaymentIntentId(),
            'Estorno no Stripe: ' . $refundId,
        ]);

        try {
            $this->email->deliver(new OutgoingMessage(
                trim($this->operatorEmail),
                'Central Vet Pro: pagamento Pix estornado (assinatura cancelada)',
                $body,
                'cancelled-pix-refund-' . $invoice->id(),
            ));
        } catch (\Throwable $e) {
            error_log('[CancelledPixRefund] ' . json_encode([
                'event' => 'cancelled_pix_refund_alert_failed',
                'invoice' => $invoice->id(),
                'exception' => $e::class,
            ]));
        }
    }
}
