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
    private FakeAuthorizationPolicy $authorization;
    private FakeRedis $redis;

    public function setUp(): void
    {
        $this->calls = new FakeMcpToolCallRepository(self::TENANT);
        $this->authorization = new FakeAuthorizationPolicy(true);
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

    /** @param list<AiTool>|AiTool $tools */
    private function policy(AiTool|array $tools, ?Closure $clock = null, ?ToolRateLimiter $limiter = null, ?McpToolCallRepositoryInterface $calls = null, ?ToolRegistry $registry = null): ToolPolicy
    {
        $registry ??= $this->registry($tools);

        return new ToolPolicy(
            $registry,
            new JsonSchemaValidator(),
            $calls ?? $this->calls,
            $limiter ?? new ToolRateLimiter(new LoginRateLimiter($this->redis, ToolRateLimiter::MAX_CALLS, ToolRateLimiter::DECAY_SECONDS, 'centralvet:ai-tool:')),
            null,
            $clock,
        );
    }

    /** @param list<AiTool>|AiTool $tools */
    private function registry(AiTool|array $tools): ToolRegistry
    {
        return new ToolRegistry(
            is_array($tools) ? $tools : [$tools],
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            $this->authorization,
            $this->tenant(),
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
        $this->authorization->setAllowed(false);
        $tool = $this->tool();
        $registry = $this->registry($tool);
        $offered = array_column($registry->definitions(ToolFamily::CLINICAL), 'name');
        $outcome = $this->run($this->policy($tool, registry: $registry), ['id' => 5], $offered);

        Assert::same([], $offered);
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
        $this->authorization->setAllowed(false);
        $tool = $this->tool();
        $outcome = $this->run($this->policy($tool), ['id' => 0]);

        $this->assertDenied($outcome, 'invalid_input', 'entrada inválida');
        Assert::true(count($this->authorization->requests) <= 1, 'RBAC runs at most once, from the registry cache');
    }

    public function testForbiddenWhenRbacDenies(): void
    {
        $this->authorization->setAllowed(false);
        $tool = $this->tool();
        $outcome = $this->run($this->policy($tool));

        $this->assertDenied($outcome, 'forbidden', 'sem permissão');
        Assert::same(0, $tool->executions());
        Assert::count(1, $this->authorization->requests);
        $request = $this->authorization->requests[0];
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

    public function testFreeTextIsMinimizedBeforeTheExcerptCut(): void
    {
        $motive = str_repeat('x', 152) . ' (85) 98765-4321 ligou';
        $exam = str_repeat('y', 390) . ' CPF 123.456.789-09 conferido';
        $result = new ToolResult(
            [['motivo' => $motive, 'resultado' => $exam]],
            false,
            ['motivo', 'resultado'],
            [],
            ['motivo' => 160, 'resultado' => 400],
        );
        $outcome = $this->run($this->policy($this->tool($result)));

        Assert::false($outcome->isError);
        foreach (['98765', '987', '4321', '123.456', '789-09'] as $fragment) {
            Assert::false(str_contains($outcome->content, $fragment), 'content leaks ' . $fragment);
        }
        $row = json_decode($outcome->content, true)['rows'][0];
        Assert::true(mb_strlen($row['motivo']) <= 160);
        Assert::true(mb_strlen($row['resultado']) <= 400);
        Assert::true(str_starts_with($row['motivo'], str_repeat('x', 152)));
        Assert::true(str_starts_with($row['resultado'], str_repeat('y', 390)));

        // Region where the phone/CPF was: no digit at all (a cut before
        // minimizing leaves "(85) 9…" / "CPF 123.…"), and it carries the
        // marker or a prefix of it (the cut may fall inside the marker).
        $phoneRegion = mb_substr($row['motivo'], 152);
        $cpfRegion = mb_substr($row['resultado'], 390);
        Assert::same(0, preg_match('/\d/', $phoneRegion), 'phone digits leaked: ' . $phoneRegion);
        Assert::same(0, preg_match('/\d/', $cpfRegion), 'CPF digits leaked: ' . $cpfRegion);
        Assert::true(self::carriesMarker($phoneRegion, '[TELEFONE]'), 'phone region without marker: ' . $phoneRegion);
        Assert::true(self::carriesMarker(preg_replace('/^\s*CPF/u', '', $cpfRegion), '[CPF]'), 'CPF region without marker: ' . $cpfRegion);
    }

    /** True when $region holds $marker or is cut inside it (" [TELEF…"). */
    private static function carriesMarker(string $region, string $marker): bool
    {
        $region = trim(rtrim(trim($region), '…'));
        if (str_contains($region, $marker)) {
            return true;
        }

        return $region !== '' && str_starts_with($marker, $region);
    }

    public function testExcerptLengthsMustMapFieldsToPositiveLengths(): void
    {
        Assert::throws(\InvalidArgumentException::class, static fn () => new ToolResult([], false, [], [], ['motivo' => 0]));
        Assert::throws(\InvalidArgumentException::class, static fn () => new ToolResult([], false, [], [], [160]));
    }

    public function testThreeExecutionsDecideRbacOncePerOfferedTool(): void
    {
        $tools = [
            $this->tool(new ToolResult([['nome' => 'Rex']])),
            new FakeAiTool('buscar_paciente', ToolFamily::CLINICAL, 'GlobalSearchController::onSearch'),
        ];
        $registry = $this->registry($tools);
        $policy = $this->policy($tools, registry: $registry);
        $offered = array_column($registry->definitions(ToolFamily::CLINICAL), 'name');

        foreach ([self::TOOL, 'buscar_paciente', self::TOOL] as $name) {
            $outcome = $policy->execute($this->context(), ToolFamily::CLINICAL, $name, ['id' => 5], $offered);
            Assert::false($outcome->isError, $name . ' must run');
        }

        $actions = array_map(static fn ($request): string => $request->action(), $this->authorization->requests);
        Assert::same([self::PERMISSION, 'GlobalSearchController::onSearch'], $actions);
    }
}
