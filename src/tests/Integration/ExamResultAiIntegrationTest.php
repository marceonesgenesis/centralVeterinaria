<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;
use CentralVet\Tests\Support\TestDatabase;

/**
 * Fase 8A, T-15: resumo de exame por IA em ExamResultForm, sempre revisado
 * por um humano, sem tocar no resultado original.
 *
 * O controller Adianti roda num processo PHP separado (padrão de
 * BedFormIntegrationTest) com `AI_PROVIDER=stub` e `DB_DATABASE` apontando
 * para o banco de teste. Como o subprocesso usa a própria conexão
 * (TTransaction), as linhas de fixture precisam estar commitadas: ficam num
 * tenant descartável `F8A teste` criado em setUp() e apagado por inteiro em
 * tearDown() (audit_log, ai_interaction, flag, termo, exame, atendimento,
 * paciente, tutor e tenant), sem resíduo no banco de teste.
 */
final class ExamResultAiIntegrationTest
{
    private const MARKER = '@@EXAMRESULTAI@@';
    private const ORIGINAL = "F8A teste Hemograma: Hb 12,5 g/dL\nLeucócitos 9.800/µL  \n";

    private ?\PDO $pdo = null;
    private string $dbName = '';
    private int $userId = 0;
    private int $unitId = 0;
    private int $tenantId = 0;

    /** @var array<string, int> */
    private array $ids = [];

    /** @var array<string, int> exam_request.id of each fixture */
    private array $requests = [];

