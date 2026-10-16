<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Ai\Tools\ToolOutcome;
use CentralVet\Ai\Tools\ToolPolicy;
use CentralVet\Ai\Tools\ToolRateLimiter;
use CentralVet\Ai\Tools\ToolRegistry;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Domain\Contract\McpToolCallRepositoryInterface;
use CentralVet\Domain\McpToolCall;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiTool;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeFeatureFlagRepository;
use CentralVet\Tests\Support\FakeMcpToolCallRepository;
use CentralVet\Tests\Support\FakeRedis;
use Closure;
use RuntimeException;

final class ToolPolicyTest
{
    private const TENANT = 7;
    private const UNIT = 3;
    private const USER = 11;
    private const TOOL = 'obter_paciente';
    private const PERMISSION = 'PatientForm::onEdit';

    private FakeMcpToolCallRepository $calls;
    private FakeAuthorizationPolicy $policyAuthorization;
    private FakeAuthorizationPolicy $registryAuthorization;
    private FakeRedis $redis;

    public function setUp(): void
    {
        $this->calls = new FakeMcpToolCallRepository(self::TENANT);
        $this->policyAuthorization = new FakeAuthorizationPolicy(true);
        $this->registryAuthorization = new FakeAuthorizationPolicy(true);
        $this->redis = new FakeRedis();
    }

    private function tenant(): TenantContext
    {
        return TenantContext::authenticated(self::TENANT, self::USER, self::UNIT);
    }

    private function context(): ToolContext
    {
        return ToolContext::create($this->tenant(), 'corr-t08', 42, 'copilot_clinical', null);
    }

    private function policy(AiTool $tool, ?Closure $clock = null, ?ToolRateLimiter $limiter = null, ?McpToolCallRepositoryInterface $calls = null): ToolPolicy
    {
        $registry = new ToolRegistry(
            [$tool],
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            $this->registryAuthorization,
            $this->tenant(),
        );

        return new ToolPolicy(
            $registry,
            new JsonSchemaValidator(),
            $this->policyAuthorization,
            $calls ?? $this->calls,
            $limiter ?? new ToolRateLimiter(new LoginRateLimiter($this->redis, ToolRateLimiter::MAX_CALLS, ToolRateLimiter::DECAY_SECONDS, 'centralvet:ai-tool:')),
            null,
            $clock,
        );
    }

    private function tool(?ToolResult $result = null, ?\Throwable $failure = null, bool $inScope = true): FakeAiTool
    {
        return new FakeAiTool(self::TOOL, ToolFamily::CLINICAL, self::PERMISSION, $result, $failure, $inScope);
    }

    /** @param array<string, mixed> $input */
    private function run(ToolPolicy $policy, array $input = ['id' => 5], ?array $offered = null): ToolOutcome
    {
        return $policy->execute($this->context(), ToolFamily::CLINICAL, self::TOOL, $input, $offered ?? [self::TOOL]);
    }

    private function assertDenied(ToolOutcome $outcome, string $reason, string $message): void
    {
        Assert::true($outcome->isError, 'denied outcome must be an error');
        Assert::same('denied', $outcome->decision);
        Assert::same($reason, $outcome->denyReason);
        Assert::same(['error' => $message], json_decode($outcome->content, true));
        Assert::true(strlen($outcome->content) <= ToolPolicy::MAX_BYTES);

        $rows = $this->calls->all();
        Assert::count(1, $rows);
        Assert::same(McpToolCall::DECISION_DENIED, $rows[0]->decision());
        Assert::same($reason, $rows[0]->denyReason());
        Assert::same(self::TENANT, $rows[0]->tenantId());
        Assert::same(self::UNIT, $rows[0]->systemUnitId());
        Assert::same(self::USER, $rows[0]->systemUserId());
        Assert::same(42, $rows[0]->conversationId());
        Assert::same('corr-t08', $rows[0]->correlationId());
    }

    public function testNotOfferedWhenNameIsNotInOfferedList(): void
    {
        $tool = $this->tool();
        $outcome = $this->run($this->policy($tool), ['id' => 5], ['buscar_paciente']);

        $this->assertDenied($outcome, 'not_offered', 'sem permissão');
        Assert::same(0, $tool->executions());
    }

    public function testToolWithoutScreenPermissionRequestedByModelIsNotOffered(): void
    {
        $this->registryAuthorization->setAllowed(false);
        $tool = $this->tool();
        $outcome = $this->run($this->policy($tool));

        $this->assertDenied($outcome, 'not_offered', 'sem permissão');
        Assert::same(0, $tool->executions());
    }

