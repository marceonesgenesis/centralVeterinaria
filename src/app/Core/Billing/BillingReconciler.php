<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\BillingEventRepositoryInterface;
use CentralVet\Billing\Contract\BillingOrderRepositoryInterface;
use CentralVet\Billing\Contract\SubscriptionInvoiceRepositoryInterface;
use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use CentralVet\Billing\Stripe\StripeClientInterface;
use DateTimeImmutable;
use Throwable;

/**
 * Reconciliação da cobrança (Etapa 3, T-18), rodada por cron
 * (bin/billing-reconcile.php). Cinco fases, nesta ordem:
 *
 * 1. Eventos perdidos: `GET /v1/events` desde `latestEventCreated() - 1 dia`
 *    (sem eventos: `now - 3 dias`), paginado por `starting_after`, aplicados
 *    do mais antigo ao mais novo por BillingEventIngestor::ingest (o
 *    duplicado sai `duplicate` e não conta como ingerido).
 * 1b. Pedidos pagos com login_conflict (T-31): LoginConflictRefundService
 *    cancela a Subscription do cartão, estorna e marca refunded; o erro de
 *    um pedido não interrompe os demais e o primeiro sobe no fim da fase.
 * 2. Pedidos pagos sem provisionamento, provisionamento falho ou convite não
 *    enviado: TenantProvisioningService::fulfill (abre a própria transação).
 * 3. Emissão Pix: assinatura e pedido travados (nessa ordem), issueNext
 *    (falha do Stripe deixa a fatura sem PI, commitada, para a fase 4).
 *    O e-mail do Pix sai depois do commit (PixInvoiceIssuer::deliverPending)
 *    e é descartado no rollback; a falha dele não desfaz fatura nem PI.
 * 4. Faturas Pix abertas: fatura, assinatura e pedido travados (a ordem do
 *    BillingEventProcessor: fatura → assinatura); vencida → markOverdue,
 *    numa transação própria antes de qualquer chamada ao Stripe;
 *    sem PaymentIntent ou com o Pix expirado → ensurePaymentIntent, só com a
 *    assinatura active/past_due (suspensa/cancelada não recebe Pix novo).
 * 5. Tolerância vencida: suspendIfGraceExpired com a assinatura travada.
 *
 * Cada item roda na sua transação (TransactionRunner), desfeita em
 * exceção, e a falha de um item só é logada (classe) e não interrompe os
 * demais. Falha de uma fase inteira (listagem no banco ou no Stripe) não impede as fases
 * seguintes; depois da última, a primeira dessas falhas sobe.
 *
 * ingest() e fulfill() são chamados fora de transação aberta pelo
 * reconciliador (o convite sai depois do commit). As escritas no Stripe
 * são o POST /v1/payment_intents do PixInvoiceIssuer e, no login_conflict,
 * o DELETE /v1/subscriptions e o POST /v1/refunds, todas com
 * Idempotency-Key.
 */
final class BillingReconciler
{
    public const EVENT_OVERLAP_SECONDS = 86400;
    public const FIRST_RUN_LOOKBACK_DAYS = 3;
    public const EVENTS_PAGE_SIZE = 100;
    public const MAX_EVENT_PAGES = 100;
    public const FULFILLMENT_BATCH = 50;
    public const PIX_BATCH = 100;
    public const GRACE_BATCH = 100;
    public const REFUND_BATCH = 50;

    private const REFRESHABLE_STATUSES = [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE];

    public function __construct(
        private readonly StripeClientInterface $stripe,
        private readonly BillingEventRepositoryInterface $events,
        private readonly BillingEventIngestor $ingestor,
        private readonly BillingOrderRepositoryInterface $orders,
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly SubscriptionInvoiceRepositoryInterface $invoices,
        private readonly PixInvoiceIssuer $pixIssuer,
        private readonly TenantProvisioningService $provisioning,
        private readonly TransactionRunner $tx,
        private readonly LoginConflictRefundService $refunds,
    ) {
    }

