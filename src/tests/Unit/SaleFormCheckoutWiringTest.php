<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * T-15: o SaleForm finaliza pela SaleCheckoutService (forma de pagamento +
 * token de envio), trata repetição, caixa fechado e deadlock/lock wait, e
 * monta o recibo dos dados gravados (sem snapshot do carrinho em sessão).
 * Lê o fonte: o SaleForm depende do Adianti e do banco.
 */
final class SaleFormCheckoutWiringTest
{
    public function testPaymentMethodComboAndSubmissionToken(): void
    {
        $source = $this->source();

        Assert::stringContains("new TCombo('payment_method')", $source);
        Assert::stringContains("_t('Payment method')", $source);
        Assert::stringContains("_t('On account (open receivable)')", $source);
        Assert::stringContains('Payment::METHODS', $source);
        Assert::stringContains('CvFormat::paymentMethod(', $source);
        Assert::stringContains("new THidden('submission_token')", $source);
        Assert::stringContains('SubmissionToken::generate()', $source);
    }

    public function testOnSaveCallsCheckout(): void
    {
        $body = $this->methodBody('onSave');

        Assert::stringContains('->checkout(', $body);
        Assert::stringContains("submissionToken: (string) (\$param['submission_token'] ?? '')", $body);
        Assert::stringContains("action: 'SaleForm::onSave'", $body);
        Assert::stringContains('paymentMethod:', $body);
        Assert::false(str_contains($body, '->create('), 'onSave ainda chama SaleService::create direto');
    }

    public function testOnSaveHandlesDuplicateCashSessionAndLockErrors(): void
    {
        $body = $this->methodBody('onSave');

        Assert::true((bool) preg_match('/catch\s*\(\s*\\\\?(?:CentralVet\\\\Domain\\\\Exception\\\\)?DuplicateSubmissionException\s+\$\w+\s*\)/', $body), 'DuplicateSubmissionException sem catch');
        Assert::stringContains("_t('This submission was already processed')", $body);
        Assert::stringContains('referenceId()', $body);
        Assert::true((bool) preg_match('/catch\s*\(\s*\\\\?(?:CentralVet\\\\Domain\\\\Exception\\\\)?CashSessionRequiredException\s+\$\w+\s*\)/', $body), 'CashSessionRequiredException sem catch');
        Assert::true((bool) preg_match('/catch\s*\(\s*\\\\?PDOException\s+\$\w+\s*\)/', $body), 'PDOException sem catch');
        Assert::stringContains('1205', $body);
        Assert::stringContains('1213', $body);
        Assert::stringContains("_t('Stock changed during the sale; review the cart and try again')", $body);
    }

    public function testCheckoutServiceIsWired(): void
    {
        $source = $this->source();

        Assert::stringContains('new \\CentralVet\\Application\\SaleCheckoutService(', $source);
        Assert::stringContains('SubmissionKeyRepository($context, $connection)', $source);
    }

    public function testReceiptIsBuiltFromPersistedData(): void
    {
        $source = $this->source();
        $body = $this->methodBody('onGenerateReceiptPdf');

        Assert::stringContains('SaleReceiptHtml::render(', $body);
        Assert::stringContains('->listBySale(', $body);
        Assert::stringContains('->receiptSummary(', $body);
        Assert::false(str_contains($source, 'receiptSessionKey'), 'snapshot do carrinho em sessão ainda presente');
    }

    public function testFinalizeButtonKeepsTouchTarget(): void
    {
        $source = $this->source();

        Assert::true((bool) preg_match("/\\\$saveBtn->class\s*=\s*'[^']*cv-touch-target/", $source), 'botão Finalizar sem cv-touch-target');
        Assert::false((bool) preg_match('/text-align\s*:\s*(right|center)/', $source), 'rótulo alinhado fora da esquerda');
    }

    private function methodBody(string $method): string
    {
        $source = $this->source();
        $start = strpos($source, "function {$method}(");
        Assert::true($start !== false, "{$method}() ausente");

        $end = strpos($source, "\n    }\n", (int) $start);
        Assert::true($end !== false, "fim de {$method}() não encontrado");

        return substr($source, (int) $start, (int) $end - (int) $start);
    }

    private function source(): string
    {
        $path = dirname(__DIR__, 2) . '/app/control/clinic/SaleForm.php';
        Assert::true(is_file($path), "arquivo ausente: {$path}");

        return (string) file_get_contents($path);
    }
}
