<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Ai\AiConfig;
use CentralVet\Ai\AiGatewayFactory;
use CentralVet\Ai\AiPromptCatalog;
use CentralVet\Assistant\Contract\AiAssistantInterface;
use CentralVet\Audit\AuditEvent;
use CentralVet\Audit\Contract\AuditLogWriterInterface;
use CentralVet\Audit\PdoAuditLogWriter;
use CentralVet\Authorization\AdiantiProgramPermissionProvider;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\RbacAuthorizationService;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiSuggestion;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\AiClinicalContextQueryInterface;
use CentralVet\Domain\Contract\AiGatewayInterface;
use CentralVet\Domain\Contract\AiInteractionRepositoryInterface;
use CentralVet\Domain\Contract\AiTenantSettingRepositoryInterface;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Domain\Exception\AiInteractionNotFoundException;
use CentralVet\Domain\Exception\AiProviderException;
use CentralVet\Domain\Exception\AiSubjectNotFoundException;
use CentralVet\Domain\Exception\AiUnavailableException;
use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Persistence\AiClinicalContextQuery;
use CentralVet\Persistence\AiInteractionRepository;
use CentralVet\Persistence\AiTenantSettingRepository;
use CentralVet\Persistence\FeatureFlagRepository;
use CentralVet\Tenancy\AdiantiSessionContextSource;
use CentralVet\Tenancy\Exception\MissingTenantContext;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * AI clinical assistant of Fase 8A (T-11).
 *
 * Each generation runs, in order: active unit (fail-closed), subject load
 * (encounter or exam result of the tenant), RBAC on the host screen action
 * with the subject unit, input checks, availability (flag, current terms,
 * monthly quota, configured gateway) and only then the minimized prompt and
 * the provider call. A suggestion or a provider failure is recorded in
 * `ai_interaction` and audited; nothing reaches the clinical record until
 * the professional decides (`decide`). Audit data and exception messages
 * carry ids, purpose, model, tokens, cost and codes only, never the input,
 * the prompt, the suggestion or the final text.
 */
final class AiAssistService implements AiAssistantInterface
{
    public const MAX_FREE_TEXT_CHARS = 8000;

    public const AUDIT_GENERATED = 'ai.suggestion.generated';
    public const AUDIT_FAILED = 'ai.suggestion.failed';
    public const AUDIT_DECIDED = 'ai.suggestion.decided';

    private const ENTITY_TYPE = 'ai_interaction';

    private readonly Closure $clock;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly AiInteractionRepositoryInterface $interactions,
        private readonly AiTenantSettingRepositoryInterface $settings,
        private readonly FeatureFlagRepositoryInterface $flags,
        private readonly AiClinicalContextQueryInterface $clinical,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly AuditLogWriterInterface $audit,
        private readonly TenantContext $context,
        private readonly string $locale = 'pt',
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Wires the PDO repositories, the clinical context query, the gateway of
     * the environment configuration, RBAC and the audit log on `$connection`.
     *
     * @throws AiUnavailableException `ai_not_configured` when the AI environment is invalid.
     */
    public static function forContext(TenantContext $context, PDO $connection, string $locale): self
    {
        try {
            $gateway = AiGatewayFactory::fromConfig(AiConfig::fromEnvironment());
        } catch (Throwable $e) {
            error_log('AiAssistService: invalid AI configuration (' . $e::class . ')');

            throw new AiUnavailableException('ai_not_configured');
        }

        $audit = new PdoAuditLogWriter($connection);

        return new self(
            $gateway,
            new AiInteractionRepository($context, $connection),
            new AiTenantSettingRepository($context, $connection),
            new FeatureFlagRepository($context, $connection),
            new AiClinicalContextQuery($context, $connection),
            new RbacAuthorizationService(
                new AdiantiProgramPermissionProvider(new AdiantiSessionContextSource()),
                $audit,
            ),
            $audit,
            $context,
            $locale,
        );
    }

    public function summarizePatient(int $encounterId): AiSuggestion
    {
        $unitId = $this->context->requireUnitId();
        $encounter = $this->clinical->encounterContext($encounterId) ?? throw new AiSubjectNotFoundException();

        $this->authorizeGeneration(AiPurpose::PATIENT_SUMMARY, $encounter);
        $this->assertAvailable();

        $history = $this->clinical->patientHistory((int) $encounter['patient_id'], $encounterId);

        return $this->generate(AiPurpose::PATIENT_SUMMARY, $encounterId, $unitId, [
            'encounter' => $encounter,
            'history' => $history,
        ]);
    }