    /**
     * @return array{events_fetched: int, events_ingested: int, orders_fulfilled: int, orders_failed: int, orders_refunded: int, pix_invoices_issued: int, pix_invoices_overdue: int, pix_intents_refreshed: int, subscriptions_suspended: int}
     */
    public function run(DateTimeImmutable $now): array
    {
        $counters = [
            'events_fetched' => 0,
            'events_ingested' => 0,
            'orders_fulfilled' => 0,
            'orders_failed' => 0,
            'orders_refunded' => 0,
            'pix_invoices_issued' => 0,
            'pix_invoices_overdue' => 0,
            'pix_intents_refreshed' => 0,
            'subscriptions_suspended' => 0,
        ];
        $phaseFailure = null;

        foreach (['reconcileEvents', 'refundLoginConflicts', 'fulfillOrders', 'issuePixInvoices', 'refreshOpenPix', 'suspendGraceExpired'] as $phase) {
            try {
                $this->{$phase}($now, $counters);
            } catch (Throwable $e) {
                self::log(sprintf('phase %s failed: %s', $phase, $e::class));
                $phaseFailure ??= $e;
            }
        }

        if ($phaseFailure !== null) {
            throw $phaseFailure;
        }

        return $counters;
    }

    /** @param array<string, int> $counters */
    private function reconcileEvents(DateTimeImmutable $now, array &$counters): void
    {
        $latest = $this->events->latestEventCreated();
        $since = $latest !== null
            ? $latest - self::EVENT_OVERLAP_SECONDS
            : $now->modify(sprintf('-%d days', self::FIRST_RUN_LOOKBACK_DAYS))->getTimestamp();

        $fetched = [];
        $startingAfter = null;
        for ($page = 0; $page < self::MAX_EVENT_PAGES; $page++) {
            $params = ['created' => ['gte' => $since], 'limit' => self::EVENTS_PAGE_SIZE];
            if ($startingAfter !== null) {
                $params['starting_after'] = $startingAfter;
            }

            $response = $this->stripe->request('GET', '/v1/events', $params);
            $data = is_array($response['data'] ?? null) ? array_values($response['data']) : [];
            foreach ($data as $event) {
                if (is_array($event)) {
                    $fetched[] = $event;
                }
            }

            $last = $data === [] ? null : end($data);
            $startingAfter = is_array($last) && is_string($last['id'] ?? null) ? $last['id'] : null;
            if (($response['has_more'] ?? false) !== true || $startingAfter === null) {
                break;
            }

            if ($page === self::MAX_EVENT_PAGES - 1) {
                self::log(sprintf('events page cap %d reached; older events of this window were not fetched', self::MAX_EVENT_PAGES));
            }
        }

        $counters['events_fetched'] += count($fetched);

        // Stripe lista do mais novo ao mais antigo; aplica na ordem em que aconteceram.
        $fetched = array_reverse($fetched);
        usort($fetched, static fn (array $a, array $b): int => (int) ($a['created'] ?? 0) <=> (int) ($b['created'] ?? 0));

        foreach ($fetched as $event) {
            try {
                $result = $this->ingestor->ingest($event, (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now);
                if ($result !== BillingEventIngestor::DUPLICATE) {
                    $counters['events_ingested']++;
                }
            } catch (Throwable $e) {
                self::log(sprintf('event %s failed: %s', (string) ($event['id'] ?? '?'), $e::class));
            }
        }
    }

    /**
     * Pedido pago com login_conflict: cancelamento e estorno (T-31). Um
     * pedido que falha não impede os demais; o primeiro erro sobe no fim.
     *
     * @param array<string, int> $counters
     */
    private function refundLoginConflicts(DateTimeImmutable $now, array &$counters): void
    {
        $failure = null;

        foreach ($this->orders->listLoginConflictsToRefund(self::REFUND_BATCH) as $orderId) {
            try {
                if ($this->refunds->refund($orderId, $now) === LoginConflictRefundService::RESULT_REFUNDED) {
                    $counters['orders_refunded']++;
                }
            } catch (Throwable $e) {
                self::log(sprintf('refund of billing order %d failed: %s', $orderId, $e::class));
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param array<string, int> $counters */
    private function fulfillOrders(DateTimeImmutable $now, array &$counters): void
    {
        foreach ($this->orders->listPendingFulfillment(self::FULFILLMENT_BATCH) as $orderId) {
            try {
                $result = $this->provisioning->fulfill($orderId, $now);
            } catch (Throwable $e) {
                self::log(sprintf('billing order %d failed: %s', $orderId, $e::class));
                $counters['orders_failed']++;

                continue;
            }

            if (in_array($result->status(), [ProvisioningResult::PROVISIONED, ProvisioningResult::ALREADY_PROVISIONED], true)) {
                $counters['orders_fulfilled']++;
            } elseif ($result->status() === ProvisioningResult::FAILED) {
                $counters['orders_failed']++;
            }
        }
    }

    /** @param array<string, int> $counters */
    private function issuePixInvoices(DateTimeImmutable $now, array &$counters): void
    {
        $until = $now->modify(sprintf('+%d days', PixInvoiceIssuer::ISSUE_DAYS_BEFORE));

        foreach ($this->subscriptions->listPixDueForIssue($until, self::PIX_BATCH) as $subscriptionId) {
            try {
                $issued = $this->tx->run(function () use ($subscriptionId, $now): bool {
                    $subscription = $this->subscriptions->findByIdForUpdate($subscriptionId);
                    if ($subscription === null) {
                        return false;
                    }

                    $order = $this->orders->findByIdForUpdate($subscription->billingOrderId());
                    if ($order === null) {
                        return false;
                    }

                    return $this->pixIssuer->issueNext($subscription, $order->priceCents(), $order->responsibleEmail(), $now) !== null;
                });
            } catch (Throwable $e) {
                $this->pixIssuer->discardPending();
                self::log(sprintf('pix issue for subscription %d failed: %s', $subscriptionId, $e::class));

                continue;
            }

            $this->pixIssuer->deliverPending();

            if ($issued) {
                $counters['pix_invoices_issued']++;
            }
        }
    }

    /**
     * Duas transações por fatura: primeiro o vencimento (markOverdue, sem
     * Stripe), commitado; depois o PaymentIntent. Stripe fora do ar ou mal
     * configurado não impede past_due nem, depois, a suspensão.
     *
     * @param array<string, int> $counters
     */
    private function refreshOpenPix(DateTimeImmutable $now, array &$counters): void
    {
        foreach ($this->invoices->listOpenPix($now, self::PIX_BATCH) as $invoiceId) {
            try {
                $overdue = $this->tx->run(function () use ($invoiceId, $now): bool {
                    $invoice = $this->invoices->findByIdForUpdate($invoiceId);
                    if ($invoice === null || !$invoice->isOverdue($now)) {
                        return false;
                    }

                    $subscription = $this->subscriptions->findByIdForUpdate($invoice->subscriptionId());
                    if ($subscription === null || !$subscription->markOverdue($now)) {
                        return false;
                    }

                    $this->subscriptions->save($subscription);

                    return true;
                });
                $counters['pix_invoices_overdue'] += $overdue ? 1 : 0;
            } catch (Throwable $e) {
                self::log(sprintf('overdue check of pix invoice %d failed: %s', $invoiceId, $e::class));
            }

            try {
                $refreshed = $this->tx->run(function () use ($invoiceId, $now): bool {
                    $invoice = $this->invoices->findByIdForUpdate($invoiceId);
                    if ($invoice === null || !$invoice->needsPaymentIntent($now)) {
                        return false;
                    }

                    $subscription = $this->subscriptions->findByIdForUpdate($invoice->subscriptionId());
                    if ($subscription === null || !in_array($subscription->status(), self::REFRESHABLE_STATUSES, true)) {
                        return false;
                    }

                    $order = $this->orders->findByIdForUpdate($subscription->billingOrderId());
                    if ($order === null) {
                        return false;
                    }

                    return $this->pixIssuer->ensurePaymentIntent($invoice, $subscription->stripeCustomerId(), $order->responsibleEmail(), $now);
                });
            } catch (Throwable $e) {
                $this->pixIssuer->discardPending();
                self::log(sprintf('payment intent of pix invoice %d failed: %s', $invoiceId, $e::class));

                continue;
            }

            $this->pixIssuer->deliverPending();
            $counters['pix_intents_refreshed'] += $refreshed ? 1 : 0;
        }
    }

    /** @param array<string, int> $counters */
    private function suspendGraceExpired(DateTimeImmutable $now, array &$counters): void
    {
        foreach ($this->subscriptions->listGraceExpired($now, self::GRACE_BATCH) as $subscriptionId) {
            try {
                $suspended = $this->tx->run(function () use ($subscriptionId, $now): bool {
                    $subscription = $this->subscriptions->findByIdForUpdate($subscriptionId);
                    if ($subscription === null || !$subscription->suspendIfGraceExpired($now)) {
                        return false;
                    }

                    $this->subscriptions->save($subscription);

                    return true;
                });
            } catch (Throwable $e) {
                self::log(sprintf('grace check for subscription %d failed: %s', $subscriptionId, $e::class));

                continue;
            }

            if ($suspended) {
                $counters['subscriptions_suspended']++;
            }
        }
    }

    private static function log(string $message): void
    {
        error_log('[BillingReconciler] ' . $message);
    }
}
