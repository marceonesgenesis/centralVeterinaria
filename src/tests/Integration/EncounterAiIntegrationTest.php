<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;
use CentralVet\Tests\Support\TestDatabase;

/**
 * Fase 8A, T-14: IA no atendimento (EncounterView). Resumo do paciente e
 * anamnese organizada (texto livre/ditado), sempre com revisão humana
 * antes de gravar no atendimento.
 *
 * O controller só carrega com o Adianti, num processo PHP separado
 * (init.php define _t() global), apontado para o banco de teste
 * (DB_DATABASE = TestDatabase::resolveName) e com AI_PROVIDER=stub — sem
 * rede. Como o subprocesso abre as próprias transações (TTransaction), as
 * linhas de fixture são gravadas de fato no banco de teste, num tenant
 * descartável com prefixo "F8A teste", e apagadas em tearDown().
 */
final class EncounterAiIntegrationTest
{
    private const MARKER = '@@ENCOUNTERAI@@';

    private ?\PDO $pdo = null;
    private string $database = '';
    private int $tenantId = 0;
    private int $unitId = 0;
    private int $userId = 0;
    private int $tutorId = 0;
    private int $patientId = 0;
    private int $encounterId = 0;

    public function setUp(): void
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $this->database = TestDatabase::resolveName(getenv());

        try {
            $this->pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    getenv('DB_HOST') ?: '127.0.0.1',
                    getenv('DB_PORT') ?: '3306',
                    $this->database,
                ),
                (string) (getenv('DB_USERNAME') ?: 'centralvet'),
                (string) (getenv('DB_PASSWORD') ?: ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $e) {
            throw new SkippedTestException('Real MySQL service unreachable: ' . $e->getMessage());
        }

        if (!(bool) $this->pdo->query("SHOW TABLES LIKE 'ai_interaction'")->fetchColumn()) {
            throw new SkippedTestException('Migration 0014 not applied to the test database');
        }

        $this->unitId = (int) $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $this->pdo->query('SELECT id FROM system_users ORDER BY id LIMIT 1')->fetchColumn();
        Assert::true($this->unitId > 0 && $this->userId > 0, 'Fixture requires system_unit and system_users rows');

