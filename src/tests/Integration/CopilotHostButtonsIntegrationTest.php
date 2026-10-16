<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;
use CentralVet\Tests\Support\TestDatabase;

/**
 * Fase 8B, T-16: link "Copiloto" nas telas hospedeiras (PatientForm em
 * edição e EncounterView), abrindo a cortina do AiCopilotPanel com o
 * paciente como âncora. Só aparece com a IA disponível no tenant.
 *
 * Os controllers só carregam com o Adianti, num processo PHP separado
 * (init.php), apontado para o banco de teste e com AI_PROVIDER=stub — sem
 * rede. As fixtures são gravadas de fato num tenant descartável
 * ("F8B teste") e apagadas em tearDown().
 */
final class CopilotHostButtonsIntegrationTest
{
    private const MARKER = '@@COPILOTHOST@@';

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

        if (!(bool) $this->pdo->query("SHOW TABLES LIKE 'ai_conversation'")->fetchColumn()) {
            throw new SkippedTestException('Migration 0015 not applied to the test database');
        }

        $this->unitId = (int) $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $this->pdo->query('SELECT id FROM system_users ORDER BY id LIMIT 1')->fetchColumn();
        Assert::true($this->unitId > 0 && $this->userId > 0, 'Fixture requires system_unit and system_users rows');

        $slug = 'f8b-teste-' . bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, :status)')
            ->execute(['slug' => $slug, 'name' => 'F8B teste tenant (' . $slug . ')', 'status' => 'active']);
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :name, :phone)')
            ->execute(['tenant_id' => $this->tenantId, 'name' => 'F8B teste tutor', 'phone' => '11900000000']);
        $this->tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:tenant_id, :tutor_id, :name, :species)')
            ->execute(['tenant_id' => $this->tenantId, 'tutor_id' => $this->tutorId, 'name' => 'F8B teste Rex', 'species' => 'canino']);
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

    public function testPatientFormShowsCopilotLinkWhenAiIsAvailable(): void
    {
        $this->enableAi();

        $html = $this->render('PatientForm', ['method' => 'onEdit', 'key' => (string) $this->patientId]);

        $this->assertCopilotLink($html);
    }

    public function testPatientFormHidesCopilotLinkWhenAiIsDisabled(): void
    {
        $html = $this->render('PatientForm', ['method' => 'onEdit', 'key' => (string) $this->patientId]);

        Assert::true(str_contains($html, 'form_Patient'), 'patient form must still render');
        Assert::true(!str_contains($html, 'data-cv-copilot'), 'AI off: copilot link must not be rendered on PatientForm');
    }

    public function testNewPatientFormHasNoCopilotLink(): void
    {
        $this->enableAi();

        $html = $this->render('PatientForm', ['method' => 'onEdit', 'tutor_id' => (string) $this->tutorId]);

        Assert::true(!str_contains($html, 'data-cv-copilot'), 'new patient: copilot link must not be rendered');
    }

    public function testEncounterViewShowsCopilotLinkWhenAiIsAvailable(): void
    {
        $this->enableAi();

        $html = $this->render('EncounterView', ['method' => 'onReload', 'encounter_id' => (string) $this->encounterId]);

        $this->assertCopilotLink($html);
    }

    public function testEncounterViewHidesCopilotLinkWhenAiIsDisabled(): void
    {
        $html = $this->render('EncounterView', ['method' => 'onReload', 'encounter_id' => (string) $this->encounterId]);

        Assert::true(str_contains($html, 'anamnesis_text'), 'encounter form must still render');
        Assert::true(!str_contains($html, 'data-cv-copilot'), 'AI off: copilot link must not be rendered on EncounterView');
    }

    private function assertCopilotLink(string $html): void
    {
        $matched = preg_match('/<a\b[^>]*data-cv-copilot="clinical"[^>]*>/', $html, $tag);
        Assert::true($matched === 1, 'copilot link with data-cv-copilot="clinical" must be rendered');

        $tag = html_entity_decode($tag[0], ENT_QUOTES | ENT_HTML5);
        Assert::true(str_contains($tag, 'class=AiCopilotPanel'), 'copilot link must open AiCopilotPanel: ' . $tag);
        Assert::true(preg_match('/anchor_patient_id=' . $this->patientId . '(?!\d)/', $tag) === 1, 'copilot link must anchor the patient: ' . $tag);
        Assert::true(str_contains($tag, 'generator="adianti"'), 'copilot link must use the adianti generator: ' . $tag);
        Assert::true(str_contains($tag, 'cv-touch-target'), 'copilot link must be a 44px touch target: ' . $tag);
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

    /**
     * Runs the controller as the Adianti engine does (constructor, then
     * show() → run() calls the method) for an authenticated session of the
     * throwaway tenant.
     *
     * @param array<string, string> $get
     */
    private function render(string $class, array $get): string
    {
        $src = dirname(__DIR__, 2);
        $get = array_merge(['class' => $class], $get);
        $session = [
            'logged' => true,
            'tenantid' => $this->tenantId,
            'userid' => $this->userId,
            'userunitid' => $this->unitId,
            'programs' => [$class => true, 'AiCopilotPanel' => true],
        ];

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . 'if (getenv("DB_DATABASE") !== ' . var_export($this->database, true) . ') { exit("wrong database"); }'
            . 'foreach (' . var_export($session, true) . ' as $k => $v) { TSession::setValue($k, $v); }'
            . '$_GET = ' . var_export($get, true) . ';'
            . '$_POST = [];'
            . '$_REQUEST = $_GET;'
            . '$out = [];'
            . 'ob_start();'
            . 'try { $class = ' . var_export($class, true) . '; $page = new $class($_REQUEST); $page->show(); }'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo ' . var_export(self::MARKER, true) . ', json_encode($out);';

        $env = [
            'DB_DATABASE' => $this->database,
            'AI_PROVIDER' => 'stub',
            'AI_STUB_BEHAVIOR' => 'ok',
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

        Assert::true(is_array($decoded), $class . ' subprocess output: ' . substr($out, 0, 2000));
        Assert::true(!isset($decoded['error']), $class . ' render threw: ' . (string) ($decoded['error'] ?? ''));

        return (string) $decoded['html'];
    }
}
