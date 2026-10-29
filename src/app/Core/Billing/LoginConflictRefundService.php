<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\BillingOrderRepositoryInterface;
use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeClientInterface;
use CentralVet\Communication\MessageChannelProviderInterface;
use CentralVet\Communication\OutgoingMessage;
use DateTimeImmutable;

/**
 * Estorno do pedido pago cujo e-mail já é login (Etapa 3, T-31): na corrida
 * em que o e-mail vira login entre o checkout e o webhook, o provisionamento
 * cai em `login_conflict` depois do pagamento. Chamado pelo reconciliador,
 * fora da requisição do Stripe, em três passos retomáveis, cada um na sua
 * transação com o pedido relido FOR UPDATE:
 *
 * 1. cancelamento (só cartão): GET da Subscription do Stripe e, se ainda não
 *    `canceled`, DELETE com Idempotency-Key `cancel-<public_id>` (a chave do
 *    Stripe vale 24 h, por isso o GET antes); grava subscription_cancelled_at;
 * 2. estorno total do PaymentIntent (Pix: o da sessão; cartão: o da fatura da
 *    sessão), com Idempotency-Key `refund-<public_id>`; cobrança já estornada
 *    (`charge_already_refunded`) reaproveita o estorno existente; grava
 *    stripe_refund_id;
 * 3. assinatura local `cancelled` (o reconciliador não emite fatura Pix) e
 *    pedido `refunded`.
 *
 * Depois do commit: linha no error_log e, com $operatorEmail, alerta ao
 * operador (falha do e-mail só vai ao log). StripeApiException em qualquer
 * passo é logada e relançada; o que já foi gravado fica para a retomada.
 */
final class LoginConflictRefundService
{
    public const RESULT_REFUNDED = 'refunded';
    public const RESULT_ALREADY_REFUNDED = 'already_refunded';
    public const RESULT_NOT_APPLICABLE = 'not_applicable';

    public function __construct(
        private readonly TransactionRunner $tx,
        private readonly BillingOrderRepositoryInterface $orders,
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly StripeClientInterface $stripe,
        private readonly MessageChannelProviderInterface $email,
        private readonly string $operatorEmail,
    ) {
    }

    public function refund(int $billingOrderId, DateTimeImmutable $now): string
    {
        $skip = $this->step('cancel_subscription', $billingOrderId, function (BillingOrder $order) use ($now): void {
            if ($order->paymentMethod() !== BillingOrder::METHOD_CARD || $order->subscriptionCancelledAt() !== null) {
                return;
            }

            $this->cancelStripeSubscription($order);
            $order->markSubscriptionCancelled($now);
            $this->orders->save($order);
        });
        if ($skip !== null) {
            return $skip;
        }

        $skip = $this->step('refund', $billingOrderId, function (BillingOrder $order): void {
            if ($order->stripeRefundId() !== null) {
                return;
            }

            $order->recordRefund($this->createRefund($order));
            $this->orders->save($order);
        });
        if ($skip !== null) {
            return $skip;
        }

        $refunded = null;
        $skip = $this->step('mark_refunded', $billingOrderId, function (BillingOrder $order) use ($now, &$refunded): void {
            $this->cancelLocalSubscription((int) $order->id(), $now);
            $order->markRefunded($now);
            $this->orders->save($order);
            $refunded = $order;
        });
        if ($skip !== null || !$refunded instanceof BillingOrder) {
            return $skip ?? self::RESULT_NOT_APPLICABLE;
        }

        error_log('[LoginConflictRefund] ' . json_encode([
            'event' => 'login_conflict_refunded',
            'order' => $refunded->publicId(),
            'payment_method' => $refunded->paymentMethod(),
            'refund' => $refunded->stripeRefundId(),
        ]));
        $this->alertOperator($refunded);

        return self::RESULT_REFUNDED;
    }

    /**
     * Relê o pedido FOR UPDATE numa transação própria e roda $work; devolve
     * o resultado quando o pedido não cabe mais (já estornado, fora do
     * login_conflict ou inexistente), ou null depois de rodar $work.
     *
     * @param \Closure(BillingOrder): void $work
     */
    private function step(string $step, int $billingOrderId, \Closure $work): ?string
    {
        $reference = (string) $billingOrderId;
        try {
            return $this->tx->run(function () use ($billingOrderId, $work, &$reference): ?string {
                $order = $this->orders->findByIdForUpdate($billingOrderId);
                if ($order === null) {
                    return self::RESULT_NOT_APPLICABLE;
                }
                if ($order->status() === BillingOrder::STATUS_REFUNDED) {
                    return self::RESULT_ALREADY_REFUNDED;
                }
                if (!$order->isLoginConflict()) {
                    return self::RESULT_NOT_APPLICABLE;
                }

                $reference = $order->publicId();
                $work($order);

                return null;
            });
        } catch (StripeApiException $e) {
            error_log('[LoginConflictRefund] ' . json_encode([
                'event' => 'login_conflict_refund_failed',
                'order' => $reference,
                'step' => $step,
                'exception' => $e::class,
            ]));

            throw $e;
        }
    }

