<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\MutationRequestPolicy;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 5, T-17: telas de suporte e de exportação dos dados (LGPD). Lê os
 * fontes (as telas dependem do Adianti e do banco, que a suíte unitária não
 * sobe): canais do suporte vindos de PilotSettings, links legais de
 * LegalDocuments, download do ZIP de TenantDataExporter com auditoria,
 * cabeçalhos de anexo privado e remoção do temporário, rótulos à esquerda
 * e ações com .cv-touch-target.
 */
final class SupportAndExportScreensWiringTest
{
    private const SUPPORT = '/app/control/admin/SupportView.php';
    private const EXPORT = '/app/control/admin/ClinicDataExportView.php';

    public function testSupportViewShowsConfiguredChannelsAndLegalLinks(): void
    {
        $source = $this->source(self::SUPPORT);

        foreach ([
            'PilotSettings',
            'supportEmail()',
            'supportWhatsapp()',
            'supportHours()',
            'https://wa.me/',
            'mailto:',
            'LegalDocuments::TERMS_URL',
            'LegalDocuments::PRIVACY_URL',
            '#cancelamento',
            "_t('Support channel not configured')",
            "_t('Support e-mail')",
            "_t('Service hours')",
            "_t('Cancellation policy')",
        ] as $needle) {
            Assert::stringContains($needle, $source, "SupportView must use {$needle}");
        }
    }

    public function testExportViewDownloadsAuditedZipAndRemovesTheTempFile(): void
    {
        $source = $this->source(self::EXPORT);

        Assert::true((bool) preg_match('/public\s+(static\s+)?function\s+onDownload\s*\(/', $source), 'ClinicDataExportView must declare public onDownload()');
        foreach ([
            'TenantDataExporter',
            '->export(',
            'AuditEvent',
            'clinic_data_exported',
            'application/zip',
            'Content-Disposition: attachment',
            'dados-clinica-',
            'Cache-Control: private, no-store',
            'unlink(',
            "_t('Export clinic data')",
            "_t('The file contains the clinic records in CSV format, one file per table')",
        ] as $needle) {
            Assert::stringContains($needle, $source, "ClinicDataExportView must use {$needle}");
        }
    }

    public function testDownloadIsAReadMethod(): void
    {
        Assert::true(MutationRequestPolicy::isReadOnly('ClinicDataExportView', 'onDownload'), 'onDownload only reads (GET download)');
    }

    public function testLabelsLeftAlignedAndTouchTargets(): void
    {
        foreach ([self::SUPPORT, self::EXPORT] as $file) {
            $source = $this->source($file);
            Assert::stringContains('cv-touch-target', $source, "{$file} actions need .cv-touch-target");
            Assert::false((bool) preg_match('/text-align\s*:\s*(right|center)/i', $source), "{$file}: labels stay left-aligned");
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