    public function organizeAnamnesis(int $encounterId, string $freeText): AiSuggestion
    {
        $unitId = $this->context->requireUnitId();
        $encounter = $this->clinical->encounterContext($encounterId) ?? throw new AiSubjectNotFoundException();

        $this->authorizeGeneration(AiPurpose::ANAMNESIS_ORGANIZE, $encounter);

        $text = trim($freeText);

        if ($text === '') {
            throw new AiUnavailableException('ai_empty_input');
        }

        if (mb_strlen($text) > self::MAX_FREE_TEXT_CHARS) {
            throw new AiUnavailableException('ai_input_too_long');
        }

        $this->assertAvailable();

        return $this->generate(AiPurpose::ANAMNESIS_ORGANIZE, $encounterId, $unitId, [
            'encounter' => $encounter,
            'free_text' => $text,
        ]);
    }

    public function summarizeExamResult(int $examResultId): AiSuggestion
    {
        $unitId = $this->context->requireUnitId();
        $exam = $this->clinical->examResultContext($examResultId) ?? throw new AiSubjectNotFoundException();

        $this->authorizeGeneration(AiPurpose::EXAM_SUMMARY, $exam);
        $this->assertAvailable();

        return $this->generate(AiPurpose::EXAM_SUMMARY, $examResultId, $unitId, ['exam' => $exam]);
    }

    /**
     * The one rule for a posted decision (T-29), shared by ExamResultForm and
     * EncounterView. The posted text is normalized (CRLF/CR → LF, trim).
     * `discarded` keeps no text; accepted/edited with an empty text is
     * refused; `edited` keeps the posted text. "Accept" stays `accepted` only
     * when the posted text is the shown suggestion — by the posted SHA-256
     * when there is one, otherwise by the stored suggestion text — and
     * becomes `edited` with the posted text otherwise, so an edit is never
     * lost.
     *
     * @return array{decision: string, final_text: ?string}
     * @throws InvalidArgumentException `Unknown AI decision` or `Edited AI text must not be empty`.
     */
    public static function resolveDecision(string $decision, string $postedText, string $postedSha, string $suggestionText): array
    {
        if (!in_array($decision, AiInteraction::DECISIONS, true)) {
            throw new InvalidArgumentException('Unknown AI decision');
        }

        if ($decision === AiInteraction::STATUS_DISCARDED) {
            return ['decision' => $decision, 'final_text' => null];
        }

        $text = self::normalizeText($postedText);

        if ($text === '') {
            throw new InvalidArgumentException('Edited AI text must not be empty');
        }

        if ($decision === AiInteraction::STATUS_ACCEPTED) {
            $unchanged = $postedSha !== ''
                ? hash_equals($postedSha, hash('sha256', $text))
                : $text === self::normalizeText($suggestionText);

            if ($unchanged) {
                return ['decision' => AiInteraction::STATUS_ACCEPTED, 'final_text' => null];
            }
        }

        return ['decision' => AiInteraction::STATUS_EDITED, 'final_text' => $text];
    }

