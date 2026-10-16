<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;
use CentralVet\Tests\Support\TestDatabase;

/**
 * Fase 8B, T-15: "Minhas conversas" (AiConversationList), auditoria de
 * ferramentas (AiToolCallList) e rótulos do copiloto nas telas da 8A
 * (AiInteractionList / AiSettingsForm).
 *
 * Os controllers só carregam com o Adianti, num processo PHP separado
 * (init.php define _t() global), apontado para o banco de teste
 * (DB_DATABASE = TestDatabase::resolveName), com AI_PROVIDER=stub e
 * RATE_LIMIT_DRIVER=files — sem rede nem Redis. Como o subprocesso abre as
 * próprias transações, as fixtures são gravadas de fato num tenant
 * descartável ("F8B teste") e apagadas em tearDown(), com o usuário V
 * criado só para o teste.
 */
final class AiCopilotListsIntegrationTest
{
    private const MARKER = '@@AICOPILOTLISTS@@';
    private const INPUT = '{"termo":"Thor"}';
    private const MESSAGE = 'F8B teste texto sigiloso da conversa';

    private ?\PDO $pdo = null;
    private string $database = '';
    private int $tenantId = 0;
    private int $unitA = 0;
    private int $unitB = 0;
    private int $userU = 0;
    private int $userV = 0;
    private int $conversationU = 0;
    private int $conversationV = 0;
    private int $conversationB = 0;

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

        if (!(bool) $this->pdo->query("SHOW TABLES LIKE 'mcp_tool_call'")->fetchColumn()) {
            throw new SkippedTestException('Migration 0015 not applied to the test database');
        }

        $units = array_map('intval', $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        $this->userU = (int) $this->pdo->query('SELECT id FROM system_users ORDER BY id LIMIT 1')->fetchColumn();
        Assert::true(count($units) === 2 && $this->userU > 0, 'Fixture requires two system_unit rows and one system_users row');
        [$this->unitA, $this->unitB] = $units;

        $slug = 'f8b-teste-' . bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, :status)')
            ->execute(['slug' => $slug, 'name' => 'F8B teste tenant (' . $slug . ')', 'status' => 'active']);
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $this->userV = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_users')->fetchColumn() + random_int(1000, 9999);
        $this->pdo->prepare('INSERT INTO system_users (id, name, login, active) VALUES (:id, :name, :login, :active)')
            ->execute(['id' => $this->userV, 'name' => 'F8B teste usuário V', 'login' => $slug . '-v', 'active' => 'Y']);

        $this->conversationU = $this->conversation($this->unitA, $this->userU, 'F8B teste conversa do U');
        $this->conversationV = $this->conversation($this->unitA, $this->userV, 'F8B teste conversa do V');
        $this->conversationB = $this->conversation($this->unitB, $this->userU, 'F8B teste conversa da unidade B');

        $this->pdo->prepare(
            'INSERT INTO ai_conversation_message (tenant_id, conversation_id, role, content) VALUES (:tenant_id, :conversation_id, :role, :content)',
        )->execute(['tenant_id' => $this->tenantId, 'conversation_id' => $this->conversationU, 'role' => 'user', 'content' => self::MESSAGE]);

