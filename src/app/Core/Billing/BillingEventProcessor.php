<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\BillingOrderRepositoryInterface;
use CentralVet\Billing\Contract\SubscriptionInvoiceRepositoryInterface;
use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use CentralVet\Billing\Stripe\StripeClientInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Traduz um evento do Stripe (já autenticado e deduplicado por `event_id`,
 * T-17) para pedido, assinatura e fatura (T-14).
 *
 * Cartão: Subscription do Stripe; o checkout pago busca a assinatura
 * (`GET /v1/subscriptions/<id>`) e os `invoice.*`/`customer.subscription.*`
 * acompanham o ciclo. Pix: sem Subscription no Stripe; o checkout pago cria
 * a assinatura local de 1 mês e a 1ª fatura já paga, e cada fatura seguinte
 * (T-23) é paga por `payment_intent.succeeded` com
 * `metadata[subscription_invoice]`.
 *
 * O `created` do evento é o `$eventCreated` do domínio, que descarta evento
 * mais antigo que o último aplicado. Exceção deliberada no Pix: o pagamento
 * de uma fatura que avança o período é aplicado mesmo com `created` menor
 * que o da assinatura, porque a ordem do ciclo Pix é a dos períodos locais
 * (o PaymentIntent da fatura nova pode ter sido criado antes do último
 * evento registrado); fatura de período anterior continua sujeita à guarda.
 *
 * Exige transação aberta pelo chamador (os *ForUpdate recusam sem ela) e
 * não comita. BillingEventOutOfOrder: o evento chegou antes do que ele
 * altera; o chamador desfaz e tenta depois.
 */
final class BillingEventProcessor
{
    private const INVOICE_EVENTS = [
        'invoice.created', 'invoice.finalized', 'invoice.paid',
        'invoice.payment_failed', 'invoice.voided', 'invoice.marked_uncollectible',
    ];

    public function __construct(
        private readonly BillingOrderRepositoryInterface $orders,
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly SubscriptionInvoiceRepositoryInterface $invoices,
        private readonly StripeClientInterface $stripe,
    ) {
    }

    /**
     * @param array<string, mixed> $event evento do Stripe (`type`, `created`, `data.object`)
     * @throws BillingEventOutOfOrder
     */
    public function process(array $event, DateTimeImmutable $now): BillingEventOutcome
    {
        $type = (string) ($event['type'] ?? '');
        $created = (int) ($event['created'] ?? 0);
        $object = $event['data']['object'] ?? null;

        if (!is_array($object)) {
            return BillingEventOutcome::ignored();
        }

        return match (true) {
            $type === 'checkout.session.completed' => ($object['payment_status'] ?? null) === 'paid'
                ? $this->checkoutPaid($object, $type, $created)
                : BillingEventOutcome::ignored(),
            $type === 'checkout.session.async_payment_succeeded' => $this->checkoutPaid($object, $type, $created),
            $type === 'checkout.session.expired',
            $type === 'checkout.session.async_payment_failed' => $this->checkoutExpired($object),
            in_array($type, self::INVOICE_EVENTS, true) => $this->invoiceEvent($object, $type, $created, $now),
            $type === 'customer.subscription.updated',
            $type === 'customer.subscription.deleted' => $this->subscriptionEvent($object, $type, $created, $now),
            $type === 'payment_intent.succeeded' => $this->paymentIntentSucceeded($object, $type, $created),
            $type === 'charge.refunded' => $this->chargeRefunded($object, $type, $created),
            default => BillingEventOutcome::ignored(),
        };
    }

