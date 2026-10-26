<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\SubscriptionInvoiceRepositoryInterface;
use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeClientInterface;
use DateTimeImmutable;

/**
 * Cancelamento da assinatura pelo cliente, com efeito no fim do período
 * pago (Etapa 5, T-08). Sem reativação pelo app.
 *
 * Cartão: `POST /v1/subscriptions/<id>` com `cancel_at_period_end=true` e
 * Idempotency-Key `cancel-subscription-<id local>`, antes de gravar e fora
 * da transação (não segura a trava da linha durante a chamada de rede); o
 * `customer.subscription.deleted` do fim do período leva a `cancelled`
 * (BillingEventProcessor). Falha do Stripe não grava nada.
 * Pix (sem Subscription no Stripe): marca local; o BillingReconciler
 * deixa de emitir fatura e, vencido o período, finaliza como `cancelled`.
 * A fatura do período seguinte já emitida (3 dias antes do fim) e ainda
 * aberta é anulada (T-21): antes da transação, o PaymentIntent dela é
 * cancelado no Stripe com Idempotency-Key `cancel-pix-intent-<fatura>`
 * (`payment_intent_unexpected_state` consulta o PI: `canceled`/`succeeded`
 * seguem, o pago é estornado pelo reconciliador); na transação, a fatura é
 * relida com trava e vira `void`. Com o cancelamento já agendado, a fatura
 * futura que sobrou (falha anterior) ainda é anulada.
 *
 * A gravação relê a assinatura com findByIdForUpdate dentro do
 * TransactionRunner, de modo que dois pedidos simultâneos gravam uma vez.
 */
final class SubscriptionCancellationService
{
    public const RESULT_SCHEDULED = 'scheduled';
    public const RESULT_ALREADY_SCHEDULED = 'already_scheduled';

    public const NO_ACTIVE_SUBSCRIPTION = 'No active subscription to cancel';
    public const PROVIDER_FAILED = 'The payment provider did not confirm the cancellation. Try again in a few minutes';

