<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6A, T-12: BedForm (cadastro de leitos da unidade) expõe os campos
 * code, name e daily_rate; na edição (id), code fica só leitura.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * no padrão de AppointmentFormPostIntegrationTest.
 */
final class BedFormIntegrationTest
{
    private const MARKER = '@@BEDFORM@@';

    private function runInAdianti(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";' . $body;

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        // Sem sessão, TMessage pode escrever antes do JSON: só o que vem
        // depois do marcador conta.
        $pos = strrpos((string) $out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr((string) $out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'BedForm subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testBedFormHasCodeNameAndDailyRateFields(): void
    {
        $result = $this->runInAdianti(
            '$page = new BedForm([]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$fields = [];'
            . 'foreach (["code", "name", "daily_rate"] as $n) { $fields[$n] = $form->getField($n) !== null; }'
            . 'echo "@@BEDFORM@@", json_encode($fields);'
        );

        Assert::same(['code' => true, 'name' => true, 'daily_rate' => true], $result);
    }

    public function testCodeIsReadOnlyWhenEditingAnExistingBed(): void
    {
        $result = $this->runInAdianti(
            '$page = new BedForm(["id" => 7]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . 'echo "@@BEDFORM@@", json_encode(["editable" => (bool) $form->getField("code")->getEditable()]);'
        );

        Assert::same(['editable' => false], $result);
    }

    /**
     * Correção 1 (gate T-20): alvos de toque de 44 px no tablet. A regra
     * `.cv-touch-target` mora na seção `cv-touch` de cv-components.css.
     */
    public function testTouchTargetRuleExistsInSharedCss(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/app/templates/adminbs5/cv-components.css');
        $start = strpos($css, '/* cv-touch */');

        Assert::true($start !== false, 'cv-components.css must have a /* cv-touch */ section');

        $section = substr($css, $start);
        Assert::true(
            preg_match('/\.cv-touch-target\s*\{[^}]*min-height:\s*44px;[^}]*\}/', $section) === 1
                && preg_match('/\.cv-touch-target\s*\{[^}]*min-width:\s*44px;[^}]*\}/', $section) === 1,
            '.cv-touch-target must set min-height and min-width 44px'
        );
    }

    public function testBedFormSaveButtonIsATouchTarget(): void
    {
        $result = $this->runInAdianti(
            '$page = new BedForm([]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . 'ob_start(); $form->show(); $html = ob_get_clean();'
            . 'preg_match_all("/<button[^>]*>/", $html, $m);'
            . '$save = array_values(array_filter($m[0], fn ($b) => str_contains($b, "onSave")));'
            . 'echo "@@BEDFORM@@", json_encode(["found" => count($save), "touch" => $save !== [] && str_contains($save[0], "cv-touch-target")]);'
        );

        Assert::same(['found' => 1, 'touch' => true], $result);
    }

    public function testBedListRowActionsAreTouchTargets(): void
    {
        $result = $this->runInAdianti(
            '$page = new BedList([]);'
            . '$grid = (new ReflectionProperty($page, "datagrid"))->getValue($page);'
            . '$grid->addItem((object) ["id" => 1, "code" => "L1", "name" => "x", "daily_rate_cents" => 0, "status" => "available"]);'
            . 'ob_start(); $grid->show(); $html = ob_get_clean();'
            . 'preg_match_all("/<a [^>]*href=\"[^\"]*(onEdit|onDeactivate)[^\"]*\"[^>]*>\\s*<span[^>]*class=\"([^\"]*)\"/", $html, $m);'
            . '$touch = array_filter($m[2], fn ($c) => str_contains($c, "cv-touch-target"));'
            . 'echo "@@BEDFORM@@", json_encode(["actions" => count($m[2]), "touch" => count($touch)]);'
        );

        Assert::same(['actions' => 2, 'touch' => 2], $result);
    }
}