    /** CRLF/CR → LF and trim, as a textarea posts it; the screens use it for the posted text and the rendered SHA. */
    public static function normalizeText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * @throws InvalidArgumentException `Unknown AI decision` for a decision outside AiInteraction::DECISIONS, before any lookup, authorization or audit.
     * @throws AiInteractionNotFoundException for a missing, foreign or already decided interaction (one single message).
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied for an interaction of another unit or without permission.
     */
    public function decide(int $interactionId, string $decision, ?string $finalText): AiInteraction
    {
        if (!in_array($decision, AiInteraction::DECISIONS, true)) {
            throw new InvalidArgumentException('Unknown AI decision');
        }

        $interaction = $this->interactions->find($interactionId) ?? throw new AiInteractionNotFoundException();

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: AiPurpose::decideAction($interaction->purpose()),
            requiresUnitScope: true,
            resourceTenantId: $this->context->tenantId(),
            resourceUnitId: $interaction->systemUnitId(),
            entityType: self::ENTITY_TYPE,
            entityId: $interactionId,
            metadata: ['purpose' => $interaction->purpose(), 'decision' => $decision],
        ))->assertAllowed();

        $interaction->decide($decision, $finalText, $this->context->userId(), ($this->clock)());

        if (!$this->interactions->saveDecision($interaction)) {
            throw new AiInteractionNotFoundException();
        }

        $this->record(self::AUDIT_DECIDED, $interaction, [
            'purpose' => $interaction->purpose(),
            'decision' => $interaction->status(),
            'interaction_id' => $interaction->id(),
            'final_length' => $interaction->finalText() === null ? 0 : mb_strlen($interaction->finalText()),
        ]);

        return $interaction;
    }

    public function latestDecided(string $purpose, int $subjectId): ?AiInteraction
    {
        AiPurpose::assertValid($purpose);
        $unitId = $this->context->requireUnitId();
        $interaction = $this->interactions->latestDecidedFor($purpose, $subjectId);

        return $interaction !== null && $interaction->systemUnitId() === $unitId ? $interaction : null;
    }

    public function isAvailable(): bool
    {
        try {
            $this->context->requireUnitId();

            return $this->unavailabilityReason() === null;
        } catch (MissingTenantContext) {
            return false;
        }
    }

    /** @param array<string, mixed> $subject */
    private function authorizeGeneration(string $purpose, array $subject): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: AiPurpose::hostAction($purpose),
            requiresUnitScope: true,
            resourceTenantId: $this->context->tenantId(),
            resourceUnitId: (int) $subject['system_unit_id'],
            entityType: self::ENTITY_TYPE,
            entityId: null,
            metadata: ['purpose' => $purpose],
        ))->assertAllowed();
    }

    private function assertAvailable(): void
    {
        $reason = $this->unavailabilityReason();

        if ($reason !== null) {
            throw new AiUnavailableException($reason);
        }
    }

    private function unavailabilityReason(): ?string
    {
        if (!$this->flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT)) {
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

    /** @param array<string, mixed> $promptContext */
    private function generate(string $purpose, int $subjectId, int $unitId, array $promptContext): AiSuggestion
    {
        $request = AiPromptCatalog::build($purpose, $promptContext, $this->locale);

        $interaction = AiInteraction::start(
            $this->context->tenantId(),
            $unitId,
            $this->context->userId(),
            $purpose,
            $subjectId,
            $request->promptVersion,
            hash('sha256', $request->system . "\n" . $request->userContent),
            CorrelationIdContext::current() ?? CorrelationIdContext::generate(),
        );

        $startedAt = hrtime(true);

        try {
            $result = $this->gateway->complete($request);
        } catch (AiProviderException $e) {
            $this->fail($interaction, $e->reason(), self::elapsedMs($startedAt), $e->usage(), $e->model(), $e->costMicroUsd());

            throw new AiUnavailableException($e->reason());
        }

        $latencyMs = self::elapsedMs($startedAt);

        if (trim($result->text) === '') {
            $this->fail($interaction, 'ai_provider_error', $latencyMs, $result->usage, $result->model);

            throw new AiUnavailableException('ai_provider_error');
        }

        $interaction->recordSuggestion($result, $latencyMs);
        $interaction = $this->interactions->insert($interaction);
        $this->record(self::AUDIT_GENERATED, $interaction, $this->usageMetadata($interaction));

        return new AiSuggestion(
            (int) $interaction->id(),
            $purpose,
            (string) $interaction->suggestionText(),
            $interaction->provider(),
            (string) $interaction->model(),
            $interaction->promptVersion(),
        );
    }

    private function fail(AiInteraction $interaction, string $reason, int $latencyMs, ?AiUsage $usage, ?string $model, ?int $costMicroUsd = null): void
    {
        $interaction->recordFailure($reason, $latencyMs, $usage, $model, $this->gateway->providerName(), $costMicroUsd);
        $interaction = $this->interactions->insert($interaction);
        $this->record(self::AUDIT_FAILED, $interaction, $this->usageMetadata($interaction));
    }

    /** @return array<string, mixed> */
    private function usageMetadata(AiInteraction $interaction): array
    {
        $usage = $interaction->usage();

        return [
            'interaction_id' => $interaction->id(),
            'purpose' => $interaction->purpose(),
            'subject_type' => $interaction->subjectType(),
            'subject_id' => $interaction->subjectId(),
            'prompt_version' => $interaction->promptVersion(),
            'provider' => $interaction->provider(),
            'model' => $interaction->model(),
            'status' => $interaction->status(),
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_tokens' => $usage->cacheReadTokens,
            'cache_write_tokens' => $usage->cacheWriteTokens,
            'cost_micro_usd' => $interaction->costMicroUsd(),
            'latency_ms' => $interaction->latencyMs(),
            'error_code' => $interaction->errorCode(),
        ];
    }

    /** @param array<string, mixed> $metadata ids, purpose, model, tokens, cost, decision and codes only. */
    private function record(string $action, AiInteraction $interaction, array $metadata): void
    {
        $this->audit->record(new AuditEvent(
            tenantId: $this->context->tenantId(),
            unitId: $this->context->unitId(),
            userId: $this->context->userId(),
            correlationId: $interaction->correlationId() !== '' ? $interaction->correlationId() : (CorrelationIdContext::current() ?? CorrelationIdContext::generate()),
            action: $action,
            entityType: self::ENTITY_TYPE,
            entityId: $interaction->id(),
            beforeData: null,
            afterData: null,
            metadata: $metadata,
            ipAddress: null,
            userAgent: null,
        ));
    }

    /**
     * First instant of the current month in the application timezone
     * (`date_default_timezone_get()`, i.e. APP_TIMEZONE), the same zone the
     * MySQL session uses to write `created_at`.
     */
    private function monthStart(): DateTimeImmutable
    {
        return ($this->clock)()
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->modify('first day of this month')
            ->setTime(0, 0);
    }

    private static function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