        $this->toolCall($this->userU, 'buscar_paciente', 'allowed', null, 'f8b-corr-allowed', null);
        $this->toolCall($this->userU, 'obter_paciente', 'denied', 'not_found', 'f8b-corr-denied', null);
        $this->toolCall($this->userV, 'obter_exames', 'denied', 'forbidden', 'f8b-corr-user-v', null);
        $this->toolCall($this->userU, 'obter_paciente', 'denied', 'invalid_input', 'f8b-corr-old', '2026-01-10 10:00:00');
    }

    public function tearDown(): void
    {
        if ($this->pdo === null || $this->tenantId <= 0) {
            return;
        }

        foreach (['mcp_tool_call', 'ai_conversation_message', 'ai_interaction', 'ai_conversation', 'audit_log', 'feature_flag', 'ai_tenant_setting'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE tenant_id = :tenant_id")->execute(['tenant_id' => $this->tenantId]);
        }

        $this->pdo->prepare('DELETE FROM tenant WHERE id = :id')->execute(['id' => $this->tenantId]);
        $this->pdo->prepare('DELETE FROM system_users WHERE id = :id')->execute(['id' => $this->userV]);
        $this->tenantId = 0;
    }

    public function testConversationListShowsOnlyOwnConversationsOfTheActiveUnit(): void
    {
        $result = $this->run('AiConversationList', []);
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::stringContains('Minhas conversas', $html, 'page title in pt');
        Assert::stringContains('cv-table', $html, 'conversations render in a cv-table');
        Assert::stringContains('F8B teste conversa do U', $html, 'own conversation of the active unit is listed');
        Assert::false(str_contains($html, 'F8B teste conversa do V'), 'conversation of another user must not be listed');
        Assert::false(str_contains($html, 'F8B teste conversa da unidade B'), 'conversation of another unit must not be listed');
        Assert::false(str_contains($html, self::MESSAGE), 'message content is never listed');
        Assert::stringContains('conversation_id=' . $this->conversationU, $html, 'open link carries the conversation id');
        Assert::stringContains('class=AiCopilotPanel', $html, 'open link goes to the copilot panel');
        Assert::true(preg_match('/<a [^>]*conversation_id=' . $this->conversationU . '[^>]*>/', $html, $link) === 1, 'open link rendered');
        Assert::stringContains('cv-touch-target', $link[0], 'open link is a touch target');
    }

    public function testConversationListShowsEmptyState(): void
    {
        $this->pdo->prepare('DELETE FROM ai_conversation WHERE id = :id')->execute(['id' => $this->conversationU]);

        $result = $this->run('AiConversationList', []);

        Assert::stringContains('cv-state--empty', (string) $result['html'], 'empty list renders cv-state--empty');
        Assert::stringContains('Nenhuma conversa ainda', (string) $result['html'], 'empty state text in pt');
    }

    public function testDeleteRemovesTheOwnConversation(): void
    {
        $result = $this->run('AiConversationList', ['method' => 'onDelete', 'conversation_id' => (string) $this->conversationU, 'delete' => '1']);

        Assert::true(!isset($result['error']), 'delete threw: ' . (string) ($result['error'] ?? ''));
        Assert::same(0, $this->count('ai_conversation', $this->conversationU), 'own conversation is deleted');
        Assert::same(0, (int) $this->scalar('SELECT COUNT(*) FROM ai_conversation_message WHERE conversation_id = ?', [$this->conversationU]), 'its messages go with it');
        Assert::stringContains('Conversa apagada', (string) $result['html'], 'deletion is confirmed in pt');
    }

    public function testDeleteWithoutConfirmationAsksAndKeepsTheConversation(): void
    {
        $result = $this->run('AiConversationList', ['method' => 'onDelete', 'conversation_id' => (string) $this->conversationU]);

        Assert::true(!isset($result['error']), 'delete threw: ' . (string) ($result['error'] ?? ''));
        Assert::same(1, $this->count('ai_conversation', $this->conversationU), 'nothing is deleted before the confirmation');
    }

    public function testDeletingConversationOfThePreviousUnitShowsNotFound(): void
    {
        $result = $this->run('AiConversationList', ['method' => 'onDelete', 'conversation_id' => (string) $this->conversationB, 'delete' => '1']);

        Assert::stringContains('Conversa não encontrada', (string) $result['html'], 'conversation of the previous unit is "not found"');
        Assert::same(1, $this->count('ai_conversation', $this->conversationB), 'conversation of the previous unit stays');
    }

    public function testDeletingConversationOfAnotherUserShowsNotFound(): void
    {
        $result = $this->run('AiConversationList', ['method' => 'onDelete', 'conversation_id' => (string) $this->conversationV, 'delete' => '1']);

        Assert::stringContains('Conversa não encontrada', (string) $result['html'], 'conversation of another user is "not found"');
        Assert::same(1, $this->count('ai_conversation', $this->conversationV), 'conversation of another user stays');
    }

    public function testHistoryWorksWithoutAiConfiguration(): void
    {
        $this->assertHistoryWorksWith(['AI_PROVIDER' => 'f8b-not-configured']);
    }

    public function testHistoryWorksWhenTheToolLimiterFails(): void
    {
        $this->assertHistoryWorksWith(['RATE_LIMIT_DRIVER' => 'f8b-broken']);
    }

    /** @param array<string, string> $env */
    private function assertHistoryWorksWith(array $env): void
    {
        $list = $this->run('AiConversationList', [], [], null, '', $env);
        Assert::true(!isset($list['error']), 'render threw: ' . (string) ($list['error'] ?? ''));
        Assert::stringContains('F8B teste conversa do U', (string) $list['html'], 'own conversation is listed without gateway/limiter');
        Assert::false(str_contains((string) $list['html'], 'ai_not_configured'), 'no raw error code on screen');

        $deleted = $this->run('AiConversationList', ['method' => 'onDelete', 'conversation_id' => (string) $this->conversationU, 'delete' => '1'], [], null, '', $env);
        Assert::true(!isset($deleted['error']), 'delete threw: ' . (string) ($deleted['error'] ?? ''));
        Assert::same(0, $this->count('ai_conversation', $this->conversationU), 'own conversation is deleted without gateway/limiter');

        $foreign = $this->run('AiConversationList', ['method' => 'onDelete', 'conversation_id' => (string) $this->conversationB, 'delete' => '1'], [], null, '', $env);
        Assert::stringContains('Conversa não encontrada', (string) $foreign['html'], 'conversation of another unit stays "not found"');
        Assert::same(1, $this->count('ai_conversation', $this->conversationB), 'conversation of another unit stays');

        $audit = $this->run('AiToolCallList', [], ['decision' => 'denied'], null, '', $env);
        Assert::stringContains('f8b-corr-denied', (string) $audit['html'], 'tool audit works without gateway/limiter');
    }

    public function testConversationListWithoutTheProgramShowsTranslatedDenial(): void
    {
        $result = $this->run('AiConversationList', [], [], ['AiConversationList' => true]);

        Assert::stringContains('Permissão negada', (string) $result['html'], 'denial is translated');
        Assert::false(str_contains((string) $result['html'], 'F8B teste conversa do U'), 'denied user sees no conversation');
    }

    /** Review Focus: conversa da unidade anterior aberta depois da troca de unidade → "Conversa não encontrada". */
    public function testOpeningConversationOfThePreviousUnitFromTheListShowsNotFound(): void
    {
        $this->assertOpenShowsNotFound($this->conversationB, 'F8B teste conversa da unidade B', 'F8B teste mensagem sigilosa da unidade B');
    }

    public function testOpeningConversationOfAnotherUserShowsNotFound(): void
    {
        $this->assertOpenShowsNotFound($this->conversationV, 'F8B teste conversa do V', 'F8B teste mensagem sigilosa do V');
    }

    private function assertOpenShowsNotFound(int $conversationId, string $title, string $content): void
    {
        $this->pdo->prepare(
            'INSERT INTO ai_conversation_message (tenant_id, conversation_id, role, content) VALUES (:tenant_id, :conversation_id, :role, :content)',
        )->execute(['tenant_id' => $this->tenantId, 'conversation_id' => $conversationId, 'role' => 'user', 'content' => $content]);

        // IA disponível: sem isso o painel para em "indisponível" antes de buscar a conversa
        $this->pdo->prepare('INSERT INTO feature_flag (tenant_id, flag_key, enabled, updated_by_system_user_id) VALUES (:tenant_id, :key, 1, :user_id)')
            ->execute(['tenant_id' => $this->tenantId, 'key' => 'ai_assistant', 'user_id' => $this->userU]);
        $this->pdo->prepare(
            'INSERT INTO ai_tenant_setting (tenant_id, terms_version, terms_accepted_at, terms_accepted_by_system_user_id, monthly_token_quota, updated_by_system_user_id)
             VALUES (:tenant_id, :version, NOW(6), :user_id, 300000, :user_id2)',
        )->execute(['tenant_id' => $this->tenantId, 'version' => \CentralVet\Domain\AiTerms::VERSION, 'user_id' => $this->userU, 'user_id2' => $this->userU]);

        // sessão já trocada para a unidade A (a conversa B foi criada antes da troca)
        $result = $this->run('AiCopilotPanel', ['method' => 'onOpen', 'family' => 'clinical', 'conversation_id' => (string) $conversationId, 'register_state' => 'false']);
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'open threw: ' . (string) ($result['error'] ?? ''));
        Assert::stringContains('Conversa não encontrada', $html, 'foreign conversation is "not found"');
        Assert::false(str_contains($html, $content), 'no message of the foreign conversation is rendered');
        Assert::false(str_contains($html, $title), 'the foreign conversation title is not rendered');
    }

    public function testToolCallListFiltersDeniedAndNeverShowsInputNorConversationText(): void
    {
        $result = $this->run('AiToolCallList', [], ['decision' => 'denied']);
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::stringContains('Auditoria de ferramentas', $html, 'page title in pt');
        Assert::stringContains('cv-table', $html, 'calls render in a cv-table');
        Assert::stringContains('f8b-corr-denied', $html, 'denied call is listed');
        Assert::stringContains('f8b-corr-user-v', $html, 'denied call of another user is listed');
        Assert::false(str_contains($html, 'f8b-corr-allowed'), 'allowed call is filtered out');
        Assert::false(str_contains($html, 'Thor'), 'input_minimized is never shown');
        Assert::false(str_contains($html, '&quot;termo&quot;') || str_contains($html, '"termo"'), 'input_minimized is never shown');
        Assert::false(str_contains($html, self::MESSAGE), 'conversation content is never shown');
        Assert::stringContains('not_found', $html, 'deny reason column');
    }

    public function testToolCallListFiltersByUserToolAndPeriod(): void
    {
        $byUser = (string) $this->run('AiToolCallList', [], ['user_id' => (string) $this->userV])['html'];
        Assert::stringContains('f8b-corr-user-v', $byUser, 'user filter keeps the user calls');
        Assert::false(str_contains($byUser, 'f8b-corr-denied'), 'user filter drops other users');

        $byTool = (string) $this->run('AiToolCallList', [], ['tool_name' => 'buscar_paciente'])['html'];
        Assert::stringContains('f8b-corr-allowed', $byTool, 'tool filter keeps the tool calls');
        Assert::false(str_contains($byTool, 'f8b-corr-denied'), 'tool filter drops other tools');

        $byPeriod = (string) $this->run('AiToolCallList', [], ['date_from' => '01/01/2026', 'date_to' => '31/01/2026'])['html'];
        Assert::stringContains('f8b-corr-old', $byPeriod, 'period filter keeps the calls of the period');
        Assert::false(str_contains($byPeriod, 'f8b-corr-allowed'), 'period filter drops calls outside the period');

        $empty = (string) $this->run('AiToolCallList', [], ['date_from' => '2025-01-01', 'date_to' => '2025-01-31'])['html'];
        Assert::stringContains('Nenhuma chamada de ferramenta no período', $empty, 'empty period shows the empty state');
    }

    public function testToolCallListWithoutTheProgramIsDenied(): void
    {
        $result = $this->run('AiToolCallList', [], [], ['AiCopilotPanel' => true]);

        Assert::false(str_contains((string) $result['html'], 'f8b-corr-denied'), 'denied manager sees no call');
    }

    public function testInteractionListShowsAnsweredCopilotInteraction(): void
    {
        $this->pdo->prepare(
            'INSERT INTO ai_interaction (tenant_id, system_unit_id, system_user_id, purpose, subject_type, subject_id, prompt_version,
             provider, model, status, input_sha256, correlation_id)
             VALUES (:tenant_id, :unit_id, :user_id, :purpose, :subject_type, :subject_id, :prompt_version, :provider, :model, :status, :sha, :corr)',
        )->execute([
            'tenant_id' => $this->tenantId,
            'unit_id' => $this->unitA,
            'user_id' => $this->userU,
            'purpose' => 'copilot_clinical',
            'subject_type' => 'ai_conversation',
            'subject_id' => $this->conversationU,
            'prompt_version' => 'copilot_clinical.v1',
            'provider' => 'stub',
            'model' => 'stub',
            'status' => 'answered',
            'sha' => str_repeat('a', 64),
            'corr' => 'f8b-corr-interaction',
        ]);

        $html = (string) $this->run('AiInteractionList', [])['html'];

        Assert::stringContains('Respondida', $html, 'answered status label in pt');
        Assert::stringContains('Copiloto clínico', $html, 'copilot purpose label in pt');
    }

    public function testCopilotLabelsAndLeftAlignedSources(): void
    {
        $result = $this->run('AiSettingsForm', [], [], null, '$out["labels"] = [AiInteractionList::statusLabel("answered"), AiSettingsForm::purposeLabel("copilot_clinical"), AiSettingsForm::purposeLabel("copilot_management")];');

        Assert::same(['Respondida', 'Copiloto clínico', 'Copiloto de gestão'], $result['labels'] ?? null, 'copilot labels in pt');
        Assert::true(preg_match('/<a [^>]*href="index\.php\?class=AiToolCallList"[^>]*>/', (string) $result['html'], $link) === 1, 'settings link to the tool audit');
        Assert::stringContains('cv-touch-target', $link[0], 'tool audit link is a touch target');

        foreach (['AiConversationList', 'AiToolCallList'] as $class) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/control/clinic/' . $class . '.php');
            Assert::same(0, preg_match_all('/text-align:\s*(right|center)/', $source), $class . ' must not right/center-align anything inline');
        }
    }

    private function conversation(int $unitId, int $userId, string $title): int
    {
        $this->pdo->prepare(
            'INSERT INTO ai_conversation (tenant_id, system_unit_id, system_user_id, family, title) VALUES (:tenant_id, :unit_id, :user_id, :family, :title)',
        )->execute(['tenant_id' => $this->tenantId, 'unit_id' => $unitId, 'user_id' => $userId, 'family' => 'clinical', 'title' => $title]);

        return (int) $this->pdo->lastInsertId();
    }

    private function toolCall(int $userId, string $tool, string $decision, ?string $reason, string $correlation, ?string $createdAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO mcp_tool_call (tenant_id, system_unit_id, system_user_id, conversation_id, correlation_id, tool_name, input_minimized,
             decision, deny_reason, row_count, truncated, duration_ms, created_at)
             VALUES (:tenant_id, :unit_id, :user_id, :conversation_id, :corr, :tool, :input, :decision, :reason, 3, 0, 12, COALESCE(:created_at, CURRENT_TIMESTAMP(6)))',
        )->execute([
            'tenant_id' => $this->tenantId,
            'unit_id' => $this->unitA,
            'user_id' => $userId,
            'conversation_id' => $this->conversationU,
            'corr' => $correlation,
            'tool' => $tool,
            'input' => self::INPUT,
            'decision' => $decision,
            'reason' => $reason,
            'created_at' => $createdAt,
        ]);
    }

    private function count(string $table, int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM {$table} WHERE id = ?", [$id]);
    }

    /** @param list<scalar> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /**
     * Renderiza `$class` como o usuário U na unidade A.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post
     * @param array<string, bool>|null $programs
     * @param array<string, string> $envOverrides
     * @return array<string, mixed>
     */
    private function run(string $class, array $get, array $post = [], ?array $programs = null, string $extra = '', array $envOverrides = []): array
    {
        $src = dirname(__DIR__, 2);
        $get = array_merge(['class' => $class], $get);
        $session = [
            'logged' => true,
            'tenantid' => $this->tenantId,
            'userid' => $this->userU,
            'userunitid' => $this->unitA,
            'programs' => $programs ?? [
                'AiCopilotPanel' => true,
                'AiConversationList' => true,
                'AiToolCallList' => true,
                'AiInteractionList' => true,
                'AiSettingsForm' => true,
            ],
        ];

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . 'if (getenv("DB_DATABASE") !== ' . var_export($this->database, true) . ') { exit("wrong database"); }'
            . 'foreach (' . var_export($session, true) . ' as $k => $v) { TSession::setValue($k, $v); }'
            . '$_GET = ' . var_export($get, true) . ';'
            . '$_POST = ' . var_export($post, true) . ';'
            . '$_REQUEST = array_merge($_GET, $_POST);'
            . '$out = [];'
            . 'ob_start();'
            . 'try { if (!class_exists(' . var_export($class, true) . ')) { throw new RuntimeException("missing class ' . $class . '"); }'
            . ' $page = new ' . $class . '($_REQUEST);'
            . ' if (!empty($_GET["method"])) { $page->{$_GET["method"]}($_REQUEST); }'
            . ' $page->show(); ' . $extra . ' }'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo ' . var_export(self::MARKER, true) . ', json_encode($out);';

        $env = [
            'DB_DATABASE' => $this->database,
            'AI_PROVIDER' => 'stub',
            'APP_ENV' => 'testing',
            'ANTHROPIC_API_KEY' => '',
            'RATE_LIMIT_DRIVER' => 'files',
        ];
        $env = array_merge($env, $envOverrides);
        $prefix = 'env';

        foreach ($env as $name => $value) {
            $prefix .= ' ' . $name . '=' . escapeshellarg($value);
        }

        $out = (string) shell_exec($prefix . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), $class . ' subprocess output: ' . substr($out, 0, 2000));

        return $decoded;
    }
}
