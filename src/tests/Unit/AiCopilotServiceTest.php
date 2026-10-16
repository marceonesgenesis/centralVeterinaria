<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\AiPromptCatalog;
use CentralVet\Ai\StubAiGateway;
use CentralVet\Ai\UnconfiguredAiGateway;
use CentralVet\Ai\Tools\Clinical\ClinicalToolset;
use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\ToolPolicy;
use CentralVet\Ai\Tools\ToolRateLimiter;
use CentralVet\Ai\Tools\ToolRegistry;
use CentralVet\Application\AiCopilotService;
use CentralVet\Application\CopilotAnswer;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\AiGatewayInterface;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Domain\Exception\AiProviderException;
use CentralVet\Domain\Exception\AiSubjectNotFoundException;
use CentralVet\Domain\Exception\AiUnavailableException;
use CentralVet\Domain\McpToolCall;
use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\FakeAiConversationRepository;
use CentralVet\Tests\Support\FakeAiInteractionRepository;
use CentralVet\Tests\Support\FakeAiTenantSettingRepository;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCopilotClinicalQuery;
use CentralVet\Tests\Support\FakeFeatureFlagRepository;
use CentralVet\Tests\Support\FakeMcpToolCallRepository;
use CentralVet\Tests\Support\FakeRedis;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * T-13: AiCopilotService runs the read-only tool-use loop (at most
 * MAX_ROUNDS rounds within BUDGET_SECONDS, then one final call without
 * tools), records one `ai_interaction` per question and the minimized
 * question/answer in the conversation, and fails closed on availability,
 * anchor and ownership.
 */
