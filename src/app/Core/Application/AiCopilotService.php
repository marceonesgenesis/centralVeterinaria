<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Ai\AiConfig;
use CentralVet\Ai\AiGatewayFactory;
use CentralVet\Ai\AiLocale;
use CentralVet\Ai\AiPromptCatalog;
use CentralVet\Ai\PiiMinimizer;
use CentralVet\Ai\Tools\Clinical\ClinicalToolset;
use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolPolicy;
use CentralVet\Ai\Tools\ToolRateLimiter;
use CentralVet\Ai\Tools\ToolRegistry;
use CentralVet\Audit\PdoAuditLogWriter;
use CentralVet\Authorization\AdiantiProgramPermissionProvider;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\RbacAuthorizationService;
use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiConversationMessage;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\AiConversationRepositoryInterface;
use CentralVet\Domain\Contract\AiGatewayInterface;
use CentralVet\Domain\Contract\AiInteractionRepositoryInterface;
use CentralVet\Domain\Contract\AiTenantSettingRepositoryInterface;
use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Domain\Exception\AiProviderException;
use CentralVet\Domain\Exception\AiSubjectNotFoundException;
use CentralVet\Domain\Exception\AiUnavailableException;
use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Persistence\AiConversationRepository;
use CentralVet\Persistence\AiInteractionRepository;
use CentralVet\Persistence\AiTenantSettingRepository;
use CentralVet\Persistence\CopilotClinicalQuery;
use CentralVet\Persistence\FeatureFlagRepository;
use CentralVet\Persistence\McpToolCallRepository;
use CentralVet\Tenancy\AdiantiSessionContextSource;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Read-only copilot of Fase 8B (spec § 3.3).
 *
 * `ask` checks, in order: active unit, family and question; RBAC of
 * `AiCopilotPanel::onAsk`; availability (as the 8A, plus the management
 * flag); the anchor patient in the unit; the owned conversation. Then the
 * minimized question is stored, one `ai_interaction` is started and the
 * tool-use loop runs: at most MAX_ROUNDS rounds within BUDGET_SECONDS, each
 * `tool_use` through ToolPolicy (fail-closed, audited), then one final call
 * without tools when the rounds run out, or TIMEOUT_TEXT without another
 * call when the budget does. The interaction is recorded in every case
 * after it starts (`answered` with the summed usage, or `failed`); the
 * answer text lives only in the conversation (90-day retention). Logs and
 * exception messages carry ids and class names only.
 */
final class AiCopilotService
{
    public const MAX_ROUNDS = 4;
    public const BUDGET_SECONDS = 45;
    public const HISTORY_LIMIT = 20;
    public const MAX_QUESTION_CHARS = 2000;

    public const TIMEOUT_TEXT = [
        'pt' => 'Não consegui concluir a consulta, tente uma pergunta mais específica.',
        'en' => 'I could not finish the lookup, try a more specific question.',
    ];

    /** Messages shown when a conversation is opened. */
    private const CONVERSATION_VIEW_LIMIT = 500;

    private const ACTION_ASK = 'AiCopilotPanel::onAsk';
    private const ACTION_OPEN = 'AiCopilotPanel::onOpen';
    private const ACTION_DELETE = 'AiCopilotPanel::onDelete';
    private const ENTITY_TYPE = 'ai_conversation';

    private const PURPOSES = [
        ToolFamily::CLINICAL => AiPurpose::COPILOT_CLINICAL,
        ToolFamily::MANAGEMENT => AiPurpose::COPILOT_MANAGEMENT,
    ];

    private readonly Closure $clock;
    private readonly Closure $monotonicSeconds;