    private const INVOICE_LOOKUP_LIMIT = 10;
    private const INTENT_SETTLED_STATUSES = ['canceled', 'succeeded'];

    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly StripeClientInterface $stripe,
        private readonly TransactionRunner $tx,
        private readonly SubscriptionInvoiceRepositoryInterface $invoices,
    ) {
    }

    /**
     * @return self::RESULT_*
     *
     * @throws SubscriptionCancellationException sem assinatura ativa ou com o Stripe sem confirmar
     */
    public function request(int $tenantId, int $userId, DateTimeImmutable $now): string
    {
        $subscription = $this->subscriptions->findByTenantId($tenantId);
        if ($subscription === null || $subscription->id() === null || $subscription->status() === Subscription::STATUS_CANCELLED) {
            throw new SubscriptionCancellationException(self::NO_ACTIVE_SUBSCRIPTION);
        }

        $id = (int) $subscription->id();
        $alreadyScheduled = $subscription->cancelAtPeriodEnd();
        $futureInvoiceIds = [];

        if ($subscription->paymentMethod() === Subscription::METHOD_CARD) {
            if ($alreadyScheduled) {
                return self::RESULT_ALREADY_SCHEDULED;
            }
            $this->scheduleOnStripe($subscription, $id);
        } else {
            foreach ($this->futurePixInvoices($subscription) as $invoice) {
                $this->cancelPixIntent($invoice, $id);
                $futureInvoiceIds[] = (int) $invoice->id();
            }

            if ($alreadyScheduled && $futureInvoiceIds === []) {
                return self::RESULT_ALREADY_SCHEDULED;
            }
        }

        return $this->tx->run(function () use ($id, $userId, $now, $futureInvoiceIds): string {
            $locked = $this->subscriptions->findByIdForUpdate($id);
            if ($locked === null || $locked->status() === Subscription::STATUS_CANCELLED) {
                throw new SubscriptionCancellationException(self::NO_ACTIVE_SUBSCRIPTION);
            }

            foreach ($futureInvoiceIds as $invoiceId) {
                $invoice = $this->invoices->findByIdForUpdate($invoiceId);
                if ($invoice !== null && $invoice->voidForCancellation()) {
                    $this->invoices->save($invoice);
                }
            }

            if (!$locked->requestCancellation($userId, $now)) {
                return self::RESULT_ALREADY_SCHEDULED;
            }

            $this->subscriptions->save($locked);

            return self::RESULT_SCHEDULED;
        });
    }

    /**
     * Faturas locais abertas de período que começa no fim do período corrente
     * ou depois; a do período corrente não entra.
     *
     * @return list<SubscriptionInvoice>
     */
    private function futurePixInvoices(Subscription $subscription): array
    {
        $periodEnd = $subscription->currentPeriodEnd();
        if ($periodEnd === null) {
            return [];
        }

        $future = [];
        foreach ($this->invoices->listBySubscription((int) $subscription->id(), self::INVOICE_LOOKUP_LIMIT) as $invoice) {
            $periodStart = $invoice->periodStart();
            if (
                $invoice->id() !== null
                && $invoice->localKey() !== null
                && $invoice->status() === SubscriptionInvoice::STATUS_OPEN
                && $periodStart !== null
                && $periodStart >= $periodEnd
            ) {
                $future[] = $invoice;
            }
        }

        return $future;
    }

    /**
     * Cancela o PaymentIntent da fatura futura; sem PI não chama o Stripe.
     * `payment_intent_unexpected_state` consulta o PI e segue se ele já está
     * `canceled` ou `succeeded`; qualquer outra falha vira PROVIDER_FAILED.
     */
    private function cancelPixIntent(SubscriptionInvoice $invoice, int $subscriptionId): void
    {
        $paymentIntentId = $invoice->stripePaymentIntentId();
        if ($paymentIntentId === null) {
            return;
        }

        $invoiceId = (int) $invoice->id();
        try {
            $this->stripe->request(
                'POST',
                '/v1/payment_intents/' . $paymentIntentId . '/cancel',
                [],
                'cancel-pix-intent-' . $invoiceId,
            );

            return;
        } catch (StripeApiException $e) {
            if ($e->stripeCode() === 'payment_intent_unexpected_state' && $this->intentIsSettled($paymentIntentId)) {
                return;
            }

            error_log('[SubscriptionCancellation] ' . json_encode([
                'event' => 'cancel_pix_intent_failed',
                'subscription' => $subscriptionId,
                'invoice' => $invoiceId,
                'exception' => $e::class,
                'http_status' => $e->httpStatus(),
                'stripe_code' => $e->stripeCode(),
            ]));

            throw new SubscriptionCancellationException(self::PROVIDER_FAILED, 0, $e);
        }
    }

    private function intentIsSettled(string $paymentIntentId): bool
    {
        try {
            $intent = $this->stripe->request('GET', '/v1/payment_intents/' . $paymentIntentId);
        } catch (StripeApiException) {
            return false;
        }

        return in_array($intent['status'] ?? null, self::INTENT_SETTLED_STATUSES, true);
    }

    private function scheduleOnStripe(Subscription $subscription, int $id): void
    {
        $stripeSubscriptionId = $subscription->stripeSubscriptionId();
        if ($stripeSubscriptionId === null) {
            throw new SubscriptionCancellationException(self::NO_ACTIVE_SUBSCRIPTION);
        }

        try {
            $this->stripe->request(
                'POST',
                '/v1/subscriptions/' . $stripeSubscriptionId,
                ['cancel_at_period_end' => 'true'],
                'cancel-subscription-' . $id,
            );
        } catch (StripeApiException $e) {
            error_log('[SubscriptionCancellation] ' . json_encode([
                'event' => 'cancel_at_period_end_failed',
                'subscription' => $id,
                'exception' => $e::class,
                'http_status' => $e->httpStatus(),
            ]));

            throw new SubscriptionCancellationException(self::PROVIDER_FAILED, 0, $e);
        }
    }
}