    public function setUp(): void
    {
        $this->dbName = TestDatabase::resolveName(getenv());

        try {
            $this->pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    getenv('DB_HOST') ?: '127.0.0.1',
                    getenv('DB_PORT') ?: '3306',
                    $this->dbName,
                ),
                (string) (getenv('DB_USERNAME') ?: 'centralvet'),
                (string) (getenv('DB_PASSWORD') ?: ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $e) {
            throw new SkippedTestException('Real MySQL service unreachable: ' . $e->getMessage());
        }

        if (!$this->pdo->query("SHOW TABLES LIKE 'ai_interaction'")->fetchColumn()) {
            throw new SkippedTestException('Migration 0014 not applied to the test database');
        }

        if (!is_file(dirname(__DIR__, 2) . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $this->userId = (int) $this->pdo->query('SELECT id FROM system_users ORDER BY id LIMIT 1')->fetchColumn();
        $this->unitId = (int) $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 1')->fetchColumn();
        Assert::true($this->userId > 0 && $this->unitId > 0, 'Fixture requires a system_users and a system_unit row');

        // Restos de uma execução interrompida (slug próprio deste teste).
        foreach ($this->pdo->query("SELECT id FROM tenant WHERE slug LIKE 'f8a-exam-ai-%'")->fetchAll(\PDO::FETCH_COLUMN) as $stale) {
            $this->deleteTenant((int) $stale);
        }

        try {
            $this->seed();
        } catch (\Throwable $e) {
            $this->tearDown();

            throw $e;
        }
    }

    public function tearDown(): void
    {
        if ($this->pdo === null || $this->tenantId <= 0) {
            return;
        }

        $this->deleteTenant($this->tenantId);
        $this->tenantId = 0;
    }

    private function deleteTenant(int $tenantId): void
    {
        foreach ([
            'audit_log', 'ai_interaction', 'ai_tenant_setting', 'feature_flag', 'exam_result', 'exam_request',
            'exam_catalog_item', 'encounter', 'patient', 'tutor', 'system_unit',
        ] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE tenant_id = ?")->execute([$tenantId]);
        }

        $this->pdo->prepare('DELETE FROM tenant WHERE id = ?')->execute([$tenantId]);
    }

    public function testEditedSummaryKeepsTheOriginalResultAndShowsOnReload(): void
    {
        $before = $this->resultTextHex($this->ids['result']);

        $summary = $this->runAdianti(sprintf(
            '$param = ["class" => "ExamResultForm", "method" => "onAiExamSummary", "exam_result_id" => %d];'
            . '$page = new ExamResultForm($param); $page->onAiExamSummary($param); $page->show();',
            $this->ids['result'],
        ));

        Assert::stringContains('cv-ai-panel', $summary, 'summary shows the AI panel');
        Assert::stringContains('[stub] exam_summary', $summary, 'stub suggestion is shown for review');
        Assert::stringContains('onAiDecide', $summary, 'review actions post to onAiDecide');

        $interactionId = $this->latestInteractionId();
        Assert::true($interactionId > 0, 'summary records an ai_interaction');

        $reload = $this->runAdianti(sprintf(
            '$param = ["class" => "ExamResultForm", "method" => "onAiDecide", "exam_result_id" => %1$d, "interaction_id" => %2$d,'
            . ' "decision" => "edited", "ai_final_text" => "F8A teste resumo editado <b>x</b>"];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);'
            . 'ob_end_clean(); ob_start(); (new ExamResultForm(["exam_result_id" => %1$d]))->show();',
            $this->ids['result'],
            $interactionId,
        ));

        Assert::same($before, $this->resultTextHex($this->ids['result']), 'exam_result.structured_result_text is byte-for-byte unchanged');
        Assert::same('edited', $this->interactionStatus($interactionId));
        Assert::stringContains('cv-ai-origin', $reload, 'reloaded screen shows the AI origin seal');
        Assert::stringContains('F8A teste resumo editado &lt;b&gt;x&lt;/b&gt;', $reload, 'edited text is shown escaped');
        Assert::false(str_contains($reload, 'resumo editado <b>'), 'edited text is never rendered as raw HTML');
    }

    public function testResultWithoutStructuredTextHasNoSummaryButton(): void
    {
        $html = $this->runAdianti(sprintf(
            '(new ExamResultForm(["exam_result_id" => %d]))->show();',
            $this->ids['result_without_text'],
        ));

        Assert::false(str_contains($html, 'onAiExamSummary'), 'no summary button without structured text');
        Assert::stringContains('onAiExamSummary', $this->runAdianti(sprintf(
            '(new ExamResultForm(["exam_result_id" => %d]))->show();',
            $this->ids['result'],
        )), 'result with structured text shows the summary button');
    }

    public function testDiscardedSummaryIsNotShown(): void
    {
        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param);',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();

        $reload = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %1$d, "interaction_id" => %2$d, "decision" => "discarded", "ai_final_text" => ""];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);'
            . 'ob_end_clean(); ob_start(); (new ExamResultForm(["exam_result_id" => %1$d]))->show();',
            $this->ids['result'],
            $interactionId,
        ));

        Assert::same('discarded', $this->interactionStatus($interactionId));
        Assert::false(str_contains($reload, 'cv-ai-origin'), 'discarded summary has no origin seal');
        Assert::false(str_contains($reload, '[stub]'), 'discarded suggestion text is not shown');
    }

    public function testInteractionOfAnotherExamIsNotFound(): void
    {
        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param);',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();

        $html = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => "accepted", "ai_final_text" => "F8A teste outro exame"];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);',
            $this->ids['other_result'],
            $interactionId,
        ));

        Assert::stringContains('Sugestão de IA não encontrada.', $html);
        Assert::same('pending_review', $this->interactionStatus($interactionId), 'interaction of another exam is not decided');
    }

    /**
     * Correção pós-revisão final: falha do provedor (timeout do stub) fica
     * registrada e commitada — linha `failed` com error_code e auditoria
     * ai.suggestion.failed —, a tela avisa e o resultado não muda.
     */
    public function testProviderFailureIsCommittedAndAudited(): void
    {
        $before = $this->resultTextHex($this->ids['result']);

        $html = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param); $page->show();',
            $this->ids['result'],
        ), 'timeout');

        $statement = $this->pdo->prepare("SELECT id, status, error_code FROM ai_interaction WHERE tenant_id = ? AND purpose = 'exam_summary'");
        $statement->execute([$this->tenantId]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        Assert::count(1, $rows, 'the failed interaction is committed');
        Assert::same('failed', $rows[0]['status']);
        Assert::same('ai_timeout', $rows[0]['error_code']);

        $audit = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = 'ai.suggestion.failed' AND entity_id = ?");
        $audit->execute([$this->tenantId, (int) $rows[0]['id']]);
        Assert::same(1, (int) $audit->fetchColumn(), 'ai.suggestion.failed is audited');

        Assert::same($before, $this->resultTextHex($this->ids['result']), 'exam_result unchanged');
        Assert::false(str_contains($html, 'EXCEPTION '), 'failure is handled on screen: ' . substr($html, 0, 300));
        Assert::stringContains('cv-ai-panel', $html, 'screen keeps rendering the AI panel');
    }

    public function testDisabledAiHidesThePanel(): void
    {
        $this->pdo->prepare('UPDATE feature_flag SET enabled = 0 WHERE tenant_id = ?')->execute([$this->tenantId]);

        $html = $this->runAdianti(sprintf(
            '(new ExamResultForm(["exam_result_id" => %d]))->show();',
            $this->ids['result'],
        ));

        Assert::false(str_contains($html, 'cv-ai-panel'), 'AI panel hidden when AI is off');
        Assert::false(str_contains($html, 'onAiExamSummary'), 'summary button hidden when AI is off');
    }

    /**
     * T-08: "Aceitar" com o texto alterado no textarea grava `edited` com o
     * texto postado (normalizado), nunca `accepted` com a sugestão original.
     */
    public function testAcceptWithEditedTextIsRecordedAsEdited(): void
    {
        $before = $this->resultTextHex($this->ids['result']);
        $summary = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param); $page->show();',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();
        Assert::true($interactionId > 0, 'summary records an ai_interaction');

        $sha = $this->postedSha($summary);
        Assert::same(hash('sha256', trim(str_replace(["\r\n", "\r"], "\n", $this->suggestionText($interactionId)))), $sha, 'hidden SHA is the normalized suggestion hash');

        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => "accepted", "ai_suggestion_sha256" => %s,'
            . ' "ai_final_text" => "F8A teste resumo do profissional\r\nlinha 2  \r\n"];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);',
            $this->ids['result'],
            $interactionId,
            var_export($sha, true),
        ));

        Assert::same('edited', $this->interactionStatus($interactionId), 'accept with changed text becomes edited');
        Assert::same("F8A teste resumo do profissional\nlinha 2", $this->interactionFinalText($interactionId), 'final_text is the posted text, normalized');
        Assert::same($before, $this->resultTextHex($this->ids['result']), 'exam_result unchanged');
    }

    /** T-08: "Aceitar" sem mexer no texto (só CRLF/espaços) continua `accepted`. */
    public function testAcceptWithUntouchedTextStaysAccepted(): void
    {
        $summary = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param); $page->show();',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();
        $suggestion = $this->suggestionText($interactionId);

        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => "accepted", "ai_suggestion_sha256" => %s, "ai_final_text" => %s];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);',
            $this->ids['result'],
            $interactionId,
            var_export($this->postedSha($summary), true),
            var_export(str_replace("\n", "\r\n", $suggestion) . "\r\n", true),
        ));

        Assert::same('accepted', $this->interactionStatus($interactionId));
    }

    /**
     * T-20: "Aceitar" sem ai_suggestion_sha256 e com o texto igual à sugestão
     * fica `accepted`, como na EncounterView (revoga o ruling T-08 · onda 2).
     */
    public function testAcceptWithoutShaStaysAccepted(): void
    {
        $before = $this->resultTextHex($this->ids['result']);
        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param);',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();

        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => "accepted", "ai_final_text" => %s];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);',
            $this->ids['result'],
            $interactionId,
            var_export($this->suggestionText($interactionId), true),
        ));

        Assert::same('accepted', $this->interactionStatus($interactionId), 'accept without SHA stays accepted');
        Assert::same($before, $this->resultTextHex($this->ids['result']), 'exam_result unchanged');
    }

    /**
     * T-25: "Aceitar" sem ai_suggestion_sha256 compara o texto postado com a
     * sugestão gravada: igual (só CRLF/espaços nas pontas) fica `accepted`.
     */
    public function testAcceptWithoutShaAndSameTextAsStoredSuggestionStaysAccepted(): void
    {
        $before = $this->resultTextHex($this->ids['result']);
        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param);',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();

        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => "accepted", "ai_final_text" => %s];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);',
            $this->ids['result'],
            $interactionId,
            var_export(str_replace("\n", "\r\n", $this->suggestionText($interactionId)) . "\r\n", true),
        ));

        Assert::same('accepted', $this->interactionStatus($interactionId), 'accept without SHA and the stored suggestion stays accepted');
        Assert::same($before, $this->resultTextHex($this->ids['result']), 'exam_result unchanged');
    }

    /**
     * T-25: "Aceitar" sem ai_suggestion_sha256 com texto diferente da sugestão
     * gravada vira `edited` com o texto postado; a edição nunca se perde.
     */
    public function testAcceptWithoutShaAndEditedTextIsRecordedAsEdited(): void
    {
        $before = $this->resultTextHex($this->ids['result']);
        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param);',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();

        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => "accepted",'
            . ' "ai_final_text" => "F8A teste resumo editado sem hash\r\nlinha 2  \r\n"];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);',
            $this->ids['result'],
            $interactionId,
        ));

        Assert::same('edited', $this->interactionStatus($interactionId), 'accept without SHA and changed text becomes edited');
        Assert::same("F8A teste resumo editado sem hash\nlinha 2", $this->interactionFinalText($interactionId), 'final_text is the posted text, normalized');
        Assert::same($before, $this->resultTextHex($this->ids['result']), 'exam_result unchanged');
    }

    /** T-20: "Aceitar" com o texto apagado é recusado sem decidir nem auditar. */
    public function testAcceptWithEmptyTextIsRefused(): void
    {
        $before = $this->resultTextHex($this->ids['result']);
        $summary = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param); $page->show();',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();
        $auditBefore = $this->auditCount($interactionId);

        $html = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => "accepted", "ai_suggestion_sha256" => %s, "ai_final_text" => " \r\n "];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);',
            $this->ids['result'],
            $interactionId,
            var_export($this->postedSha($summary), true),
        ));

        Assert::stringContains('O texto editado não pode ficar vazio.', $html);
        Assert::same('pending_review', $this->interactionStatus($interactionId), 'empty text does not decide');
        Assert::same(null, $this->interactionFinalText($interactionId), 'final_text stays null');
        Assert::same($auditBefore, $this->auditCount($interactionId), 'empty text writes no audit event');
        Assert::same($before, $this->resultTextHex($this->ids['result']), 'exam_result unchanged');
    }

    /** T-29 correção 1: recusa (texto vazio, decisão inválida) mantém a sugestão pendente na tela com o texto. */
    public function testRefusedDecisionKeepsThePendingSuggestionOnScreen(): void
    {
        $summary = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param); $page->show();',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();
        $sha = $this->postedSha($summary);

        foreach ([
            ['accepted', " \r\n ", trim(str_replace(["\r\n", "\r"], "\n", $this->suggestionText($interactionId)))],
            ['hacked', 'F8A texto editado antes do erro', 'F8A texto editado antes do erro'],
        ] as [$decision, $posted, $shown])
        {
            $html = $this->runAdianti(sprintf(
                '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => %s, "ai_suggestion_sha256" => %s, "ai_final_text" => %s];'
                . '$page = new ExamResultForm($param); $page->onAiDecide($param); $page->show();',
                $this->ids['result'],
                $interactionId,
                var_export($decision, true),
                var_export($sha, true),
                var_export($posted, true),
            ));

            Assert::same('pending_review', $this->interactionStatus($interactionId), $decision . ': nothing is decided');
            Assert::true(str_contains($html, '__adianti_warning'), $decision . ': refusal shows a warning');
            Assert::true(
                (bool) preg_match('/name="interaction_id"[^>]*value="' . $interactionId . '"/', $html),
                $decision . ': pending suggestion stays under review',
            );
            Assert::same($sha, $this->postedSha($html), $decision . ': SHA of the shown suggestion is kept');
            Assert::stringContains(htmlspecialchars($shown, ENT_QUOTES), $html);
        }
    }

    /** T-08: decisão fora de accepted/edited/discarded não decide nem audita. */
    public function testInvalidDecisionChangesNothing(): void
    {
        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param);',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();
        $auditBefore = $this->auditCount($interactionId);

        $html = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => "hacked", "ai_final_text" => "F8A teste x"];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);',
            $this->ids['result'],
            $interactionId,
        ));

        Assert::stringContains('Ação inválida', $html);
        Assert::same('pending_review', $this->interactionStatus($interactionId), 'invalid decision does not change the row');
        Assert::same($auditBefore, $this->auditCount($interactionId), 'invalid decision writes no audit event');
    }

    /** T-08: o resumo só é gerado por POST; um GET (link, prefetch) não chama o provedor. */
    public function testSummaryOverGetCreatesNoInteraction(): void
    {
        $html = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param);',
            $this->ids['result'],
        ), 'ok', 'GET');

        Assert::stringContains('Ação inválida', $html);
        Assert::same(0, $this->latestInteractionId(), 'GET creates no ai_interaction');
    }

    /** T-08: o botão "Resumir exame" é um TButton num TForm (POST), não um link GET. */
    public function testSummaryButtonPostsAForm(): void
    {
        $html = $this->runAdianti(sprintf(
            '(new ExamResultForm(["exam_result_id" => %d]))->show();',
            $this->ids['result'],
        ));

        Assert::stringContains('name="ai_summarize"', $html);
        Assert::stringContains('form_ExamResultAiSummary_' . $this->ids['result'], $html);
        Assert::false((bool) preg_match('/href="[^"]*method=onAiExamSummary/', $html), 'no GET link to onAiExamSummary');
    }

    /** T-08: interação de outra unidade do mesmo tenant responde como inexistente. */
    public function testInteractionOfAnotherUnitIsNotFound(): void
    {
        $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d]; $page = new ExamResultForm($param); $page->onAiExamSummary($param);',
            $this->ids['result'],
        ));
        $interactionId = $this->latestInteractionId();
        $this->pdo->prepare('UPDATE ai_interaction SET system_unit_id = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$this->otherUnitId(), $interactionId, $this->tenantId]);

        $html = $this->runAdianti(sprintf(
            '$param = ["exam_result_id" => %d, "interaction_id" => %d, "decision" => "edited", "ai_final_text" => "F8A teste outra unidade"];'
            . '$page = new ExamResultForm($param); $page->onAiDecide($param);',
            $this->ids['result'],
            $interactionId,
        ));

        Assert::stringContains('Sugestão de IA não encontrada.', $html);
        Assert::same('pending_review', $this->interactionStatus($interactionId), 'interaction of another unit is not decided');
    }

    /** T-08: caminhos legados por exam_request_id (sem resultado → lançamento; com resultado → só leitura). */
    public function testLegacyExamRequestPaths(): void
    {
        $record = $this->runAdianti(sprintf(
            '(new ExamResultForm(["exam_request_id" => %d]))->show();',
            $this->requests['without_result'],
        ));
        Assert::stringContains('Registrar resultado', $record, 'request without result opens the record form');
        Assert::false(str_contains($record, 'cv-exam-result-text'), 'record form has no read-only result');

        $readOnly = $this->runAdianti(sprintf(
            '(new ExamResultForm(["exam_request_id" => %d]))->show();',
            $this->requests['result'],
        ));
        Assert::stringContains('cv-exam-result-text', $readOnly, 'request with result opens the read-only view');
        Assert::false(str_contains($readOnly, 'Registrar resultado'), 'read-only view has no record action');
    }

    /** T-08: controle positivo de testDisabledAiHidesThePanel. */
    public function testEnabledAiShowsThePanel(): void
    {
        $html = $this->runAdianti(sprintf(
            '(new ExamResultForm(["exam_result_id" => %d]))->show();',
            $this->ids['result'],
        ));

        Assert::stringContains('cv-ai-panel', $html, 'AI panel shown when AI is on');
        Assert::stringContains('onAiExamSummary', $html, 'summary button shown when AI is on');
    }

    /**
     * Runs $body after init.php with an authenticated session of the fixture
     * tenant/unit; returns everything $body printed.
     */
    private function runAdianti(string $body, string $stubBehavior = 'ok', string $requestMethod = 'POST'): string
    {
        $src = dirname(__DIR__, 2);
        $session = [
            'logged' => true,
            'userid' => $this->userId,
            'tenantid' => $this->tenantId,
            'userunitid' => $this->unitId,
            'login' => 'f8a.teste',
            'programs' => ['ExamResultForm' => true],
        ];

        $code = 'putenv(' . var_export('DB_DATABASE=' . $this->dbName, true) . ');'
            . 'putenv("AI_PROVIDER=stub"); putenv(' . var_export('AI_STUB_BEHAVIOR=' . $stubBehavior, true) . ');'
            . 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$_SERVER["REQUEST_METHOD"] = ' . var_export($requestMethod, true) . ';'
            . 'foreach (' . var_export($session, true) . ' as $k => $v) { TSession::setValue($k, $v); }'
            . 'ob_start();'
            . 'try {' . $body . '} catch (Throwable $e) { echo "EXCEPTION ", get_class($e), ": ", $e->getMessage(); }'
            . '$html = ob_get_clean();'
            . 'echo "' . self::MARKER . '", json_encode(["html" => $html]);';

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded) && is_string($decoded['html'] ?? null), 'ExamResultForm subprocess output: ' . $out);

        return $decoded['html'];
    }

    private function seed(): void
    {
        $slug = 'f8a-exam-ai-' . bin2hex(random_bytes(4));
        $this->tenantId = $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'F8A teste tenant (' . $slug . ')',
            'status' => 'active',
        ]);

        $tutor = $this->insert('tutor', [
            'tenant_id' => $this->tenantId,
            'public_id' => $this->uuid(),
            'full_name' => 'F8A teste Tutor',
            'phone' => '85999990013',
            'email' => 'f8a.teste@example.invalid',
        ]);
        $patient = $this->insert('patient', [
            'tenant_id' => $this->tenantId,
            'tutor_id' => $tutor,
            'name' => 'F8A teste Rex',
            'species' => 'canino',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2024-03-15',
        ]);
        $catalog = $this->insert('exam_catalog_item', [
            'tenant_id' => $this->tenantId,
            'name' => 'F8A teste Hemograma',
            'price_cents' => 9000,
        ]);
        $encounter = $this->insert('encounter', [
            'tenant_id' => $this->tenantId,
            'system_unit_id' => $this->unitId,
            'patient_id' => $patient,
            'professional_system_user_id' => $this->userId,
            'status' => 'in_progress',
            'started_at' => '2031-09-07 09:00:00',
        ]);

        foreach ([
            'result' => ['structured_result_text' => self::ORIGINAL, 'stored_object_key' => null],
            'other_result' => ['structured_result_text' => 'F8A teste outro exame', 'stored_object_key' => null],
            'result_without_text' => ['structured_result_text' => null, 'stored_object_key' => 'f8a/teste/laudo.pdf'],
        ] as $key => $columns) {
            $request = $this->insert('exam_request', [
                'tenant_id' => $this->tenantId,
                'encounter_id' => $encounter,
                'patient_id' => $patient,
                'exam_catalog_item_id' => $catalog,
                'professional_system_user_id' => $this->userId,
                'status' => 'result_available',
                'requested_at' => '2031-09-07 11:00:00',
            ]);
            $this->requests[$key] = $request;
            $this->ids[$key] = $this->insert('exam_result', [
                'tenant_id' => $this->tenantId,
                'exam_request_id' => $request,
                'received_at' => '2031-09-07 15:00:00',
            ] + $columns);
        }

        $this->requests['without_result'] = $this->insert('exam_request', [
            'tenant_id' => $this->tenantId,
            'encounter_id' => $encounter,
            'patient_id' => $patient,
            'exam_catalog_item_id' => $catalog,
            'professional_system_user_id' => $this->userId,
            'status' => 'requested',
            'requested_at' => '2031-09-07 11:00:00',
        ]);

        $this->insert('feature_flag', [
            'tenant_id' => $this->tenantId,
            'flag_key' => 'ai_assistant',
            'enabled' => 1,
            'updated_by_system_user_id' => $this->userId,
        ]);
        $this->insert('ai_tenant_setting', [
            'tenant_id' => $this->tenantId,
            'terms_version' => \CentralVet\Domain\AiTerms::VERSION,
            'terms_accepted_at' => '2026-10-01 10:00:00',
            'terms_accepted_by_system_user_id' => $this->userId,
            'monthly_token_quota' => 300000,
            'updated_by_system_user_id' => $this->userId,
        ]);
    }

    private function resultTextHex(int $resultId): ?string
    {
        $statement = $this->pdo->prepare('SELECT HEX(structured_result_text) FROM exam_result WHERE id = ?');
        $statement->execute([$resultId]);

        $hex = $statement->fetchColumn();

        return $hex === false ? null : (string) $hex;
    }

    private function latestInteractionId(): int
    {
        $statement = $this->pdo->prepare("SELECT MAX(id) FROM ai_interaction WHERE tenant_id = ? AND purpose = 'exam_summary'");
        $statement->execute([$this->tenantId]);

        return (int) $statement->fetchColumn();
    }

    private function interactionStatus(int $interactionId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM ai_interaction WHERE id = ? AND tenant_id = ?');
        $statement->execute([$interactionId, $this->tenantId]);

        return (string) $statement->fetchColumn();
    }

    private function postedSha(string $html): string
    {
        Assert::true((bool) preg_match('/name="ai_suggestion_sha256"[^>]*value="([0-9a-f]{64})"/', $html, $m), 'suggestion form carries ai_suggestion_sha256');

        return $m[1];
    }

    private function suggestionText(int $interactionId): string
    {
        $statement = $this->pdo->prepare('SELECT suggestion_text FROM ai_interaction WHERE id = ? AND tenant_id = ?');
        $statement->execute([$interactionId, $this->tenantId]);

        return (string) $statement->fetchColumn();
    }

    private function interactionFinalText(int $interactionId): ?string
    {
        $statement = $this->pdo->prepare('SELECT final_text FROM ai_interaction WHERE id = ? AND tenant_id = ?');
        $statement->execute([$interactionId, $this->tenantId]);
        $text = $statement->fetchColumn();

        return $text === false || $text === null ? null : (string) $text;
    }

    private function auditCount(int $interactionId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND entity_id = ?');
        $statement->execute([$this->tenantId, (string) $interactionId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Second system_unit (any other than the session unit); when the test
     * database has only one, creates one in the fixture tenant (removed by
     * deleteTenant()). system_unit.id is not AUTO_INCREMENT (Adianti).
     */
    private function otherUnitId(): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM system_unit WHERE id <> ? ORDER BY id LIMIT 1');
        $statement->execute([$this->unitId]);
        $id = (int) $statement->fetchColumn();

        if ($id > 0) {
            return $id;
        }

        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit')->fetchColumn();
        $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (:id, :tenant_id, :name)')
            ->execute(['id' => $id, 'tenant_id' => $this->tenantId, 'name' => 'F8A teste outra unidade']);

        return $id;
    }

    /** @param array<string, int|string|null> $row */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));
        $statement->execute($row);

        return (int) $this->pdo->lastInsertId();
    }

    private function uuid(): string
    {
        return (string) $this->pdo->query('SELECT UUID()')->fetchColumn();
    }
}
