<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6B, T-14: ficha da cirurgia. Sem `id`, estado vazio antes de
 * resolver o tenant; o motivo do cancelamento (texto clínico) só vem do
 * corpo do POST e nunca vai no script da confirmação; as ações de
 * autorização casam com AuthorizationRequest::ACTION_PATTERN.
 *
 * O controller só carrega com o Adianti, num processo PHP separado
 * (init.php define _t() global).
 */
final class SurgeryViewIntegrationTest
{
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

        Assert::true(is_array($decoded), 'SurgeryView subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testWithoutIdShowsEmptyStateWithoutThrowing(): void
    {
        $result = $this->runAdianti('$page = new SurgeryView([]); $page->show();');

        Assert::true(!isset($result['error']), 'SurgeryView without id threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(
            str_contains((string) $result['html'], 'cv-state--empty'),
            'SurgeryView without id must render cv-state--empty'
        );
    }

    public function testCancelIgnoresReasonFromQueryString(): void
    {
        $result = $this->runAdianti(
            '$out["expected"] = _t("The cancellation reason is required");'
            . '$_POST = [];'
            . '$_GET = ["class" => "SurgeryView", "method" => "onCancel", "id" => "3", "cancellation_reason_text" => "via url"];'
            . '$m = new ReflectionMethod("SurgeryView", "postedReason"); $m->setAccessible(true);'
            . '$out["posted"] = $m->invoke(null);'
            . 'SurgeryView::onCancel($_GET);'
        );

        Assert::true(!isset($result['error']), 'onCancel threw: ' . (string) ($result['error'] ?? ''));
        Assert::same('', $result['posted'] ?? null, 'postedReason() must ignore the query string');
        Assert::true(
            str_contains((string) $result['html'], (string) $result['expected']),
            'onCancel must take cancellation_reason_text only from the POST body'
        );
    }

    public function testCancelConfirmationPostsTheFormWithoutReasonInScript(): void
    {
        $result = $this->runAdianti(
            '$_POST["cancellation_reason_text"] = "Tutor desistiu CLINICO123";'
            . 'SurgeryView::onAskCancel(["id" => 3, "cancellation_reason_text" => "Tutor desistiu CLINICO123"]);'
        );

        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'onAskCancel threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains($html, '__adianti_question'), 'onAskCancel must ask for confirmation');
        Assert::true(
            str_contains($html, "__adianti_post_data('form_SurgeryView_cancel'"),
            'confirmation must post the cancellation form'
        );
        Assert::true(!str_contains($html, 'CLINICO123'), 'reason text must not appear in the confirmation script');
        Assert::true(!str_contains($html, 'cancellation_reason_text='), 'reason must not go in the query string');
    }

    public function testAuthorizationActionsMatchThePattern(): void
    {
        $result = $this->runAdianti(
            '$r = new ReflectionClass("SurgeryView"); $actions = [];'
            . 'foreach ($r->getReflectionConstants() as $c) { if (str_starts_with($c->getName(), "ACTION_")) { $actions[] = $c->getValue(); } }'
            . '$out["actions"] = $actions;'
            . '$out["pattern"] = CentralVet\Authorization\AuthorizationRequest::ACTION_PATTERN;'
        );

        Assert::true(!isset($result['error']), 'reflection threw: ' . (string) ($result['error'] ?? ''));

        $actions = (array) ($result['actions'] ?? []);

        foreach ([
            'SurgeryView::onReload',
            'SurgeryView::onStartPreOp',
            'SurgeryView::onStart',
            'SurgeryView::onCancel',
            'SurgeryView::onComplete',
            'SurgeryView::onScheduleFollowUp',
        ] as $expected) {
            Assert::true(in_array($expected, $actions, true), 'missing action constant ' . $expected);
        }

        foreach ($actions as $action) {
            Assert::true(
                preg_match((string) $result['pattern'], (string) $action) === 1,
                'action ' . (string) $action . ' must match AuthorizationRequest::ACTION_PATTERN'
            );
        }
    }

    /**
     * Botões de status da ficha com a classe .cv-touch-target: agendada
     * mostra "Iniciar pré-op" e cancelamento; concluída mostra "Internar no
     * pós-operatório" apontando para a admissão da 6A.
     */
    public function testStatusActionsUseTouchTargetAndAdmissionLink(): void
    {
        $result = $this->runAdianti(
            '$start = new DateTimeImmutable("2026-10-06 09:00");'
            . '$s = CentralVet\Domain\Surgery::schedule(1, 1, 7, 10, 2, 4, "Castração", 50000, 5, 5, $start, $start->modify("+1 hour"), null);'
            . '$s->assignId(3);'
            . '$data = ["surgery" => $s, "team" => [], "events" => [], "phases" => [], "materials" => [], "patient_name" => "Rex", "room_label" => "S1", "surgeon" => null, "services" => []];'
            . '$r = new ReflectionClass("SurgeryView"); $v = $r->newInstanceWithoutConstructor();'
            . '$m = $r->getMethod("buildActionsPanel"); $m->setAccessible(true);'
            . '$m->invoke($v, $data)->show();'
            . 'echo "<!--split-->";'
            . '$s->startPreOp(); $s->recordConsent("Tutor", "Texto", 5, $start); $s->start($start); $s->complete($start->modify("+1 hour"), 5);'
            . '$m->invoke($v, $data)->show();'
        );

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        [$scheduled, $completed] = explode('<!--split-->', (string) $result['html']) + [1 => ''];

        $pattern = '/class=["\'][^"\']*\bcv-touch-target\b/';
        Assert::true(str_contains($scheduled, 'onStartPreOp'), 'scheduled surgery must offer start pre-op');
        Assert::true(preg_match_all($pattern, $scheduled) >= 2, 'start pre-op and cancel buttons must carry cv-touch-target');
        Assert::true(
            str_contains($completed, 'class=HospitalizationAdmissionForm&amp;encounter_id=10&amp;patient_id=7')
                || str_contains($completed, 'class=HospitalizationAdmissionForm&encounter_id=10&patient_id=7'),
            'completed surgery must link to the post-operative admission'
        );
        Assert::true(preg_match_all($pattern, $completed) >= 1, 'completed actions must carry cv-touch-target');
    }
}
