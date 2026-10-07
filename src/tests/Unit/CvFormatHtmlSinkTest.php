<?php

declare(strict_types=1);

namespace {
    if (!class_exists('CvFormat', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvFormat.php';
    }
}

namespace CentralVet\Tests\Unit {

    use CentralVet\Tests\Support\Assert;
    use CvFormat;

    /**
     * T-63, correção 3: valor para atributo HTML cujo conteúdo decodificado
     * volta a ser interpretado como HTML (tooltip tippy com allowHTML a partir
     * de [title]). O navegador decodifica o atributo uma vez; o que sobra
     * tem de ser texto, não marcação.
     */
    final class CvFormatHtmlSinkTest
    {
        public function testMarkupIsEscapedTwice(): void
        {
            Assert::same(
                '&amp;lt;img src=x onerror=alert(1)&amp;gt;.pdf',
                CvFormat::forHtmlSink('<img src=x onerror=alert(1)>.pdf')
            );
        }

        public function testOneDecodeLeavesEscapedTextWithoutRawTag(): void
        {
            $raw = '<img src=x onerror=alert(1)>"\'&.pdf';
            $afterAttributeDecode = html_entity_decode(CvFormat::forHtmlSink($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            Assert::same(CvFormat::e($raw), $afterAttributeDecode);
            Assert::false(str_contains($afterAttributeDecode, '<'), 'one decode must not yield a raw tag');
            Assert::same($raw, html_entity_decode($afterAttributeDecode, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        public function testPlainUtf8NameIsUnchanged(): void
        {
            Assert::same('Relatório Ação.pdf', CvFormat::forHtmlSink('Relatório Ação.pdf'));
        }

        public function testNullBecomesEmptyString(): void
        {
            Assert::same('', CvFormat::forHtmlSink(null));
        }
    }
}
