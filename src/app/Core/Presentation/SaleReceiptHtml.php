<?php

declare(strict_types=1);

namespace CentralVet\Presentation;

use CentralVet\Domain\Payment;
use CentralVet\Domain\Sale;
use CentralVet\Domain\SaleItem;
use DateTimeImmutable;

/**
 * HTML do recibo da venda (T-15), montado só dos dados gravados: os itens de
 * SaleItemRepositoryInterface::listBySale e o resumo financeiro de
 * SaleFinanceReader::receiptSummary. Sem Adianti: o tradutor chega por
 * parâmetro ($translate(string $key, string ...$params): string), então a
 * classe é testável sem o framework. Todo texto variável passa por
 * htmlspecialchars; o resultado é entregue ao dompdf pelo SaleForm.
 */
final class SaleReceiptHtml
{
    /** Rótulos (chaves de tradução) das formas de pagamento, como CvFormat::paymentMethod. */
    private const METHOD_LABELS = [
        Payment::METHOD_CASH => 'Cash',
        Payment::METHOD_DEBIT_CARD => 'Debit card',
        Payment::METHOD_CREDIT_CARD => 'Credit card',
        Payment::METHOD_PIX => 'Pix',
        Payment::METHOD_BANK_TRANSFER => 'Bank transfer',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<SaleItem> $items
     * @param array{status?: string, total_cents?: int, received_cents?: int, payment_methods?: list<string>, cancelled_at?: ?string, cancelled_by_name?: ?string, cancellation_reason?: ?string} $summary
     * @param callable(string, string...): string $translate
     */
    public static function render(Sale $sale, array $items, array $summary, callable $translate): string
    {
        $t = static fn (string $key, string ...$params): string => self::e($translate($key, ...$params));

        $rows = '';

        foreach ($items as $item) {
            $rows .= '<tr>'
                . '<td>' . self::e($item->descriptionText()) . '</td>'
                . '<td>' . $item->quantity() . '</td>'
                . '<td>' . self::money($item->unitPriceCents()) . '</td>'
                . '<td>' . self::money($item->totalCents()) . '</td>'
                . '</tr>';
        }

        $totalCents = (int) ($summary['total_cents'] ?? $sale->totalAmountCents());
        $receivedCents = (int) ($summary['received_cents'] ?? 0);
        $dueCents = max(0, $totalCents - $receivedCents);

        $patientLine = $sale->patientId() !== null
            ? ' &mdash; ' . $t('Patient') . ': ' . $sale->patientId()
            : '';

        $html = '<html><head><meta charset="utf-8"></head><body>'
            . '<h3>' . $t('Sale receipt') . ' #' . (int) $sale->id() . '</h3>';

        $status = (string) ($summary['status'] ?? $sale->status());

        if ($status === Sale::STATUS_CANCELLED || $sale->isCancelled()) {
            $html .= self::cancellationBlock($sale, $summary, $translate, $t);
        }

        $html .= '<p>' . $t('Tutor') . ': ' . $sale->tutorId() . $patientLine . '</p>'
            . '<p>' . $t('Date') . ': ' . self::e($sale->soldAt()->format('d/m/Y H:i')) . '</p>'
            . '<table border="1" cellpadding="4" cellspacing="0" width="100%">'
            . '<thead><tr>'
            . '<th align="left">' . $t('Description') . '</th><th align="left">' . $t('Qty') . '</th>'
            . '<th align="left">' . $t('Unit price') . '</th><th align="left">' . $t('Total') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p><strong>' . $t('Grand total') . ': ' . self::money($totalCents) . '</strong></p>';

        if ($receivedCents > 0) {
            $methods = array_map(
                static fn (string $method): string => $translate(self::METHOD_LABELS[$method] ?? $method),
                array_values(array_map('strval', (array) ($summary['payment_methods'] ?? []))),
            );
            $methodsText = $methods === [] ? '' : ' (' . self::e(implode(', ', $methods)) . ')';

            $html .= '<p>' . $t('Amount received') . ': ' . self::money($receivedCents) . $methodsText . '</p>';
        } else {
            $html .= '<p>' . $t('No payment received') . '</p>';
        }

        return $html
            . '<p>' . $t('Amount due') . ': ' . self::money($dueCents) . '</p>'
            . '</body></html>';
    }

    /**
     * @param array<string, mixed> $summary
     * @param callable(string, string...): string $translate
     * @param callable(string, string...): string $t
     */
    private static function cancellationBlock(Sale $sale, array $summary, callable $translate, callable $t): string
    {
        $by = (string) ($summary['cancelled_by_name'] ?? '');

        if ($by === '' && $sale->cancelledBySystemUserId() !== null) {
            $by = '#' . $sale->cancelledBySystemUserId();
        }

        $at = self::formatDateTime($summary['cancelled_at'] ?? null) ?? ($sale->cancelledAt()?->format('d/m/Y H:i') ?? '');
        $reason = (string) ($summary['cancellation_reason'] ?? $sale->cancellationReason() ?? '');

        // Os parâmetros são escapados antes de entrar na frase; a frase
        // traduzida (texto fixo do catálogo) também passa pelo escape.
        $block = '<p><strong>' . $t('Cancelled sale') . '</strong></p>'
            . '<p>' . self::e($translate('Cancelled by ^1 on ^2', $by, $at)) . '</p>';

        if ($reason !== '') {
            $block .= '<p>' . $t('Reason') . ': ' . self::e($reason) . '</p>';
        }

        return $block;
    }

    private static function formatDateTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $date === false ? $value : $date->format('d/m/Y H:i');
    }

    /** "R$ x,xx" a partir de centavos inteiros, igual a CvFormat::money. */
    private static function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . 'R$ ' . number_format(intdiv($abs, 100), 0, ',', '.') . ',' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