    public function testInvalidInputIsDenied(): void
    {
        $tool = $this->tool();
        $outcome = $this->run($this->policy($tool), ['id' => 'abc']);

        $this->assertDenied($outcome, 'invalid_input', 'entrada inválida');
        Assert::same(0, $tool->executions());
    }

    public function testInvalidInputWinsOverRbacDenial(): void
    {
        $this->policyAuthorization->setAllowed(false);
        $tool = $this->tool();
        $outcome = $this->run($this->policy($tool), ['id' => 0]);

        $this->assertDenied($outcome, 'invalid_input', 'entrada inválida');
        Assert::count(0, $this->policyAuthorization->requests, 'RBAC must not run after invalid input');
    }

    public function testForbiddenWhenRbacDenies(): void
    {
        $this->policyAuthorization->setAllowed(false);
        $tool = $this->tool();
        $outcome = $this->run($this->policy($tool));

        $this->assertDenied($outcome, 'forbidden', 'sem permissão');
        Assert::same(0, $tool->executions());
        $request = $this->policyAuthorization->requests[0];
        Assert::same(self::PERMISSION, $request->action());
        Assert::same(self::TENANT, $request->context()->tenantId());
        Assert::same(self::UNIT, $request->context()->unitId());
    }

    public function testNotFoundWhenOutOfScope(): void
    {
        $tool = $this->tool(inScope: false);
        $outcome = $this->run($this->policy($tool));

        $this->assertDenied($outcome, 'not_found', 'não encontrado');
        Assert::same(0, $tool->executions());
    }

    public function testThirtyFirstCallInWindowIsRateLimited(): void
    {
        $tool = $this->tool();
        $policy = $this->policy($tool);
        for ($i = 0; $i < ToolRateLimiter::MAX_CALLS; $i++) {
            Assert::false($this->run($policy)->isError, 'call ' . ($i + 1) . ' must be allowed');
        }

        $outcome = $this->run($policy);

        Assert::true($outcome->isError);
        Assert::same('rate_limited', $outcome->denyReason);
        Assert::same(['error' => 'limite atingido'], json_decode($outcome->content, true));
        Assert::same(30, $tool->executions());
        $rows = $this->calls->all();
        Assert::count(31, $rows);
        Assert::same('denied', $rows[30]->decision());
        Assert::same('rate_limited', $rows[30]->denyReason());
        Assert::same(600, $this->redis->ttl('centralvet:ai-tool:' . hash('sha256', self::TENANT . '|' . self::USER)));
    }

    public function testRateLimitIsPerTenantAndUser(): void
    {
        $limiter = new ToolRateLimiter(new LoginRateLimiter($this->redis, ToolRateLimiter::MAX_CALLS, ToolRateLimiter::DECAY_SECONDS, 'centralvet:ai-tool:'));
        for ($i = 0; $i < 30; $i++) {
            Assert::true($limiter->attempt(1, 1));
        }
        Assert::false($limiter->attempt(1, 1));
        Assert::true($limiter->attempt(1, 2));
        Assert::true($limiter->attempt(2, 1));
    }

    public function testExceptionBecomesGenericErrorWithoutLeakingMessage(): void
    {
        $tool = $this->tool(failure: new RuntimeException('SQLSTATE secret'));
        $outcome = $this->run($this->policy($tool));

        $this->assertDenied($outcome, 'error', 'erro ao consultar');
        Assert::false(str_contains($outcome->content, 'SQLSTATE'));
        Assert::false(str_contains($outcome->content, 'secret'));
        Assert::false(str_contains($outcome->content, 'RuntimeException'));
        Assert::same(1, $tool->executions());
    }

    public function testToolInputExceptionIsInvalidInput(): void
    {
        $tool = $this->tool(failure: new ToolInputException('bad period'));
        $outcome = $this->run($this->policy($tool));

        $this->assertDenied($outcome, 'invalid_input', 'entrada inválida');
        Assert::false(str_contains($outcome->content, 'bad period'));
    }

    public function testSlowToolIsTimeoutAndResultDiscarded(): void
    {
        $ticks = [1000, 1000 + ToolPolicy::TIMEOUT_MS + 1];
        $clock = static function () use (&$ticks): int {
            return count($ticks) > 1 ? array_shift($ticks) : $ticks[0];
        };
        $tool = $this->tool(new ToolResult([['nome' => 'Rex']]));
        $outcome = $this->run($this->policy($tool, $clock));

        $this->assertDenied($outcome, 'timeout', 'erro ao consultar');
        Assert::false(str_contains($outcome->content, 'Rex'));
        Assert::same(1, $tool->executions());
        Assert::true($this->calls->all()[0]->durationMs() > ToolPolicy::TIMEOUT_MS);
    }

