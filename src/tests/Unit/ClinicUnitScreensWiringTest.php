<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\MutationRequestPolicy;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 3, T-25: telas de unidades por clínica, menu e inventário CSRF.
 * Lê os fontes (as telas dependem do Adianti e do banco): mutação onSave
 * pela MutationRequestPolicy, grade só de ClinicUnitService::listUnits(),
 * sem SystemUnit sem filtro de tenant, rótulos à esquerda, ações com
 * .cv-touch-target; menu com Equipe e Unidades da clínica; inventário
 * CSRF com ClinicUserForm e ClinicUnitForm.
 */
final class ClinicUnitScreensWiringTest
{
    private const FORM = '/app/control/admin/ClinicUnitForm.php';
    private const LIST = '/app/control/admin/ClinicUnitList.php';

    public function testSaveRequiresPostAndEditOnlyReads(): void
    {
        Assert::false(MutationRequestPolicy::isReadOnly('ClinicUnitForm', 'onSave'), 'ClinicUnitForm::onSave must require POST + token');
        Assert::true(MutationRequestPolicy::isReadOnly('ClinicUnitForm', 'onEdit'), 'onEdit only reads');
        Assert::true(MutationRequestPolicy::isReadOnly('ClinicUnitList', 'onReload'), 'onReload only reads');
    }

    public function testFormAndListUseOnlyTheClinicUnitService(): void
    {
        $form = $this->source(self::FORM);
        $list = $this->source(self::LIST);

        Assert::stringContains('ClinicUnitService', $form);
        foreach (['onEdit', 'onSave'] as $method) {
            Assert::true((bool) preg_match('/function\s+' . $method . '\s*\(/', $form), "ClinicUnitForm must declare {$method}()");
        }
        Assert::stringContains('->create(', $form);
        Assert::stringContains('->rename(', $form);
        Assert::false(str_contains($form, 'onDelete'), 'units are never deleted');

        Assert::stringContains('->listUnits()', $list);
        Assert::stringContains("_t('New unit')", $list);
        Assert::stringContains("_t('Edit')", $list);
        Assert::stringContains("_t('Clinic units')", $list);
    }

    public function testScreensNeverReadSystemUnitsWithoutTheTenant(): void
    {
        foreach ([self::FORM, self::LIST] as $file) {
            $source = $this->source($file);
            foreach (['SystemUnit::', 'SystemUnit(', 'SystemGroup::', 'SystemUser::', "TRepository('System", 'TDBCombo', 'TDBUniqueSearch'] as $forbidden) {
                Assert::false(str_contains($source, $forbidden), "{$file} must not use {$forbidden}");
            }
        }
    }

    public function testErrorsLabelsAndTouchTargets(): void
    {
        foreach ([self::FORM, self::LIST] as $file) {
            $source = $this->source($file);
            Assert::stringContains('CvFormat::userError', $source, "{$file} must show errors through CvFormat::userError");
            Assert::stringContains('cv-touch-target', $source, "{$file} actions need .cv-touch-target");
            Assert::false((bool) preg_match('/text-align\s*:\s*(right|center)/i', $source), "{$file}: labels and headers stay left-aligned");
            Assert::false((bool) preg_match("/TDataGridColumn\([^)]*'(right|center)'/", $source), "{$file}: grid headers stay left-aligned");
        }
    }

    public function testMenuHasTeamAndClinicUnits(): void
    {
        $menu = $this->source('/menu.xml');

        Assert::true((bool) preg_match("/<menuitem label='_t\{Team\}'>\s*<icon>[^<]+<\/icon>\s*<action>ClinicUserList<\/action>/", $menu), 'menu item Team → ClinicUserList');
        Assert::true((bool) preg_match("/<menuitem label='_t\{Clinic units\}'>\s*<icon>[^<]+<\/icon>\s*<action>ClinicUnitList<\/action>/", $menu), 'menu item Clinic units → ClinicUnitList');
    }

    public function testCsrfInventoryListsTheClinicMutations(): void
    {
        $inventory = $this->source('/../docs/security/csrf-inventario.md');

        foreach (['ClinicUserForm::onSave', 'ClinicUserForm::onDeactivate', 'ClinicUserForm::onReactivate', 'ClinicUnitForm::onSave'] as $action) {
            Assert::stringContains($action, $inventory);
        }
    }

    public function testSeedRegistersTheFourClinicProgramsForGroupOne(): void
    {
        $initial = $this->source('/app/database/seeds/initial-application-programs.sql');

        foreach (['ClinicUserList', 'ClinicUserForm', 'ClinicUnitList', 'ClinicUnitForm'] as $controller) {
            Assert::stringContains("'{$controller}'", $initial);
            Assert::true(
                preg_match("/current_links\\), 1, p\\.id\\s+FROM system_program p WHERE p\\.controller='{$controller}'/", $initial) === 1,
                "{$controller} linked to group 1",
            );
        }
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . $relative;
        $source = is_file($path) ? file_get_contents($path) : false;
        Assert::true(is_string($source), "{$relative} must exist");

        return (string) $source;
    }
}
