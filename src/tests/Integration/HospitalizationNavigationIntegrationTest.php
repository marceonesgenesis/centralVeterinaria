<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6A, T-17: navegação da internação — abas CvNav('hospitalization'),
 * ação "Internar" no plano clínico do EncounterView e itens do menu lateral.
 *
 * CvNav e EncounterView só carregam com o Adianti, num processo PHP separado
 * (init.php define _t() global).
 */
final class HospitalizationNavigationIntegrationTest
{
    /**
     * @return array<string, mixed>
     */
    private function runAdianti(): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$out = [];'
            . 'try { $out["tabs"] = CvNav::group("hospitalization"); }'
            . ' catch (Throwable $e) { $out["tabs_error"] = get_class($e) . ": " . $e->getMessage(); }'
            . 'try { $out["plan"] = (new ReflectionClassConstant("EncounterView", "PLAN_ACTIONS"))->getValue(); }'
            . ' catch (Throwable $e) { $out["plan_error"] = get_class($e) . ": " . $e->getMessage(); }'
            . 'echo json_encode($out);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'navigation subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testCvNavHospitalizationGroupLinksBoardAndBeds(): void
    {
        $result = $this->runAdianti();

        Assert::true(isset($result['tabs']), 'CvNav::group("hospitalization"): ' . (string) ($result['tabs_error'] ?? 'missing'));
        Assert::same('index.php?class=HospitalizationBoard', $result['tabs']['board']['href'] ?? null);
        Assert::same('index.php?class=BedList', $result['tabs']['beds']['href'] ?? null);
    }

    public function testEncounterPlanActionsOfferHospitalization(): void
    {
        $result = $this->runAdianti();

        Assert::true(isset($result['plan']), 'EncounterView::PLAN_ACTIONS: ' . (string) ($result['plan_error'] ?? 'missing'));
        Assert::same(
            ['Hospitalize', 'fa:procedures', 'HospitalizationAdmissionForm'],
            $result['plan']['hospitalization'] ?? null
        );
    }

    public function testMenuHasHospitalizationBeforeSurgeriesAndBedsInSettings(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/menu.xml');

        $board = strpos($xml, '<action>HospitalizationBoard</action>');
        $surgeries = strpos($xml, "label='_t{Surgeries}'");
        $settings = strpos($xml, "label='_t{Settings}'");
        $beds = strpos($xml, '<action>BedList</action>');

        Assert::true($board !== false, 'menu.xml must contain <action>HospitalizationBoard</action>');
        Assert::true($beds !== false, 'menu.xml must contain <action>BedList</action>');
        Assert::true($surgeries !== false && $board < $surgeries, 'Hospitalization must come before Surgeries');
        Assert::true($settings !== false && $beds > $settings, 'Beds must be inside Settings');
    }
}