    /** @param array<string, mixed> $session */
    private function checkoutPaid(array $session, string $type, int $created): BillingEventOutcome
    {
        $sessionId = (string) ($session['id'] ?? '');
        $order = $sessionId !== '' ? $this->orders->findByCheckoutSessionIdForUpdate($sessionId) : null;
        if ($order === null) {
            if (!self::marked($session['client_reference_id'] ?? null, $session['metadata']['billing_order'] ?? null)) {
                return self::foreign($type, $sessionId);
            }

            throw BillingEventOutOfOrder::missing('Billing order for checkout session', $sessionId, $type);
        }

        $orderId = (int) $order->id();
        $paidAt = self::fromUnix($created);
        $isCard = $order->paymentMethod() === BillingOrder::METHOD_CARD;
        $stripeSubscriptionId = $isCard ? self::id($session['subscription'] ?? null) : null;

        if ($isCard && $stripeSubscriptionId === null) {
            throw new RuntimeException(sprintf('Card checkout session "%s" has no subscription', $sessionId));
        }

        if (!$order->markPaid($stripeSubscriptionId, $paidAt)) {
            return BillingEventOutcome::ignored();
        }

        $this->orders->save($order);

        if ($this->subscriptions->findByBillingOrderId($orderId) !== null) {
            return BillingEventOutcome::processed($orderId);
        }

        $customerId = $order->stripeCustomerId() ?? self::id($session['customer'] ?? null) ?? '';

        if ($isCard) {
            $remote = $this->stripe->request('GET', '/v1/subscriptions/' . rawurlencode((string) $stripeSubscriptionId));
            $item = $remote['items']['data'][0] ?? [];
            $start = self::fromUnix($remote['current_period_start'] ?? $item['current_period_start'] ?? null);
            $end = self::fromUnix($remote['current_period_end'] ?? $item['current_period_end'] ?? null);
            if ($start === null || $end === null) {
                throw new RuntimeException(sprintf('Stripe subscription "%s" has no current period', $stripeSubscriptionId));
            }

            $this->subscriptions->insert(Subscription::start(
                billingOrderId: $orderId,
                planId: $order->planId(),
                paymentMethod: Subscription::METHOD_CARD,
                stripeCustomerId: $customerId,
                stripeSubscriptionId: $stripeSubscriptionId,
                stripeStatus: (string) ($remote['status'] ?? ''),
                periodStart: $start,
                periodEnd: $end,
                eventCreated: $created,
            ));

            return BillingEventOutcome::processed($orderId);
        }

        $periodEnd = $paidAt->modify('+1 month');
        $subscriptionId = $this->subscriptions->insert(Subscription::start(
            billingOrderId: $orderId,
            planId: $order->planId(),
            paymentMethod: Subscription::METHOD_PIX,
            stripeCustomerId: $customerId,
            stripeSubscriptionId: null,
            stripeStatus: 'active',
            periodStart: $paidAt,
            periodEnd: $periodEnd,
            eventCreated: $created,
        ));

        $invoice = SubscriptionInvoice::issuePix($subscriptionId, $order->priceCents(), $paidAt, $periodEnd, $paidAt);
        $paymentIntentId = self::id($session['payment_intent'] ?? null);
        if ($paymentIntentId !== null) {
            $invoice->attachPaymentIntent($paymentIntentId, null, null);
        }
        $invoice->markPaidByPaymentIntent((int) ($session['amount_total'] ?? $order->priceCents()), $created);
        $this->invoices->insert($invoice);

        return BillingEventOutcome::processed($orderId);
    }

    /** @param array<string, mixed> $session */
    private function checkoutExpired(array $session): BillingEventOutcome
    {
        $sessionId = (string) ($session['id'] ?? '');
        $order = $sessionId !== '' ? $this->orders->findByCheckoutSessionIdForUpdate($sessionId) : null;

        if ($order === null || !$order->expire()) {
            return BillingEventOutcome::ignored();
        }

        $this->orders->save($order);

        return BillingEventOutcome::processed();
    }