final class AiCopilotServiceTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 3;
    private const PATIENT_ID = 20;
    private const OTHER_PATIENT_ID = 21;
    private const MODEL = 'claude-sonnet-5-5';

    private FakeAiInteractionRepository $interactions;
    private FakeAiTenantSettingRepository $settings;
    private FakeFeatureFlagRepository $flags;
    private FakeAiConversationRepository $conversations;
    private FakeMcpToolCallRepository $calls;
    private FakeCopilotClinicalQuery $clinical;
    private FakeAuthorizationPolicy $authorization;
    private TenantContext $context;

    public function setUp(): void
    {
        CorrelationIdContext::clear();
        $this->interactions = new FakeAiInteractionRepository(self::TENANT_ID);
        $this->settings = new FakeAiTenantSettingRepository(self::acceptedSetting(AiTerms::VERSION));
        $this->flags = new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]);
        $this->conversations = new FakeAiConversationRepository(self::TENANT_ID, self::UNIT_ID, self::USER_ID);
        $this->calls = new FakeMcpToolCallRepository(self::TENANT_ID);
        $this->clinical = new FakeCopilotClinicalQuery();
        $this->clinical->setInUnit('patient', self::PATIENT_ID, true);
        $this->clinical->setInUnit('patient', self::OTHER_PATIENT_ID, false);
        $this->clinical->seed('patient', [self::PATIENT_ID => self::patientRow()]);
        $this->clinical->seed('tutorNames', [self::PATIENT_ID => ['Maria Silva']]);
        $this->authorization = new FakeAuthorizationPolicy(true);
        $this->context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
    }

    public function tearDown(): void
    {
        CorrelationIdContext::clear();
    }

    public function testExposesTheLoopLimits(): void
    {
        Assert::same(4, AiCopilotService::MAX_ROUNDS);
        Assert::same(45, AiCopilotService::BUDGET_SECONDS);
        Assert::same(20, AiCopilotService::HISTORY_LIMIT);
        Assert::same(2000, AiCopilotService::MAX_QUESTION_CHARS);
        Assert::same('Não consegui concluir a consulta, tente uma pergunta mais específica.', AiCopilotService::TIMEOUT_TEXT['pt']);
        Assert::true(isset(AiCopilotService::TIMEOUT_TEXT['en']));
    }

    public function testClinicalToolsetCreatesTheSevenClinicalTools(): void
    {
        $names = array_map(static fn ($tool): string => $tool->name(), ClinicalToolset::create($this->clinical));
        sort($names);
        $expected = [
            'buscar_paciente', 'consultar_vacinacao', 'obter_exames', 'obter_historico_atendimentos',
            'obter_paciente', 'obter_prescricoes', 'obter_prontuario',
        ];

        Assert::same($expected, $names);
    }

    public function testZeroRoundsAnswersDirectly(): void
    {
        $gateway = StubAiGateway::scripted([self::text('Rex está bem.', 100, 20)]);

        $answer = $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, 'Como está o paciente?');

        Assert::instanceOf(CopilotAnswer::class, $answer);
        Assert::false($answer->failed);
        Assert::null($answer->reason);
        Assert::same('Rex está bem.', $answer->text);
        Assert::same([], $answer->toolSummary);
        Assert::true($answer->conversationId > 0);

        $requests = $gateway->requests();
        Assert::count(1, $requests);
        Assert::same(AiPurpose::COPILOT_CLINICAL, $requests[0]->purpose);
        Assert::same(AiPromptCatalog::VERSIONS[AiPurpose::COPILOT_CLINICAL], $requests[0]->promptVersion);
        Assert::same(AiPromptCatalog::copilotSystem(AiPurpose::COPILOT_CLINICAL, 'pt'), $requests[0]->system);
        Assert::count(7, $requests[0]->tools);
        Assert::same([['role' => 'user', 'content' => 'Como está o paciente?']], $requests[0]->messages);
    }

    public function testOneRoundRunsTheToolAndReturnsTheAnswerWithTheSummary(): void
    {
        $gateway = StubAiGateway::scripted([
            self::toolUse([['tu_1', 'obter_paciente', ['patient_id' => self::PATIENT_ID]]], 100, 10),
            self::text('Rex, canino, 12 kg.', 200, 30),
        ]);

        $answer = $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, 'Resumo do paciente');

        Assert::false($answer->failed);
        Assert::same('Rex, canino, 12 kg.', $answer->text);
        Assert::same([['tool' => 'obter_paciente', 'label' => 'cadastro do paciente', 'count' => 1]], $answer->toolSummary);

        $requests = $gateway->requests();
        Assert::count(2, $requests);
        $messages = $requests[1]->messages;
        Assert::count(3, $messages);
        Assert::same('assistant', $messages[1]['role']);
        Assert::same('tool_use', $messages[1]['content'][0]['type']);
        Assert::same('user', $messages[2]['role']);
        $result = $messages[2]['content'][0];
        Assert::same('tool_result', $result['type']);
        Assert::same('tu_1', $result['tool_use_id']);
        Assert::false($result['is_error']);
        Assert::stringContains('Rex', $result['content']);
        Assert::count(7, $requests[1]->tools);

        $rows = $this->calls->all();
        Assert::count(1, $rows);
        Assert::same(McpToolCall::DECISION_ALLOWED, $rows[0]->decision());
        Assert::same($answer->conversationId, $rows[0]->conversationId());
    }

    public function testFourRoundsThenOneFinalCallWithTheSameToolsAndToolChoiceNone(): void
    {
        $steps = [];
        for ($i = 1; $i <= 4; $i++) {
            $steps[] = self::toolUse([["tu_{$i}", 'obter_paciente', []]], 10, 1);
        }
        $steps[] = self::text('Resposta final.', 10, 5);
        $gateway = StubAiGateway::scripted($steps);

        $answer = $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, 'Pergunta longa');

        $requests = $gateway->requests();
        Assert::count(5, $requests);
        for ($i = 0; $i < 4; $i++) {
            Assert::count(7, $requests[$i]->tools, "round {$i} offers the tools");
            Assert::null($requests[$i]->toolChoice, "round {$i} leaves tool_choice to the API default");
        }
        Assert::same($requests[0]->tools, $requests[4]->tools, 'the final call keeps the same tool definitions');
        Assert::same(['type' => 'none'], $requests[4]->toolChoice, 'the final call forbids tool use');
        Assert::false($answer->failed, 'the final call must end in an answer, not a provider error');
        Assert::same('Resposta final.', $answer->text);
        Assert::same([['tool' => 'obter_paciente', 'label' => 'cadastro do paciente', 'count' => 4]], $answer->toolSummary);
        Assert::count(4, $this->calls->all());
        Assert::same(AiInteraction::STATUS_ANSWERED, $this->interactions->all()[0]->status());
    }

    public function testToolUseDespiteToolChoiceNoneEndsWithTheCouldNotFinishText(): void
    {
        $steps = [];
        for ($i = 1; $i <= 5; $i++) {
            $steps[] = self::toolUse([["tu_{$i}", 'obter_paciente', []]], 10, 1);
        }
        $gateway = StubAiGateway::scripted($steps);

        $answer = $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, 'Pergunta longa');

        Assert::count(5, $gateway->requests());
        Assert::false($answer->failed);
        Assert::same(AiCopilotService::TIMEOUT_TEXT['pt'], $answer->text);
        Assert::count(4, $this->calls->all(), 'the fifth tool_use is not executed');
        Assert::same(AiInteraction::STATUS_ANSWERED, $this->interactions->all()[0]->status());
        Assert::same(55, $this->interactions->all()[0]->usage()->totalTokens());
    }

    public function testBudgetSpentByTheProviderCallSkipsTheTools(): void
    {
        $gateway = StubAiGateway::scripted([
            self::toolUse([['tu_1', 'obter_paciente', []]], 100, 10),
            self::text('nunca chega', 1, 1),
        ]);
        $ticks = [0.0, 46.0];
        $monotonic = static function () use (&$ticks): float {
            return count($ticks) > 1 ? array_shift($ticks) : $ticks[0];
        };

        $answer = $this->service($gateway, $monotonic)->ask('clinical', null, self::PATIENT_ID, 'Resumo');

        Assert::count(1, $gateway->requests());
        Assert::same(AiCopilotService::TIMEOUT_TEXT['pt'], $answer->text);
        Assert::same([], $this->calls->all(), 'no tool runs after the budget is spent');
        Assert::same([], $answer->toolSummary);
    }

    public function testExhaustedBudgetReturnsTheTimeoutTextWithoutAnotherCall(): void
    {
        $gateway = StubAiGateway::scripted([
            self::toolUse([['tu_1', 'obter_paciente', []]], 100, 10),
            self::text('nunca chega', 1, 1),
        ]);
        $ticks = [0.0];
        $monotonic = static function () use (&$ticks): float {
            return count($ticks) > 1 ? array_shift($ticks) : $ticks[0];
        };
        $ticks = [0.0, 46.0];

        $answer = $this->service($gateway, $monotonic)->ask('clinical', null, self::PATIENT_ID, 'Resumo');

        Assert::count(1, $gateway->requests(), 'no call after the budget');
        Assert::false($answer->failed);
        Assert::same(AiCopilotService::TIMEOUT_TEXT['pt'], $answer->text);

        $interaction = $this->interactions->all()[0];
        Assert::same(AiInteraction::STATUS_ANSWERED, $interaction->status());
        Assert::same(100, $interaction->usage()->inputTokens);

        $messages = $this->conversations->lastMessages($answer->conversationId, 10);
        Assert::same(AiCopilotService::TIMEOUT_TEXT['pt'], $messages[1]->content());
    }

    public function testThreeToolUsesDecideRbacOncePerOfferedTool(): void
    {
        $gateway = StubAiGateway::scripted([
            self::toolUse([
                ['tu_1', 'obter_paciente', ['patient_id' => self::PATIENT_ID]],
                ['tu_2', 'obter_historico_atendimentos', ['patient_id' => self::PATIENT_ID]],
                ['tu_3', 'obter_paciente', []],
            ], 100, 10),
            self::text('Rex está bem.', 200, 30),
        ]);

        $answer = $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, 'Resumo do paciente');

        Assert::false($answer->failed);
        Assert::count(3, $this->calls->all());
        $tools = [];
        foreach ($this->authorization->requests as $request) {
            if ($request->entityType() === 'mcp_tool') {
                $tools[] = $request->metadata()['tool'];
            }
        }
        sort($tools);
        $expected = array_map(static fn ($tool): string => $tool->name(), ClinicalToolset::create($this->clinical));
        sort($expected);
        Assert::same($expected, $tools, 'one RBAC decision per offered tool');
    }

    public function testDeniedToolInTheMiddleSendsAnErrorResultAndTheAnswerArrives(): void
    {
        $gateway = StubAiGateway::scripted([
            self::toolUse([['tu_1', 'consultar_agenda', []]], 10, 1),
            self::text('Não tenho acesso à agenda.', 10, 5),
        ]);

        $answer = $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, 'Agenda de hoje?');

        $result = $gateway->requests()[1]->messages[2]['content'][0];
        Assert::same('tool_result', $result['type']);
        Assert::true($result['is_error'], 'denied tool must be sent as is_error');
        Assert::same('Não tenho acesso à agenda.', $answer->text);
        Assert::false($answer->failed);
        Assert::same([], $answer->toolSummary, 'a denied tool is not listed as consulted');

        $rows = $this->calls->all();
        Assert::count(1, $rows);
        Assert::same(McpToolCall::DECISION_DENIED, $rows[0]->decision());
        Assert::same('not_offered', $rows[0]->denyReason());
    }

    public function testProviderErrorRecordsTheFailureAndAFailedMessage(): void
    {
        $gateway = StubAiGateway::scripted([
            self::toolUse([['tu_1', 'obter_paciente', []]], 50, 5),
            new AiProviderException('ai_overloaded', 529),
        ]);

        $answer = $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, 'Resumo');

        Assert::true($answer->failed);
        Assert::same('ai_overloaded', $answer->reason);

        $interactions = $this->interactions->all();
        Assert::count(1, $interactions);
        Assert::same(AiInteraction::STATUS_FAILED, $interactions[0]->status());
        Assert::same('ai_overloaded', $interactions[0]->errorCode());
        Assert::same(50, $interactions[0]->usage()->inputTokens);

        $messages = $this->conversations->lastMessages($answer->conversationId, 10);
        Assert::count(2, $messages);
        Assert::true($messages[1]->isFailed());
        Assert::same('ai_overloaded', $messages[1]->content());
    }

    public function testRecordsTwoMessagesAndOneAnsweredInteractionWithSummedUsageAndOneCorrelationId(): void
    {
        $gateway = StubAiGateway::scripted([
            self::toolUse([['tu_1', 'obter_paciente', []]], 100, 10),
            self::toolUse([['tu_2', 'obter_paciente', ['patient_id' => self::PATIENT_ID]]], 200, 20),
            self::text('Resumo pronto.', 300, 30),
        ]);

        $answer = $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, 'Resumo');

        $messages = $this->conversations->lastMessages($answer->conversationId, 10);
        Assert::count(2, $messages);
        Assert::same('user', $messages[0]->role());
        Assert::same('Resumo', $messages[0]->content());
        Assert::same('assistant', $messages[1]->role());
        Assert::same('Resumo pronto.', $messages[1]->content());
        Assert::same([['tool' => 'obter_paciente', 'label' => 'cadastro do paciente', 'count' => 2]], $messages[1]->toolSummary());

        $interactions = $this->interactions->all();
        Assert::count(1, $interactions);
        $interaction = $interactions[0];
        Assert::same(AiPurpose::COPILOT_CLINICAL, $interaction->purpose());
        Assert::same(AiInteraction::STATUS_ANSWERED, $interaction->status());
        Assert::same(AiPurpose::SUBJECT_CONVERSATION, $interaction->subjectType());
        Assert::same($answer->conversationId, $interaction->subjectId());
        Assert::same(600, $interaction->usage()->inputTokens);
        Assert::same(60, $interaction->usage()->outputTokens);
        Assert::same(660, $interaction->costMicroUsd(), 'cost is summed over the rounds');
        Assert::same(hash('sha256', 'Resumo'), $interaction->inputSha256());
        Assert::null($interaction->suggestionText());

        $rows = $this->calls->all();
        Assert::count(2, $rows);
        foreach ($rows as $row) {
            Assert::same($interaction->correlationId(), $row->correlationId());
        }
        Assert::true($interaction->correlationId() !== '');
    }

    public function testQuestionWithPhoneAndCpfIsMinimizedInTitleMessageAndRequest(): void
    {
        $gateway = StubAiGateway::scripted([self::text('Ok.', 1, 1)]);
        $question = 'Tutora Maria Silva ligou do (11) 98765-4321, CPF 123.456.789-09, sobre o Rex';

        $answer = $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, $question);

        $conversation = $this->conversations->findOwned($answer->conversationId);
        $stored = $this->conversations->lastMessages($answer->conversationId, 10)[0]->content();
        $sent = $gateway->requests()[0]->messages[0]['content'];

        foreach (['title' => $conversation->title(), 'message' => $stored, 'request' => $sent] as $where => $text) {
            Assert::stringContains('[TELEFONE]', $text, "{$where} must carry [TELEFONE]");
            Assert::stringContains('[CPF]', $text, "{$where} must carry [CPF]");
            Assert::false(str_contains($text, '98765'), "{$where} must not carry the phone");
            Assert::false(str_contains($text, '123.456'), "{$where} must not carry the CPF");
            Assert::false(str_contains($text, 'Maria Silva'), "{$where} must not carry the tutor name");
        }
        Assert::true(mb_strlen($conversation->title()) <= AiConversation::TITLE_MAX);
    }

    public function testHistoryContinuesAnOwnedConversationAndSkipsFailedMessages(): void
    {
        $first = $this->service(StubAiGateway::scripted([new AiProviderException('ai_overloaded')]))
            ->ask('clinical', null, self::PATIENT_ID, 'Primeira');
        $second = $this->service(StubAiGateway::scripted([self::text('Resposta 2', 1, 1)]))
            ->ask('clinical', $first->conversationId, self::PATIENT_ID, 'Segunda');
        $gateway = StubAiGateway::scripted([self::text('Resposta 3', 1, 1)]);

        $third = $this->service($gateway)->ask('clinical', $first->conversationId, self::PATIENT_ID, 'Terceira');

        Assert::same($first->conversationId, $second->conversationId);
        Assert::same($first->conversationId, $third->conversationId);
        Assert::same([
            ['role' => 'user', 'content' => "Primeira\n\nSegunda"],
            ['role' => 'assistant', 'content' => 'Resposta 2'],
            ['role' => 'user', 'content' => 'Terceira'],
        ], $gateway->requests()[0]->messages);
    }

    public function testAvailabilityFollowsThe8AOrderWithoutCallingTheGateway(): void
    {
        $gateway = StubAiGateway::scripted([]);

        $this->flags = new FakeFeatureFlagRepository([]);
        Assert::same('ai_disabled', $this->service($gateway)->availability('clinical'));

        $this->flags = new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]);
        $this->settings = new FakeAiTenantSettingRepository(self::acceptedSetting('2025-01-v0'));
        Assert::same('ai_terms_pending', $this->service($gateway)->availability('clinical'));

        $this->settings = new FakeAiTenantSettingRepository(self::acceptedSetting(AiTerms::VERSION));
        $this->interactions->setMonthlyTokenUsage(PHP_INT_MAX);
        Assert::same('ai_quota_exceeded', $this->service($gateway)->availability('clinical'));

        $this->interactions->setMonthlyTokenUsage(0);
        Assert::same('ai_not_configured', $this->service(new UnconfiguredAiGateway())->availability('clinical'));

        Assert::null($this->service($gateway)->availability('clinical'));
        Assert::same([], $gateway->requests());
    }

    public function testManagementNeedsItsOwnFlag(): void
    {
        $gateway = StubAiGateway::scripted([]);

        Assert::same('ai_disabled', $this->service($gateway)->availability('management'));

        $this->flags = new FakeFeatureFlagRepository([
            FeatureFlagRepositoryInterface::AI_ASSISTANT,
            FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT,
        ]);
        Assert::null($this->service($gateway)->availability('management'));
        Assert::same([], $gateway->requests());
    }

    public function testUnavailableAskThrowsWithoutRecording(): void
    {
        $this->flags = new FakeFeatureFlagRepository([]);
        $gateway = StubAiGateway::scripted([]);

        try {
            $this->service($gateway)->ask('clinical', null, self::PATIENT_ID, 'Resumo');
            throw new AssertionFailedException('Expected AiUnavailableException');
        } catch (AiUnavailableException $e) {
            Assert::same('ai_disabled', $e->reason());
        }

        Assert::same([], $gateway->requests());
        Assert::same([], $this->interactions->all());
        Assert::same([], $this->conversations->messages());
    }

    public function testAnchorOutsideTheUnitIsNotFoundAndRecordsNothing(): void
    {
        $gateway = StubAiGateway::scripted([self::text('x', 1, 1)]);

        Assert::throws(AiSubjectNotFoundException::class, fn () => $this->service($gateway)->ask('clinical', null, self::OTHER_PATIENT_ID, 'Resumo'));
        Assert::throws(AiSubjectNotFoundException::class, fn () => $this->service($gateway)->assertAnchor(self::OTHER_PATIENT_ID));

        Assert::same([], $gateway->requests());
        Assert::same([], $this->interactions->all());
        Assert::same([], $this->conversations->messages());
        Assert::same([], $this->conversations->listOwned(null, null, 10));
        Assert::same([], $this->calls->all());
    }

    public function testConversationOfAnotherUserOrUnitIsNotFound(): void
    {
        $now = new DateTimeImmutable();
        $otherUser = $this->conversations->insert(AiConversation::start(self::TENANT_ID, self::UNIT_ID, 99, 'clinical', null, 'Outra', $now));
        $otherUnit = $this->conversations->insert(AiConversation::start(self::TENANT_ID, 6, self::USER_ID, 'clinical', null, 'Outra', $now));
        $gateway = StubAiGateway::scripted([self::text('x', 1, 1)]);

        Assert::throws(AiSubjectNotFoundException::class, fn () => $this->service($gateway)->ask('clinical', (int) $otherUser->id(), null, 'Oi'));
        Assert::throws(AiSubjectNotFoundException::class, fn () => $this->service($gateway)->ask('clinical', (int) $otherUnit->id(), null, 'Oi'));
        Assert::null($this->service($gateway)->conversation((int) $otherUser->id()));
        Assert::false($this->service($gateway)->deleteConversation((int) $otherUnit->id()));
        Assert::same([], $gateway->requests());
        Assert::same([], $this->interactions->all());
    }

    public function testInvalidInputIsRejectedBeforeAnything(): void
    {
        $gateway = StubAiGateway::scripted([]);
        $service = $this->service($gateway);

        Assert::throws(InvalidArgumentException::class, fn () => $service->ask('financeiro', null, null, 'Oi'));
        Assert::throws(InvalidArgumentException::class, fn () => $service->ask('clinical', null, null, '   '));
        Assert::throws(InvalidArgumentException::class, fn () => $service->ask('clinical', null, null, str_repeat('a', AiCopilotService::MAX_QUESTION_CHARS + 1)));

        $this->flags = new FakeFeatureFlagRepository([
            FeatureFlagRepositoryInterface::AI_ASSISTANT,
            FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT,
        ]);
        Assert::throws(InvalidArgumentException::class, fn () => $this->service($gateway)->ask('management', null, self::PATIENT_ID, 'Oi'));
        Assert::same([], $gateway->requests());
    }

    public function testAskIsGuardedByThePanelPermission(): void
    {
        $this->authorization->setAllowed(false);
        $gateway = StubAiGateway::scripted([]);

        Assert::throws(AuthorizationDenied::class, fn () => $this->service($gateway)->ask('clinical', null, null, 'Oi'));
        Assert::same('AiCopilotPanel::onAsk', $this->authorization->requests[0]->action());
        Assert::same([], $gateway->requests());
    }

    public function testConversationsListsAndOpensOwnedConversations(): void
    {
        $answer = $this->service(StubAiGateway::scripted([self::text('Resposta', 1, 1)]))
            ->ask('clinical', null, self::PATIENT_ID, 'Pergunta');
        $service = $this->service(StubAiGateway::scripted([]));

        $list = $service->conversations('clinical', self::PATIENT_ID, 10);
        Assert::count(1, $list);
        Assert::same($answer->conversationId, $list[0]->id());

        $opened = $service->conversation($answer->conversationId);
        Assert::notNull($opened);
        Assert::same($answer->conversationId, $opened['conversation']->id());
        Assert::count(2, $opened['messages']);

        Assert::true($service->deleteConversation($answer->conversationId));
        Assert::null($service->conversation($answer->conversationId));
    }

    private function service(AiGatewayInterface $gateway, ?Closure $monotonic = null): AiCopilotService
    {
        $registry = new ToolRegistry(ClinicalToolset::create($this->clinical), $this->flags, $this->authorization, $this->context);
        $policy = new ToolPolicy(
            $registry,
            new JsonSchemaValidator(),
            $this->calls,
            new ToolRateLimiter(new LoginRateLimiter(new FakeRedis(), ToolRateLimiter::MAX_CALLS, ToolRateLimiter::DECAY_SECONDS, ToolRateLimiter::PREFIX)),
            null,
        );

        return new AiCopilotService(
            $gateway,
            $this->interactions,
            $this->settings,
            $this->flags,
            $this->conversations,
            $registry,
            $policy,
            $this->clinical,
            $this->authorization,
            null,
            $this->context,
            'pt',
            null,
            $monotonic ?? static fn (): float => 0.0,
        );
    }

    private static function text(string $text, int $in, int $out): AiCompletionResult
    {
        return new AiCompletionResult(
            $text,
            'fake',
            self::MODEL,
            new AiUsage($in, $out),
            $in + $out,
            'end_turn',
            'req-text',
            [],
            [['type' => 'text', 'text' => $text]],
        );
    }

    /** @param list<array{0: string, 1: string, 2: array<string, mixed>}> $uses */
    private static function toolUse(array $uses, int $in, int $out): AiCompletionResult
    {
        $toolUses = [];
        $content = [];
        foreach ($uses as [$id, $name, $input]) {
            $toolUses[] = ['id' => $id, 'name' => $name, 'input' => $input];
            $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
        }

        return new AiCompletionResult('', 'fake', self::MODEL, new AiUsage($in, $out), $in + $out, 'tool_use', 'req-tool', $toolUses, $content);
    }

    private static function acceptedSetting(string $version): AiTenantSetting
    {
        $setting = AiTenantSetting::defaults(self::TENANT_ID);
        $setting->acceptTerms($version, self::USER_ID, new DateTimeImmutable('2026-10-01 09:00:00'));

        return $setting;
    }

    /** @return array<string, mixed> */
    private static function patientRow(): array
    {
        return [
            'id' => self::PATIENT_ID,
            'name' => 'Rex',
            'species' => 'canino',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2020-01-01',
            'allergies' => null,
            'weight_kg' => '12.00',
            'weight_measured_at' => null,
            'tutor_full_name' => 'Maria Silva',
        ];
    }
}

