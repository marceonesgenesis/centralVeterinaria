<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Ai\StubAiGateway;
use CentralVet\Ai\Tools\Clinical\ClinicalToolset;
use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\ToolPolicy;
use CentralVet\Ai\Tools\ToolRateLimiter;
use CentralVet\Ai\Tools\ToolRegistry;
use CentralVet\Application\AiCopilotService;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiConversationMessage;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\AiConversationRepositoryInterface;
use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Persistence\AiConversationRepository;
use CentralVet\Persistence\AiInteractionRepository;
use CentralVet\Persistence\AiTenantSettingRepository;
use CentralVet\Persistence\CopilotClinicalQuery;
use CentralVet\Persistence\FeatureFlagRepository;
use CentralVet\Persistence\McpToolCallRepository;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeRedis;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\SkippedTestException;
use CentralVet\Tests\Support\TestDatabase;
use RuntimeException;

/**
 * AiCopilotService::forContext() (T-13) wires the PDO repositories, the
 * clinical toolset, the tool policy and the gateway of the environment on a
 * real connection. A question with the stub provider (subprocess with
 * init.php and the `AiCopilotPanel` program in the session, own connection
 * in a transaction that is always rolled back) records one conversation,
 * two messages and one answered `copilot_clinical` interaction.
 *
 * T-28: the service opens no transaction (the caller hands a PDO in
 * autocommit); with the real repositories, the tool calls and the
 * interaction share the correlation id, and a failure writing the answer
 * still leaves exactly one interaction while no existing conversation is
 * lost.
 */
final class AiCopilotServiceForContextIntegrationTest extends MysqlIntegrationTestCase
{
    private const MARKER = '__T13_FOR_CONTEXT__';
    private const ENV = ['AI_PROVIDER', 'APP_ENV'];

    /** @var array<string, string|false> */
    private array $saved = [];

    public function setUp(): void
    {
        parent::setUp();

        CorrelationIdContext::clear();

        foreach (self::ENV as $name) {
            $this->saved[$name] = getenv($name);
        }

        foreach (['ai_conversation', 'ai_conversation_message', 'mcp_tool_call'] as $table) {
            if (!(bool) $this->pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn()) {
                throw new SkippedTestException("Migration 0015 not applied to the test database ({$table})");
            }
        }
    }