    /**
     * $minimizer is accepted for the planned signature only: PiiMinimizer is
     * a static utility with a private constructor, so callers pass null.
     */
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly AiInteractionRepositoryInterface $interactions,
        private readonly AiTenantSettingRepositoryInterface $settings,
        private readonly FeatureFlagRepositoryInterface $flags,
        private readonly AiConversationRepositoryInterface $conversations,
        private readonly ToolRegistry $registry,
        private readonly ToolPolicy $policy,
        private readonly CopilotClinicalQueryInterface $clinical,
        private readonly AuthorizationPolicyInterface $authorization,
        ?PiiMinimizer $minimizer,
        private readonly TenantContext $context,
        private readonly string $locale = 'pt',
        ?Closure $clock = null,
        ?Closure $monotonicSeconds = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
        $this->monotonicSeconds = $monotonicSeconds ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * Wires the PDO repositories, the clinical toolset, the tool policy (rate
     * limiter of the environment), RBAC on the Adianti session and the
     * gateway of the environment configuration on `$connection`.
     *
     * @throws AiUnavailableException `ai_not_configured` when the AI environment is invalid.
     */
    public static function forContext(TenantContext $context, PDO $connection, string $locale): self
    {
        try {
            $gateway = AiGatewayFactory::fromConfig(AiConfig::fromEnvironment());
        } catch (Throwable $e) {
            error_log('AiCopilotService: invalid AI configuration (' . $e::class . ')');

            throw new AiUnavailableException('ai_not_configured');
        }

        $authorization = new RbacAuthorizationService(
            new AdiantiProgramPermissionProvider(new AdiantiSessionContextSource()),
            new PdoAuditLogWriter($connection),
        );
        $flags = new FeatureFlagRepository($context, $connection);
        $clinical = new CopilotClinicalQuery($context, $connection);
        $registry = new ToolRegistry(ClinicalToolset::create($clinical), $flags, $authorization, $context);
        $policy = new ToolPolicy(
            $registry,
            new JsonSchemaValidator(),
            $authorization,
            new McpToolCallRepository($context, $connection),
            ToolRateLimiter::fromEnvironment(),
            null,
        );

        return new self(
            $gateway,
            new AiInteractionRepository($context, $connection),
            new AiTenantSettingRepository($context, $connection),
            $flags,
            new AiConversationRepository($context, $connection),
            $registry,
            $policy,
            $clinical,
            $authorization,
            null,
            $context,
            $locale,
        );
    }

    /**
     * @throws InvalidArgumentException for an unknown family, a blank or too long question, or a management question with an anchor.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied without `AiCopilotPanel::onAsk` in the active unit.
     * @throws AiUnavailableException when the copilot of the family is unavailable (see availability()).
     * @throws AiSubjectNotFoundException for an anchor outside the unit or a conversation that is not owned.
     */
    public function ask(string $family, ?int $conversationId, ?int $anchorPatientId, string $question): CopilotAnswer
    {
        $unitId = $this->context->requireUnitId();
        $purpose = self::purpose($family);
        $question = trim($question);

        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            throw new InvalidArgumentException('Copilot question must have 1 to ' . self::MAX_QUESTION_CHARS . ' characters');
        }

        $this->authorize(self::ACTION_ASK, $conversationId, ['family' => $family]);

        $reason = $this->availability($family);
        if ($reason !== null) {
            throw new AiUnavailableException($reason);
        }

        if ($anchorPatientId !== null) {
            if ($family !== ToolFamily::CLINICAL) {
                throw new InvalidArgumentException('Only the clinical copilot may be anchored to a patient');
            }

            if (!$this->clinical->patientInUnit($anchorPatientId)) {
                throw new AiSubjectNotFoundException();
            }
        }

        $conversation = null;
        if ($conversationId !== null) {
            $conversation = $this->conversations->findOwned($conversationId);

            if ($conversation === null || $conversation->family() !== $family
                || ($anchorPatientId !== null && $conversation->anchorPatientId() !== $anchorPatientId)) {
                throw new AiSubjectNotFoundException();
            }

            $anchorPatientId = $conversation->anchorPatientId();

            if ($anchorPatientId !== null && !$this->clinical->patientInUnit($anchorPatientId)) {
                throw new AiSubjectNotFoundException();
            }
        }

        $minimized = PiiMinimizer::minimize(
            $question,
            $anchorPatientId !== null ? $this->clinical->tutorNames($anchorPatientId) : [],
        );

        $now = ($this->clock)();
        $conversation ??= $this->conversations->insert(AiConversation::start(
            $this->context->tenantId(),
            $unitId,
            $this->context->userId(),
            $family,
            $anchorPatientId,
            mb_substr($minimized, 0, AiConversation::TITLE_MAX),
            $now,
        ));
        $conversationId = (int) $conversation->id();

        $this->conversations->appendMessage(AiConversationMessage::user($conversationId, $minimized, $now));
        $messages = self::history($this->conversations->lastMessages($conversationId, self::HISTORY_LIMIT));

        $correlationId = CorrelationIdContext::current() ?? CorrelationIdContext::generate();
        $interaction = AiInteraction::start(
            $this->context->tenantId(),
            $unitId,
            $this->context->userId(),
            $purpose,
            $conversationId,
            AiPromptCatalog::VERSIONS[$purpose],
            hash('sha256', $minimized),
            $correlationId,
        );

        $toolContext = ToolContext::create($this->context, $correlationId, $conversationId, $purpose, $anchorPatientId);

