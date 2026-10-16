<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\SkippedTestException;
use CentralVet\Tests\Support\TestDatabase;

/**
 * Fase 8B, T-14: AiCopilotPanel (cortina lateral do copiloto).
 *
 * O controller só carrega com o Adianti, num processo PHP separado
 * (init.php). A subclasse de teste troca os pontos de extensão: contexto
 * de tenant fixo, transação na conexão própria do subprocesso (sempre
 * desfeita no fim) e serviço montado com os repositórios PDO reais, RBAC
 * liberado, rate limit em memória e um StubAiGateway roteirizado
 * (uma rodada de `obter_paciente` e a resposta final).
 */
final class AiCopilotPanelIntegrationTest extends MysqlIntegrationTestCase
{
    private const MARKER = '__T14_PANEL__';
    private const ANSWER = 'F8B teste resposta do copiloto';

    public function setUp(): void
    {
        parent::setUp();

        foreach (['ai_conversation', 'ai_conversation_message', 'mcp_tool_call'] as $table) {
            if (!(bool) $this->pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn()) {
                throw new SkippedTestException("Migration 0015 not applied to the test database ({$table})");
            }
        }
    }

    public function testOpenClinicalWithAnchorShowsTitleQuestionAndLiveRegion(): void
    {
        $out = $this->runPanel(
            '$page = new AiCopilotPanelT14(["method" => "onOpen"]);'
            . '$page->onOpen(["family" => "clinical", "anchor_patient_id" => $patientId]); $page->show();'
        );

        $html = (string) $out['html'];

        Assert::stringContains('Copiloto clínico', $html, 'clinical title in pt');
        Assert::stringContains('Sua pergunta', $html, 'question label in pt');
        Assert::stringContains('Resposta gerada por IA a partir dos dados do sistema. Confira antes de usar.', $html, 'AI notice');
        Assert::stringContains('adianti_target_container="adianti_right_panel"', $html, 'opens in the right panel');
        Assert::true(preg_match('/<div[^>]*id="cv-ai-copilot-status"[^>]*>/', $html, $status) === 1, 'live region rendered');
        Assert::stringContains('role="status"', $status[0], 'live region has role=status');
        Assert::stringContains('aria-live="polite"', $status[0], 'live region is polite');
        Assert::stringContains('name="form_AiCopilotPanel"', $html, 'question form rendered');
        Assert::stringContains('data-thinking-label="Pensando…"', $html, 'send button carries the thinking label');
        Assert::true(preg_match('/name="submit_token"[^>]*value="[0-9a-f]{32}"|value="[0-9a-f]{32}"[^>]*name="submit_token"/', $html) === 1, 'hidden submit_token of 32 hex chars');
        Assert::stringContains('app/templates/adminbs5/js/cv-ai-copilot.js', $html, 'panel includes its own JS');
        Assert::stringContains('Conversas anteriores', $html, 'previous conversations section');
    }

    public function testAskStoresTwoMessagesAndShowsTheAnswerWithConsulted(): void
    {
        $out = $this->runPanel(
            '$page = new AiCopilotPanelT14(["method" => "onAsk"]);'
            . '$page->onAsk(["family" => "clinical", "anchor_patient_id" => (string) $patientId, "conversation_id" => "",'
            . ' "question" => "Como está o paciente?", "submit_token" => str_repeat("a", 32)]); $page->show();'
            . '$conversationId = (int) $count("SELECT MAX(id) FROM ai_conversation WHERE tenant_id = :t");'
            . '$out["conversation_id"] = $conversationId;'
            . '$s = $pdo->prepare("SELECT COUNT(*) FROM ai_conversation_message WHERE conversation_id = :c"); $s->execute(["c" => $conversationId]);'
            . '$out["messages"] = (int) $s->fetchColumn();'
        );

        $html = (string) $out['html'];

        Assert::true($out['conversation_id'] > 0, 'conversation created');
        Assert::same(2, $out['messages'], 'question and answer stored');
        Assert::stringContains(self::ANSWER, $html, 'answer of the stub is shown');
        Assert::stringContains('Consultei', $html, '"Consultei" under the answer');
        Assert::stringContains('Apagar conversa', $html, 'delete action for the current conversation');
        Assert::stringContains('Nova conversa', $html, 'new conversation action');
    }

