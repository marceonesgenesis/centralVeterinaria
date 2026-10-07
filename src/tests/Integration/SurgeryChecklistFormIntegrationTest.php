<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6B, T-16: checklist de segurança no tablet (SurgeryChecklistForm)
 * e materiais da cirurgia (SurgeryMaterialForm), mais a seção
 * `cv-checklist` de cv-components.css.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * no padrão de BedFormIntegrationTest. Sem sessão: nenhuma tela toca o banco.
 */
final class SurgeryChecklistFormIntegrationTest
{
    private const MARKER = '@@SURGERYCHECKLIST@@';

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

        Assert::true(is_array($decoded), 'SurgeryChecklistForm subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testChecklistFormWithoutSurgeryIdDoesNotThrow(): void
    {
        $result = $this->runInAdianti(
            '$page = new SurgeryChecklistForm(["phase" => "time_out"]);'
            . 'ob_start(); $page->show(); $html = ob_get_clean();'
            . 'echo "@@SURGERYCHECKLIST@@", json_encode(["built" => true, "empty" => str_contains($html, "cv-state--empty")]);'
        );

        Assert::same(['built' => true, 'empty' => true], $result);
    }

    public function testItemFieldsBuildOneTouchCheckboxPerTimeOutItem(): void
    {
        $result = $this->runInAdianti(
            '$out = [];'
            . 'foreach (SurgeryChecklistForm::itemFields("time_out") as $field) {'
            . '  ob_start(); $field->show(); $html = ob_get_clean();'
            . '  preg_match("/<input[^>]*value=\"([^\"]*)\"/", $html, $v);'
            . '  preg_match("/<input[^>]*name=\"([^\"]*)\"/", $html, $n);'
            . '  $out[] = ["value" => $v[1] ?? null, "name" => $n[1] ?? null,'
            . '    "class" => in_array("cv-checklist__item", explode(" ", (string) $field->getProperty("class")), true)];'
            . '}'
            . 'echo "@@SURGERYCHECKLIST@@", json_encode($out);'
        );

        $expected = [];
        foreach (['team_introduced', 'procedure_and_site_confirmed', 'antibiotic_prophylaxis_reviewed', 'critical_steps_reviewed'] as $code) {
            $expected[] = ['value' => $code, 'name' => 'items[]', 'class' => true];
        }

        Assert::same($expected, $result);
    }

    public function testUnknownPhaseRendersEmptyStateWithoutTouchingTheDatabase(): void
    {
        $result = $this->runInAdianti(
            '$page = new SurgeryChecklistForm(["surgery_id" => 1, "phase" => "foo"]);'
            . 'ob_start(); $page->show(); $html = ob_get_clean();'
            . 'echo "@@SURGERYCHECKLIST@@", json_encode(["empty" => str_contains($html, "cv-state--empty"), "confirm" => str_contains($html, "onConfirm")]);'
        );

        Assert::same(['empty' => true, 'confirm' => false], $result);
    }

    public function testMaterialFormHasProductAndQuantityFields(): void
    {
        $result = $this->runInAdianti(
            '$page = new SurgeryMaterialForm(["surgery_id" => 1]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . 'echo "@@SURGERYCHECKLIST@@", json_encode(['
            . '  "product_id" => $form->getField("product_id") !== null,'
            . '  "quantity" => $form->getField("quantity") !== null,'
            . '  "add" => str_contains((function () use ($form) { ob_start(); $form->show(); return ob_get_clean(); })(), "onAdd"),'
            . ']);'
        );

        // sem sessão a cirurgia não carrega: só leitura, sem botão "Adicionar"
        Assert::same(['product_id' => true, 'quantity' => true, 'add' => false], $result);
    }

    public function testChecklistCssSectionGivesItemsATouchArea(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/app/templates/adminbs5/cv-components.css');
        $start = strpos($css, '/* cv-checklist */');

        Assert::true($start !== false, 'cv-components.css must have a /* cv-checklist */ section');

        $section = substr($css, $start);
        Assert::true(
            preg_match('/\.cv-checklist__item\s*\{[^}]*var\(--cv-touch-target\)[^}]*\}/', $section) === 1,
            '.cv-checklist__item must size its touch area with var(--cv-touch-target)'
        );
        Assert::true(str_contains($section, '.cv-checklist__item--checked'), '.cv-checklist__item--checked must be styled');
        Assert::true(str_contains($section, '.cv-checklist {') || str_contains($section, '.cv-checklist{'), '.cv-checklist must be styled');
    }
}
