<?php

declare(strict_types=1);

namespace CentralVet\Presentation;

use CentralVet\Billing\PlanEntitlements;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionInvoice;
use DateTimeImmutable;

/**
 * Dados da SubscriptionStatusView (T-21): resumo da assinatura do tenant e
 * linhas das faturas. Sem Adianti e sem tradução: devolve as chaves em
 * inglês da T-01 (a view passa por _t()) e textos já formatados (datas
 * d/m/Y, valores em reais).
 *
 * O link de pagamento só sai em fatura `open` com URL https e, no Pix, com
 * `pix_expires_at` nulo ou futuro (Pix expirado é regenerado pelo
 * reconciliador, T-18).
 */
final class SubscriptionStatusPresenter
{
    public const NO_SUBSCRIPTION = 'No subscription registered for this clinic';
    public const SUSPENDED_MESSAGE = 'Your subscription is suspended. Regularize the payment to continue';

    private const SUBSCRIPTION_STATUS = [
        Subscription::STATUS_ACTIVE => 'Active',
        Subscription::STATUS_PAST_DUE => 'Overdue',
        Subscription::STATUS_SUSPENDED => 'Suspended',
        Subscription::STATUS_CANCELLED => 'Cancelled',
        Subscription::STATUS_INCOMPLETE => 'Incomplete',
    ];

    private const CANCELLABLE_STATUSES = [
        Subscription::STATUS_ACTIVE,
        Subscription::STATUS_PAST_DUE,
        Subscription::STATUS_SUSPENDED,
    ];

    private const INVOICE_STATUS = [
        SubscriptionInvoice::STATUS_DRAFT => 'Draft',
        SubscriptionInvoice::STATUS_OPEN => 'Open (pending status)',
        SubscriptionInvoice::STATUS_PAID => 'Paid',
        SubscriptionInvoice::STATUS_UNCOLLECTIBLE => 'Overdue',
        SubscriptionInvoice::STATUS_VOID => 'Canceled',
        SubscriptionInvoice::STATUS_REFUNDED => 'Refunded',
    ];

    private function __construct()
    {
    }

    /**
     * @return array{plan: ?string, status_key: ?string, period: ?string, grace_until: ?string, message_key: ?string}
     */
    public static function summary(?Subscription $subscription, DateTimeImmutable $now): array
    {
        if ($subscription === null) {
            return [
                'plan' => null,
                'status_key' => null,
                'period' => null,
                'grace_until' => null,
                'message_key' => self::NO_SUBSCRIPTION,
            ];
        }

        $status = $subscription->status();
        $blocked = $subscription->accessLevel($now) === Subscription::ACCESS_BLOCKED;
        // past_due com a tolerância vencida já está sem acesso, mesmo antes
        // de o reconciliador marcar suspended
        $suspended = $status === Subscription::STATUS_SUSPENDED
            || ($status === Subscription::STATUS_PAST_DUE && $blocked);

        $grace = in_array($status, [Subscription::STATUS_PAST_DUE, Subscription::STATUS_SUSPENDED], true)
            ? $subscription->graceUntil()
            : null;

        return [
            'plan' => PlanEntitlements::forPlan($subscription->planId())->name(),
            'status_key' => $suspended ? 'Suspended' : (self::SUBSCRIPTION_STATUS[$status] ?? $status),
            'period' => self::period($subscription->currentPeriodStart(), $subscription->currentPeriodEnd()),
            'grace_until' => $grace?->format('d/m/Y'),
            'message_key' => $suspended ? self::SUSPENDED_MESSAGE : null,
        ];
    }

    /**
     * Data do fim do período (d/m/Y) quando o cancelamento já está agendado
     * (Etapa 5, T-14); null sem assinatura ou sem agendamento.
     */
    public static function cancelScheduledFor(?Subscription $subscription): ?string
    {
        if ($subscription === null || !$subscription->cancelAtPeriodEnd()) {
            return null;
        }

        return $subscription->currentPeriodEnd()?->format('d/m/Y');
    }

    /**
     * O botão "Cancelar assinatura" só aparece em assinatura `active`,
     * `past_due` ou `suspended` ainda sem cancelamento agendado (T-14).
     */
    public static function canCancel(?Subscription $subscription): bool
    {
        if ($subscription === null || $subscription->cancelAtPeriodEnd()) {
            return false;
        }

        return in_array($subscription->status(), self::CANCELLABLE_STATUSES, true);
    }

    /**
     * @param list<SubscriptionInvoice> $invoices na ordem de exibição (listBySubscription: period_start DESC)
     * @return list<array{period: ?string, due_at: ?string, amount_due: string, amount_paid: string, status_key: string, pay_url: ?string}>
     */
    public static function invoiceRows(array $invoices, DateTimeImmutable $now): array
    {
        $rows = [];
        foreach ($invoices as $invoice) {
            $status = $invoice->isOverdue($now) ? 'Overdue' : (self::INVOICE_STATUS[$invoice->status()] ?? $invoice->status());

            $rows[] = [
                'period' => self::period($invoice->periodStart(), $invoice->periodEnd()),
                'due_at' => $invoice->dueAt()?->format('d/m/Y'),
                'amount_due' => self::money($invoice->amountDueCents()),
                'amount_paid' => self::money($invoice->amountPaidCents()),
                'status_key' => $status,
                'pay_url' => self::payUrl($invoice, $now),
            ];
        }

        return $rows;
    }

    private static function payUrl(SubscriptionInvoice $invoice, DateTimeImmutable $now): ?string
    {
        if ($invoice->status() !== SubscriptionInvoice::STATUS_OPEN) {
            return null;
        }

        $url = $invoice->hostedInvoiceUrl();
        if ($url === null || !str_starts_with($url, 'https://')) {
            return null;
        }

        $expiresAt = $invoice->pixExpiresAt();
        if ($expiresAt !== null && $expiresAt <= $now) {
            return null;
        }

        return $url;
    }

    private static function period(?DateTimeImmutable $start, ?DateTimeImmutable $end): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        return $start->format('d/m/Y') . ' – ' . $end->format('d/m/Y');
    }

    /** "R$ x,xx" a partir de centavos inteiros, igual a CvFormat::money. */
    private static function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . 'R$ ' . number_format(intdiv($abs, 100), 0, ',', '.') . ',' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
