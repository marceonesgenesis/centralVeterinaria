<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7A, T-20: navegação da comunicação — item "Pending items" no menu,
 * submenu de CRM / Communication, abas CvNav('communication') e ações de
 * comunicação no cabeçalho do TutorForm.
 *
 * CvNav só carrega com o Adianti, num processo PHP separado
 * (init.php define _t() global).
 */
final class CommunicationNavigationIntegrationTest
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
            . 'try { $out["tabs"] = CvNav::group("communication"); }'
            . ' catch (Throwable $e) { $out["tabs_error"] = get_class($e) . ": " . $e->getMessage(); }'
            . 'echo json_encode($out);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'navigation subprocess output: ' . (string) $out);

        return $decoded;
    }

    private function menu(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/menu.xml');
    }

    public function testMenuParsesAsXml(): void
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($this->menu());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        Assert::true($doc !== false, 'menu.xml must be well-formed XML');
    }

    public function testMenuHasPendingCenterRightAfterDashboard(): void
    {
        $doc = simplexml_load_string($this->menu());
        Assert::true($doc !== false, 'menu.xml must be well-formed XML');

        $labels = [];
        $actions = [];
        foreach ($doc->menuitem as $item) {
            $labels[] = (string) $item['label'];
            $actions[] = isset($item->action) ? (string) $item->action : null;
        }

        $dashboard = array_search('Dashboard', $labels, true);
        Assert::true($dashboard !== false, 'menu.xml must keep the Dashboard item');
        Assert::same('_t{Pending items}', $labels[$dashboard + 1] ?? null);
        Assert::same('PendingCenter', $actions[$dashboard + 1] ?? null);
    }

    public function testCrmItemIsSubmenuWithMessagesAndTemplates(): void
    {
        $xml = $this->menu();
        Assert::true(strpos($xml, 'item=crm') === false, 'menu.xml must not keep the coming-soon crm item');

        $doc = simplexml_load_string($xml);
        Assert::true($doc !== false, 'menu.xml must be well-formed XML');

        $crm = null;
        foreach ($doc->menuitem as $item) {
            if ((string) $item['label'] === '_t{CRM / Communication}') {
                $crm = $item;
            }
        }

        Assert::true($crm !== null, 'menu.xml must keep the CRM / Communication item');
        Assert::same('fas:comments fa-fw', (string) $crm->icon);
        Assert::true(isset($crm->menu), 'CRM / Communication must be a submenu');

        $children = [];
        foreach ($crm->menu->menuitem as $child) {
            $children[(string) $child['label']] = (string) $child->action;
        }

        Assert::same(
            ['_t{Messages}' => 'CommunicationMessageList', '_t{Message templates}' => 'MessageTemplateList'],
            $children
        );
    }

    public function testCvNavCommunicationGroupLinksMessagesAndTemplates(): void
    {
        $result = $this->runAdianti();

        Assert::true(isset($result['tabs']), 'CvNav::group("communication"): ' . (string) ($result['tabs_error'] ?? 'missing'));
        Assert::same('index.php?class=CommunicationMessageList', $result['tabs']['messages']['href'] ?? null);
        Assert::same('index.php?class=MessageTemplateList', $result['tabs']['templates']['href'] ?? null);
    }

    public function testTutorFormHeaderOffersCommunicationActions(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/control/clinic/TutorForm.php');

        Assert::true(strpos($source, 'TutorCommunicationForm&tutor_id=') !== false, 'TutorForm must link TutorCommunicationForm&tutor_id=');
        Assert::true(strpos($source, 'CommunicationComposeForm&tutor_id=') !== false, 'TutorForm must link CommunicationComposeForm&tutor_id=');
    }
}
