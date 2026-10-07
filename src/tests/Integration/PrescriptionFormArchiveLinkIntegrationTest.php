<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7B, T-22: os links "Gerar PDF" e "Arquivar PDF" da PrescriptionForm
 * (receita salva) são alvos de toque de 44 px: levam a classe
 * .cv-touch-target (CLAUDE.md, design system).
 *
 * O controller só carrega com o Adianti, num processo PHP separado
 * (init.php define _t() global). Sem patient_id a tela não consulta o
 * resumo clínico; nenhum dado é gravado.
 */
final class PrescriptionFormArchiveLinkIntegrationTest
{
    private const MARKER = '@@RXARCHIVE@@';

    /**
     * @return array<string, mixed>
     */
    private function runAdianti(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$out = [];'
            . 'ob_start();'
            . 'try { ' . $body . ' }'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo "' . self::MARKER . '", json_encode($out);';

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'PrescriptionForm subprocess output: ' . $out);

        return $decoded;
    }

    public function testPdfLinksOfSavedPrescriptionAreTouchTargets(): void
    {
        $result = $this->runAdianti(
            '$page = new PrescriptionForm(["encounter_id" => 5, "prescription_id" => 9]);'
            . '$page->show();'
        );

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        $html = (string) $result['html'];

        foreach (['method=onGeneratePdf', 'class=DocumentRequestForm&amp;kind=prescription', 'class=DocumentRequestForm&kind=prescription'] as $needle) {
            if (!str_contains($html, $needle)) {
                continue;
            }

            Assert::true(
                preg_match('/<a\b[^>]*' . preg_quote($needle, '/') . '[^>]*>/', $html, $m) === 1,
                "link {$needle} must be an <a>"
            );
            Assert::true(
                preg_match('/class=["\'][^"\']*\bcv-touch-target\b/', $m[0]) === 1,
                "link {$needle} must carry cv-touch-target, got: {$m[0]}"
            );
        }

        Assert::true(
            str_contains($html, 'method=onGeneratePdf')
                && (str_contains($html, 'class=DocumentRequestForm&kind=prescription') || str_contains($html, 'class=DocumentRequestForm&amp;kind=prescription')),
            'saved prescription must render the Generate PDF and Archive PDF links'
        );
    }
}
