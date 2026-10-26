<?php

declare(strict_types=1);

namespace CentralVet\Billing;

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
 * Pix (sem Subscription no Stripe): só a marca local; o BillingReconciler
 * deixa de emitir fatura e, vencido o período, finaliza como `cancelled`.
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

    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly StripeClientInterface $stripe,
        private readonly TransactionRunner $tx,
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

        if ($subscription->cancelAtPeriodEnd()) {
            return self::RESULT_ALREADY_SCHEDULED;
        }

        $id = (int) $subscription->id();
        if ($subscription->paymentMethod() === Subscription::METHOD_CARD) {
            $this->scheduleOnStripe($subscription, $id);
        }

        return $this->tx->run(function () use ($id, $userId, $now): string {
            $locked = $this->subscriptions->findByIdForUpdate($id);
            if ($locked === null || $locked->status() === Subscription::STATUS_CANCELLED) {
                throw new SubscriptionCancellationException(self::NO_ACTIVE_SUBSCRIPTION);
            }

            if (!$locked->requestCancellation($userId, $now)) {
                return self::RESULT_ALREADY_SCHEDULED;
            }

            $this->subscriptions->save($locked);

            return self::RESULT_SCHEDULED;
        });
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