    public function testAllowedCallIsAuditedWithRowsAndDuration(): void
    {
        $ticks = [100, 112];
        $clock = static function () use (&$ticks): int {
            return count($ticks) > 1 ? array_shift($ticks) : $ticks[0];
        };
        $tool = $this->tool(new ToolResult([['nome' => 'Rex'], ['nome' => 'Mel']]));
        $outcome = $this->run($this->policy($tool, $clock));

        Assert::false($outcome->isError);
        Assert::same(self::TOOL, $outcome->toolName);
        Assert::same('allowed', $outcome->decision);
        Assert::null($outcome->denyReason);
        Assert::same(2, $outcome->rowCount);
        Assert::false($outcome->truncated);
        Assert::same(
            ['rows' => [['nome' => 'Rex'], ['nome' => 'Mel']], 'row_count' => 2, 'truncated' => false],
            json_decode($outcome->content, true),
        );
        $rows = $this->calls->all();
        Assert::count(1, $rows);
        Assert::same('allowed', $rows[0]->decision());
        Assert::null($rows[0]->denyReason());
        Assert::same(2, $rows[0]->rowCount());
        Assert::false($rows[0]->truncated());
        Assert::same(12, $rows[0]->durationMs());
    }

    public function testSixtyRowsAreCutToFiftyAndTruncated(): void
    {
        $data = [];
        for ($i = 1; $i <= 60; $i++) {
            $data[] = ['id' => $i];
        }
        $outcome = $this->run($this->policy($this->tool(new ToolResult($data))));

        $decoded = json_decode($outcome->content, true);
        Assert::count(ToolResult::MAX_ROWS, $decoded['rows']);
        Assert::same(50, $decoded['row_count']);
        Assert::true($decoded['truncated']);
        Assert::same(50, $outcome->rowCount);
        Assert::true($outcome->truncated);
        Assert::true($this->calls->all()[0]->truncated());
        Assert::same(50, $this->calls->all()[0]->rowCount());
    }

    public function testOversizedContentIsCutToMaxBytes(): void
    {
        $data = [];
        for ($i = 1; $i <= 50; $i++) {
            $data[] = ['id' => $i, 'texto' => str_repeat('a', 1000)];
        }
        $outcome = $this->run($this->policy($this->tool(new ToolResult($data))));

        Assert::true(strlen($outcome->content) <= ToolPolicy::MAX_BYTES);
        $decoded = json_decode($outcome->content, true);
        Assert::true($decoded['truncated']);
        Assert::true(count($decoded['rows']) < 50 && count($decoded['rows']) > 0);
        Assert::same(1, $decoded['rows'][0]['id']);
        Assert::same(count($decoded['rows']), $decoded['row_count']);
    }

    public function testFreeTextFieldsAreMinimized(): void
    {
        $result = new ToolResult(
            [['anamnese' => 'Tutor Maria Souza 85 98765-4321', 'paciente' => 'Rex']],
            false,
            ['anamnese'],
            ['Maria Souza'],
        );
        $outcome = $this->run($this->policy($this->tool($result)));

        Assert::false(str_contains($outcome->content, 'Maria Souza'));
        Assert::false(str_contains($outcome->content, 'Maria'));
        Assert::false(str_contains($outcome->content, '98765-4321'));
        Assert::stringContains('Tutor', $outcome->content);
        Assert::stringContains('Rex', $outcome->content);
    }

    public function testInputMinimizedHasNoPhoneDigitsNorSecrets(): void
    {
        $tool = new FakeAiTool('buscar_paciente', ToolFamily::CLINICAL, 'GlobalSearchController::onSearch');
        $outcome = $this->policy($tool)->execute(
            $this->context(),
            ToolFamily::CLINICAL,
            'buscar_paciente',
            ['termo' => 'Rex 85 98765-4321', 'cpf' => '123.456.789-09'],
            ['buscar_paciente'],
        );

        Assert::true($outcome->isError, 'unknown property is invalid input for the fake schema');
        $minimized = $this->calls->all()[0]->inputMinimized();
        Assert::false(str_contains($minimized, '98765'));
        Assert::false(str_contains($minimized, '4321'));
        Assert::false(str_contains($minimized, '123.456'));
        Assert::stringContains('Rex', $minimized);
        Assert::same('termo', array_key_first(json_decode($minimized, true)));
    }

