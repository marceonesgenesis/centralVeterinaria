<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\SubscriptionAccessGuard;
use CentralVet\Security\MutationRequestPolicy;
use CentralVet\Tenancy\ClinicRoleProfiles;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 5, T-19: registro das telas novas. Os 6 controllers da etapa estão
 * no seed inicial (grupo 1) e nos 4 grupos de plano; todo programa dos
 * perfis restritos existe no seed inicial (ou no permission.sql, para o
 * autoatendimento System*); o menu aponta primeiros passos, dados da
 * clínica, exportação e ajuda; a guarda deixa suporte, exportação e
 * cancelamento abertos com a assinatura bloqueada; o inventário CSRF cita
 * os métodos de escrita e de leitura. Lê os fontes: telas dependem do
 * Adianti e do banco, que a suíte unitária não sobe.
 */
final class Etapa5WiringTest
{
    private const CONTROLLERS = [
        'OnboardingChecklistView', 'ClinicProfileForm', 'FirstEncounterGuideView',
        'SupportView', 'ClinicDataExportView', 'SubscriptionCancelForm',
    ];

    private const PLAN_GROUPS = [
        'Central Vet - Plano Starter', 'Central Vet - Plano Pro',
        'Central Vet - Plano Business', 'Central Vet - Plano Enterprise',
    ];

    private const WRITES = [
        'ClinicProfileForm::onSave', 'FirstEncounterGuideView::onMarkRead',
        'OnboardingChecklistView::onMarkTeamReviewed', 'SubscriptionCancelForm::onConfirm',
    ];

    private const READS = ['ClinicDataExportView::onDownload', 'ClinicProfileForm::onEdit'];

    public function testInitialSeedRegistersTheSixProgramsForGroupOne(): void
    {
        $seed = $this->initialSeed();

        foreach (self::CONTROLLERS as $controller) {
            Assert::stringContains("controller='" . $controller . "'", $seed, $controller . ' missing in system_program');
            Assert::true(
                preg_match(
                    "/INSERT INTO system_program \(id, name, controller\)\s*SELECT [^;]*'Central Vet - [^']+', '" . $controller . "'/",
                    $seed,
                ) === 1,
                $controller . ' must use the "Central Vet - <Nome>" pattern',
            );
            Assert::true(
                preg_match(
                    "/current_links\), 1, p\.id\s+FROM system_program p WHERE p\.controller='" . $controller . "'/",
                    $seed,
                ) === 1,
                $controller . ' must be linked to group 1',
            );
        }
    }

    public function testPlanGroupsLinkTheSixPrograms(): void
    {
        $links = $this->planLinks();

        foreach (self::PLAN_GROUPS as $group) {
            foreach (self::CONTROLLERS as $controller) {
                Assert::true(in_array($controller, $links[$group] ?? [], true), $group . ' must link ' . $controller);
            }
        }
    }

    public function testEveryRoleProfileProgramIsRegistered(): void
    {
        $seed = $this->initialSeed();
        $permission = $this->source(dirname(__DIR__, 2) . '/app/database/permission.sql');

        foreach (ClinicRoleProfiles::roles() as $role) {
            foreach (ClinicRoleProfiles::programs($role) as $controller) {
                $registered = str_contains($seed, "controller='" . $controller . "'")
                    || (str_starts_with($controller, 'System') && str_contains($permission, "'" . $controller . "')"));
                Assert::true($registered, $role . ' program ' . $controller . ' is not registered in system_program');
            }
        }
    }

    /**
     * Reaplicar o seed inicial depois de role-groups.sql não pode ligar a IA
     * aos perfis restritos (achado da T-04): os grants amplos excluem também
     * 'Central Vet - Perfil %'.
     */
    public function testBroadAiGrantsExcludeTheRoleProfiles(): void
    {
        $broad = 0;
        foreach (explode(';', $this->initialSeed()) as $statement) {
            if (!str_contains($statement, 'clinical_group')) {
                continue;
            }
            $broad++;
            Assert::same(2, substr_count($statement, "NOT LIKE 'Central Vet - Perfil %'"), 'grant and id offset must exclude role profiles');
        }
        Assert::same(2, $broad, 'AiCopilotPanel and AiConversationList are the broad grants');
    }

    public function testMenuPointsTheNewScreens(): void
    {
        $menu = $this->source(dirname(__DIR__, 2) . '/menu.xml');

        foreach (['OnboardingChecklistView', 'ClinicProfileForm', 'ClinicDataExportView', 'SupportView'] as $controller) {
            Assert::stringContains('<action>' . $controller . '</action>', $menu);
        }
        Assert::false(str_contains($menu, 'onComingSoon#item=help'), 'Help must open SupportView');
        Assert::false(str_contains($menu, 'onComingSoon#item=dashboard'), 'top item must open the first steps');

        $labels = [
            'First steps' => 'OnboardingChecklistView',
            'Clinic data' => 'ClinicProfileForm',
            'Export clinic data' => 'ClinicDataExportView',
            'Help' => 'SupportView',
        ];
        foreach ($labels as $label => $controller) {
            Assert::true(
                preg_match("/<menuitem label='_t\{" . preg_quote($label, '/') . "\}'>\s*<icon>[^<]+<\/icon>\s*<action>" . $controller . '<\/action>/', $menu) === 1,
                'menu item ' . $label . ' → ' . $controller,
            );
        }
    }

    public function testGuardKeepsSupportExportAndCancelOpen(): void
    {
        foreach (['SupportView', 'ClinicDataExportView', 'SubscriptionCancelForm'] as $controller) {
            Assert::true(in_array($controller, SubscriptionAccessGuard::ALWAYS_ALLOWED, true), $controller . ' must be ALWAYS_ALLOWED');
        }
    }

    public function testCsrfInventoryAndPolicyCoverTheNewMethods(): void
    {
        $inventory = $this->source(dirname(__DIR__, 3) . '/docs/security/csrf-inventario.md');

        foreach (self::WRITES as $action) {
            Assert::stringContains($action, $inventory);
            [$class, $method] = explode('::', $action);
            Assert::false(MutationRequestPolicy::isReadOnly($class, $method), $action . ' must require POST + token');
        }
        foreach (self::READS as $action) {
            Assert::stringContains($action, $inventory);
            [$class, $method] = explode('::', $action);
            Assert::true(MutationRequestPolicy::isReadOnly($class, $method), $action . ' only reads');
        }
    }

    /** @return array<string, list<string>> */
    private function planLinks(): array
    {
        $links = [];
        $seed = $this->source(dirname(__DIR__, 2) . '/app/database/seeds/plan-groups.sql');
        foreach (explode(';', $seed) as $statement) {
            if (!str_contains($statement, 'INSERT INTO system_group_program')) {
                continue;
            }
            if (preg_match("/g\.name='([^']+)'/", $statement, $group) === 1
                && preg_match("/p\.controller='([^']+)'/", $statement, $controller) === 1
            ) {
                $links[$group[1]][] = $controller[1];
            }
        }

        return $links;
    }

    private function initialSeed(): string
    {
        return $this->source(dirname(__DIR__, 2) . '/app/database/seeds/initial-application-programs.sql');
    }

    private function source(string $path): string
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        Assert::true(is_string($source), 'missing file ' . $path);

        return (string) $source;
    }
}
