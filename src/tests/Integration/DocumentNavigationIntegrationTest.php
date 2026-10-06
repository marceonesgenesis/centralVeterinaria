<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7B, T-18: navegação de documentos — submenu Documents no menu,
 * abas CvNav('documents') e links de entrada para DocumentRequestForm nas
 * telas clínicas (paciente, carteira de vacinação, cirurgia e receita).
 *
 * CvNav só carrega com o Adianti, num processo PHP separado
 * (init.php define _t() global).
 */
final class DocumentNavigationIntegrationTest
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
            . 'try { $out["tabs"] = CvNav::group("documents"); }'
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

    private function clinicSource(string $class): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/app/control/clinic/' . $class . '.php');
    }

    public function testMenuParsesAsXml(): void
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($this->menu());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        Assert::true($doc !== false, 'menu.xml must be well-formed XML');
    }

    public function testDocumentsItemIsSubmenuRightAfterCrm(): void
    {
        $doc = simplexml_load_string($this->menu());
        Assert::true($doc !== false, 'menu.xml must be well-formed XML');

        $items = [];
        foreach ($doc->menuitem as $item) {
            $items[] = $item;
        }

        $crmIndex = null;
        foreach ($items as $i => $item) {
            if ((string) $item['label'] === '_t{CRM / Communication}') {
                $crmIndex = $i;
            }
        }
        Assert::true($crmIndex !== null, 'menu.xml must keep the CRM / Communication item');

        $documents = $items[$crmIndex + 1] ?? null;
        Assert::true($documents !== null, 'menu.xml must have an item after CRM / Communication');
        Assert::same('_t{Documents}', (string) $documents['label']);
        Assert::same('fas:file-pdf fa-fw', (string) $documents->icon);
        Assert::true(isset($documents->menu), 'Documents must be a submenu');

        $children = [];
        foreach ($documents->menu->menuitem as $child) {
            $children[(string) $child['label']] = (string) $child->action;
        }

        Assert::same(
            ['_t{Documents}' => 'DocumentList', '_t{Document templates}' => 'DocumentTemplateList'],
            $children
        );
    }

    public function testCvNavDocumentsGroupLinksListAndTemplates(): void
    {
        $result = $this->runAdianti();

        Assert::true(isset($result['tabs']), 'CvNav::group("documents"): ' . (string) ($result['tabs_error'] ?? 'missing'));
        Assert::same(['documents', 'templates'], array_keys($result['tabs']));
        Assert::same('index.php?class=DocumentList', $result['tabs']['documents']['href'] ?? null);
        Assert::same('index.php?class=DocumentTemplateList', $result['tabs']['templates']['href'] ?? null);
    }

    public function testPatientFormOffersDocumentsAndMedicalCertificate(): void
    {
        $source = $this->clinicSource('PatientForm');

        Assert::true(strpos($source, 'class=DocumentList&patient_id=') !== false, 'PatientForm must link DocumentList&patient_id=');
        Assert::true(
            strpos($source, 'class=DocumentRequestForm&kind=medical_certificate&source_id=') !== false,
            'PatientForm must link DocumentRequestForm&kind=medical_certificate'
        );
    }

    public function testVaccinationCardViewOffersVaccinationCardPdf(): void
    {
        Assert::true(
            strpos($this->clinicSource('VaccinationCardView'), 'class=DocumentRequestForm&kind=vaccination_card&source_id=') !== false,
            'VaccinationCardView must link DocumentRequestForm&kind=vaccination_card'
        );
    }

    public function testSurgeryViewOffersConsentPdf(): void
    {
        Assert::true(
            strpos($this->clinicSource('SurgeryView'), 'class=DocumentRequestForm&kind=surgery_consent&source_id=') !== false,
            'SurgeryView must link DocumentRequestForm&kind=surgery_consent'
        );
    }

    public function testPrescriptionFormOffersArchivePdfAndKeepsSyncPdf(): void
    {
        $source = $this->clinicSource('PrescriptionForm');

        Assert::true(
            strpos($source, 'class=DocumentRequestForm&kind=prescription&source_id=') !== false,
            'PrescriptionForm must link DocumentRequestForm&kind=prescription'
        );
        Assert::true(
            strpos($source, 'class=PrescriptionForm&method=onGeneratePdf') !== false,
            'PrescriptionForm must keep the synchronous PDF link'
        );
    }
}