    /** Tool whose assertInScope() throws $scopeFailure (8B T-08 correção 1). */
    private function scopeFailingTool(\Throwable $scopeFailure): AiTool
    {
        return new class (self::TOOL, self::PERMISSION, $scopeFailure) implements AiTool {
            public int $executions = 0;

            public function __construct(private string $toolName, private string $permission, private \Throwable $failure)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'scope failing tool';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]]];
            }

            public function requiredPermission(): string
            {
                return $this->permission;
            }

            public function family(): string
            {
                return ToolFamily::CLINICAL;
            }

            public function label(string $lang): string
            {
                return 'Falha';
            }

            public function assertInScope(ToolContext $context, array $input): void
            {
                throw $this->failure;
            }

            public function execute(ToolContext $context, array $input): ToolResult
            {
                $this->executions++;

                return new ToolResult([]);
            }
        };
    }

    public function testToolInputExceptionInScopeIsDeniedAndAudited(): void
    {
        $tool = $this->scopeFailingTool(new ToolInputException('patient_id required'));
        $outcome = $this->run($this->policy($tool));

        $this->assertDenied($outcome, 'invalid_input', 'entrada inválida');
        Assert::same(0, $tool->executions);
    }

    public function testPdoExceptionInScopeIsDeniedAsErrorWithoutLeak(): void
    {
        $tool = $this->scopeFailingTool(new \PDOException('SQLSTATE[HY000] secret'));
        $outcome = $this->run($this->policy($tool));

        $this->assertDenied($outcome, 'error', 'erro ao consultar');
        Assert::false(str_contains($outcome->content, 'SQLSTATE'));
        Assert::false(str_contains($outcome->content, 'PDOException'));
        Assert::same(0, $tool->executions);
    }

    public function testRateLimiterFailureDeniesFailClosed(): void
    {
        $broken = new class extends \Redis {
            public function __construct()
            {
            }

            public function get($key): mixed
            {
                throw new \RedisException('Connection refused secret');
            }

            public function incr($key, $by = 1): \Redis|int|false
            {
                throw new \RedisException('Connection refused secret');
            }
        };
        $tool = $this->tool();
        $limiter = new ToolRateLimiter(new LoginRateLimiter($broken, ToolRateLimiter::MAX_CALLS, ToolRateLimiter::DECAY_SECONDS, 'centralvet:ai-tool:'));
        $outcome = $this->run($this->policy($tool, null, $limiter));

        $this->assertDenied($outcome, 'rate_limited', 'limite atingido');
        Assert::false(str_contains($outcome->content, 'secret'));
        Assert::same(0, $tool->executions());
    }

    public function testGenericExceptionInExecuteIsDeniedAndAudited(): void
    {
        $tool = $this->tool(failure: new \LogicException('internal detail'));
        $outcome = $this->run($this->policy($tool));

        $this->assertDenied($outcome, 'error', 'erro ao consultar');
        Assert::false(str_contains($outcome->content, 'internal detail'));
    }

    public function testRegistryFailureIsDeniedAsError(): void
    {
        $tool = $this->tool();
        $outcome = $this->policy($tool)->execute($this->context(), 'unknown_family', self::TOOL, ['id' => 5], [self::TOOL]);

        $this->assertDenied($outcome, 'error', 'erro ao consultar');
        Assert::same(0, $tool->executions());
    }

    public function testAuditFailureStillDeniesWithoutPropagating(): void
    {
        $failingCalls = new class implements McpToolCallRepositoryInterface {
            public function insert(McpToolCall $call): McpToolCall
            {
                throw new \PDOException('SQLSTATE audit down');
            }

            public function search(?int $systemUserId, ?string $toolName, ?string $decision, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit): array
            {
                return [];
            }
        };
        $tool = $this->tool(new ToolResult([['nome' => 'Rex']]));
        $outcome = $this->run($this->policy($tool, null, null, $failingCalls));

        Assert::true($outcome->isError);
        Assert::same('denied', $outcome->decision);
        Assert::same('error', $outcome->denyReason);
        Assert::same(['error' => 'erro ao consultar'], json_decode($outcome->content, true));
    }

    public function testHugeInputIsCappedInAudit(): void
    {
        $outcome = $this->run($this->policy($this->tool()), ['id' => 5, 'extra' => str_repeat('x', 100000)]);

        Assert::same('invalid_input', $outcome->denyReason);
        Assert::true(strlen($this->calls->all()[0]->inputMinimized()) <= ToolPolicy::MAX_INPUT_BYTES);
    }
}
