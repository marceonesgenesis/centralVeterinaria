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

    /**
     * Roda $body no processo com init.php; $body grava em $out e o
     * resultado volta em JSON. "html" é a saída capturada.
     *
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
            . 'echo json_encode($out);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'HospitalizationView subprocess output: ' . (string) $out);

        return $decoded;
    }

    /**
     * Correção 1: transferir sem leito de destino respondia em silêncio no
     * navegador. A ação é estática (a ficha e o diálogo ficam como estão) e
     * devolve a mensagem de validação traduzida.
     */
    public function testTransferWithoutDestinationBedShowsTranslatedValidationMessage(): void
    {
        $result = $this->runAdianti(
            '$out["static"] = (new ReflectionMethod("HospitalizationView", "onTransfer"))->isStatic();'
            . '$out["expected"] = _t("Select the destination bed");'
            . 'HospitalizationView::onTransfer(["id" => 3, "to_bed_id" => ""]);'
        );

        Assert::true(!isset($result['error']), 'onTransfer threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(($result['static'] ?? false) === true, 'onTransfer must be static to keep the page state');
        Assert::true(str_contains((string) $result['html'], '__adianti_error'), 'onTransfer without bed must show an error dialog');
        Assert::true(
            str_contains((string) $result['html'], (string) $result['expected']),
            'onTransfer without bed must show "' . (string) $result['expected'] . '"'
        );
    }

    /**
     * Correção 1: o resumo da alta (texto clínico) não vai na URL. A
     * confirmação reenvia o formulário por POST para onDischarge.
     */
    public function testDischargeConfirmationPostsTheFormWithoutSummaryInUrl(): void
    {
        $result = $this->runAdianti(
            '$_POST["summary_text"] = "Paciente estavel CLINICO123";'
            . 'HospitalizationView::onAskDischarge(["id" => 3, "summary_text" => "Paciente estavel CLINICO123"]);'
        );

        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'onAskDischarge threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains($html, '__adianti_question'), 'onAskDischarge must ask for confirmation');
        Assert::true(
            str_contains($html, "__adianti_post_data('form_HospitalizationView_discharge'"),
            'confirmation must post the discharge form'
        );
        Assert::true(!str_contains($html, 'CLINICO123'), 'summary text must not appear in the confirmation URL/script');
        Assert::true(!str_contains($html, 'summary_text='), 'summary_text must not go in the query string');
    }

    public function testDischargeIgnoresSummaryFromQueryString(): void
    {
        $result = $this->runAdianti(
            '$out["expected"] = _t("The discharge summary is required");'
            . '$_GET = ["class" => "HospitalizationView", "method" => "onDischarge", "id" => "3", "summary_text" => "via url"];'
            . 'HospitalizationView::onDischarge($_GET);'
        );

        Assert::true(!isset($result['error']), 'onDischarge threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(
            str_contains((string) $result['html'], (string) $result['expected']),
            'onDischarge must take summary_text only from the POST body'
        );
    }

    /**
     * Correção 1: botões de ação da ficha com a classe .cv-touch-target.
     */
    public function testActionButtonsUseTouchTargetClass(): void
    {
        $result = $this->runAdianti(
            '$now = new DateTimeImmutable();'
            . '$h = CentralVet\Domain\Hospitalization::admit(1, 1, 5, 7, 10, 1, 1, "Motivo", null, 5000, $now->modify("-3 hours"));'
            . '$h->assignId(3);'
            . '$bed = CentralVet\Domain\Bed::reconstitute(["id" => 11, "tenant_id" => 1, "system_unit_id" => 1, "code" => "L2", "name" => "Leito 2", "daily_rate_cents" => 5000, "status" => "available", "current_hospitalization_id" => null]);'
            . '$order = CentralVet\Domain\HospitalizationOrder::reconstitute(20, 1, 3, "medication", "Dipirona", null, null, "1 ml", "iv", 8, $now, $now->modify("+1 day"), "active", 1, null);'
            . '$data = ["hospitalization" => $h, "events" => [], "orders" => [$order], "administrations" => [], "patient_name" => "Rex", "beds" => [11 => $bed], "responsible" => null];'
            . '$r = new ReflectionClass("HospitalizationView"); $v = $r->newInstanceWithoutConstructor();'
            . '$r->getMethod("buildSidePanel")->invoke($v, $data)->show();'
            . 'echo "<!--split-->";'
            . '$r->getMethod("buildOrdersPanel")->invoke($v, $data)->show();'
        );

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        [$side, $orders] = explode('<!--split-->', (string) $result['html']) + [1 => ''];

        $pattern = '/class=["\'][^"\']*\bcv-touch-target\b/';
        Assert::true(preg_match_all($pattern, $side) >= 2, 'transfer and discharge buttons must carry the cv-touch-target class');
        Assert::true(preg_match_all($pattern, $orders) >= 2, 'new prescription and suspend links must carry the cv-touch-target class');
    }
}
