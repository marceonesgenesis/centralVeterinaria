<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6B, T-18: navegação da cirurgia — abas CvNav('surgery'), ação
 * "Agendar cirurgia" no plano clínico do EncounterView e itens do menu lateral.
 *
 * CvNav e EncounterView só carregam com o Adianti, num processo PHP separado
 * (init.php define _t() global).
 */
final class SurgeryNavigationIntegrationTest
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
            . 'try { $out["tabs"] = CvNav::group("surgery"); }'
            . ' catch (Throwable $e) { $out["tabs_error"] = get_class($e) . ": " . $e->getMessage(); }'
            . 'try { $out["plan"] = (new ReflectionClassConstant("EncounterView", "PLAN_ACTIONS"))->getValue(); }'
            . ' catch (Throwable $e) { $out["plan_error"] = get_class($e) . ": " . $e->getMessage(); }'
            . 'echo json_encode($out);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'navigation subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testCvNavSurgeryGroupLinksListAndRooms(): void
    {
        $result = $this->runAdianti();

        Assert::true(isset($result['tabs']), 'CvNav::group("surgery"): ' . (string) ($result['tabs_error'] ?? 'missing'));
        Assert::same('index.php?class=SurgeryList', $result['tabs']['list']['href'] ?? null);
        Assert::same('index.php?class=SurgeryRoomList', $result['tabs']['rooms']['href'] ?? null);
    }

    public function testEncounterPlanActionsOfferSurgery(): void
    {
        $result = $this->runAdianti();

        Assert::true(isset($result['plan']), 'EncounterView::PLAN_ACTIONS: ' . (string) ($result['plan_error'] ?? 'missing'));
        Assert::same(
            ['Schedule surgery', 'fa:kit-medical', 'SurgeryScheduleForm'],
            $result['plan']['surgery'] ?? null
        );
    }

    public function testMenuPointsSurgeriesAndSurgeryRoomsToRealScreens(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/menu.xml');

        $settings = strpos($xml, "label='_t{Settings}'");
        $rooms = strpos($xml, '<action>SurgeryRoomList</action>');

        Assert::true(strpos($xml, '<action>SurgeryList</action>') !== false, 'menu.xml must contain <action>SurgeryList</action>');
        Assert::true($rooms !== false, 'menu.xml must contain <action>SurgeryRoomList</action>');
        Assert::true($settings !== false && $rooms > $settings, 'Surgery rooms must be inside Settings');
        Assert::true(strpos($xml, 'item=surgeries') === false, 'menu.xml must not keep the coming-soon surgeries item');
    }
}
