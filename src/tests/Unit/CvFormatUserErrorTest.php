<?php

declare(strict_types=1);

namespace {
    if (!function_exists('_t')) {
        /**
         * _t de teste: devolve a própria chave com ^1/^2 substituídos.
         */
        function _t($msg, $param1 = null, $param2 = null, $param3 = null)
        {
            $out = (string) $msg;
            foreach ([1 => $param1, 2 => $param2, 3 => $param3] as $i => $param) {
                if ($param !== null) {
                    $out = str_replace('^' . $i, (string) $param, $out);
                }
            }
            return $out;
        }
    }

    if (!class_exists('CvFormat', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvFormat.php';
    }
}

namespace CentralVet\Tests\Unit {

    use CentralVet\Domain\Exception\CrossTenantReferenceException;
    use CentralVet\Tests\Support\Assert;
    use CvFormat;
    use InvalidArgumentException;
    use RuntimeException;

    /**
     * Rodada 2, T-28: CvFormat::userError/userMessage traduzem mensagens do
     * catálogo UserMessage e escapam parâmetros e fallback para TMessage.
     */
    final class CvFormatUserErrorTest
    {
        public function testCatalogParamIsEscaped(): void
        {
            $text = CvFormat::userError(new InvalidArgumentException('A bank account named "<b>x</b>" already exists for this unit'));

            Assert::stringContains('&lt;b&gt;x&lt;/b&gt;', $text);
            Assert::false(str_contains($text, '<b>'), 'raw <b> must not reach TMessage');
        }

        public function testCrossTenantReferenceReturnsTheClinicKey(): void
        {
            Assert::same(
                'The selected record does not belong to this clinic',
                CvFormat::userError(new CrossTenantReferenceException('Tutor 99 belongs to another tenant'))
            );
        }

        public function testCatalogMessageGoesThroughTranslationKey(): void
        {
            Assert::same(
                'Encounter ^1 is finished and cannot be paused',
                str_replace('3408', '^1', CvFormat::userError(new RuntimeException('Encounter 3408 is finished and cannot be paused')))
            );
            Assert::same('Patient sex must be one of M, F, U', CvFormat::userError(new InvalidArgumentException('Patient sex must be one of M, F, U')));
        }

        public function testUnknownMessageIsEscapedFallback(): void
        {
            Assert::same('&lt;script&gt;x&lt;/script&gt;', CvFormat::userError(new RuntimeException('<script>x</script>')));
        }

        public function testUserMessageAppliesTheSameRule(): void
        {
            Assert::same('A service named "&lt;i&gt;" already exists', CvFormat::userMessage('A service named "<i>" already exists for this tenant'));
            Assert::same('a &amp; b', CvFormat::userMessage('a & b'));
        }
    }
}