    private function cancelStripeSubscription(BillingOrder $order): void
    {
        $stripeSubscriptionId = $order->stripeSubscriptionId();
        if ($stripeSubscriptionId === null || $stripeSubscriptionId === '') {
            return;
        }

        $path = '/v1/subscriptions/' . rawurlencode($stripeSubscriptionId);
        $current = $this->stripe->request('GET', $path);
        if (($current['status'] ?? null) === 'canceled') {
            return;
        }

        $this->stripe->request('DELETE', $path, [], 'cancel-' . $order->publicId());
    }

    private function createRefund(BillingOrder $order): string
    {
        $paymentIntent = $this->paymentIntentOf($order);

        try {
            $refund = $this->stripe->request('POST', '/v1/refunds', [
                'payment_intent' => $paymentIntent,
                'reason' => 'requested_by_customer',
                'metadata' => ['billing_order' => $order->publicId(), 'reason' => ProvisioningConflict::LOGIN_CONFLICT],
            ], 'refund-' . $order->publicId());
        } catch (StripeApiException $e) {
            if ($e->stripeCode() !== 'charge_already_refunded') {
                throw $e;
            }

            $list = $this->stripe->request('GET', '/v1/refunds', ['payment_intent' => $paymentIntent, 'limit' => 1]);
            $refund = is_array($list['data'][0] ?? null) ? $list['data'][0] : [];
        }

        $id = self::id($refund);
        if ($id === null) {
            throw new StripeApiException('Stripe refund without id', 0);
        }

        return $id;
    }

    /** Pix: PaymentIntent da sessão; cartão: o da fatura da sessão. */
    private function paymentIntentOf(BillingOrder $order): string
    {
        $sessionId = (string) $order->stripeCheckoutSessionId();
        $session = $this->stripe->request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId));

        if ($order->paymentMethod() === BillingOrder::METHOD_PIX) {
            $paymentIntent = self::id($session['payment_intent'] ?? null);
        } else {
            $invoiceId = self::id($session['invoice'] ?? null);
            if ($invoiceId === null) {
                throw new StripeApiException('Checkout session without invoice', 0);
            }
            $invoice = $this->stripe->request('GET', '/v1/invoices/' . rawurlencode($invoiceId));
            $paymentIntent = self::id($invoice['payment_intent'] ?? null);
        }

        if ($paymentIntent === null) {
            throw new StripeApiException('No payment intent to refund', 0);
        }

        return $paymentIntent;
    }

    private function cancelLocalSubscription(int $billingOrderId, DateTimeImmutable $now): void
    {
        $found = $this->subscriptions->findByBillingOrderId($billingOrderId);
        $subscription = $found?->id() !== null ? $this->subscriptions->findByIdForUpdate((int) $found->id()) : null;
        if ($subscription === null || $subscription->status() === Subscription::STATUS_CANCELLED) {
            return;
        }

        $subscription->applyProviderStatus('canceled', null, null, max($subscription->lastEventCreated(), $now->getTimestamp()), $now);
        $this->subscriptions->save($subscription);
    }

    private function alertOperator(BillingOrder $order): void
    {
        if (trim($this->operatorEmail) === '') {
            return;
        }

        $body = implode("\n", [
            'Um pedido pago foi estornado automaticamente porque o e-mail do responsável já é login no Central Vet Pro.',
            '',
            'Pedido: ' . $order->publicId(),
            'Plano: ' . PlanEntitlements::forPlan($order->planId())->name(),
            'Forma de pagamento: ' . ($order->paymentMethod() === BillingOrder::METHOD_PIX ? 'Pix' : 'cartão'),
            'Valor: R$ ' . number_format($order->priceCents() / 100, 2, ',', '.'),
            'Clínica: ' . $order->clinicName(),
            'E-mail do responsável: ' . $order->responsibleEmail(),
            'Estorno no Stripe: ' . (string) $order->stripeRefundId(),
        ]);

        try {
            $this->email->deliver(new OutgoingMessage(
                trim($this->operatorEmail),
                'Central Vet Pro: pedido ' . $order->publicId() . ' estornado (e-mail já cadastrado)',
                $body,
                'login-conflict-refund-' . $order->publicId(),
            ));
        } catch (\Throwable $e) {
            error_log('[LoginConflictRefund] ' . json_encode([
                'event' => 'login_conflict_alert_failed',
                'order' => $order->publicId(),
                'exception' => $e::class,
            ]));
        }
    }

    /** Id de um objeto do Stripe, expandido ou não. */
    private static function id(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