    /** @param array<string, mixed> $stripeInvoice */
    private function invoiceEvent(array $stripeInvoice, string $type, int $created, DateTimeImmutable $now): BillingEventOutcome
    {
        $stripeSubscriptionId = self::id($stripeInvoice['subscription'] ?? null)
            ?? self::id($stripeInvoice['parent']['subscription_details']['subscription'] ?? null);
        if ($stripeSubscriptionId === null) {
            return BillingEventOutcome::ignored();
        }

        $subscription = $this->subscriptions->findByStripeSubscriptionIdForUpdate($stripeSubscriptionId);
        if ($subscription === null) {
            if (!self::marked(
                $stripeInvoice['subscription_details']['metadata']['billing_order'] ?? null,
                $stripeInvoice['parent']['subscription_details']['metadata']['billing_order'] ?? null,
                $stripeInvoice['metadata']['billing_order'] ?? null,
            )) {
                return self::foreign($type, (string) ($stripeInvoice['id'] ?? ''));
            }

            throw BillingEventOutOfOrder::missing('Subscription', $stripeSubscriptionId, $type);
        }

        $changed = false;
        $stripeInvoiceId = (string) ($stripeInvoice['id'] ?? '');
        $invoice = $stripeInvoiceId !== '' ? $this->invoices->findByStripeInvoiceIdForUpdate($stripeInvoiceId) : null;

        if ($invoice === null) {
            $invoice = SubscriptionInvoice::fromStripe((int) $subscription->id(), $stripeInvoice, $created);
            $invoice = $invoice->withId($this->invoices->insert($invoice));
            $changed = true;
        } elseif ($invoice->applyStripe($stripeInvoice, $created)) {
            $this->invoices->save($invoice);
            $changed = true;
        }

        $subscriptionChanged = false;
        if ($type === 'invoice.paid' && $invoice->periodStart() !== null && $invoice->periodEnd() !== null) {
            $subscriptionChanged = $subscription->markInvoicePaid($invoice->periodStart(), $invoice->periodEnd(), $created);
        } elseif ($type === 'invoice.payment_failed') {
            $subscriptionChanged = $subscription->markPaymentFailed($created, $now);
        }

        if ($subscriptionChanged) {
            $this->subscriptions->save($subscription);
        }

        return $changed || $subscriptionChanged ? BillingEventOutcome::processed() : BillingEventOutcome::ignored();
    }

    /** @param array<string, mixed> $remote objeto Subscription do Stripe */
    private function subscriptionEvent(array $remote, string $type, int $created, DateTimeImmutable $now): BillingEventOutcome
    {
        $stripeSubscriptionId = (string) ($remote['id'] ?? '');
        $subscription = $stripeSubscriptionId !== '' ? $this->subscriptions->findByStripeSubscriptionIdForUpdate($stripeSubscriptionId) : null;
        if ($subscription === null) {
            if (!self::marked($remote['metadata']['billing_order'] ?? null)) {
                return self::foreign($type, $stripeSubscriptionId);
            }

            throw BillingEventOutOfOrder::missing('Subscription', $stripeSubscriptionId, $type);
        }

        $item = $remote['items']['data'][0] ?? [];
        $changed = $subscription->applyProviderStatus(
            (string) ($remote['status'] ?? ''),
            self::fromUnix($remote['current_period_start'] ?? $item['current_period_start'] ?? null),
            self::fromUnix($remote['current_period_end'] ?? $item['current_period_end'] ?? null),
            $created,
            $now,
        );

        if (!$changed) {
            return BillingEventOutcome::ignored();
        }

        $this->subscriptions->save($subscription);

        return BillingEventOutcome::processed();
    }

    /** @param array<string, mixed> $paymentIntent */
    private function paymentIntentSucceeded(array $paymentIntent, string $type, int $created): BillingEventOutcome
    {
        $invoiceKey = (string) ($paymentIntent['metadata']['subscription_invoice'] ?? '');
        if (!ctype_digit($invoiceKey) || (int) $invoiceKey <= 0) {
            // O PaymentIntent do 1º mês traz só metadata[billing_order]: o checkout já tratou.
            return BillingEventOutcome::ignored();
        }

        $invoice = $this->invoices->findByIdForUpdate((int) $invoiceKey);
        if ($invoice === null) {
            throw BillingEventOutOfOrder::missing('Subscription invoice', $invoiceKey, $type);
        }

        $paymentIntentId = (string) ($paymentIntent['id'] ?? '');
        if ($invoice->stripePaymentIntentId() !== null && $invoice->stripePaymentIntentId() !== $paymentIntentId) {
            return BillingEventOutcome::ignored();
        }

        $wasVoid = $invoice->status() === SubscriptionInvoice::STATUS_VOID;
        if (!$invoice->markPaidByPaymentIntent((int) ($paymentIntent['amount_received'] ?? 0), $created)) {
            return BillingEventOutcome::ignored();
        }

        $this->invoices->save($invoice);

        $subscription = $this->subscriptions->findByIdForUpdate($invoice->subscriptionId());
        if ($subscription === null) {
            throw BillingEventOutOfOrder::missing('Subscription', (string) $invoice->subscriptionId(), $type);
        }

        $periodStart = $invoice->periodStart();
        $periodEnd = $invoice->periodEnd();

        // T-22: o dinheiro entrou (fatura `paid`), mas o período posterior ao
        // cancelamento não reativa nem prolonga a assinatura; o reconciliador
        // estorna (CancelledPixRefundService).
        if ($wasVoid || self::paidAfterCancellation($subscription, $periodStart)) {
            error_log('[BillingEventProcessor] ' . json_encode(['event' => 'pix_paid_after_cancellation', 'invoice' => $invoice->id()]));

            return BillingEventOutcome::processed();
        }

        if ($periodStart !== null && $periodEnd !== null) {
            $advances = $subscription->currentPeriodEnd() === null || $periodEnd > $subscription->currentPeriodEnd();
            $eventCreated = $advances ? max($created, $subscription->lastEventCreated()) : $created;

            if ($subscription->markInvoicePaid($periodStart, $periodEnd, $eventCreated)) {
                $this->subscriptions->save($subscription);
            }
        }

        return BillingEventOutcome::processed();
    }

