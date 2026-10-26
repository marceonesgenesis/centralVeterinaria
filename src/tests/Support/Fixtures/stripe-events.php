<?php

declare(strict_types=1);

/**
 * Etapa 3, T-14: construtores de eventos do Stripe no formato do webhook
 * (`id`, `type`, `created`, `data.object`), só com os campos que o
 * BillingEventProcessor lê. Uso:
 *
 *     $events = require __DIR__ . '/../Support/Fixtures/stripe-events.php';
 *     $event = $events['checkoutCompleted']('cs_test_1', 'sub_1', 1000);
 *
 * @return array<string, Closure>
 */

/**
 * T-28: marca do Central Vet (`client_reference_id`/`metadata.billing_order`)
 * nos objetos; `null` omite as chaves (evento de outra integração).
 *
 * @param array<string, mixed> $object
 * @param array<string, mixed> $marks
 * @return array<string, mixed>
 */
$mark = static fn (array $object, ?string $billingOrder, array $marks): array => $billingOrder === null ? $object : $object + $marks;

$event = static function (string $type, array $object, int $created): array {
    static $sequence = 0;
    $sequence++;

    return [
        'id' => sprintf('evt_test_%s_%d_%d', str_replace('.', '_', $type), $created, $sequence),
        'object' => 'event',
        'type' => $type,
        'created' => $created,
        'livemode' => false,
        'data' => ['object' => $object],
    ];
};

return [
    'event' => $event,

    /** Checkout de cartão (`mode=subscription`) concluído e pago. */
    'checkoutCompleted' => static fn (string $sessionId, ?string $subscriptionId, int $created, string $paymentStatus = 'paid', ?string $billingOrder = 'order_test'): array => $event(
        'checkout.session.completed',
        $mark([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'mode' => $subscriptionId !== null ? 'subscription' : 'payment',
            'customer' => 'cus_test_1',
            'subscription' => $subscriptionId,
            'payment_intent' => $subscriptionId === null ? 'pi_test_first' : null,
            'payment_status' => $paymentStatus,
            'amount_total' => 9700,
        ], $billingOrder, ['client_reference_id' => $billingOrder, 'metadata' => ['billing_order' => $billingOrder]]),
        $created,
    ),

    /** Checkout Pix (`mode=payment`) pago depois (pagamento assíncrono). */
    'checkoutAsyncSucceeded' => static fn (string $sessionId, string $paymentIntentId, int $amountTotal, int $created, ?string $billingOrder = 'order_test'): array => $event(
        'checkout.session.async_payment_succeeded',
        $mark([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'mode' => 'payment',
            'customer' => 'cus_test_1',
            'subscription' => null,
            'payment_intent' => $paymentIntentId,
            'payment_status' => 'paid',
            'amount_total' => $amountTotal,
        ], $billingOrder, ['client_reference_id' => $billingOrder, 'metadata' => ['billing_order' => $billingOrder]]),
        $created,
    ),

    'checkoutExpired' => static fn (string $sessionId, int $created): array => $event(
        'checkout.session.expired',
        ['id' => $sessionId, 'object' => 'checkout.session', 'payment_status' => 'unpaid', 'status' => 'expired'],
        $created,
    ),

    /** Objeto Subscription devolvido por `GET /v1/subscriptions/<id>`. */
    'stripeSubscription' => static fn (string $subscriptionId, string $status, int $periodStart, int $periodEnd, bool $periodOnItems = false): array => $periodOnItems
        ? [
            'id' => $subscriptionId,
            'object' => 'subscription',
            'status' => $status,
            'customer' => 'cus_test_1',
            'items' => ['data' => [['current_period_start' => $periodStart, 'current_period_end' => $periodEnd]]],
        ]
        : [
            'id' => $subscriptionId,
            'object' => 'subscription',
            'status' => $status,
            'customer' => 'cus_test_1',
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ],

    'subscriptionUpdated' => static fn (string $subscriptionId, string $status, int $created, ?int $periodStart = null, ?int $periodEnd = null, ?string $billingOrder = 'order_test'): array => $event(
        'customer.subscription.updated',
        $mark(array_filter([
            'id' => $subscriptionId,
            'object' => 'subscription',
            'status' => $status,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ], static fn ($value): bool => $value !== null), $billingOrder, ['metadata' => ['billing_order' => $billingOrder]]),
        $created,
    ),

    /** `invoice.*` de uma Subscription do cartão. */
    'invoice' => static fn (string $type, string $invoiceId, string $subscriptionId, string $status, int $amount, int $periodStart, int $periodEnd, int $created, ?string $billingOrder = 'order_test'): array => $event(
        $type,
        $mark([
            'id' => $invoiceId,
            'object' => 'invoice',
            'subscription' => $subscriptionId,
            'status' => $status,
            'amount_due' => $amount,
            'amount_paid' => $status === 'paid' ? $amount : 0,
            'currency' => 'brl',
            'hosted_invoice_url' => 'https://invoice.stripe.test/' . $invoiceId,
            'lines' => ['data' => [['period' => ['start' => $periodStart, 'end' => $periodEnd]]]],
        ], $billingOrder, ['subscription_details' => ['metadata' => ['billing_order' => $billingOrder]]]),
        $created,
    ),

    /** @param array<string, string> $metadata */
    'paymentIntentSucceeded' => static fn (string $paymentIntentId, int $amountReceived, array $metadata, int $created): array => $event(
        'payment_intent.succeeded',
        [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => $amountReceived,
            'amount_received' => $amountReceived,
            'currency' => 'brl',
            'metadata' => $metadata,
        ],
        $created,
    ),

    'chargeRefunded' => static fn (?string $invoiceId, ?string $paymentIntentId, int $amount, int $amountRefunded, int $created): array => $event(
        'charge.refunded',
        [
            'id' => 'ch_test_' . $created,
            'object' => 'charge',
            'invoice' => $invoiceId,
            'payment_intent' => $paymentIntentId,
            'amount' => $amount,
            'amount_refunded' => $amountRefunded,
            'refunded' => $amountRefunded >= $amount,
        ],
        $created,
    ),
];
