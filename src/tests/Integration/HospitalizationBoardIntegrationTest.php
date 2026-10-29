<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6A, T-16 (correção 1): o flowboard trata sessão sem tenant/unidade e
 * permissão negada com as mensagens das telas irmãs (BedList,
 * HospitalizationView), nunca com o texto cru da exceção ("An authenticated
 * session is required", "denied:boundary").
 *
 * Sem sessão o caminho não toca o banco: roda num processo PHP separado
 * (init.php define _t() global). A negação de RBAC grava audit_log, então é
 * conferida no código-fonte.
 */
final class HospitalizationBoardIntegrationTest
{
    /**
     * @return array<string, mixed>
     */
    private function renderWithoutSession(): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$out = [];'
            . 'ob_start();'
            . 'try { $page = new HospitalizationBoard([]); $page->show(); }'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo json_encode($out);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'HospitalizationBoard subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testWithoutSessionShowsTranslatedActiveUnitMessage(): void
    {
        $result = $this->renderWithoutSession();
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'HospitalizationBoard without session threw: ' . (string) ($result['error'] ?? ''));
        Assert::false(str_contains($html, 'An authenticated session is required'), 'raw MissingTenantContext message must not reach the page');
        Assert::stringContains('sessão autenticada com uma unidade ativa', $html, 'page must show the active-unit session message');
    }

    public function testAuthorizationDeniedAndMissingContextHaveOwnHandlers(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/control/clinic/HospitalizationBoard.php');

        Assert::true(
            (bool) preg_match('/catch \(\\\\?CentralVet\\\\Authorization\\\\Exception\\\\AuthorizationDenied \$e\)\s*\{[^}]*_t\(\'You are not allowed to view the shift board\'\)/', $source),
            'AuthorizationDenied must be caught and shown as the permission-denied message'
        );
        Assert::true(
            (bool) preg_match('/catch \(\\\\?CentralVet\\\\Tenancy\\\\Exception\\\\MissingTenantContext \$e\)\s*\{[^}]*_t\(\'An authenticated session with an active unit is required\'\)/', $source),
            'MissingTenantContext must be caught and shown as the active-unit session message'
        );
    }
}
