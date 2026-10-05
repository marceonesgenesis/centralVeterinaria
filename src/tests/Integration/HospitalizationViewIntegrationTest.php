<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6A, T-14: ficha da internação. Sem `id`, a página mostra o estado
 * vazio (`cv-state--empty`) antes de resolver o tenant — sem sessão, sem
 * banco e sem exceção.
 *
 * O controller só carrega com o Adianti, num processo PHP separado
 * (init.php define _t() global).
 */
final class HospitalizationViewIntegrationTest
{
    /**
     * @return array<string, mixed>
     */
    private function renderWithoutId(): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$out = [];'
            . 'ob_start();'
            . 'try { $page = new HospitalizationView([]); $page->show(); }'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo json_encode($out);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'HospitalizationView subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testWithoutIdShowsEmptyStateWithoutThrowing(): void
    {
        $result = $this->renderWithoutId();

        Assert::true(!isset($result['error']), 'HospitalizationView without id threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(
            str_contains((string) $result['html'], 'cv-state--empty'),
            'HospitalizationView without id must render cv-state--empty'
        );
    }
}
