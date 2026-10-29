<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\MutationRequestPolicy;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 3, T-24: telas de equipe por clínica. Lê os fontes (as telas
 * dependem do Adianti e do banco, que a suíte unitária não sobe):
 * mutações pela MutationRequestPolicy, combos só de allowedGroups() e das
 * unidades do tenant (nunca SystemGroup/SystemUnit sem filtro), grade só de
 * listUsers(), erros por CvFormat::userError, rótulos à esquerda e ações
 * com .cv-touch-target.
 */
final class ClinicTeamScreensWiringTest
{
    private const FORM = '/app/control/admin/ClinicUserForm.php';
    private const LIST = '/app/control/admin/ClinicUserList.php';

    public function testMutationsRequirePostAndEditOnlyReads(): void
    {
        foreach (['onSave', 'onDeactivate', 'onReactivate'] as $method) {
            Assert::false(MutationRequestPolicy::isReadOnly('ClinicUserForm', $method), "ClinicUserForm::{$method} must require POST + token");
        }
        Assert::true(MutationRequestPolicy::isReadOnly('ClinicUserForm', 'onEdit'), 'onEdit only reads');
        Assert::true(MutationRequestPolicy::isReadOnly('ClinicUserList', 'onReload'), 'onReload only reads');
    }

    public function testFormCombosReadOnlyAllowedGroupsAndTenantUnits(): void
    {
        $form = $this->source(self::FORM);

        Assert::stringContains('ClinicTeamService', $form);
        Assert::stringContains('->allowedGroups()', $form);
        Assert::stringContains('->listUnits()', $form);
        foreach (['onEdit', 'onSave', 'onDeactivate', 'onReactivate'] as $method) {
            Assert::true((bool) preg_match('/function\s+' . $method . '\s*\(/', $form), "ClinicUserForm must declare {$method}()");
        }
        Assert::stringContains('->invite(', $form);
        Assert::stringContains('->update(', $form);
        Assert::stringContains('->deactivate(', $form);
        Assert::stringContains('->reactivate(', $form);
        Assert::stringContains('setEditable(false)', $form, 'e-mail must be read-only when editing');
    }

    public function testListShowsOnlyListUsers(): void
    {
        $list = $this->source(self::LIST);

        Assert::stringContains('->listUsers()', $list);
        Assert::stringContains("_t('Invite user')", $list);
        foreach (["_t('Edit')", "_t('Deactivate')", "_t('Reactivate')"] as $label) {
            Assert::stringContains($label, $list);
        }
    }

    public function testScreensNeverReadSystemGroupsOrUnitsWithoutTheTenant(): void
    {
        foreach ([self::FORM, self::LIST] as $file) {
            $source = $this->source($file);
            foreach (['SystemGroup::', 'SystemUnit::', 'SystemUser::', "TRepository('System", 'TDBCombo', 'TDBUniqueSearch', 'TDBMultiSearch', 'TDBCheckGroup'] as $forbidden) {
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

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . $relative;
        $source = is_file($path) ? file_get_contents($path) : false;
        Assert::true(is_string($source), "{$relative} must exist");

        return (string) $source;
    }
}
