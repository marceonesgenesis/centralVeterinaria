<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\Sale;
use CentralVet\Domain\SaleItem;
use CentralVet\Presentation\SaleReceiptHtml;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;

/**
 * T-15: o recibo do PDV é montado dos dados gravados (sale_item +
 * SaleFinanceReader::receiptSummary), mostra o recebido/saldo e o
 * cancelamento, e escapa todo texto variável.
 */
final class SaleReceiptHtmlTest
{
    public function testUnpaidSaleShowsNoPaymentAndAmountDue(): void
    {
        $html = $this->render($this->sale(), $this->summary(0, []));

        Assert::stringContains('No payment received', $html);
        Assert::stringContains('Amount due', $html);
        Assert::stringContains('R$ 100,00', $html);
        Assert::false(str_contains($html, 'Amount received'), 'venda sem recebimento não mostra Amount received');
    }

    public function testPaidSaleShowsAmountReceivedAndMethods(): void
    {
        $html = $this->render($this->sale(), $this->summary(10000, ['cash']));

        Assert::stringContains('Amount received', $html);
        Assert::stringContains('Cash', $html);
        Assert::stringContains('Amount due', $html);
        Assert::stringContains('R$ 0,00', $html);
        Assert::false(str_contains($html, 'No payment received'), 'venda paga não mostra No payment received');
    }

    public function testCancelledSaleShowsCancellation(): void
    {
        $sale = $this->sale(Sale::STATUS_CANCELLED);
        $summary = $this->summary(0, []);
        $summary['status'] = Sale::STATUS_CANCELLED;
        $summary['cancelled_at'] = '2026-10-21 10:30:00';
        $summary['cancelled_by_name'] = 'Ana <i>';
        $summary['cancellation_reason'] = 'Cliente desistiu';

        $html = $this->render($sale, $summary);

        Assert::stringContains('Cancelled sale', $html);
        Assert::stringContains('Cancelled by Ana &lt;i&gt; on 21/10/2026 10:30', $html);
        Assert::stringContains('Cliente desistiu', $html);
    }

    public function testItemDescriptionIsEscaped(): void
    {
        $html = $this->render($this->sale(), $this->summary(0, []));

        Assert::stringContains('&lt;b&gt;x&lt;/b&gt;', $html);
        Assert::false(str_contains($html, '<b>x</b>'), 'descrição do item saiu sem escape');
    }

    private function render(Sale $sale, array $summary): string
    {
        $items = [
            SaleItem::reconstitute(1, 1, 7, SaleItem::TYPE_PRODUCT, 3, '<b>x</b>', 5000, 2, 10000, null),
        ];

        return SaleReceiptHtml::render($sale, $items, $summary, [$this, 'translate']);
    }

    public function translate(string $key, string ...$params): string
    {
        foreach ($params as $index => $param) {
            $key = str_replace('^' . ($index + 1), $param, $key);
        }

        return $key;
    }

    private function sale(string $status = Sale::STATUS_COMPLETED): Sale
    {
        $cancelled = $status === Sale::STATUS_CANCELLED;

        return Sale::reconstitute(
            7,
            1,
            1,
            11,
            null,
            null,
            5,
            $status,
            10000,
            new DateTimeImmutable('2026-10-20 09:00:00'),
            null,
            $cancelled ? new DateTimeImmutable('2026-10-21 10:30:00') : null,
            $cancelled ? 5 : null,
            $cancelled ? 'Cliente desistiu' : null,
        );
    }

    /** @param list<string> $methods */
    private function summary(int $receivedCents, array $methods): array
    {
        return [
            'sale_id' => 7,
            'status' => Sale::STATUS_COMPLETED,
            'total_cents' => 10000,
            'receivable_status' => $receivedCents >= 10000 ? 'paid' : 'open',
            'received_cents' => $receivedCents,
            'payment_methods' => $methods,
            'cancelled_at' => null,
            'cancelled_by_name' => null,
            'cancellation_reason' => null,
        ];
    }
}
