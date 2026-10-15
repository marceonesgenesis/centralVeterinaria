<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;

/**
 * Fase 8A, T-16: navegação de IA — submenu AI assistant dentro de Settings
 * com AiSettingsForm e AiInteractionList, ambos registrados no seed.
 */
final class AiNavigationIntegrationTest
{
    private function menu(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/menu.xml');
    }

    private function seed(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/app/database/seeds/initial-application-programs.sql');
    }

    private function settingsItem(): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($this->menu());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        Assert::true($doc !== false, 'menu.xml must be well-formed XML');

        foreach ($doc->menuitem as $item) {
            if ((string) $item['label'] === '_t{Settings}') {
                return $item;
            }
        }

        throw new \RuntimeException('menu.xml must keep the Settings item');
    }

    public function testSettingsHasAiAssistantSubmenu(): void
    {
        $settings = $this->settingsItem();
        Assert::true(isset($settings->menu), 'Settings must be a submenu');

        $ai = null;
        foreach ($settings->menu->menuitem as $child) {
            if ((string) $child['label'] === '_t{AI assistant}') {
                $ai = $child;
            }
        }

        Assert::true($ai !== null, 'Settings must have the AI assistant submenu');
        Assert::true(isset($ai->menu), 'AI assistant must be a submenu');
        Assert::true(trim((string) $ai->icon) !== '', 'AI assistant must have an icon');

        $children = [];
        foreach ($ai->menu->menuitem as $child) {
            $children[(string) $child['label']] = (string) $child->action;
            Assert::true(trim((string) $child->icon) !== '', 'AI menu items must have an icon');
        }

        Assert::same(
            ['_t{AI settings}' => 'AiSettingsForm', '_t{AI interactions}' => 'AiInteractionList'],
            $children
        );
    }

    public function testAiActionsAppearOnlyInsideSettings(): void
    {
        $menu = $this->menu();
        $settingsXml = $this->settingsItem()->asXML();

        foreach (['AiSettingsForm', 'AiInteractionList'] as $controller) {
            $action = '<action>' . $controller . '</action>';
            Assert::same(1, substr_count($menu, $action), $controller . ' must appear once in menu.xml');
            Assert::true(strpos((string) $settingsXml, $action) !== false, $controller . ' must be inside Settings');
        }
    }

    public function testSeedRegistersAiControllers(): void
    {
        $seed = $this->seed();

        Assert::true(
            strpos($seed, "'Central Vet - AI Settings', 'AiSettingsForm'") !== false,
            'seed must register AiSettingsForm'
        );
        Assert::true(
            strpos($seed, "'Central Vet - AI Interaction List', 'AiInteractionList'") !== false,
            'seed must register AiInteractionList'
        );
    }
}
