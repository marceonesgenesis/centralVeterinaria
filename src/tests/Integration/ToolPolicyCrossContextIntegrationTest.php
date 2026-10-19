<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolPolicy;
use CentralVet\Ai\Tools\ToolRateLimiter;
use CentralVet\Ai\Tools\ToolRegistry;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Persistence\McpToolCallRepository;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiTool;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeFeatureFlagRepository;
use CentralVet\Tests\Support\FakeRedis;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * A ToolContext of another user reaching ToolPolicy is denied as
 * `forbidden` and that denial is audited in the real `mcp_tool_call`
 * under the registry's own tenant/unit/user, without the foreign
 * conversation (the repository rejects rows of another user or unit).
 * Rows live only inside the test transaction (rolled back).
 */
final class ToolPolicyCrossContextIntegrationTest extends MysqlIntegrationTestCase
{
    private const TOOL = 'obter_paciente';

    private int $tenantId;
    private int $unitId;
    private int $userId;

    public function setUp(): void
    {
        parent::setUp();

        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        $exists = (bool) $this->pdo->query("SHOW TABLES LIKE 'mcp_tool_call'")->fetchColumn();
        Assert::true($exists, 'Migration 0015 must be applied to the test database (mcp_tool_call)');

        $slug = 'f8b-t25-' . bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)')
            ->execute(['slug' => $slug, 'legal_name' => 'F8B teste tenant (' . $slug . ')', 'status' => 'active']);
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');
    }

    public function testToolContextOfAnotherUserIsDeniedAndAuditedUnderTheRegistryContext(): void
    {
        $tenant = TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId);
        $tool = new FakeAiTool(self::TOOL, ToolFamily::CLINICAL, 'PatientForm::onEdit');
        $policy = new ToolPolicy(
            new ToolRegistry(
                [$tool],
                new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
                new FakeAuthorizationPolicy(true),
                $tenant,
            ),
            new JsonSchemaValidator(),
            new McpToolCallRepository($tenant, $this->pdo),
            new ToolRateLimiter(new LoginRateLimiter(new FakeRedis(), ToolRateLimiter::MAX_CALLS, ToolRateLimiter::DECAY_SECONDS, 'centralvet:ai-tool:')),
        );
        $correlationId = 'corr-t25-' . bin2hex(random_bytes(4));
        $otherUser = $this->userId + 100000;
        $foreign = ToolContext::create(
            TenantContext::authenticated($this->tenantId, $otherUser, $this->unitId),
            $correlationId,
            987654321,
            'copilot_clinical',
            555,
        );

        $outcome = $policy->execute($foreign, ToolFamily::CLINICAL, self::TOOL, ['id' => 5], [self::TOOL]);

        Assert::true($outcome->isError, 'context mismatch must be denied');
        Assert::same('denied', $outcome->decision);
        Assert::same('forbidden', $outcome->denyReason);
        Assert::same(['error' => 'sem permissão'], json_decode($outcome->content, true));
        Assert::same(0, $tool->executions(), 'the tool must not run');

        $statement = $this->pdo->prepare(
            'SELECT tenant_id, system_unit_id, system_user_id, conversation_id, decision, deny_reason, tool_name
               FROM mcp_tool_call WHERE correlation_id = ?',
        );
        $statement->execute([$correlationId]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        Assert::count(1, $rows, 'the cross-context denial must be audited');
        Assert::same($this->tenantId, (int) $rows[0]['tenant_id']);
        Assert::same($this->unitId, (int) $rows[0]['system_unit_id']);
        Assert::same($this->userId, (int) $rows[0]['system_user_id']);
        Assert::null($rows[0]['conversation_id'], 'foreign conversation is not recorded');
        Assert::same('denied', $rows[0]['decision']);
        Assert::same('forbidden', $rows[0]['deny_reason']);
        Assert::same(self::TOOL, $rows[0]['tool_name']);
    }
}
