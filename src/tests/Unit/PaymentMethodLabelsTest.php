<?php

declare(strict_types=1);

namespace {
    if (!function_exists('_t')) {
        /** _t de teste: devolve '[t]' + a chave, para distinguir o texto traduzido. */
        function _t($msg, $param1 = null, $param2 = null, $param3 = null)
        {
            return '[t]' . (string) $msg;
        }
    }

    if (!class_exists('CvFormat', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvFormat.php';
    }
}

namespace CentralVet\Tests\Unit {

    use CentralVet\Domain\Payment;
    use CentralVet\Presentation\SaleReceiptHtml;
    use CentralVet\Tests\Support\Assert;
    use CvFormat;
    use ReflectionClass;

    /**
     * Etapa 3, T-06: os rótulos das formas de pagamento têm uma fonte só,
     * Payment::METHOD_LABELS, lida por CvFormat::paymentMethod e pelo recibo.
     */
    final class PaymentMethodLabelsTest
    {
        public function testLabelsCoverEveryMethodWithTheCurrentTexts(): void
        {
            Assert::true(defined(Payment::class . '::METHOD_LABELS'), 'Payment::METHOD_LABELS não existe');

            Assert::same([
                Payment::METHOD_CASH => 'Cash',
                Payment::METHOD_DEBIT_CARD => 'Debit card',
                Payment::METHOD_CREDIT_CARD => 'Credit card',
                Payment::METHOD_PIX => 'Pix',
                Payment::METHOD_BANK_TRANSFER => 'Bank transfer',
            ], constant(Payment::class . '::METHOD_LABELS'));
            Assert::same(Payment::METHODS, array_keys(constant(Payment::class . '::METHOD_LABELS')));
        }

        public function testCvFormatTranslatesTheSameLabels(): void
        {
            Assert::true(defined(Payment::class . '::METHOD_LABELS'), 'Payment::METHOD_LABELS não existe');

            foreach (constant(Payment::class . '::METHOD_LABELS') as $method => $label) {
                Assert::same(_t($label), CvFormat::paymentMethod($method), "rótulo de {$method}");
            }

            Assert::same('unknown_method', CvFormat::paymentMethod('unknown_method'));
        }

        public function testReceiptHasNoLabelCopyOfItsOwn(): void
        {
            Assert::false(
                (new ReflectionClass(SaleReceiptHtml::class))->hasConstant('METHOD_LABELS'),
                'SaleReceiptHtml ainda declara METHOD_LABELS; deve ler Payment::METHOD_LABELS',
            );
        }

        public function testCvFormatReadsThePaymentConstant(): void
        {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/lib/widget/CvFormat.php');

            Assert::stringContains('Payment::METHOD_LABELS', $source);
        }
    }
}
