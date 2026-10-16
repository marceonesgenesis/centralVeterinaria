<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;

/**
 * Fase 8A, T-16: navegação de IA — submenu AI assistant dentro de Settings
 * com AiSettingsForm e AiInteractionList, ambos registrados no seed.
 * Fase 8B, T-17: o submenu ganha "Minhas conversas" e "Auditoria de ferramentas";
 * o bloco 8B do seed registra AiCopilotPanel, AiConversationList e AiToolCallList.
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
            [
                '_t{AI settings}' => 'AiSettingsForm',
                '_t{AI interactions}' => 'AiInteractionList',
                '_t{My conversations}' => 'AiConversationList',
                '_t{Tool audit}' => 'AiToolCallList',
            ],
            $children
        );
    }

    public function testAiActionsAppearOnlyInsideSettings(): void
    {
        $menu = $this->menu();
        $settingsXml = $this->settingsItem()->asXML();

        foreach (['AiSettingsForm', 'AiInteractionList', 'AiConversationList', 'AiToolCallList'] as $controller) {
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
    /**
     * Bloco "Fase 8B — Copiloto" do seed: do marcador até o único COMMIT.
     */
    private function copilotBlock(): string
    {
        $seed = $this->seed();
        $start = strpos($seed, '-- Fase 8B — Copiloto');
        Assert::true($start !== false, 'seed must have the "Fase 8B — Copiloto" block');
        $commit = strpos($seed, "\nCOMMIT;");
        Assert::true($commit !== false && $commit > $start, 'the 8B block must come before the COMMIT');

        return substr($seed, (int) $start, (int) $commit - (int) $start);
    }

    public function testSeedRegistersCopilotControllersInsideThe8bBlock(): void
    {
        $seed = $this->seed();
        Assert::same(1, preg_match_all('/^START TRANSACTION;/m', $seed), 'seed must keep a single START TRANSACTION');
        Assert::same(1, preg_match_all('/^COMMIT;/m', $seed), 'seed must keep a single COMMIT');

        $block = $this->copilotBlock();
        foreach (['AiCopilotPanel', 'AiConversationList', 'AiToolCallList'] as $controller) {
            Assert::true(
                strpos($block, "'" . $controller . "'") !== false,
                $controller . ' must be registered in the 8B block'
            );
        }
    }

    /**
     * Executa o bloco 8B dentro de uma transação desfeita no fim: os grupos com
     * EncounterView recebem AiCopilotPanel e AiConversationList, só o grupo 1 recebe
     * AiToolCallList, e rodar o bloco de novo não duplica nada.
     */
    public function testCopilotSeedBlockGrantsByEncounterViewAndIsIdempotent(): void
    {
        try {
            $pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    getenv('DB_HOST') ?: '127.0.0.1',
                    getenv('DB_PORT') ?: '3306',
                    \CentralVet\Tests\Support\TestDatabase::resolveName(getenv()),
                ),
                (string) (getenv('DB_USERNAME') ?: 'centralvet'),
                (string) (getenv('DB_PASSWORD') ?: ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $e) {
            throw new \CentralVet\Tests\Support\SkippedTestException('Real MySQL service unreachable: ' . $e->getMessage());
        }

        $statements = array_filter(
            array_map('trim', preg_split('/;\s*\n/', (string) preg_replace('/^--.*$/m', '', $this->copilotBlock()))),
            static fn (string $sql): bool => $sql !== ''
        );

        $pdo->beginTransaction();
        try {
            $nextGroup = (int) $pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM system_group')->fetchColumn();
            $clinicA = $nextGroup;
            $clinicB = $nextGroup + 1;
            $other = $nextGroup + 2;
            $insertGroup = $pdo->prepare('INSERT INTO system_group (id, name) VALUES (?, ?)');
            foreach ([$clinicA => 'T17 clinic A', $clinicB => 'T17 clinic B', $other => 'T17 other'] as $id => $name) {
                $insertGroup->execute([$id, $name]);
            }

            $encounterView = $pdo->query("SELECT id FROM system_program WHERE controller='EncounterView'")->fetchColumn();
            if ($encounterView === false) {
                $encounterView = (int) $pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM system_program')->fetchColumn();
                $pdo->prepare("INSERT INTO system_program (id, name, controller) VALUES (?, 'Central Vet - Encounter View', 'EncounterView')")
                    ->execute([$encounterView]);
            }
            $link = $pdo->prepare(
                'INSERT INTO system_group_program (id, system_group_id, system_program_id)
                 SELECT COALESCE(MAX(id),0)+1, ?, ? FROM system_group_program'
            );
            foreach ([1, $clinicA, $clinicB] as $group) {
                $exists = $pdo->prepare('SELECT 1 FROM system_group_program WHERE system_group_id=? AND system_program_id=?');
                $exists->execute([$group, $encounterView]);
                if ($exists->fetchColumn() === false) {
                    $link->execute([$group, $encounterView]);
                }
            }

            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
            $grants = static function () use ($pdo): array {
                $rows = $pdo->query(
                    "SELECT p.controller, gp.system_group_id FROM system_group_program gp
                     JOIN system_program p ON p.id = gp.system_program_id
                     WHERE p.controller IN ('AiCopilotPanel','AiConversationList','AiToolCallList')
                     ORDER BY p.controller, gp.system_group_id"
                )->fetchAll(\PDO::FETCH_NUM);

                return array_map(static fn (array $r): string => $r[0] . '@' . $r[1], $rows);
            };
            $first = $grants();

            foreach (['AiCopilotPanel', 'AiConversationList'] as $controller) {
                foreach ([1, $clinicA, $clinicB] as $group) {
                    Assert::true(in_array($controller . '@' . $group, $first, true), $controller . ' must be granted to group ' . $group);
                }
                Assert::true(!in_array($controller . '@' . $other, $first, true), $controller . ' must not be granted without EncounterView');
            }
            Assert::same(['AiToolCallList@1'], array_values(array_filter(
                $first,
                static fn (string $g): bool => str_starts_with($g, 'AiToolCallList@')
            )));

            $programs = (int) $pdo->query('SELECT COUNT(*) FROM system_program')->fetchColumn();
            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
            Assert::same($first, $grants(), 'running the 8B block twice must not add grants');
            Assert::same($programs, (int) $pdo->query('SELECT COUNT(*) FROM system_program')->fetchColumn());
        } finally {
            $pdo->rollBack();
        }
    }
}