    public function testRepeatedSubmitTokenAsksOnlyOnce(): void
    {
        $out = $this->runPanel(
            '$post = ["family" => "clinical", "anchor_patient_id" => (string) $patientId, "conversation_id" => "",'
            . ' "question" => "Como está o paciente?", "submit_token" => str_repeat("b", 32)];'
            . '$page = new AiCopilotPanelT14(["method" => "onAsk"]); $page->onAsk($post);'
            . '$page = new AiCopilotPanelT14(["method" => "onAsk"]); $page->onAsk($post); $page->show();'
            . '$out["interactions"] = $count("SELECT COUNT(*) FROM ai_interaction WHERE tenant_id = :t");'
            . '$out["conversations"] = $count("SELECT COUNT(*) FROM ai_conversation WHERE tenant_id = :t");'
            . '$out["messages"] = $count("SELECT COUNT(*) FROM ai_conversation_message WHERE tenant_id = :t");'
        );

        Assert::same(1, $out['interactions'], 'one ai_interaction for two submits with the same token');
        Assert::same(1, $out['conversations']);
        Assert::same(2, $out['messages']);
        Assert::stringContains(self::ANSWER, (string) $out['html'], 'the repeated submit re-renders the conversation');
    }

    public function testDeleteRemovesTheConversation(): void
    {
        $out = $this->runPanel(
            '$page = new AiCopilotPanelT14(["method" => "onAsk"]);'
            . '$page->onAsk(["family" => "clinical", "anchor_patient_id" => (string) $patientId, "conversation_id" => "",'
            . ' "question" => "Como está o paciente?", "submit_token" => str_repeat("c", 32)]);'
            . '$conversationId = (int) $count("SELECT MAX(id) FROM ai_conversation WHERE tenant_id = :t");'
            . '$out["before"] = $count("SELECT COUNT(*) FROM ai_conversation WHERE tenant_id = :t");'
            . '$page = new AiCopilotPanelT14(["method" => "onDelete"]);'
            . '$page->onDelete(["conversation_id" => (string) $conversationId, "family" => "clinical", "anchor_patient_id" => (string) $patientId]); $page->show();'
            . '$s = $pdo->prepare("SELECT COUNT(*) FROM ai_conversation WHERE id = :c"); $s->execute(["c" => $conversationId]);'
            . '$out["after"] = (int) $s->fetchColumn();'
        );

        Assert::same(1, $out['before']);
        Assert::same(0, $out['after'], 'conversation deleted');
        Assert::stringContains('Conversa apagada', (string) $out['html'], 'deletion is confirmed');
    }

    public function testAnchorOutsideTheUnitShowsPatientNotFound(): void
    {
        $out = $this->runPanel(
            '$page = new AiCopilotPanelT14(["method" => "onOpen"]);'
            . '$page->onOpen(["family" => "clinical", "anchor_patient_id" => $otherPatientId]); $page->show();'
        );

        $html = (string) $out['html'];

        Assert::stringContains('Paciente não encontrado', $html, 'patient not found message');
        Assert::false(str_contains($html, 'form_AiCopilotPanel'), 'no question form for an anchor outside the unit');
        Assert::false(str_contains($html, 'Copiloto clínico'), 'the panel is not rendered');
    }

    public function testDisabledAiShowsTheReasonWithoutTheForm(): void
    {
        $out = $this->runPanel(
            '$pdo->prepare("UPDATE feature_flag SET enabled = 0 WHERE tenant_id = :t AND flag_key = \'ai_assistant\'")->execute(["t" => $tenantId]);'
            . '$page = new AiCopilotPanelT14(["method" => "onOpen"]);'
            . '$page->onOpen(["family" => "clinical", "anchor_patient_id" => $patientId]); $page->show();'
            . '$out["reason"] = _t("The AI assistant is turned off for this clinic.");'
        );

        $html = (string) $out['html'];

        Assert::stringContains((string) $out['reason'], $html, 'the 8A reason is shown');
        Assert::false(str_contains($html, 'form_AiCopilotPanel'), 'no question form while unavailable');
    }

