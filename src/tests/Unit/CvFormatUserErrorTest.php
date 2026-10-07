<?php

declare(strict_types=1);

namespace {
    if (!function_exists('_t')) {
        define('CENTRALVET_TEST_T_STUB', true);

        /**
         * _t de teste: devolve '[t]' + a chave com ^1/^2 substituídos, para
         * o teste distinguir o texto que passou pela tradução do cru.
         */
        function _t($msg, $param1 = null, $param2 = null, $param3 = null)
        {
            $out = (string) $msg;
            foreach ([1 => $param1, 2 => $param2, 3 => $param3] as $i => $param) {
                if ($param !== null) {
                    $out = str_replace('^' . $i, (string) $param, $out);
                }
            }
            return '[t]' . $out;
        }
    }

    if (!class_exists('CvFormat', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvFormat.php';
    }
}

namespace CentralVet\Tests\Unit {

    use CentralVet\Domain\Exception\CrossTenantReferenceException;
    use CentralVet\Tests\Support\Assert;
    use CentralVet\Tests\Support\SkippedTestException;
    use CvFormat;
    use InvalidArgumentException;
    use RuntimeException;

    /**
     * Rodada 2, T-28: CvFormat::userError/userMessage traduzem mensagens do
     * catálogo UserMessage e escapam parâmetros e fallback para TMessage.
     */
    final class CvFormatUserErrorTest
    {
        public function setUp(): void
        {
            if (!defined('CENTRALVET_TEST_T_STUB')) {
                throw new SkippedTestException('Global _t() already defined (Adianti loaded): the [t] prefix stub is not active');
            }
        }

        public function testCatalogParamIsEscaped(): void
        {
            $text = CvFormat::userError(new InvalidArgumentException('A bank account named "<b>x</b>" already exists for this unit'));

            Assert::stringContains('&lt;b&gt;x&lt;/b&gt;', $text);
            Assert::false(str_contains($text, '<b>'), 'raw <b> must not reach TMessage');
        }

        public function testCrossTenantReferenceReturnsTheClinicKey(): void
        {
            Assert::same(
                '[t]The selected record does not belong to this clinic',
                CvFormat::userError(new CrossTenantReferenceException('Tutor 99 belongs to another tenant'))
            );
        }

        public function testCatalogMessageGoesThroughTranslationKey(): void
        {
            Assert::same(
                '[t]Encounter 3408 is finished and cannot be paused',
                CvFormat::userError(new RuntimeException('Encounter 3408 is finished and cannot be paused')),
                'catalog message must go through _t() with ^1 = 3408'
            );
            Assert::same(
                '[t]Patient sex must be one of M, F, U',
                CvFormat::userError(new InvalidArgumentException('Patient sex must be one of M, F, U')),
                'parameterless catalog message must go through _t()'
            );
        }

        public function testUnknownMessageIsEscapedFallback(): void
        {
            Assert::same('&lt;script&gt;x&lt;/script&gt;', CvFormat::userError(new RuntimeException('<script>x</script>')), 'unknown message must not go through _t()');
        }

        public function testDatabaseErrorBecomesGenericText(): void
        {
            $generic = '[t]Could not complete the operation. Please try again';

            Assert::same($generic, CvFormat::userError(new RuntimeException('x', 0, new \PDOException('SQLSTATE[23000]: Integrity constraint violation'))), 'PDOException as previous');
            Assert::same($generic, CvFormat::userError(new \PDOException('connection lost')), 'PDOException itself');
            Assert::same($generic, CvFormat::userError(new RuntimeException('SQLSTATE[42S22]: Column not found: 1054 foo')), 'SQLSTATE in the message');
            Assert::same($generic, CvFormat::userError(new CrossTenantReferenceException('Tutor 1', 0, new \PDOException('SQLSTATE[HY000]'))), 'PDO rule comes before the others');
        }

        public function testUserMessageAppliesTheSameRule(): void
        {
            Assert::same('[t]A service named "&lt;i&gt;" already exists', CvFormat::userMessage('A service named "<i>" already exists for this tenant'));
            Assert::same('a &amp; b', CvFormat::userMessage('a & b'));
        }
    }
}
