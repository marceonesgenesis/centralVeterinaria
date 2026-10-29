<?php

declare(strict_types=1);

namespace {
    // SaleList depende do Adianti, que a suíte não sobe: carrega só as
    // classes reais necessárias (lib/adianti, só leitura) e os aliases
    // globais que o init.php criaria.
    $adianti = dirname(__DIR__, 2) . '/lib/adianti';

    if (!class_exists('Adianti\\Widget\\Base\\TElement')) {
        require $adianti . '/widget/base/TElement.php';
    }
    if (!class_exists('TElement')) {
        class_alias('Adianti\\Widget\\Base\\TElement', 'TElement');
    }
    if (!interface_exists('Adianti\\Control\\AdiantiController')) {
        require $adianti . '/control/AdiantiController.php';
    }
    if (!trait_exists('Adianti\\Control\\AdiantiPageControlTrait')) {
        require $adianti . '/control/AdiantiPageControlTrait.php';
    }
    if (!class_exists('Adianti\\Control\\TPage')) {
        require $adianti . '/control/TPage.php';
    }
    if (!class_exists('TPage')) {
        class_alias('Adianti\\Control\\TPage', 'TPage');
    }
    if (!function_exists('_t')) {
        function _t($msg, $param1 = null, $param2 = null, $param3 = null)
        {
            return (string) $msg;
        }
    }
    if (!class_exists('CvFormat', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvFormat.php';
    }
    if (!class_exists('SaleList', false)) {
        require dirname(__DIR__, 2) . '/app/control/clinic/SaleList.php';
    }
}

namespace CentralVet\Tests\Unit {

    use CentralVet\Tests\Support\Assert;
    use SaleList;
    use TElement;

    /**
     * T-16, correção 2: o TDataGrid aplica htmlspecialchars a todo valor
     * escalar da linha ANTES do transformer, então um botão montado como
     * string HTML na linha aparecia escapado, como texto. A célula de ação
     * precisa ser um TElement montado no transformer a partir do objeto.
     */
    final class SaleListActionCellTest
    {
        public function testCompletedSaleGetsARenderableCancelLink(): void
        {
            $cell = SaleList::actionCell((object) ['id' => 1715, 'status' => 'completed']);

            Assert::instanceOf(TElement::class, $cell, 'the action cell must be an element, not an HTML string the grid escapes');

            $html = $cell->getContents();

            Assert::stringContains('<a ', $html);
            Assert::stringContains('index.php?class=SaleCancelForm&method=onEdit&sale_id=1715', html_entity_decode($html));
            Assert::stringContains('generator="adianti"', $html);
            Assert::stringContains('cv-touch-target', $html);
            Assert::false(str_contains($html, '&lt;a'), 'link markup must not be escaped');
        }

        public function testCancelledSaleHasNoAction(): void
        {
            Assert::same('', SaleList::actionCell((object) ['id' => 9, 'status' => 'cancelled']));
        }

        public function testActionColumnBuildsTheCellInTheTransformer(): void
        {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/control/clinic/SaleList.php');

            Assert::stringContains('self::actionCell($object)', $source);
            Assert::false(str_contains($source, '$row->action ='), 'the row must not carry pre-rendered HTML');
        }
    }
}