    public function testButtonsAreTouchTargetsAndNothingIsCenteredOrRightAligned(): void
    {
        $out = $this->runPanel(
            '$page = new AiCopilotPanelT14(["method" => "onAsk"]);'
            . '$page->onAsk(["family" => "clinical", "anchor_patient_id" => (string) $patientId, "conversation_id" => "",'
            . ' "question" => "Como está o paciente?", "submit_token" => str_repeat("d", 32)]); $page->show();'
            . '$out["link"] = AiCopilotPanel::linkFor("clinical", 7);'
            . '$out["link_management"] = AiCopilotPanel::linkFor("management", null);'
        );

        $html = (string) $out['html'];

        preg_match_all('/<(button|a)\b[^>]*>/', $html, $controls);
        Assert::true(count($controls[0]) >= 3, 'send, new and delete controls rendered');
        foreach ($controls[0] as $control) {
            Assert::stringContains('cv-touch-target', $control, 'every button/link carries cv-touch-target: ' . $control);
        }
        Assert::same(0, preg_match_all('/text-align:\s*(right|center)/', $html), 'rendered panel has no right/center alignment');

        $src = dirname(__DIR__, 2);
        $source = (string) file_get_contents($src . '/app/control/clinic/AiCopilotPanel.php');
        Assert::same(0, preg_match_all('/text-align:\s*(right|center)/', $source), 'controller source has no right/center alignment');

        $css = (string) file_get_contents($src . '/app/templates/adminbs5/cv-components.css');
        Assert::true(preg_match_all('/\.cv-ai-copilot[^{]*\{[^}]*\}/', $css, $rules) > 0, '.cv-ai-copilot* rules exist');
        foreach ($rules[0] as $rule) {
            Assert::same(0, preg_match('/text-align:\s*(right|center)/', $rule), 'copilot CSS has no right/center alignment: ' . $rule);
        }

        $js = (string) file_get_contents($src . '/app/templates/adminbs5/js/cv-ai-copilot.js');
        Assert::stringContains('disabled', $js, 'JS disables the send button');
        Assert::stringContains('aria-busy', $js, 'JS marks the form busy');
        Assert::stringContains('data-thinking-label', $js, 'JS announces the thinking label');

        Assert::same('index.php?class=AiCopilotPanel&method=onOpen&family=clinical&register_state=false&anchor_patient_id=7', $out['link']);
        Assert::same('index.php?class=AiCopilotPanel&method=onOpen&family=management&register_state=false', $out['link_management']);
    }

    /**
     * Roda $body depois do init.php, com o tenant semeado na conexão do
     * subprocesso (transação sempre desfeita). Em escopo: $pdo, $tenantId,
     * $patientId (com atendimento na unidade), $otherPatientId (sem vínculo
     * com a unidade) e $count(sql) (parâmetro :t = tenant). Devolve $out
     * com o HTML impresso em `html`.
     *
     * @return array<string, mixed>
     */
    private function runPanel(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_PORT') ?: '3306',
            TestDatabase::resolveName(getenv()),
        );

        $subclasses = 'if (!class_exists("AiCopilotPanel")) { $out["missing"] = true; } else {'
            . 'final class T14 {'
            . ' public static PDO $pdo; public static CentralVet\Tenancy\TenantContext $context;'
            . ' public static ?CentralVet\Ai\StubAiGateway $gateway = null;'
            . ' public static function service(CentralVet\Tenancy\TenantContext $c, string $locale): CentralVet\Application\AiCopilotService {'
            . '  self::$gateway ??= CentralVet\Ai\StubAiGateway::scripted(['
            . '   new CentralVet\Domain\AiCompletionResult("", "fake", "claude-sonnet-5-5", new CentralVet\Domain\AiUsage(10, 5), 15, "tool_use", "req-1",'
            . '    [["id" => "tu_1", "name" => "obter_paciente", "input" => []]], [["type" => "tool_use", "id" => "tu_1", "name" => "obter_paciente", "input" => []]]),'
            . '   new CentralVet\Domain\AiCompletionResult("' . self::ANSWER . '", "fake", "claude-sonnet-5-5", new CentralVet\Domain\AiUsage(20, 8), 28, "end_turn", "req-2",'
            . '    [], [["type" => "text", "text" => "' . self::ANSWER . '"]]),'
            . '  ]);'
            . '  $clinical = new CentralVet\Persistence\CopilotClinicalQuery($c, self::$pdo);'
            . '  $flags = new CentralVet\Persistence\FeatureFlagRepository($c, self::$pdo);'
            . '  $auth = new CentralVet\Tests\Support\FakeAuthorizationPolicy(true);'
            . '  $registry = new CentralVet\Ai\Tools\ToolRegistry(CentralVet\Ai\Tools\Clinical\ClinicalToolset::create($clinical), $flags, $auth, $c);'
            . '  $policy = new CentralVet\Ai\Tools\ToolPolicy($registry, new CentralVet\Ai\Tools\JsonSchemaValidator(),'
            . '   new CentralVet\Persistence\McpToolCallRepository($c, self::$pdo),'
            . '   new CentralVet\Ai\Tools\ToolRateLimiter(new CentralVet\Security\LoginRateLimiter(new CentralVet\Tests\Support\FakeRedis(), 30, 600, "t14:")), null);'
            . '  return new CentralVet\Application\AiCopilotService(self::$gateway,'
            . '   new CentralVet\Persistence\AiInteractionRepository($c, self::$pdo), new CentralVet\Persistence\AiTenantSettingRepository($c, self::$pdo),'
            . '   $flags, new CentralVet\Persistence\AiConversationRepository($c, self::$pdo), $registry, $policy, $clinical, $auth, null, $c, $locale); }'
            . '}'
            . 'class AiCopilotPanelT14 extends AiCopilotPanel {'
            . ' protected static function resolveTenantContext(): CentralVet\Tenancy\TenantContext { return T14::$context; }'
            . ' protected static function makeService(CentralVet\Tenancy\TenantContext $context, string $locale): CentralVet\Application\AiCopilotService { return T14::service($context, $locale); }'
            . ' protected static function transaction(callable $fn): mixed { return $fn(T14::$pdo); }'
            . '}';

