<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\SubscriptionInvoiceRepositoryInterface;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeClientInterface;
use CentralVet\Communication\MessageChannelProviderInterface;
use CentralVet\Communication\OutgoingMessage;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fatura Pix de cada período (Etapa 3, T-23): sem Subscription no Stripe, a
 * assinatura Pix recebe, ISSUE_DAYS_BEFORE dias antes do fim do período, uma
 * fatura local (`pix:<assinatura>:<current_period_end Y-m-d>`) com um
 * PaymentIntent Pix avulso e o link de pagamento por e-mail.
 *
 * Não abre transação: o chamador abre. O e-mail com o link não sai dentro
 * dela: ensurePaymentIntent o deixa pendente e o chamador chama
 * deliverPending() depois do commit (ou discardPending() no rollback), para
 * a falha do e-mail nunca desfazer a fatura com o PaymentIntent já criado e
 * nenhum e-mail apontar para fatura desfeita. A Idempotency-Key do
 * PaymentIntent deriva do local_key da fatura e do número da tentativa:
 * repetir a mesma tentativa devolve o mesmo PaymentIntent no Stripe.
 * $translate é `_t` no endpoint: fn (string $key, string ...$params): string.
 */
final class PixInvoiceIssuer
{
    public const ISSUE_DAYS_BEFORE = 3;

    /** 3 dias até o vencimento + 7 de tolerância. */
    public const PIX_EXPIRES_AFTER_SECONDS = 864000;

    public const REFERENCE = 'pix-invoice';

    /** Envios do mesmo e-mail em deliverPending() antes de desistir (o link segue na tela da assinatura). */
    public const DELIVERY_ATTEMPTS = 3;

    private const ISSUABLE_STATUSES = [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE];

    /** @var list<OutgoingMessage> */
    private array $pending = [];

    public function __construct(
        private readonly StripeClientInterface $stripe,
        private readonly SubscriptionInvoiceRepositoryInterface $invoices,
        private readonly MessageChannelProviderInterface $email,
        private readonly \Closure $translate,
    ) {
    }

    /**
     * Emite a fatura do próximo período, ou null quando não cabe (não é Pix,
     * status fora de active/past_due, cancelamento agendado, cedo demais ou
     * fatura já emitida).
     *
     * Falha do Stripe no PaymentIntent não sobe: a fatura fica inserida sem
     * PI (o chamador commita) e o reconciliador repete pela listOpenPix com a
     * mesma Idempotency-Key, então um PI criado com resposta perdida é
     * devolvido, não duplicado.
     */
    public function issueNext(Subscription $subscription, int $priceCents, string $recipientEmail, DateTimeImmutable $now): ?SubscriptionInvoice
    {
        $subscriptionId = $subscription->id();
        $periodEnd = $subscription->currentPeriodEnd();

        if (
            $subscriptionId === null
            || $periodEnd === null
            || $subscription->paymentMethod() !== Subscription::METHOD_PIX
            || $subscription->cancelAtPeriodEnd()
            || !in_array($subscription->status(), self::ISSUABLE_STATUSES, true)
            || $periodEnd->modify(sprintf('-%d days', self::ISSUE_DAYS_BEFORE)) > $now
        ) {
            return null;
        }

        if ($this->invoices->findByLocalKey(sprintf('pix:%d:%s', $subscriptionId, $periodEnd->format('Y-m-d'))) !== null) {
            return null;
        }

        $invoice = SubscriptionInvoice::issuePix($subscriptionId, $priceCents, $periodEnd, $periodEnd->modify('+1 month'), $periodEnd);

        try {
            $invoice = $invoice->withId($this->invoices->insert($invoice));
        } catch (DuplicateInvoice) {
            return null;
        }

        try {
            $this->ensurePaymentIntent($invoice, $subscription->stripeCustomerId(), $recipientEmail, $now);
        } catch (StripeApiException $e) {
            error_log(sprintf('[PixInvoiceIssuer] payment intent for invoice %d failed: %s', (int) $invoice->id(), $e::class));
        }

        return $invoice;
    }

    /**
     * Cria o PaymentIntent Pix da fatura quando ela não tem um ou o dela
     * expirou; grava e deixa o e-mail com o link pendente (deliverPending).
     * StripeApiException sobe sem gravar nada.
     */
    public function ensurePaymentIntent(SubscriptionInvoice $invoice, string $stripeCustomerId, string $recipientEmail, DateTimeImmutable $now): bool
    {
        $invoiceId = $invoice->id();
        if ($invoiceId === null || !$invoice->needsPaymentIntent($now)) {
            return false;
        }

        $paymentIntent = $this->stripe->request('POST', '/v1/payment_intents', [
            'amount' => $invoice->amountDueCents(),
            'currency' => 'brl',
            'customer' => $stripeCustomerId,
            'payment_method_types' => ['pix'],
            'payment_method_data' => ['type' => 'pix'],
            'confirm' => true,
            'payment_method_options' => ['pix' => ['expires_after_seconds' => self::PIX_EXPIRES_AFTER_SECONDS]],
            'metadata' => ['subscription_invoice' => (string) $invoiceId],
        ], sprintf('pix-invoice-%s-%d', (string) $invoice->localKey(), $invoice->paymentIntentAttempts() + 1));

        $qrCode = $paymentIntent['next_action']['pix_display_qr_code'] ?? [];
        $payUrl = isset($qrCode['hosted_instructions_url']) && $qrCode['hosted_instructions_url'] !== ''
            ? (string) $qrCode['hosted_instructions_url']
            : null;

        $invoice->attachPaymentIntent(
            (string) ($paymentIntent['id'] ?? ''),
            $payUrl,
            self::fromUnix($qrCode['expires_at'] ?? null),
        );
        $this->invoices->save($invoice);

        $translate = $this->translate;
        $this->pending[] = new OutgoingMessage(
            $recipientEmail,
            $translate('Central Vet Pro invoice'),
            $translate(
                'Your Central Vet Pro invoice of ^1 is available. Pay with Pix by ^2: ^3',
                self::money($invoice->amountDueCents()),
                (string) $invoice->dueAt()?->format('d/m/Y'),
                (string) $payUrl,
            ),
            self::REFERENCE,
        );

        return true;
    }

    /**
     * Envia os e-mails pendentes (chamar depois do commit), cada um até
     * DELIVERY_ATTEMPTS vezes; a falha só vai para o log (sem o destinatário).
     *
     * @return int e-mails enviados
     */
    public function deliverPending(): int
    {
        $messages = $this->pending;
        $this->pending = [];
        $sent = 0;

        foreach ($messages as $message) {
            for ($attempt = 1; $attempt <= self::DELIVERY_ATTEMPTS; $attempt++) {
                try {
                    $this->email->deliver($message);
                    $sent++;

                    break;
                } catch (\Throwable $e) {
                    if ($attempt === self::DELIVERY_ATTEMPTS) {
                        error_log(sprintf('[PixInvoiceIssuer] invoice e-mail failed after %d attempts: %s', $attempt, $e::class));
                    }
                }
            }
        }

        return $sent;
    }

    /** Descarta os e-mails pendentes (rollback da transação do chamador). */
    public function discardPending(): void
    {
        $this->pending = [];
    }

    private static function money(int $cents): string
    {
        return 'R$ ' . number_format(intdiv($cents, 100), 0, ',', '.') . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function fromUnix(mixed $timestamp): ?DateTimeImmutable
    {
        if (!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