        return $this->converse($conversation, $interaction, $toolContext, $family, $purpose, $messages);
    }

    /**
     * Why the copilot of the family is unavailable, in the 8A order:
     * `ai_disabled` (flag `ai_assistant`, and `ai_copilot_management` for
     * management) → `ai_terms_pending` → `ai_quota_exceeded` (month of the
     * application timezone) → `ai_not_configured`; null when available.
     * Never calls the gateway.
     */
    public function availability(string $family): ?string
    {
        self::purpose($family);

        if (!$this->flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT)) {
            return 'ai_disabled';
        }

        if ($family === ToolFamily::MANAGEMENT && !$this->flags->isEnabled(FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT)) {
            return 'ai_disabled';
        }

        $setting = $this->settings->find() ?? AiTenantSetting::defaults($this->context->tenantId());

        if (!$setting->hasAcceptedTerms(AiTerms::VERSION)) {
            return 'ai_terms_pending';
        }

        if ($this->interactions->monthlyTokenUsage($this->monthStart()) >= $setting->monthlyTokenQuota()) {
            return 'ai_quota_exceeded';
        }

        if (!$this->gateway->isConfigured()) {
            return 'ai_not_configured';
        }

        return null;
    }

    /**
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied without `AiCopilotPanel::onOpen`.
     * @throws AiSubjectNotFoundException when the patient is not of the active unit.
     */
    public function assertAnchor(int $patientId): void
    {
        $this->context->requireUnitId();
        $this->authorize(self::ACTION_OPEN, null, ['anchor' => true]);

        if (!$this->clinical->patientInUnit($patientId)) {
            throw new AiSubjectNotFoundException();
        }
    }

    /** @return list<AiConversation> owned conversations, most recent first */
    public function conversations(?string $family, ?int $anchorPatientId, int $limit): array
    {
        $this->context->requireUnitId();

        if ($family !== null) {
            self::purpose($family);
        }

        $this->authorize(self::ACTION_OPEN, null, ['list' => true]);

        return $this->conversations->listOwned($family, $anchorPatientId, $limit);
    }

    /** @return array{conversation: AiConversation, messages: list<AiConversationMessage>}|null */
    public function conversation(int $id): ?array
    {
        $this->context->requireUnitId();
        $this->authorize(self::ACTION_OPEN, $id, []);

        $conversation = $this->conversations->findOwned($id);

        if ($conversation === null) {
            return null;
        }

        return [
            'conversation' => $conversation,
            'messages' => $this->conversations->lastMessages($id, self::CONVERSATION_VIEW_LIMIT),
        ];
    }

    public function deleteConversation(int $id): bool
    {
        $this->context->requireUnitId();
        $this->authorize(self::ACTION_DELETE, $id, []);

        return $this->conversations->deleteOwned($id);
    }

    /** @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages */
    private function converse(
        AiConversation $conversation,
        AiInteraction $interaction,
        ToolContext $toolContext,
        string $family,
        string $purpose,
        array $messages,
    ): CopilotAnswer {
        $lang = AiLocale::fromLanguage($this->locale);
        $system = AiPromptCatalog::copilotSystem($purpose, $this->locale);
        $promptVersion = AiPromptCatalog::VERSIONS[$purpose];
        $tools = $this->registry->definitions($family);
        $offered = array_map(static fn (array $tool): string => $tool['name'], $tools);

        $usage = AiUsage::none();
        $cost = null;
        $last = null;
        $summary = [];
        $rounds = 0;
        $started = $this->monotonic();

        try {
            while (true) {
                $final = $rounds >= self::MAX_ROUNDS;
                $result = $this->gateway->complete(new AiCompletionRequest(
                    $purpose,
                    $promptVersion,
                    $system,
                    '',
                    $messages,
                    $final ? [] : $tools,
                ));
                $last = $result;
                $usage = $usage->plus($result->usage);
                $cost = self::addCost($cost, $result->costMicroUsd);

                if ($final || $result->stopReason !== 'tool_use' || $result->toolUses === []) {
                    $text = trim($result->text);

                    if ($text === '') {
                        return $this->fail($conversation, $interaction, 'ai_provider_error', $started, $usage, $result->model, $cost);
                    }

                    break;
                }

                $rounds++;
                $messages[] = ['role' => 'assistant', 'content' => $result->content];
                $results = [];

                foreach ($result->toolUses as $use) {
                    $name = (string) ($use['name'] ?? '');
                    $input = is_array($use['input'] ?? null) ? $use['input'] : [];
                    $outcome = $this->policy->execute($toolContext, $family, $name, $input, $offered);
                    $results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => (string) ($use['id'] ?? ''),
                        'content' => $outcome->content,
                        'is_error' => $outcome->isError,
                    ];

                    if (!$outcome->isError) {
                        $summary = $this->countTool($summary, $family, $name, $lang);
                    }
                }

                $messages[] = ['role' => 'user', 'content' => $results];

                if ($this->monotonic() - $started >= self::BUDGET_SECONDS) {
                    $text = self::TIMEOUT_TEXT[$lang] ?? self::TIMEOUT_TEXT['pt'];

                    break;
                }
            }
        } catch (AiProviderException $e) {
            $partial = $e->usage() !== null ? $usage->plus($e->usage()) : $usage;

            return $this->fail($conversation, $interaction, $e->reason(), $started, $partial, $e->model() ?? $last?->model, self::addCost($cost, $e->costMicroUsd()));
        } catch (Throwable $e) {
            error_log('AiCopilotService: copilot loop failed (' . $e::class . ', correlation_id=' . $toolContext->correlationId . ')');

            return $this->fail($conversation, $interaction, 'ai_provider_error', $started, $usage, $last?->model, $cost);
        }

        /** @var AiCompletionResult $last */
        $interaction->recordAnswer(
            $usage,
            $cost,
            $last->provider !== '' ? $last->provider : $this->gateway->providerName(),
            $last->model,
            $last->stopReason,
            $last->providerRequestId,
            $this->elapsedMs($started),
        );
        $this->interactions->insert($interaction);

        $summary = array_values($summary);
        $now = ($this->clock)();
        $this->conversations->appendMessage(AiConversationMessage::assistant((int) $conversation->id(), $text, $summary, $now));
        $this->touch($conversation, $now);

        return new CopilotAnswer((int) $conversation->id(), $text, $summary, false, null);
    }

    private function fail(
        AiConversation $conversation,
        AiInteraction $interaction,
        string $reason,
        float $started,
        AiUsage $usage,
        ?string $model,
        ?int $cost,
    ): CopilotAnswer {
        $interaction->recordFailure($reason, $this->elapsedMs($started), $usage, $model, $this->gateway->providerName(), $cost);
        $this->interactions->insert($interaction);

        $now = ($this->clock)();
        $this->conversations->appendMessage(AiConversationMessage::failed((int) $conversation->id(), $reason, $now));
        $this->touch($conversation, $now);

        return new CopilotAnswer((int) $conversation->id(), '', [], true, $reason);
    }

    private function touch(AiConversation $conversation, DateTimeImmutable $now): void
    {
        $conversation->touch($now);
        $this->conversations->touch($conversation);
    }

    /**
     * @param array<string, array{tool: string, label: string, count: int}> $summary
     * @return array<string, array{tool: string, label: string, count: int}>
     */
    private function countTool(array $summary, string $family, string $name, string $lang): array
    {
        if (!isset($summary[$name])) {
            $tool = $this->registry->find($family, $name);
            $summary[$name] = ['tool' => $name, 'label' => $tool !== null ? $tool->label($lang) : $name, 'count' => 0];
        }

        $summary[$name]['count']++;

        return $summary;
    }

    /**
     * Stored messages → Messages API turns: failed answers are skipped, the
     * history starts with a user turn and consecutive turns of the same role
     * are joined.
     *
     * @param list<AiConversationMessage> $stored
     * @return list<array{role: string, content: string}>
     */
    private static function history(array $stored): array
    {
        $messages = [];

        foreach ($stored as $message) {
            if ($message->isFailed()) {
                continue;
            }

            $role = $message->role();

            if ($messages === [] && $role !== AiConversationMessage::ROLE_USER) {
                continue;
            }

            $last = count($messages) - 1;

            if ($last >= 0 && $messages[$last]['role'] === $role) {
                $messages[$last]['content'] .= "\n\n" . $message->content();
            } else {
                $messages[] = ['role' => $role, 'content' => $message->content()];
            }
        }

        return $messages;
    }

    /** @param array<string, mixed> $metadata ids and codes only */
    private function authorize(string $action, ?int $conversationId, array $metadata): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceTenantId: $this->context->tenantId(),
            resourceUnitId: $this->context->unitId(),
            entityType: self::ENTITY_TYPE,
            entityId: $conversationId,
            metadata: $metadata,
        ))->assertAllowed();
    }

    /** @throws InvalidArgumentException for a family outside ToolFamily::ALL */
    private static function purpose(string $family): string
    {
        if (!in_array($family, ToolFamily::ALL, true)) {
            throw new InvalidArgumentException('Unknown copilot family');
        }

        return self::PURPOSES[$family];
    }

    private static function addCost(?int $total, ?int $cost): ?int
    {
        if ($cost === null) {
            return $total;
        }

        return ($total ?? 0) + $cost;
    }

    private function monotonic(): float
    {
        return (float) ($this->monotonicSeconds)();
    }

    private function elapsedMs(float $started): int
    {
        return max(0, (int) round(($this->monotonic() - $started) * 1000));
    }

    /** First instant of the current month in the application timezone (as AiAssistService). */
    private function monthStart(): DateTimeImmutable
    {
        return ($this->clock)()
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->modify('first day of this month')
            ->setTime(0, 0);
    }
}
