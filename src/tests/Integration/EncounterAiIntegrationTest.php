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
            'ai_final_text' => '',
            'anamnesis_text' => 'Texto digitado',
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));

        $expected = 'Texto digitado' . "\n\n" . $interaction['suggestion_text'];
        Assert::same($expected, $this->encounterColumn('anamnesis_text'));
        Assert::same('accepted', $this->latestInteraction()['status']);

        // The reloaded form posts what its anamnesis textarea shows on the next autosave tick.
        $reloadedForm = $this->runView([]);
        $shown = $this->textareaValue((string) $reloadedForm['html'], 'anamnesis_text');
        Assert::same($expected, $shown, 'reloaded form must show the combined anamnesis');
        $this->runView(['method' => 'onAutosave'], ['anamnesis_text' => $shown]);
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
            'anamnesis_text' => 'Anamnese digitada nao salva',
            'diagnosis_text' => 'Diagnostico digitado',
        ]);
        Assert::true(!isset($decided['error']), 'decide threw: ' . (string) ($decided['error'] ?? ''));

        Assert::same($interaction['suggestion_text'], $this->encounterColumn('ai_summary_text'));
        Assert::same('Anamnese digitada nao salva', $this->encounterColumn('anamnesis_text'), 'typed anamnesis must survive accepting the summary');
        Assert::same('Diagnostico digitado', $this->encounterColumn('diagnosis_text'), 'other draft fields must survive accepting the summary');
    }

    public function testApplyingAnamnesisKeepsOtherUnsavedDraftFields(): void
    {
        $this->enableAi();

        $this->runView(['method' => 'onAiOrganizeAnamnesis'], ['ai_free_text' => 'Prurido', 'anamnesis_text' => 'Texto digitado']);
        $interaction = $this->latestInteraction();

        $this->runView(['method' => 'onAiDecide'], [
            'interaction_id' => (string) $interaction['id'],
            'decision' => 'accepted',
            'anamnesis_text' => 'Texto digitado',
            'physical_exam_text' => 'Exame digitado',
            'clinical_plan_text' => 'Plano digitado',
        ]);

        Assert::same('Texto digitado' . "\n\n" . $interaction['suggestion_text'], $this->encounterColumn('anamnesis_text'));
        Assert::same('Exame digitado', $this->encounterColumn('physical_exam_text'));
        Assert::same('Plano digitado', $this->encounterColumn('clinical_plan_text'));
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
        )->execute(['tenant_id' => $this->tenantId, 'version' => '2026-10-v1', 'user_id' => $this->userId, 'user_id2' => $this->userId]);
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