    public function tearDown(): void
    {
        CorrelationIdContext::clear();

        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    public function testForContextWiresTheServiceWithTheStubOutsideProduction(): void
    {
        putenv('AI_PROVIDER=stub');
        putenv('APP_ENV=testing');

        $service = AiCopilotService::forContext(TenantContext::authenticated(1, 1, 1), $this->pdo, 'pt');

        Assert::instanceOf(AiCopilotService::class, $service);
    }

    public function testAskRecordsTheConversationTwoMessagesAndOneAnsweredInteraction(): void
    {
        $out = $this->runAdianti(
            '$unitId = (int) $pdo->query("SELECT MIN(id) FROM system_unit")->fetchColumn();'
            . '$userId = (int) $pdo->query("SELECT MIN(id) FROM system_users")->fetchColumn();'
            . '$slug = "f8b-t13-" . bin2hex(random_bytes(4));'
            . '$pdo->prepare("INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, \'active\')")'
            . '->execute(["slug" => $slug, "name" => "F8B teste tenant (" . $slug . ")"]);'
            . '$tenantId = (int) $pdo->lastInsertId();'
            . '$pdo->prepare("INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), \'F8B teste tutor\', \'11900000000\')")->execute(["t" => $tenantId]);'
            . '$tutorId = (int) $pdo->lastInsertId();'
            . '$pdo->prepare("INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, \'F8B teste Rex\', \'canino\')")'
            . '->execute(["t" => $tenantId, "tutor" => $tutorId]);'
            . '$patientId = (int) $pdo->lastInsertId();'
            . '$pdo->prepare("INSERT INTO encounter (tenant_id, system_unit_id, patient_id, professional_system_user_id, status, started_at)'
            . ' VALUES (:t, :u, :p, :user, \'in_progress\', NOW(6))")->execute(["t" => $tenantId, "u" => $unitId, "p" => $patientId, "user" => $userId]);'
            . '$pdo->prepare("INSERT INTO feature_flag (tenant_id, flag_key, enabled, updated_by_system_user_id) VALUES (:t, \'ai_assistant\', 1, :user)")'
            . '->execute(["t" => $tenantId, "user" => $userId]);'
            . '$pdo->prepare("INSERT INTO ai_tenant_setting (tenant_id, terms_version, terms_accepted_at, terms_accepted_by_system_user_id, monthly_token_quota, updated_by_system_user_id)'
            . ' VALUES (:t, :v, NOW(6), :user, 300000, :user2)")->execute(["t" => $tenantId, "v" => CentralVet\Domain\AiTerms::VERSION, "user" => $userId, "user2" => $userId]);'
            . '$context = CentralVet\Tenancy\TenantContext::authenticated($tenantId, $userId, $unitId);'
            . '$answer = CentralVet\Application\AiCopilotService::forContext($context, $pdo, "pt")->ask("clinical", null, $patientId, "resumo");'
            . '$count = static function (string $sql) use ($pdo, $tenantId): int { $s = $pdo->prepare($sql); $s->execute(["t" => $tenantId]); return (int) $s->fetchColumn(); };'
            . '$interaction = $pdo->prepare("SELECT purpose, status, subject_type, subject_id FROM ai_interaction WHERE tenant_id = :t");'
            . '$interaction->execute(["t" => $tenantId]);'
            . '$out = ["failed" => $answer->failed, "conversation_id" => $answer->conversationId,'
            . ' "conversations" => $count("SELECT COUNT(*) FROM ai_conversation WHERE tenant_id = :t"),'
            . ' "messages" => $count("SELECT COUNT(*) FROM ai_conversation_message WHERE tenant_id = :t"),'
            . ' "interactions" => $interaction->fetchAll(PDO::FETCH_ASSOC)];',
        );

        Assert::true(!isset($out['error']), 'subprocess threw: ' . (string) ($out['error'] ?? ''));
        Assert::false($out['failed'] ?? true, 'subprocess output: ' . json_encode($out));
        Assert::same(1, $out['conversations']);
        Assert::same(2, $out['messages']);
        Assert::count(1, $out['interactions']);
        Assert::same('copilot_clinical', $out['interactions'][0]['purpose']);
        Assert::same('answered', $out['interactions'][0]['status']);
        Assert::same('ai_conversation', $out['interactions'][0]['subject_type']);
        Assert::same($out['conversation_id'], (int) $out['interactions'][0]['subject_id']);
    }

    /**
     * T-23: with `ai_assistant` and `ai_copilot_management` on, a management
     * question (no anchor) goes through the management toolset and records
     * one answered `copilot_management` interaction.
     */
    public function testManagementAskRecordsACopilotManagementInteraction(): void
    {
        $out = $this->runAdianti(
            '$unitId = (int) $pdo->query("SELECT MIN(id) FROM system_unit")->fetchColumn();'
            . '$userId = (int) $pdo->query("SELECT MIN(id) FROM system_users")->fetchColumn();'
            . '$slug = "f8b-t23-" . bin2hex(random_bytes(4));'
            . '$pdo->prepare("INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, \'active\')")'
            . '->execute(["slug" => $slug, "name" => "F8B teste tenant (" . $slug . ")"]);'
            . '$tenantId = (int) $pdo->lastInsertId();'
            . '$flag = $pdo->prepare("INSERT INTO feature_flag (tenant_id, flag_key, enabled, updated_by_system_user_id) VALUES (:t, :k, 1, :user)");'
            . 'foreach (["ai_assistant", "ai_copilot_management"] as $key) { $flag->execute(["t" => $tenantId, "k" => $key, "user" => $userId]); }'
            . '$pdo->prepare("INSERT INTO ai_tenant_setting (tenant_id, terms_version, terms_accepted_at, terms_accepted_by_system_user_id, monthly_token_quota, updated_by_system_user_id)'
            . ' VALUES (:t, :v, NOW(6), :user, 300000, :user2)")->execute(["t" => $tenantId, "v" => CentralVet\Domain\AiTerms::VERSION, "user" => $userId, "user2" => $userId]);'
            . '$context = CentralVet\Tenancy\TenantContext::authenticated($tenantId, $userId, $unitId);'
            . '$answer = CentralVet\Application\AiCopilotService::forContext($context, $pdo, "pt")->ask("management", null, null, "agenda de hoje");'
            . '$interaction = $pdo->prepare("SELECT purpose, status FROM ai_interaction WHERE tenant_id = :t");'
            . '$interaction->execute(["t" => $tenantId]);'
            . '$out = ["failed" => $answer->failed, "interactions" => $interaction->fetchAll(PDO::FETCH_ASSOC)];',
        );

        Assert::true(!isset($out['error']), 'subprocess threw: ' . (string) ($out['error'] ?? ''));
        Assert::false($out['failed'] ?? true, 'subprocess output: ' . json_encode($out));
        Assert::count(1, $out['interactions']);
        Assert::same('copilot_management', $out['interactions'][0]['purpose']);
        Assert::same('answered', $out['interactions'][0]['status']);
    }

    public function testToolCallsAndTheInteractionShareTheCorrelationId(): void
    {
        [$context, $patientId] = $this->clinicalFixture();
        $gateway = StubAiGateway::scripted([
            self::toolUse('tu_1', 'obter_paciente', ['patient_id' => $patientId]),
            self::text('Rex, canino.'),
        ]);

        $answer = $this->service($context, $gateway)->ask('clinical', null, $patientId, 'resumo');

        Assert::false($answer->failed);
        $interactions = $this->rows('SELECT status, correlation_id FROM ai_interaction WHERE tenant_id = :t', $context->tenantId());
        $calls = $this->rows('SELECT decision, correlation_id FROM mcp_tool_call WHERE tenant_id = :t', $context->tenantId());
        Assert::count(1, $interactions);
        Assert::same('answered', $interactions[0]['status']);
        Assert::count(1, $calls);
        Assert::same('allowed', $calls[0]['decision']);
        Assert::same($interactions[0]['correlation_id'], $calls[0]['correlation_id']);
    }

    public function testFailureWritingTheAnswerKeepsOneInteractionAndTheExistingConversations(): void
    {
        [$context, $patientId] = $this->clinicalFixture();
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM ai_conversation')->fetchColumn();
        $gateway = StubAiGateway::scripted([
            self::toolUse('tu_1', 'obter_paciente', ['patient_id' => $patientId]),
            self::text('Rex, canino.'),
        ]);
        $failing = new class (new AiConversationRepository($context, $this->pdo)) implements AiConversationRepositoryInterface {
            public function __construct(private readonly AiConversationRepository $inner)
            {
            }

            public function insert(AiConversation $conversation): AiConversation
            {
                return $this->inner->insert($conversation);
            }

            public function findOwned(int $id): ?AiConversation
            {
                return $this->inner->findOwned($id);
            }

            public function touch(AiConversation $conversation): void
            {
                $this->inner->touch($conversation);
            }

            public function listOwned(?string $family, ?int $anchorPatientId, int $limit): array
            {
                return $this->inner->listOwned($family, $anchorPatientId, $limit);
            }

            public function deleteOwned(int $id): bool
            {
                return $this->inner->deleteOwned($id);
            }

            public function appendMessage(AiConversationMessage $message): AiConversationMessage
            {
                if ($message->role() !== AiConversationMessage::ROLE_USER) {
                    throw new RuntimeException('append failed');
                }

                return $this->inner->appendMessage($message);
            }

            public function lastMessages(int $conversationId, int $limit): array
            {
                return $this->inner->lastMessages($conversationId, $limit);
            }
        };

        Assert::throws(RuntimeException::class, fn () => $this->service($context, $gateway, $failing)->ask('clinical', null, $patientId, 'resumo'));

        $calls = $this->rows('SELECT correlation_id FROM mcp_tool_call WHERE tenant_id = :t', $context->tenantId());
        Assert::count(1, $calls);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM ai_interaction WHERE correlation_id = :c');
        $count->execute(['c' => $calls[0]['correlation_id']]);
        Assert::same(1, (int) $count->fetchColumn());
        $status = $this->rows('SELECT status, error_code FROM ai_interaction WHERE tenant_id = :t', $context->tenantId());
        Assert::same(['status' => 'failed', 'error_code' => 'ai_provider_error'], $status[0]);
        Assert::same($before + 1, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_conversation')->fetchColumn(), 'no existing conversation is lost');
    }

    /**
     * Tenant with `ai_assistant` on, accepted terms and one patient with an
     * encounter in the first unit (all inside the test transaction).
     *
     * @return array{0: TenantContext, 1: int}
     */
    private function clinicalFixture(): array
    {
        $unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        $userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        $slug = 'f8b-t28-' . bin2hex(random_bytes(4));
        $this->pdo->prepare("INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, 'active')")
            ->execute(['slug' => $slug, 'name' => 'F8B teste tenant (' . $slug . ')']);
        $tenantId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), 'F8B teste tutor', '11900000000')")
            ->execute(['t' => $tenantId]);
        $tutorId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, 'F8B teste Rex', 'canino')")
            ->execute(['t' => $tenantId, 'tutor' => $tutorId]);
        $patientId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO encounter (tenant_id, system_unit_id, patient_id, professional_system_user_id, status, started_at)"
            . " VALUES (:t, :u, :p, :user, 'in_progress', NOW(6))")
            ->execute(['t' => $tenantId, 'u' => $unitId, 'p' => $patientId, 'user' => $userId]);
        $this->pdo->prepare("INSERT INTO feature_flag (tenant_id, flag_key, enabled, updated_by_system_user_id) VALUES (:t, 'ai_assistant', 1, :user)")
            ->execute(['t' => $tenantId, 'user' => $userId]);
        $this->pdo->prepare('INSERT INTO ai_tenant_setting (tenant_id, terms_version, terms_accepted_at, terms_accepted_by_system_user_id, monthly_token_quota, updated_by_system_user_id)'
            . ' VALUES (:t, :v, NOW(6), :user, 300000, :user2)')
            ->execute(['t' => $tenantId, 'v' => AiTerms::VERSION, 'user' => $userId, 'user2' => $userId]);

        return [TenantContext::authenticated($tenantId, $userId, $unitId), $patientId];
    }

    /** Real PDO repositories on the test connection, allow-all RBAC and an in-memory tool limiter. */
    private function service(TenantContext $context, StubAiGateway $gateway, ?AiConversationRepositoryInterface $conversations = null): AiCopilotService
    {
        $authorization = new FakeAuthorizationPolicy(true);
        $flags = new FeatureFlagRepository($context, $this->pdo);
        $clinical = new CopilotClinicalQuery($context, $this->pdo);
        $registry = new ToolRegistry(ClinicalToolset::create($clinical), $flags, $authorization, $context);
        $policy = new ToolPolicy(
            $registry,
            new JsonSchemaValidator(),
            new McpToolCallRepository($context, $this->pdo),
            new ToolRateLimiter(new LoginRateLimiter(new FakeRedis(), ToolRateLimiter::MAX_CALLS, ToolRateLimiter::DECAY_SECONDS, ToolRateLimiter::PREFIX)),
            null,
        );

        return new AiCopilotService(
            $gateway,
            new AiInteractionRepository($context, $this->pdo),
            new AiTenantSettingRepository($context, $this->pdo),
            $flags,
            $conversations ?? new AiConversationRepository($context, $this->pdo),
            $registry,
            $policy,
            $clinical,
            $authorization,
            null,
            $context,
            'pt',
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, int $tenantId): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['t' => $tenantId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function text(string $text): AiCompletionResult
    {
        return new AiCompletionResult($text, 'stub', 'claude-sonnet-5-5', new AiUsage(20, 5), 25, 'end_turn', 'req-text', [], [['type' => 'text', 'text' => $text]]);
    }

    /** @param array<string, mixed> $input */
    private static function toolUse(string $id, string $name, array $input): AiCompletionResult
    {
        return new AiCompletionResult(
            '',
            'stub',
            'claude-sonnet-5-5',
            new AiUsage(10, 2),
            12,
            'tool_use',
            'req-tool',
            [['id' => $id, 'name' => $name, 'input' => $input]],
            [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]],
        );
    }

    /**
     * Runs $body after init.php with the `AiCopilotPanel` program in the
     * session and the stub provider; `$pdo` (test database, open
     * transaction) is in scope and the transaction is always rolled back.
     * Returns `$out`.
     *
     * @return array<string, mixed>
     */
    private function runAdianti(string $body): array
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

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . 'TSession::setValue("programs", ["AiCopilotPanel" => true]);'
            . '$out = [];'
            . '$pdo = new PDO(' . var_export($dsn, true) . ', (string) (getenv("DB_USERNAME") ?: "centralvet"),'
            . ' (string) (getenv("DB_PASSWORD") ?: ""), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            . '$pdo->beginTransaction();'
            . 'ob_start();'
            . 'try { ' . $body . ' } catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . ' finally { $pdo->rollBack(); }'
            . 'ob_end_clean();'
            . 'echo "' . self::MARKER . '", json_encode($out);';

        $env = 'env AI_PROVIDER=stub APP_ENV=testing ANTHROPIC_API_KEY=';
        $raw = (string) shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($raw, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($raw, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'AiCopilotService subprocess output: ' . substr($raw, -500));

        return $decoded;
    }
}
