<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Etapa 5, T-15: telas de primeiros passos, dados da clínica e orientações do
 * primeiro atendimento. Lê os fontes (as telas dependem do Adianti e do banco):
 * checklist pelo OnboardingChecklistService com os 4 itens e os atalhos,
 * dados da clínica pelo ClinicProfileService, guia lido do HTML com os 7
 * passos na ordem; rótulos à esquerda e ações com .cv-touch-target.
 */
final class OnboardingScreensWiringTest
{
    private const CHECKLIST = '/app/control/admin/OnboardingChecklistView.php';
    private const PROFILE = '/app/control/admin/ClinicProfileForm.php';
    private const GUIDE = '/app/control/admin/FirstEncounterGuideView.php';
    private const GUIDE_HTML = '/app/view/onboarding/first-encounter-guide.html';

    public function testChecklistUsesTheServiceItemsAndShortcuts(): void
    {
        $source = $this->source(self::CHECKLIST);

        foreach ([
            'OnboardingChecklistService',
            '->status()',
            'ITEM_CLINIC',
            'ITEM_TEAM',
            'ITEM_SERVICES',
            'ITEM_GUIDE',
            'ClinicProfileForm',
            'ClinicUserList',
            'ServiceList',
            'ServiceImportForm',
            'FirstEncounterGuideView',
            "_t('Initial setup completed')",
            "_t('I work alone')",
        ] as $needle) {
            Assert::stringContains($needle, $source, "OnboardingChecklistView must reference {$needle}");
        }
        Assert::true((bool) preg_match('/public\s+function\s+onMarkTeamReviewed\s*\(/', $source), 'OnboardingChecklistView::onMarkTeamReviewed()');
        Assert::stringContains('->markTeamReviewed(', $source);
    }

    public function testClinicProfileFormEditsThroughTheService(): void
    {
        $source = $this->source(self::PROFILE);

        foreach (['ClinicProfileService', '->load()', '->save(', "_t('Legal name')", "_t('Trade name')", "_t('Unit name')", "_t('Clinic data saved')", 'OnboardingChecklistView'] as $needle) {
            Assert::stringContains($needle, $source, "ClinicProfileForm must reference {$needle}");
        }
        foreach (['onEdit', 'onSave'] as $method) {
            Assert::true((bool) preg_match('/public\s+function\s+' . $method . '\s*\(/', $source), "ClinicProfileForm::{$method}()");
        }
        Assert::stringContains('DomainException', $source, 'validation messages of the service reach the user');
    }

    public function testGuideReadsTheHtmlAndMarksRead(): void
    {
        $source = $this->source(self::GUIDE);

        Assert::stringContains('first-encounter-guide.html', $source);
        Assert::true((bool) preg_match('/public\s+function\s+onMarkRead\s*\(/', $source), 'FirstEncounterGuideView::onMarkRead()');
        Assert::stringContains('->markGuideRead(', $source);
        Assert::stringContains("_t('Mark as read')", $source);
    }

    public function testGuideHtmlHasTheSevenStepsInOrder(): void
    {
        $html = $this->source(self::GUIDE_HTML);

        Assert::stringContains('lang="pt-BR"', $html, 'guide is in pt-BR');
        $start = strpos($html, '<ol');
        Assert::true($start !== false, 'steps are an ordered list');
        Assert::same(7, substr_count($html, '<li'), 'seven steps');

        $offset = (int) $start;
        foreach (['Agenda', 'fila', 'atendimento', 'prescrição', 'conta do atendimento', 'recebimento', 'caixa'] as $step) {
            $position = strpos($html, $step, $offset);
            Assert::true($position !== false, "step '{$step}' after the previous one");
            $offset = (int) $position + strlen($step);
        }
    }

    public function testLabelsLeftAndTouchTargets(): void
    {
        foreach ([self::CHECKLIST, self::PROFILE, self::GUIDE] as $file) {
            $source = $this->source($file);
            Assert::stringContains('cv-touch-target', $source, "{$file} actions need .cv-touch-target");
            Assert::false((bool) preg_match('/text-align\s*:\s*(right|center)/i', $source), "{$file}: labels stay left-aligned");
            Assert::false((bool) preg_match("/TDataGridColumn\([^)]*'(right|center)'/", $source), "{$file}: grid headers stay left-aligned");
        }
        Assert::false((bool) preg_match('/text-align\s*:\s*(right|center)/i', $this->source(self::GUIDE_HTML)), 'guide stays left-aligned');
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . $relative;
        $source = is_file($path) ? file_get_contents($path) : false;
        Assert::true(is_string($source), "{$relative} must exist");

        return (string) $source;
    }
}