    /** @param array<string, mixed> $charge */
    private function chargeRefunded(array $charge, string $type, int $created): BillingEventOutcome
    {
        $stripeInvoiceId = self::id($charge['invoice'] ?? null);
        $paymentIntentId = self::id($charge['payment_intent'] ?? null);

        if ($stripeInvoiceId !== null) {
            $invoice = $this->invoices->findByStripeInvoiceIdForUpdate($stripeInvoiceId);
            if ($invoice === null) {
                if (!self::marked($charge['metadata']['billing_order'] ?? null, $charge['metadata']['subscription_invoice'] ?? null)) {
                    return self::foreign($type, (string) ($charge['id'] ?? ''));
                }

                throw BillingEventOutOfOrder::missing('Subscription invoice', $stripeInvoiceId, $type);
            }
        } elseif ($paymentIntentId !== null) {
            $invoice = $this->invoices->findByPaymentIntentIdForUpdate($paymentIntentId);
            if ($invoice === null) {
                return BillingEventOutcome::ignored();
            }
        } else {
            return BillingEventOutcome::ignored();
        }

        if (!$invoice->applyRefund(max(0, (int) ($charge['amount_refunded'] ?? 0)), $created)) {
            return BillingEventOutcome::ignored();
        }

        $this->invoices->save($invoice);

        return BillingEventOutcome::processed();
    }

    /**
     * T-28: o evento é do Central Vet quando traz a marca (pedido ou fatura
     * nossa) em algum dos campos; sem ela vem de outra integração da conta.
     */
    /**
     * Fatura de período posterior ao cancelamento (agendado ou já
     * `cancelled`): `$periodStart >= currentPeriodEnd()` da assinatura.
     */
    private static function paidAfterCancellation(Subscription $subscription, ?DateTimeImmutable $periodStart): bool
    {
        if (!$subscription->cancelAtPeriodEnd() && $subscription->status() !== Subscription::STATUS_CANCELLED) {
            return false;
        }

        $currentEnd = $subscription->currentPeriodEnd();

        return $periodStart !== null && $currentEnd !== null && $periodStart >= $currentEnd;
    }

    private static function marked(mixed ...$values): bool
    {
        foreach ($values as $value) {
            if ((is_string($value) && trim($value) !== '') || is_int($value)) {
                return true;
            }
        }

        return false;
    }

    /** Evento de outra integração na mesma conta Stripe: não é fora de ordem, não volta. */
    private static function foreign(string $type, string $objectId): BillingEventOutcome
    {
        error_log(sprintf('[BillingEventProcessor] ignored foreign %s %s', $type, $objectId));

        return BillingEventOutcome::ignored();
    }

    /** Id de um campo que pode vir como string ou como objeto expandido. */
    private static function id(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return ($timestamp is int ? DateTimeImmutable : ?DateTimeImmutable) */
    private static function fromUnix(mixed $timestamp): ?DateTimeImmutable
    {
        if (!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