        $slug = 'f8a-teste-' . bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, :status)')
            ->execute(['slug' => $slug, 'name' => 'F8A teste tenant (' . $slug . ')', 'status' => 'active']);
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :name, :phone)')
            ->execute(['tenant_id' => $this->tenantId, 'name' => 'F8A teste tutor', 'phone' => '11900000000']);
        $this->tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:tenant_id, :tutor_id, :name, :species)')
            ->execute(['tenant_id' => $this->tenantId, 'tutor_id' => $this->tutorId, 'name' => 'F8A teste Rex', 'species' => 'canino']);
        $this->patientId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO encounter (tenant_id, system_unit_id, patient_id, professional_system_user_id, status, started_at)
             VALUES (:tenant_id, :unit_id, :patient_id, :user_id, :status, NOW(6))',
        )->execute([
            'tenant_id' => $this->tenantId,
            'unit_id' => $this->unitId,
            'patient_id' => $this->patientId,
            'user_id' => $this->userId,
            'status' => 'in_progress',
        ]);
        $this->encounterId = (int) $this->pdo->lastInsertId();
    }

    public function tearDown(): void
    {
        if ($this->pdo === null || $this->tenantId <= 0) {
            return;
        }

        foreach (['ai_interaction', 'audit_log', 'feature_flag', 'ai_tenant_setting', 'encounter', 'patient', 'tutor'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE tenant_id = :tenant_id")->execute(['tenant_id' => $this->tenantId]);
        }

        $this->pdo->prepare('DELETE FROM tenant WHERE id = :id')->execute(['id' => $this->tenantId]);
        $this->tenantId = 0;
    }

    public function testEnabledAiRendersPanelWithDictationAndReviewableSuggestion(): void
    {
        $this->enableAi();

        $result = $this->runView(['method' => 'onAiPatientSummary']);
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains($html, 'name="ai_free_text"'), 'panel must render the ai_free_text textarea');
        Assert::true(str_contains($html, 'id="ai_dictation_button"'), 'panel must render the dictation button');
        Assert::true(str_contains($html, 'app/templates/adminbs5/js/cv-ai-dictation.js'), 'page must include cv-ai-dictation.js');
        Assert::true(str_contains($html, "CvDictation.attach('ai_dictation_button', 'ai_free_text'"), 'dictation must be attached to ai_free_text');
        Assert::true(preg_match('/id="ai_dictation_button"[^>]*data-label-notice="[^"]+"/', $html) === 1, 'dictation button must carry the translated data-label-notice');
        Assert::true(str_contains($html, 'cv-ai-origin'), 'suggestion must carry the AI origin badge');
        Assert::true(str_contains($html, 'name="ai_final_text"'), 'suggestion must be editable in ai_final_text');
        Assert::true(str_contains($html, '[stub] patient_summary'), 'suggestion text must be shown for review');
        Assert::true(str_contains($html, 'onAiDecide'), 'suggestion must offer the decision actions');
        Assert::null($this->encounterColumn('ai_summary_accepted_at'), 'generation alone must not touch the encounter');
    }

    public function testDisabledAiHidesEveryAiField(): void
    {
        $result = $this->runView([]);
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains($html, 'anamnesis_text'), 'encounter form must still render');

        foreach (['ai_free_text', 'ai_dictation_button', 'cv-ai-dictation.js', 'cv-ai-origin', 'ai_final_text', 'onAiPatientSummary'] as $needle) {
            Assert::true(!str_contains($html, $needle), "AI off: '{$needle}' must not be rendered");
        }
    }

    public function testApplyingOrganizedAnamnesisAppendsSuggestionToPostedText(): void
    {
        $this->enableAi();

        $generated = $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Tosse ha dois dias', 'anamnesis_text' => 'Texto digitado']);
        Assert::true(!isset($generated['error']), 'organize threw: ' . (string) ($generated['error'] ?? ''));
        Assert::true(str_contains((string) $generated['html'], 'Texto digitado'), 'unsaved anamnesis text must survive the organize round-trip');

        $interaction = $this->latestInteraction();
        Assert::same('anamnesis_organize', $interaction['purpose']);
        Assert::same('pending_review', $interaction['status']);
        Assert::null($this->encounterColumn('anamnesis_text'), 'organize alone must not touch the anamnesis');

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => 'Texto digitado',
            'draft_version' => (string) $this->renderedVersion(),
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));

        $expected = 'Texto digitado' . "\n\n" . $interaction['suggestion_text'];
        Assert::same($expected, $this->encounterColumn('anamnesis_text'));
        Assert::same('accepted', $this->latestInteraction()['status']);

        // The reloaded form posts what its anamnesis textarea shows on the next autosave tick.
        $reloadedForm = $this->runView([]);
        $shown = $this->textareaValue((string) $reloadedForm['html'], 'anamnesis_text');
        Assert::same($expected, $shown, 'reloaded form must show the combined anamnesis');
        $tick = $this->runView(['method' => 'onAutosave'], [
            'anamnesis_text' => $shown,
            'draft_version' => (string) $this->draftVersion((string) $reloadedForm['html']),
            'draft_seq' => '1',
        ]);
        Assert::same('saved', $this->autosaveResult((string) $tick['html'])['status'] ?? null, 'tick of the reloaded form is saved');
        Assert::same($expected, $this->encounterColumn('anamnesis_text'), 'autosave of the reloaded form must keep the combined text');

        $reloaded = $this->runView([]);
        Assert::true(str_contains((string) $reloaded['html'], 'cv-ai-anamnesis-notice'), 'reloaded page must flag the AI-organized anamnesis');
    }

    public function testAcceptedPatientSummaryIsStoredOnTheEncounter(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();
        Assert::same('patient_summary', $interaction['purpose']);

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => '',
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));

        Assert::same($interaction['suggestion_text'], $this->encounterColumn('ai_summary_text'));
        Assert::notNull($this->encounterColumn('ai_summary_accepted_at'));
        Assert::null($this->encounterColumn('anamnesis_text'), 'summary must not touch the anamnesis');
    }

    public function testAcceptingSummaryKeepsUnsavedDraftFields(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => 'Anamnese digitada nao salva',
            'diagnosis_text' => 'Diagnostico digitado',
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));

        Assert::same($interaction['suggestion_text'], $this->encounterColumn('ai_summary_text'));
        Assert::null($this->encounterColumn('anamnesis_text'), 'accepting the summary writes only the summary (T-24, correção 1)');
        Assert::null($this->encounterColumn('diagnosis_text'), 'accepting the summary writes no draft field');
        Assert::same('Anamnese digitada nao salva', $this->textareaValue((string) $decided['html'], 'anamnesis_text'), 'typed anamnesis stays on screen');
        Assert::same('Diagnostico digitado', $this->textareaValue((string) $decided['html'], 'diagnosis_text'), 'other typed fields stay on screen');
        Assert::true(str_contains((string) $decided['html'], 'cv-ai-accepted-summary'), 'the page shows the accepted summary');
    }

    public function testApplyingAnamnesisKeepsOtherUnsavedDraftFields(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Prurido', 'anamnesis_text' => 'Texto digitado']);
        $interaction = $this->latestInteraction();

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => 'Texto digitado',
            'physical_exam_text' => 'Exame digitado',
            'clinical_plan_text' => 'Plano digitado',
            'draft_version' => (string) $this->renderedVersion(),
        ]);
        $html = (string) $decided['html'];
        $applied = 'Texto digitado' . "\n\n" . $interaction['suggestion_text'];

        Assert::same($applied, $this->encounterColumn('anamnesis_text'));
        Assert::null($this->encounterColumn('physical_exam_text'), 'apply writes only the anamnesis (T-24, correção 1)');
        Assert::null($this->encounterColumn('clinical_plan_text'), 'apply writes only the anamnesis');
        Assert::same('accepted', $this->latestInteraction()['status']);
        Assert::same($applied, $this->textareaValue($html, 'anamnesis_text'), 'the screen shows the applied anamnesis');
        Assert::same('Exame digitado', $this->textareaValue($html, 'physical_exam_text'), 'typed exam stays on screen');
        Assert::same('Plano digitado', $this->textareaValue($html, 'clinical_plan_text'), 'typed plan stays on screen');
        Assert::same($this->renderedVersion(), $this->postedVersionField($html), 'the answer carries the new version for the tab');
        Assert::same(1, substr_count($html, 'id="encounter_autosave_indicator"'), 'the re-rendered page replaces the old one');
        Assert::same(1, substr_count($html, "window.cvAutosaveTimers['form_EncounterView_" . $this->encounterId . "'] = autosave"), 'one autosave script');
    }

    public function testSaveEditStoresTheEditedSummary(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();

        $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'edited',
            'ai_final_text' => 'Resumo revisado',
            'anamnesis_text' => '',
        ]);

        Assert::same('edited', $this->latestInteraction()['status']);
        Assert::same('Resumo revisado', $this->encounterColumn('ai_summary_text'));
    }

    public function testDecisionOnFinishedEncounterIsNotRecorded(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();
        $this->pdo->prepare("UPDATE encounter SET status = 'finished', finished_at = NOW(6) WHERE id = :id")->execute(['id' => $this->encounterId]);
        $before = $this->encounterRow();

        $result = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => 'Texto digitado',
        ]);

        Assert::true(!isset($result['error']), 'decide threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains((string) $result['html'], '__adianti_error'), 'finished encounter must show an error');
        Assert::true(!str_contains((string) $result['html'], "__adianti_goto_page('index.php?class=EncounterView&encounter_id="), 'finished encounter must not reload');
        Assert::same('pending_review', $this->latestInteraction()['status'], 'decision must not be recorded');
        Assert::same($before, $this->encounterRow(), 'finished encounter must not change');
    }

    public function testDiscardDoesNotChangeTheEncounter(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Vomito desde ontem', 'anamnesis_text' => '']);
        $interaction = $this->latestInteraction();
        $before = $this->encounterRow();

        $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'discarded',
            'ai_final_text' => 'qualquer coisa',
        ]);

        Assert::same('discarded', $this->latestInteraction()['status']);
        Assert::same($before, $this->encounterRow(), 'discard must not change the encounter');
    }

    public function testInteractionOfAnotherEncounterIsRefused(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();

        $this->pdo->prepare(
            'INSERT INTO encounter (tenant_id, system_unit_id, patient_id, professional_system_user_id, status, started_at)
             VALUES (:tenant_id, :unit_id, :patient_id, :user_id, :status, NOW(6))',
        )->execute(['tenant_id' => $this->tenantId, 'unit_id' => $this->unitId, 'patient_id' => $this->patientId, 'user_id' => $this->userId, 'status' => 'in_progress']);
        $otherEncounterId = (int) $this->pdo->lastInsertId();

        $result = $this->runView(['method' => 'onAiDecide', 'encounter_id' => (string) $otherEncounterId], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => '',
        ]);

        Assert::true(!isset($result['error']), 'decide threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains((string) $result['html'], '__adianti_warning'), 'foreign interaction must show a warning');
        Assert::same('pending_review', $this->latestInteraction()['status'], 'decision on another encounter must be rolled back');
        Assert::null($this->encounterColumn('ai_summary_text'));
    }

    public function testTimeoutShowsUnavailableWarningAndKeepsTheEncounter(): void
    {
        $this->enableAi();
        $before = $this->encounterRow();

        $result = $this->runView(['method' => 'onAiPatientSummary'], [], 'timeout');
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'timeout threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains($html, '__adianti_warning'), 'timeout must show a warning');
        Assert::true(str_contains($html, (string) $result['timeout_message']), 'warning must be the translated AI-unavailable message');
        Assert::true(str_contains($html, 'name="anamnesis_text"'), 'encounter must stay rendered');
        Assert::true(!str_contains($html, 'name="ai_final_text"'), 'no suggestion on timeout');

        $interaction = $this->latestInteraction();
        Assert::same('failed', $interaction['status']);
        Assert::same('ai_timeout', $interaction['error_code']);
        Assert::same($before, $this->encounterRow(), 'timeout must not change the encounter');
    }

    public function testSuggestionIsHtmlEscaped(): void
    {
        $this->enableAi();
        $this->pdo->prepare('UPDATE patient SET species = :species WHERE id = :id')
            ->execute(['species' => '<script>alert(1)</script>', 'id' => $this->patientId]);

        $result = $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Apatia', 'anamnesis_text' => '']);
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains($html, 'name="ai_final_text"'), 'suggestion must be rendered');
        Assert::true(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'suggestion must be escaped');
        Assert::true(!str_contains($html, '<script>alert(1)</script>'), 'raw script must never reach the page');
    }

    public function testAcceptWithChangedSummaryTextIsRecordedAsEdited(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => "  Resumo ajustado\r\npelo veterinario  ",
            'ai_suggestion_sha256' => hash('sha256', trim(str_replace(["\r\n", "\r"], "\n", (string) $interaction['suggestion_text']))),
            'anamnesis_text' => '',
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));

        $row = $this->latestInteraction();
        Assert::same('edited', $row['status'], 'accept with a changed text must be recorded as edited');
        Assert::same("Resumo ajustado\npelo veterinario", $row['final_text']);
        Assert::same("Resumo ajustado\npelo veterinario", $this->encounterColumn('ai_summary_text'));
    }

    /** T-29: "Accept" without the hidden SHA compares with the stored suggestion. */
    public function testAcceptWithoutShaAndEditedSummaryIsRecordedAsEdited(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => " Resumo sem hash\r\neditado ",
            'anamnesis_text' => '',
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));

        $row = $this->latestInteraction();
        Assert::same('edited', $row['status'], 'accept without SHA and with an edited text must be recorded as edited');
        Assert::same("Resumo sem hash\neditado", $row['final_text']);
        Assert::same("Resumo sem hash\neditado", $this->encounterColumn('ai_summary_text'));
    }

    /** T-29: "Accept" with the text cleared is refused, like on ExamResultForm. */
    public function testAcceptWithEmptyTextIsRefused(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => " \r\n ",
            'anamnesis_text' => '',
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));

        Assert::same('pending_review', $this->latestInteraction()['status'], 'empty accept must not be recorded');
        Assert::null($this->encounterColumn('ai_summary_text'));
        Assert::true(str_contains((string) $decided['html'], '__adianti_warning'), 'empty accept must show the warning');
    }

    /** T-29 correção 1: "Accept" with the text cleared keeps the draft and the suggestion. */
    public function testEmptyAcceptKeepsThePostedDraftAndThePendingSuggestion(): void
    {
        $this->assertRefusedDecisionKeepsDraftAndSuggestion('accepted', " \r\n ");
    }

    /** T-29 correção 1: an invalid decision keeps the draft and the edited suggestion. */
    public function testInvalidDecisionKeepsThePostedDraftAndThePendingSuggestion(): void
    {
        $this->assertRefusedDecisionKeepsDraftAndSuggestion('hacked', 'Resumo editado antes do erro');
    }

    /**
     * A refused decision records nothing, shows a warning and keeps on screen
     * the unsaved draft fields and the pending suggestion (the posted text,
     * or the suggestion itself when the posted text is empty).
     */
    private function assertRefusedDecisionKeepsDraftAndSuggestion(string $decision, string $finalText): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();
        $suggestion = trim(str_replace(["\r\n", "\r"], "\n", (string) $interaction['suggestion_text']));
        $shown = trim($finalText) !== '' ? $finalText : $suggestion;

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => $decision,
            'ai_final_text' => $finalText,
            'ai_suggestion_sha256' => hash('sha256', $suggestion),
            'anamnesis_text' => 'Anamnese digitada nao salva',
            'diagnosis_text' => 'Diagnostico digitado nao salvo',
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));
        $html = (string) $decided['html'];

        Assert::same('pending_review', $this->latestInteraction()['status'], 'refused decision must not be recorded');
        Assert::null($this->encounterColumn('ai_summary_text'));
        Assert::null($this->encounterColumn('diagnosis_text'), 'refused decision must not save the draft');
        Assert::true(str_contains($html, '__adianti_warning'), 'refused decision must show a warning');
        Assert::true(str_contains($html, 'Anamnese digitada nao salva'), 'posted anamnesis must stay on screen');
        Assert::true(str_contains($html, 'Diagnostico digitado nao salvo'), 'posted diagnosis must stay on screen');
        Assert::true(
            (bool) preg_match('/name="interaction_id"[^>]*value="' . (int) $interaction['id'] . '"/', $html),
            'pending suggestion must stay under review',
        );
        Assert::true(str_contains($html, htmlspecialchars($shown, ENT_QUOTES)), 'suggestion textarea must keep its text');
    }

    public function testDiscardKeepsThePostedDraftOnScreenWithoutSavingIt(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Diarreia', 'anamnesis_text' => '']);
        $interaction = $this->latestInteraction();

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'discarded',
            'ai_final_text' => 'ignorado',
            'anamnesis_text' => 'Rascunho digitado',
            'diagnosis_text' => 'Diagnostico digitado',
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));

        Assert::same('discarded', $this->latestInteraction()['status']);
        Assert::null($this->latestInteraction()['final_text']);
        Assert::null($this->encounterColumn('anamnesis_text'), 'discard writes no draft field (T-24, correção 1)');
        Assert::null($this->encounterColumn('diagnosis_text'), 'discard writes no draft field');
        Assert::same('Rascunho digitado', $this->textareaValue((string) $decided['html'], 'anamnesis_text'), 'typed anamnesis stays on screen');
        Assert::same('Diagnostico digitado', $this->textareaValue((string) $decided['html'], 'diagnosis_text'), 'typed diagnosis stays on screen');
    }

    /** T-24, reprodução 3: "Aplicar à anamnese" entre o render da aba e o tick dela. */
    public function testInFlightAutosaveAfterApplyKeepsTheAppliedAnamnesis(): void
    {
        $this->enableAi();

        // The page (and its autosave timer) is rendered before the decision.
        $page = $this->runView([]);
        $version = $this->draftVersion((string) $page['html']);
        Assert::true($version !== null, 'autosave script must carry the draft version');

        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Tosse seca', 'anamnesis_text' => 'Texto antigo']);
        $interaction = $this->latestInteraction();
        $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => 'Texto antigo',
            'weight_kg' => '12.5',
            'diagnosis_text' => 'Traqueite',
            'clinical_plan_text' => 'Repouso',
            'draft_version' => (string) $version,
        ]);
        $applied = 'Texto antigo' . "\n\n" . $interaction['suggestion_text'];
        Assert::same($applied, $this->encounterColumn('anamnesis_text'));
        $before = $this->draftColumns();

        // The tick mounted before the decision arrives afterwards with the old text.
        $result = $this->runView(['method' => 'onAutosave'], [
            'draft_version' => (string) $version,
            'draft_seq' => '3',
            'anamnesis_text' => 'Texto antigo',
            'diagnosis_text' => 'Diagnostico da aba',
            'clinical_plan_text' => '',
        ]);

        Assert::same($before, $this->draftColumns(), 'late autosave must not write any draft field');
        $answer = $this->autosaveResult((string) $result['html']);
        Assert::same('conflict', $answer['status'] ?? null, 'late autosave must be flagged as conflict');
        Assert::same('3', $answer['seq'] ?? null, 'the answer echoes the tick sequence');
        Assert::same($this->renderedVersion(), $answer['version'] ?? null, 'a conflict answers the stored version');
    }

    /** Review Focus (T-24, reprodução 2): aba desatualizada não grava nenhum campo. */
    public function testAutosaveFromAStaleTabWritesNoDraftField(): void
    {
        $this->enableAi();
        $this->pdo->prepare('UPDATE encounter SET ai_summary_text = :summary WHERE id = :id')
            ->execute(['summary' => 'Resumo aceito antes', 'id' => $this->encounterId]);
        $kept = array_intersect_key($this->encounterRow(), array_flip(['status', 'started_at', 'ai_summary_text']));

        // Tabs A and B load the same page.
        $html = (string) $this->runView([])['html'];
        Assert::true(!str_contains($html, 'anamnesis_base_sha256'), 'autosave script must not send anamnesis_base_sha256');
        $version = $this->draftVersion($html);
        Assert::true($version !== null, 'autosave script must carry the draft version');

        // Tab B saves first.
        $tabB = [
            'anamnesis_text' => 'Anamnese da aba B',
            'physical_exam_text' => 'Exame da aba B',
            'diagnosis_text' => 'Diagnostico da aba B',
            'clinical_plan_text' => 'Plano da aba B',
            'weight_kg' => '8.40',
        ];
        $saved = $this->autosaveResult((string) $this->runView(['method' => 'onAutosave'], $tabB + ['draft_version' => (string) $version, 'draft_seq' => '1'])['html']);
        Assert::same('saved', $saved['status'] ?? null);
        $afterB = $this->draftColumns();

        // Tab A ticks with its old version and every field changed.
        $result = $this->runView(['method' => 'onAutosave'], [
            'draft_version' => (string) $version,
            'draft_seq' => '1',
            'anamnesis_text' => 'Anamnese da aba A',
            'physical_exam_text' => 'Exame da aba A',
            'diagnosis_text' => 'Diagnostico da aba A',
            'clinical_plan_text' => 'Plano da aba A',
            'weight_kg' => '9.10',
            'temperature_c' => '39.0',
        ]);
        $conflict = $this->autosaveResult((string) $result['html']);

        Assert::same('conflict', $conflict['status'] ?? null, 'stale tick must be flagged as conflict');
        Assert::same($afterB, $this->draftColumns(), 'every draft field keeps the value of tab B');
        Assert::same('Diagnostico da aba B', $this->encounterColumn('diagnosis_text'));
        Assert::same('Plano da aba B', $this->encounterColumn('clinical_plan_text'));
        Assert::same('Exame da aba B', $this->encounterColumn('physical_exam_text'));
        Assert::same('Anamnese da aba B', $this->encounterColumn('anamnesis_text'));
        Assert::same($kept, array_intersect_key($this->encounterRow(), $kept), 'status, started_at and ai_summary_text are untouched');
        Assert::same($saved['version'] ?? null, $conflict['version'] ?? null, 'the conflict answers the version tab B stored');

        // "Keep my text": tab A adopts the answered version and its next tick is stored.
        $kept2 = $this->autosaveResult((string) $this->runView(['method' => 'onAutosave'], [
            'draft_version' => (string) $conflict['version'],
            'draft_seq' => '2',
            'anamnesis_text' => 'Anamnese da aba A',
        ])['html']);
        Assert::same('saved', $kept2['status'] ?? null);
        Assert::same('Anamnese da aba A', $this->encounterColumn('anamnesis_text'));
        Assert::same('Diagnostico da aba B', $this->encounterColumn('diagnosis_text'), 'fields tab A did not post keep their value');
    }

    /** Review Focus (T-24, correção 1): aba desatualizada aplica a sugestão sem sobrescrever a outra aba. */
    public function testStaleTabApplyKeepsTheOtherTabDraftAndShowsConflict(): void
    {
        $this->enableAi();
        $version = (string) $this->draftVersion((string) $this->runView([])['html']);

        // Tab A saves diagnosis, plan and exam.
        $tabA = ['diagnosis_text' => 'Diagnostico da aba A', 'clinical_plan_text' => 'Plano da aba A', 'physical_exam_text' => 'Exame da aba A'];
        $saved = $this->autosaveResult((string) $this->runView(['method' => 'onAutosave'], $tabA + ['draft_version' => $version, 'draft_seq' => '1'])['html']);
        Assert::same('saved', $saved['status'] ?? null);
        $afterA = $this->draftColumns();

        // Tab B, still on the old version, organizes and applies.
        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Tosse', 'anamnesis_text' => 'Texto da aba B']);
        $interaction = $this->latestInteraction();
        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => 'Texto da aba B',
            'diagnosis_text' => 'Diagnostico velho da aba B',
            'clinical_plan_text' => 'Plano velho da aba B',
            'physical_exam_text' => 'Exame velho da aba B',
            'draft_version' => $version,
        ]);
        $html = (string) $decided['html'];

        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));
        Assert::same($afterA, $this->draftColumns(), 'a stale apply writes no draft field');
        Assert::same('pending_review', $this->latestInteraction()['status'], 'the decision stays pending when the apply is refused');
        Assert::true(str_contains($html, 'A anamnese foi alterada em outra janela'), 'the screen shows the conflict warning');
        Assert::same('Texto da aba B', $this->textareaValue($html, 'anamnesis_text'), 'the local text stays on screen');
        Assert::same('Diagnostico velho da aba B', $this->textareaValue($html, 'diagnosis_text'), 'local fields stay on screen');
        Assert::same(trim((string) $interaction['suggestion_text']), trim((string) $this->textareaValue($html, 'ai_final_text')), 'the suggestion stays under review');
    }

    /** T-24, correção 1: Aplicar sem versão (aba antiga) é conflito e não grava. */
    public function testApplyWithoutVersionWritesNothing(): void
    {
        $this->enableAi();
        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Prurido', 'anamnesis_text' => '']);
        $interaction = $this->latestInteraction();
        $before = $this->encounterRow();

        $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => 'Texto local',
        ]);

        Assert::same($before, $this->encounterRow(), 'apply without version writes nothing');
        Assert::same('pending_review', $this->latestInteraction()['status']);
    }

    /** Review Focus (T-24, reprodução 1): resposta perdida não trava a aba. */
    public function testLostAutosaveResponseDoesNotLockTheTab(): void
    {
        $version = (string) $this->draftVersion((string) $this->runView([])['html']);
        $tick1 = ['anamnesis_text' => 'Tick 1', 'weight_kg' => '7.25'];

        // Tick 1 is stored; its response is lost, so the tab keeps the old version.
        $this->runView(['method' => 'onAutosave'], $tick1 + ['draft_version' => $version, 'draft_seq' => '1']);
        Assert::same('Tick 1', $this->encounterColumn('anamnesis_text'));

        $result = $this->runView(['method' => 'onAutosave'], [
            'draft_version' => $version,
            'draft_seq' => '2',
            'previous_draft' => (string) json_encode($tick1),
            'anamnesis_text' => 'Tick 2',
            'weight_kg' => '7.25',
        ]);
        $answer = $this->autosaveResult((string) $result['html']);

        Assert::same('saved', $answer['status'] ?? null, 'a lost response must not turn the next tick into a conflict');
        Assert::same('2', $answer['seq'] ?? null);
        Assert::same('Tick 2', $this->encounterColumn('anamnesis_text'));
        Assert::same($this->renderedVersion(), $answer['version'] ?? null, 'the answered version matches the reloaded row');
    }

    /** T-24, reprodução 4: post sem versão (aba aberta antes do deploy) é conflito. */
    public function testAutosaveWithoutVersionIsConflictAndWritesNothing(): void
    {
        $before = $this->encounterRow();

        $result = $this->runView(['method' => 'onAutosave'], ['anamnesis_text' => 'Sem versao', 'diagnosis_text' => 'Sem versao']);

        Assert::same('conflict', $this->autosaveResult((string) $result['html'])['status'] ?? null);
        Assert::true(str_contains((string) $result['html'], 'data-status="conflict"'));
        Assert::same($before, $this->encounterRow(), 'a post without version writes nothing');
    }

    /** T-28: lockForDraft is SELECT ... FOR UPDATE (needs a transaction) and updateDraft writes only the draft. */
    public function testDraftLockBlocksOtherWritersAndUpdateDraftWritesOnlyTheDraft(): void
    {
        $context = \CentralVet\Tenancy\TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId);
        $lockConnection = $this->connect();
        $repository = new \CentralVet\Persistence\EncounterRepository($context, $lockConnection);
        $this->pdo->prepare('UPDATE encounter SET paused_at = :paused, ai_summary_text = :summary WHERE id = :id')
            ->execute(['paused' => '2026-10-15 10:00:00', 'summary' => 'Resumo', 'id' => $this->encounterId]);
        $kept = array_intersect_key($this->encounterRow(), array_flip(['status', 'paused_at', 'paused_seconds', 'ai_summary_text', 'started_at']));

        Assert::throws(\LogicException::class, fn () => $repository->lockForDraft($this->encounterId), 'lockForDraft needs an open transaction');

        $lockConnection->beginTransaction();
        try {
            $locked = $repository->lockForDraft($this->encounterId);
            Assert::notNull($locked);

            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $blocked = false;
            try {
                $this->pdo->prepare('UPDATE encounter SET anamnesis_text = :text WHERE id = :id')
                    ->execute(['text' => 'Outra janela', 'id' => $this->encounterId]);
            } catch (\PDOException $e) {
                $blocked = true;
            }
            Assert::true($blocked, 'another writer waits for the draft lock');

            $locked->applyDraft(['anamnesis_text' => 'Texto da aba', 'diagnosis_text' => 'Diagnostico da aba']);
            $repository->updateDraft($locked);
            $lockConnection->commit();
        } finally {
            if ($lockConnection->inTransaction()) {
                $lockConnection->rollBack();
            }
        }

        Assert::same('Texto da aba', $this->encounterColumn('anamnesis_text'));
        Assert::same('Diagnostico da aba', $this->encounterColumn('diagnosis_text'));
        Assert::same($kept, array_intersect_key($this->encounterRow(), $kept), 'updateDraft writes no lifecycle column');
    }

    /** T-28 (c): "38,5" is stored as 38.5 and the next tick with the answered version is saved. */
    public function testDecimalCommaIsNormalizedAndTheNextTickIsSaved(): void
    {
        $version = (string) $this->draftVersion((string) $this->runView([])['html']);
        $vitals = ['temperature_c' => '38,5', 'weight_kg' => '12,5', 'capillary_refill_seconds' => '2,5'];

        $first = $this->autosaveResult((string) $this->runView(['method' => 'onAutosave'], $vitals + ['draft_version' => $version, 'draft_seq' => '1'])['html']);

        Assert::same('saved', $first['status'] ?? null);
        Assert::same('38.5', $this->encounterColumn('temperature_c'));
        Assert::same('12.50', $this->encounterColumn('weight_kg'));
        Assert::same('2.5', $this->encounterColumn('capillary_refill_seconds'));
        Assert::same($this->renderedVersion(), $first['version'] ?? null, 'the answered version matches the reloaded row');

        $second = $this->autosaveResult((string) $this->runView(['method' => 'onAutosave'], $vitals + [
            'anamnesis_text' => 'Febre',
            'draft_version' => (string) ($first['version'] ?? ''),
            'draft_seq' => '2',
        ])['html']);

        Assert::same('saved', $second['status'] ?? null, 'the tick after "38,5" must not be a conflict');
        Assert::same('Febre', $this->encounterColumn('anamnesis_text'));
    }

    /** T-28, correção 1: "abc" na temperatura não impede a anamnese; a resposta avisa o campo. */
    public function testInvalidNumberIsSkippedAndTheTickSavesTheOtherFields(): void
    {
        $page = (string) $this->runView([])['html'];
        $version = (string) $this->draftVersion($page);
        $first = $this->autosaveResult((string) $this->runView(['method' => 'onAutosave'], ['temperature_c' => '38,5', 'draft_version' => $version, 'draft_seq' => '1'])['html']);
        Assert::same('saved', $first['status'] ?? null);

        $result = $this->runView(['method' => 'onAutosave'], [
            'temperature_c' => 'abc',
            'anamnesis_text' => 'Anamnese nova',
            'draft_version' => (string) ($first['version'] ?? ''),
            'draft_seq' => '2',
        ]);
        $html = (string) $result['html'];
        $answer = $this->autosaveResult($html);

        Assert::same('saved', $answer['status'] ?? null, 'an invalid number must not stop the tick');
        Assert::same('Anamnese nova', $this->encounterColumn('anamnesis_text'));
        Assert::same('38.5', $this->encounterColumn('temperature_c'), 'the invalid field keeps the stored value');
        Assert::same($this->renderedVersion(), $answer['version'] ?? null, 'the answered version is what was written');
        Assert::true(
            preg_match('/<span class="cv-autosave-invalid" data-field="temperature_c">[^<]*Temperatura[^<]*número inválido[^<]*<\/span>/u', $html) === 1,
            'the answer carries a translated warning for the field',
        );
        Assert::true(preg_match('/id="encounter_autosave_invalid"[^>]*text-align:left/', $page) === 1, 'the page has a left-aligned box for the warnings');
        Assert::true(str_contains($page, 'cv-autosave-invalid'), 'the autosave script reads the warnings');
    }

    /** T-28, correção 2: temperatura "3855" e FC "-5" não derrubam o tick (STRICT_TRANS_TABLES). */
    public function testOutOfRangeNumberIsSkippedAndTheTickSavesTheOtherFields(): void
    {
        $version = (string) $this->draftVersion((string) $this->runView([])['html']);
        $first = $this->autosaveResult((string) $this->runView(['method' => 'onAutosave'], ['temperature_c' => '38,5', 'heart_rate_bpm' => '90', 'draft_version' => $version, 'draft_seq' => '1'])['html']);
        Assert::same('saved', $first['status'] ?? null);

        $html = (string) $this->runView(['method' => 'onAutosave'], [
            'temperature_c' => '3855',
            'heart_rate_bpm' => '-5',
            'anamnesis_text' => 'Anamnese nova',
            'draft_version' => (string) ($first['version'] ?? ''),
            'draft_seq' => '2',
        ])['html'];

        Assert::same('saved', $this->autosaveResult($html)['status'] ?? null, 'an out-of-range number must not stop the tick');
        Assert::same('Anamnese nova', $this->encounterColumn('anamnesis_text'));
        Assert::same('38.5', $this->encounterColumn('temperature_c'));
        Assert::same('90', (string) $this->encounterColumn('heart_rate_bpm'));
        Assert::true(str_contains($html, '<span class="cv-autosave-invalid" data-field="temperature_c">'), 'temperature warning');
        Assert::true(str_contains($html, '<span class="cv-autosave-invalid" data-field="heart_rate_bpm">'), 'heart rate warning');
    }

    /** T-28, correção 2: an exception in the autosave answers an error mark instead of nothing. */
    public function testAutosaveFailureAnswersAnErrorMark(): void
    {
        $page = (string) $this->runView([])['html'];
        $version = (string) $this->draftVersion($page);

        // mucous_membranes is varchar(60): the strict UPDATE fails.
        $html = (string) $this->runView(['method' => 'onAutosave'], [
            'mucous_membranes' => str_repeat('x', 70),
            'anamnesis_text' => 'Texto local',
            'draft_version' => $version,
            'draft_seq' => '4',
        ])['html'];

        Assert::true(
            str_contains($html, '<span class="cv-autosave-error" data-seq="4">Não foi possível salvar o rascunho; seu texto continua na tela.</span>'),
            'a failed tick answers the error mark',
        );
        Assert::null($this->encounterColumn('anamnesis_text'), 'nothing of the failed tick is written');
        Assert::true(str_contains($page, 'cv-autosave-error'), 'the autosave script reads the error mark');
    }

    /** T-28, correção 2: a failed "Apply to anamnesis" says so and keeps the local text. */
    public function testApplyFailureShowsTheDraftErrorAndKeepsTheText(): void
    {
        $this->enableAi();
        $version = (string) $this->draftVersion((string) $this->runView([])['html']);
        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Tosse', 'anamnesis_text' => 'Texto local']);
        $interaction = $this->latestInteraction();
        $typed = str_repeat('a', 70000); // anamnesis_text is TEXT (65535 bytes): the strict UPDATE fails.

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => $typed,
            'diagnosis_text' => 'Diagnostico local',
            'draft_version' => $version,
        ]);
        $html = (string) $decided['html'];

        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));
        Assert::true(str_contains($html, 'Não foi possível salvar o rascunho; seu texto continua na tela.'), 'the apply failure is shown');
        Assert::same('pending_review', $this->latestInteraction()['status'], 'the decision is rolled back');
        Assert::same('Diagnostico local', $this->textareaValue($html, 'diagnosis_text'), 'the local fields stay on screen');
        Assert::null($this->encounterColumn('anamnesis_text'));
    }

    /** T-32 (1): `finish` in tab B, then a tick of tab A with the current version. */
    public function testTickAfterFinishIsClosedAndWritesNothing(): void
    {
        $page = (string) $this->runView([])['html'];
        $version = (string) $this->draftVersion($page);
        $this->pdo->prepare("UPDATE encounter SET status = 'finished', finished_at = NOW(6) WHERE id = :id")->execute(['id' => $this->encounterId]);
        $before = $this->draftColumns();

        $html = (string) $this->runView(['method' => 'onAutosave'], [
            'anamnesis_text' => 'Depois do fim',
            'weight_kg' => '9',
            'diagnosis_text' => 'Otite',
            'draft_version' => $version,
            'draft_seq' => '3',
        ])['html'];

        Assert::same($before, $this->draftColumns(), 'a finished encounter takes no draft field');
        Assert::true(
            preg_match('/<span class="cv-autosave-result" data-status="closed" data-version="[0-9a-f]{64}" data-seq="3"><\/span>/', $html) === 1,
            'the tick answers closed',
        );
        Assert::true(str_contains($page, 'saved|conflict|closed'), 'the autosave script reads the closed answer');
        Assert::true(str_contains($page, 'Este atendimento foi finalizado e não pode mais ser editado'), 'the script carries the translated closed label');
    }

    /** T-32 (1): "Apply to anamnesis" after `finish` records no decision and writes no draft. */
    public function testApplyAfterFinishIsNotRecorded(): void
    {
        $this->enableAi();
        $version = (string) $this->draftVersion((string) $this->runView([])['html']);
        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Tosse', 'anamnesis_text' => 'Texto local']);
        $interaction = $this->latestInteraction();
        $this->pdo->prepare("UPDATE encounter SET status = 'finished', finished_at = NOW(6) WHERE id = :id")->execute(['id' => $this->encounterId]);
        $before = $this->draftColumns();

        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => 'Texto local',
            'draft_version' => $version,
        ]);
        $html = (string) $decided['html'];

        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));
        Assert::same('pending_review', $this->latestInteraction()['status'], 'the decision is not recorded');
        Assert::same($before, $this->draftColumns(), 'nothing of the draft is written');
        Assert::true(str_contains($html, 'Este atendimento foi finalizado e não pode mais ser editado'), 'the finished message is shown');
    }

    /**
     * T-32 (3): an answer of the latest tick without the result mark (HTTP
     * error page, login page, fatal) is an error, without needing the
     * cv-autosave-error mark; 25 s without an answer is an error too.
     */
    public function testAutosaveScriptTreatsAnUnmarkedAnswerAsAnError(): void
    {
        $page = (string) $this->runView([])['html'];

        Assert::true(
            preg_match('/if \(!result\) \{\s*(?:\/\/[^\n]*\s*)*if \(sent !== seq\) \{\s*return;\s*\}\s*showError\(\);/', $page) === 1,
            'an unmarked answer of the latest tick shows the error without reading cv-autosave-error',
        );
        Assert::true(
            preg_match("/var showError = function\(\) \{[^}]*indicatorText\('Não foi possível salvar o rascunho; seu texto continua na tela\.'\)/", $page) === 1,
            'the error is the translated draft error label',
        );
        Assert::true(preg_match('/setTimeout\(function\(\) \{[^}]*showError\(\);[\s}]*, 25000\)/', $page) === 1, 'no answer in 25 s shows the error');
    }

    /**
     * T-32, correção 1 (a): the 20 s tick moves `seq` before the 25 s
     * watchdog of the previous request fires, so the watchdog keeps its own
     * request (`sent`) and fires unless that request or a newer one was
     * answered; it never compares with the latest `seq`.
     */
    public function testUnansweredRequestShowsTheErrorEvenAfterANewerTick(): void
    {
        $page = (string) $this->runView([])['html'];

        Assert::true(
            preg_match('/var watchdog = setTimeout\(function\(\) \{\s*if \(!answeredTick && appliedSeq < sent\) \{\s*showError\(\);\s*\}\s*\}, 25000\);/', $page) === 1,
            'the watchdog of each request fires unless it or a newer request was answered',
        );
        Assert::true(preg_match('/var watchdog = setTimeout\(function\(\) \{[^}]*sent === seq/', $page) !== 1, 'the watchdog does not depend on the latest seq');
        Assert::true(preg_match('/answeredTick = true;\s*clearTimeout\(watchdog\);/', $page) === 1, 'an answer cancels its watchdog');
        Assert::true(preg_match('/appliedSeq = answered;/', $page) === 1, 'an answered request moves appliedSeq');
    }

    /**
     * T-32, correção 1 (b): `finish` commits in tab B after tab A's
     * transaction read the encounter; accepting the summary in tab A must
     * not reopen it nor write the summary.
     */
    public function testFinishBetweenTheReadAndTheSummaryAcceptKeepsTheEncounterFinished(): void
    {
        $context = \CentralVet\Tenancy\TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId);
        $policy = new \CentralVet\Tests\Support\FakeAuthorizationPolicy(allowed: true);
        $tabConnection = $this->connect();
        $tabRepository = new \CentralVet\Persistence\EncounterRepository($context, $tabConnection);
        $tabService = new \CentralVet\Application\EncounterService($tabRepository, $policy, $context, $tabConnection);
        $otherService = new \CentralVet\Application\EncounterService(
            new \CentralVet\Persistence\EncounterRepository($context, $this->pdo),
            $policy,
            $context,
            $this->pdo,
        );
        $refused = false;

        $tabConnection->beginTransaction();
        try {
            // Tab A's transaction has already read the encounter (snapshot)...
            $tabRepository->findById($this->encounterId);
            // ...when tab B's finish commits.
            $otherService->finish($this->encounterId, 'EncounterView::onFinish');
            try {
                $tabService->acceptAiSummary($this->encounterId, 'Resumo aceito');
                $tabConnection->commit();
            } catch (\CentralVet\Domain\Exception\InvalidStatusTransitionException) {
                $refused = true;
            }
        } finally {
            if ($tabConnection->inTransaction()) {
                $tabConnection->rollBack();
            }
        }

        Assert::same('finished', $this->encounterColumn('status'), 'the summary accept must not reopen the encounter');
        Assert::notNull($this->encounterColumn('finished_at'), 'finished_at is kept');
        Assert::null($this->encounterColumn('ai_summary_text'), 'a finished encounter takes no summary');
        Assert::true($refused, 'the accept is refused as a finished encounter');
    }

    /** T-28 (d): pause() committed while a tick is between its read and its write. */
    public function testPauseDuringATickDoesNotTurnItIntoConflict(): void
    {
        $context = \CentralVet\Tenancy\TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId);
        $policy = new \CentralVet\Tests\Support\FakeAuthorizationPolicy(allowed: true);
        $tickConnection = $this->connect();
        $tickRepository = new \CentralVet\Persistence\EncounterRepository($context, $tickConnection);
        $tickService = new \CentralVet\Application\EncounterService($tickRepository, $policy, $context, $tickConnection);
        $otherService = new \CentralVet\Application\EncounterService(
            new \CentralVet\Persistence\EncounterRepository($context, $this->pdo),
            $policy,
            $context,
            $this->pdo,
        );
        $version = $tickRepository->findById($this->encounterId)->draftVersion();

        $tickConnection->beginTransaction();
        try {
            // The tick's transaction has already read the row (snapshot)...
            $tickRepository->findById($this->encounterId);
            // ...when the pause button commits in another request.
            $otherService->pause($this->encounterId, 'EncounterView::onPause', new \DateTimeImmutable('2026-10-15 10:00:00'));
            $result = $tickService->autosave($this->encounterId, ['anamnesis_text' => 'Tick depois da pausa'], $version);
            $tickConnection->commit();
        } finally {
            if ($tickConnection->inTransaction()) {
                $tickConnection->rollBack();
            }
        }

        Assert::true($result->saved, 'pause does not change the draft, so the tick is not a conflict');
        Assert::same('Tick depois da pausa', $this->encounterColumn('anamnesis_text'));
        Assert::notNull($this->encounterColumn('paused_at'), 'the tick keeps paused_at');
        Assert::same($this->renderedVersion(), $result->version);
    }

    /** T-28 (e): pause() saved from a read older than the tab's tick keeps the tab's draft (SELECT). */
    public function testPauseWithAReadOlderThanTheTickKeepsTheDraft(): void
    {
        $repository = new \CentralVet\Persistence\EncounterRepository(
            \CentralVet\Tenancy\TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId),
            $this->pdo,
        );
        $stale = $repository->findById($this->encounterId);
        $version = $stale->draftVersion();

        $tab = ['anamnesis_text' => 'Anamnese da aba', 'diagnosis_text' => 'Diagnostico novo', 'weight_kg' => '8.40'];
        $saved = $this->autosaveResult((string) $this->runView(['method' => 'onAutosave'], $tab + ['draft_version' => $version, 'draft_seq' => '1'])['html']);
        Assert::same('saved', $saved['status'] ?? null);
        $afterTab = $this->draftColumns();

        $stale->pause(new \DateTimeImmutable('2026-10-15 10:00:00'));
        $repository->save($stale);

        Assert::same($afterTab, $this->draftColumns(), 'every draft field keeps the value the tab wrote');
        Assert::same('Diagnostico novo', $this->encounterColumn('diagnosis_text'));
        Assert::same('in_progress', $this->encounterColumn('status'));
        Assert::true(str_starts_with((string) $this->encounterColumn('paused_at'), '2026-10-15 10:00:00'), 'paused_at is the pause write');
    }

    /** T-28 (f): "Apply to anamnesis" while a tick is stored but unanswered. */
    public function testApplyWithAnInFlightTickIsSaved(): void
    {
        $this->enableAi();
        $page = (string) $this->runView([])['html'];
        $version = (string) $this->draftVersion($page);

        // The tick is stored; its answer has not arrived when "Apply" is clicked.
        $tick = ['anamnesis_text' => 'Texto do tick', 'diagnosis_text' => 'Otite'];
        $this->runView(['method' => 'onAutosave'], $tick + ['draft_version' => $version, 'draft_seq' => '1']);
        Assert::same('Texto do tick', $this->encounterColumn('anamnesis_text'));

        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Tosse', 'anamnesis_text' => 'Texto do tick']);
        $interaction = $this->latestInteraction();
        $decided = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => 'Texto do tick',
            'diagnosis_text' => 'Otite',
            'draft_version' => $version,
            'previous_draft' => (string) json_encode($tick),
        ]);
        $html = (string) $decided['html'];

        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));
        Assert::same('Texto do tick' . "\n\n" . $interaction['suggestion_text'], $this->encounterColumn('anamnesis_text'), 'an in-flight tick is not a conflict');
        // showDecisionConflict() calls the conflict hook; the autosave script only defines it.
        Assert::true(!str_contains($html, "window.cvAutosaveConflict['form_EncounterView_" . $this->encounterId . "']('"), 'no conflict warning');
        Assert::same('Otite', $this->encounterColumn('diagnosis_text'));
        Assert::same('accepted', $this->latestInteraction()['status']);
        Assert::same($this->renderedVersion(), $this->postedVersionField($html), 'the page adopts the new version');
        Assert::true(preg_match('/<input[^>]*name="previous_draft"/', $page) === 1, 'the form carries the previous_draft hidden field');
        Assert::true(str_contains($page, '[name="previous_draft"]'), 'the autosave script fills previous_draft');
    }

    public function testInteractionOfAnotherUnitShowsNotFound(): void
    {
        $this->enableAi();

        $otherUnit = (int) $this->pdo->query('SELECT id FROM system_unit WHERE id <> ' . $this->unitId . ' ORDER BY id LIMIT 1')->fetchColumn();
        if ($otherUnit <= 0) {
            throw new SkippedTestException('Fixture requires a second system_unit row');
        }

        $this->runView(['method' => 'onAiPatientSummary']);
        $interaction = $this->latestInteraction();
        $this->pdo->prepare('UPDATE ai_interaction SET system_unit_id = :unit WHERE id = :id')
            ->execute(['unit' => $otherUnit, 'id' => $interaction['id']]);

        $result = $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'ai_final_text' => (string) $interaction['suggestion_text'],
            'anamnesis_text' => '',
        ]);

        Assert::true(!isset($result['error']), 'decide threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains((string) $result['html'], 'Sugestão de IA não encontrada.'), 'another unit must be shown as not found');
        Assert::same('pending_review', $this->latestInteraction()['status'], 'decision of another unit must be rolled back');
        Assert::null($this->encounterColumn('ai_summary_text'));
    }

    public function testDictationButtonCarriesErrorLabelsAndAutosaveKeepsOneTimer(): void
    {
        $this->enableAi();

        $html = (string) $this->runView([])['html'];

        foreach (['data-label-listening', 'data-label-no-speech', 'data-label-audio-capture', 'data-label-network', 'data-label-error'] as $attribute) {
            Assert::true(
                preg_match('/id="ai_dictation_button"[^>]*' . $attribute . '="[^"]+"/', $html) === 1,
                "dictation button must carry the translated {$attribute}",
            );
        }
        Assert::true(str_contains($html, 'Nenhuma fala foi detectada. Tente novamente.'), 'no-speech label must be translated');
        Assert::true(str_contains($html, "window.cvAutosaveTimers['form_EncounterView_" . $this->encounterId . "']"), 'autosave timer must be kept per form');
        Assert::true(str_contains($html, 'clearInterval(window.cvAutosaveTimers['), 'previous autosave timer must be cleared');
        Assert::true(preg_match('/<button[^>]*cv-touch-target[^>]*>Manter meu texto<\/button>/', $html) === 1, 'conflict offers "Keep my text"');
        Assert::true(preg_match('/<button[^>]*cv-touch-target[^>]*>Recarregar a página<\/button>/', $html) === 1, 'conflict offers "Reload the page"');
    }

    /** A second connection to the test database (another request). */
    private function connect(): \PDO
    {
        return new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                $this->database,
            ),
            (string) (getenv('DB_USERNAME') ?: 'centralvet'),
            (string) (getenv('DB_PASSWORD') ?: ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    /** Draft version the autosave script of the page starts from. */
    private function draftVersion(string $html): ?string
    {
        if (preg_match("/var draftVersion = '([0-9a-f]{64})'/", $html, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** Version the page renders now (the stored draft). */
    private function renderedVersion(): ?string
    {
        return $this->draftVersion((string) $this->runView([])['html']);
    }

    /** Value of the hidden draft_version field of the encounter form. */
    private function postedVersionField(string $html): ?string
    {
        if (preg_match('/<input[^>]*name="draft_version"[^>]*value="([0-9a-f]{64})"/', $html, $m) !== 1
            && preg_match('/<input[^>]*value="([0-9a-f]{64})"[^>]*name="draft_version"/', $html, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** @return array{status?: string, version?: string, seq?: string} */
    private function autosaveResult(string $html): array
    {
        if (preg_match('/<span class="cv-autosave-result" data-status="(saved|conflict)" data-version="([0-9a-f]{64})" data-seq="(\d+)"><\/span>/', $html, $m) !== 1) {
            return [];
        }

        return ['status' => $m[1], 'version' => $m[2], 'seq' => $m[3]];
    }

    /** @return array<string, mixed> */
    private function draftColumns(): array
    {
        $row = $this->encounterRow();
        $fields = ['anamnesis_text', 'temperature_c', 'heart_rate_bpm', 'respiratory_rate_mpm', 'weight_kg', 'mucous_membranes', 'capillary_refill_seconds', 'physical_exam_text', 'diagnosis_text', 'clinical_plan_text'];

        return array_intersect_key($row, array_flip($fields));
    }

    private function textareaValue(string $html, string $name): ?string
    {
        if (preg_match('/<textarea[^>]*name="' . preg_quote($name, '/') . '"[^>]*>(.*?)<\/textarea>/s', $html, $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function enableAi(): void
    {
        $this->pdo->prepare('INSERT INTO feature_flag (tenant_id, flag_key, enabled, updated_by_system_user_id) VALUES (:tenant_id, :key, 1, :user_id)')
            ->execute(['tenant_id' => $this->tenantId, 'key' => 'ai_assistant', 'user_id' => $this->userId]);
        $this->pdo->prepare(
            'INSERT INTO ai_tenant_setting (tenant_id, terms_version, terms_accepted_at, terms_accepted_by_system_user_id, monthly_token_quota, updated_by_system_user_id)
             VALUES (:tenant_id, :version, NOW(6), :user_id, 300000, :user_id2)',
        )->execute(['tenant_id' => $this->tenantId, 'version' => \CentralVet\Domain\AiTerms::VERSION, 'user_id' => $this->userId, 'user_id2' => $this->userId]);
    }

    /** @return array<string, mixed> */
    private function latestInteraction(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ai_interaction WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 1');
        $statement->execute(['tenant_id' => $this->tenantId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        Assert::true(is_array($row), 'expected an ai_interaction row');

        return $row;
    }

    private function encounterColumn(string $column): mixed
    {
        return $this->encounterRow()[$column];
    }

    /** @return array<string, mixed> */
    private function encounterRow(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM encounter WHERE id = :id');
        $statement->execute(['id' => $this->encounterId]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Runs EncounterView as the Adianti engine does (constructor, then
     * show() → run() calls the method) for an authenticated session of the
     * throwaway tenant with the EncounterView program.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post
     * @return array<string, mixed>
     */
    private function runView(array $get, array $post = [], string $stubBehavior = 'ok'): array
    {
        $src = dirname(__DIR__, 2);
        $get = array_merge(['class' => 'EncounterView', 'encounter_id' => (string) $this->encounterId], $get);
        $session = [
            'logged' => true,
            'tenantid' => $this->tenantId,
            'userid' => $this->userId,
            'userunitid' => $this->unitId,
            'programs' => ['EncounterView' => true],
        ];

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . 'if (getenv("DB_DATABASE") !== ' . var_export($this->database, true) . ') { exit("wrong database"); }'
            . 'foreach (' . var_export($session, true) . ' as $k => $v) { TSession::setValue($k, $v); }'
            . '$_GET = ' . var_export($get, true) . ';'
            . '$_POST = ' . var_export($post, true) . ';'
            . '$_REQUEST = array_merge($_GET, $_POST);'
            . '$out = [];'
            . 'ob_start();'
            . 'try { $page = new EncounterView($_REQUEST); $page->show(); }'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . '$out["timeout_message"] = CvFormat::userError(new CentralVet\Domain\Exception\AiUnavailableException("ai_timeout"));'
            . 'echo ' . var_export(self::MARKER, true) . ', json_encode($out);';

        $env = [
            'DB_DATABASE' => $this->database,
            'AI_PROVIDER' => 'stub',
            'AI_STUB_BEHAVIOR' => $stubBehavior,
            'APP_ENV' => 'testing',
            'ANTHROPIC_API_KEY' => '',
        ];
        $prefix = 'env';

        foreach ($env as $name => $value) {
            $prefix .= ' ' . $name . '=' . escapeshellarg($value);
        }

        $out = (string) shell_exec($prefix . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'EncounterView subprocess output: ' . substr($out, 0, 2000));

        return $decoded;
    }
}