        $seed = '$unitId = (int) $pdo->query("SELECT MIN(id) FROM system_unit")->fetchColumn();'
            . '$userId = (int) $pdo->query("SELECT MIN(id) FROM system_users")->fetchColumn();'
            . '$slug = "f8b-t14-" . bin2hex(random_bytes(4));'
            . '$pdo->prepare("INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, \'active\')")'
            . '->execute(["slug" => $slug, "name" => "F8B teste tenant (" . $slug . ")"]);'
            . '$tenantId = (int) $pdo->lastInsertId();'
            . '$pdo->prepare("INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), \'F8B teste tutor\', \'11900000000\')")->execute(["t" => $tenantId]);'
            . '$tutorId = (int) $pdo->lastInsertId();'
            . '$pdo->prepare("INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, \'F8B teste Rex\', \'canino\')")'
            . '->execute(["t" => $tenantId, "tutor" => $tutorId]);'
            . '$patientId = (int) $pdo->lastInsertId();'
            . '$pdo->prepare("INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, \'F8B teste Mia\', \'felino\')")'
            . '->execute(["t" => $tenantId, "tutor" => $tutorId]);'
            . '$otherPatientId = (int) $pdo->lastInsertId();'
            . '$pdo->prepare("INSERT INTO encounter (tenant_id, system_unit_id, patient_id, professional_system_user_id, status, started_at)'
            . ' VALUES (:t, :u, :p, :user, \'in_progress\', NOW(6))")->execute(["t" => $tenantId, "u" => $unitId, "p" => $patientId, "user" => $userId]);'
            . '$pdo->prepare("INSERT INTO feature_flag (tenant_id, flag_key, enabled, updated_by_system_user_id) VALUES (:t, \'ai_assistant\', 1, :user)")'
            . '->execute(["t" => $tenantId, "user" => $userId]);'
            . '$pdo->prepare("INSERT INTO ai_tenant_setting (tenant_id, terms_version, terms_accepted_at, terms_accepted_by_system_user_id, monthly_token_quota, updated_by_system_user_id)'
            . ' VALUES (:t, :v, NOW(6), :user, 300000, :user2)")->execute(["t" => $tenantId, "v" => CentralVet\Domain\AiTerms::VERSION, "user" => $userId, "user2" => $userId]);'
            . 'T14::$pdo = $pdo; T14::$context = CentralVet\Tenancy\TenantContext::authenticated($tenantId, $userId, $unitId);'
            . '$count = static function (string $sql) use ($pdo, $tenantId): int { $s = $pdo->prepare($sql); $s->execute(["t" => $tenantId]); return (int) $s->fetchColumn(); };';

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . 'ApplicationTranslator::setLanguage("pt");'
            . 'TSession::setValue("programs", ["AiCopilotPanel" => true]);'
            . '$out = [];'
            . '$pdo = new PDO(' . var_export($dsn, true) . ', (string) (getenv("DB_USERNAME") ?: "centralvet"),'
            . ' (string) (getenv("DB_PASSWORD") ?: ""), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            . '$pdo->beginTransaction();'
            . 'ob_start();'
            . 'try { ' . $subclasses . $seed . $body . ' } }'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . ' finally { $pdo->rollBack(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo "' . self::MARKER . '", json_encode($out);';

        $env = 'env AI_PROVIDER=stub APP_ENV=testing ANTHROPIC_API_KEY=';
        $raw = (string) shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($raw, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($raw, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'AiCopilotPanel subprocess output: ' . substr($raw, -500));
        Assert::true(!isset($decoded['missing']), 'AiCopilotPanel must exist');
        Assert::true(!isset($decoded['error']), 'subprocess threw: ' . (string) ($decoded['error'] ?? ''));

        return $decoded;
    }
}
