<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\AiPromptCatalog;
use CentralVet\Application\AiAssistService;
use CentralVet\Assistant\Contract\AiAssistantInterface;
use CentralVet\Audit\AuditEvent;
use CentralVet\Authorization\AuthorizationDecision;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Domain\Exception\AiInteractionNotFoundException;
use CentralVet\Domain\Exception\AiProviderException;
use CentralVet\Domain\Exception\AiSubjectNotFoundException;
use CentralVet\Domain\Exception\AiUnavailableException;
use CentralVet\Tenancy\Exception\MissingTenantContext;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiClinicalContextQuery;
use CentralVet\Tests\Support\FakeAiGateway;
use CentralVet\Tests\Support\FakeAiInteractionRepository;
use CentralVet\Tests\Support\FakeAiTenantSettingRepository;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeFeatureFlagRepository;
use CentralVet\Tests\Support\SpyAuditLogWriter;
use DateTimeImmutable;
use Throwable;

/**
 * T-11: AiAssistService orchestrates each AI generation (tenant, subject,
 * RBAC, availability, quota, minimized prompt, call, record, audit) and the
 * human decision, without persisting anything clinical by itself.
 */
final class AiAssistServiceTest
{
    private const TENANT_ID = 1;
    private const OTHER_TENANT_ID = 2;
    private const UNIT_ID = 5;
    private const OTHER_UNIT_ID = 6;
    private const USER_ID = 3;
    private const ENCOUNTER_ID = 10;
    private const PATIENT_ID = 20;
    private const EXAM_RESULT_ID = 30;
    private const FREE_TEXT = 'Tosse seca há três dias, apetite normal, sem vômito';

    private FakeAiGateway $gateway;
    private FakeAiInteractionRepository $interactions;
    private FakeAiTenantSettingRepository $settings;
    private FakeFeatureFlagRepository $flags;
    private FakeAiClinicalContextQuery $clinical;
    private AuthorizationPolicyInterface $authorization;
    private SpyAuditLogWriter $audit;
    private TenantContext $context;

    public function setUp(): void
    {
        $this->gateway = new FakeAiGateway();
        $this->interactions = new FakeAiInteractionRepository(self::TENANT_ID);
        $this->settings = new FakeAiTenantSettingRepository(self::acceptedSetting(AiTerms::VERSION));
        $this->flags = new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]);
        $this->clinical = new FakeAiClinicalContextQuery(
            [self::ENCOUNTER_ID => self::encounter()],
            [self::PATIENT_ID => self::history()],
            [self::EXAM_RESULT_ID => self::exam()],
        );
        $this->authorization = new FakeAuthorizationPolicy();
        $this->audit = new SpyAuditLogWriter();
        $this->context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
    }

    public function testImplementsTheAssistantContract(): void
    {
        Assert::instanceOf(AiAssistantInterface::class, $this->service());
        Assert::same(8000, AiAssistService::MAX_FREE_TEXT_CHARS);
    }

    public function testOrganizeAnamnesisRecordsAPendingReviewAndReturnsTheSuggestion(): void
    {
        $suggestion = $this->service()->organizeAnamnesis(self::ENCOUNTER_ID, self::FREE_TEXT);

        Assert::same('Resumo de teste', $suggestion->text);
        Assert::same(AiPurpose::ANAMNESIS_ORGANIZE, $suggestion->purpose);
        Assert::same('fake', $suggestion->provider);
        Assert::same('claude-sonnet-5-5', $suggestion->model);
        Assert::same(AiPromptCatalog::version(AiPurpose::ANAMNESIS_ORGANIZE), $suggestion->promptVersion);

        $stored = $this->interactions->all();
        Assert::count(1, $stored);
        $interaction = $stored[0];
        Assert::same($suggestion->interactionId, $interaction->id());
        Assert::same(AiInteraction::STATUS_PENDING_REVIEW, $interaction->status());
        Assert::same(AiPurpose::ANAMNESIS_ORGANIZE, $interaction->purpose());
        Assert::same(AiPurpose::SUBJECT_ENCOUNTER, $interaction->subjectType());
        Assert::same(self::ENCOUNTER_ID, $interaction->subjectId());
        Assert::same(AiPromptCatalog::version(AiPurpose::ANAMNESIS_ORGANIZE), $interaction->promptVersion());
        Assert::same('fake', $interaction->provider());
        Assert::same('claude-sonnet-5-5', $interaction->model());
        Assert::same(self::TENANT_ID, $interaction->tenantId());
        Assert::same(self::UNIT_ID, $interaction->systemUnitId());
        Assert::same(self::USER_ID, $interaction->systemUserId());
        Assert::same(100, $interaction->usage()->inputTokens);
        Assert::same(50, $interaction->usage()->outputTokens);
        Assert::same(700, $interaction->costMicroUsd());
        Assert::same('Resumo de teste', $interaction->suggestionText());
        Assert::notNull($interaction->latencyMs());

        $calls = $this->gateway->calls();
        Assert::count(1, $calls);
        Assert::same(AiPurpose::ANAMNESIS_ORGANIZE, $calls[0]->purpose);
        Assert::stringContains('Tosse seca', $calls[0]->userContent);
        Assert::false(str_contains($calls[0]->userContent, 'Maria Silva'), 'Tutor name must not leave the system');
        Assert::same(hash('sha256', $calls[0]->system . "\n" . $calls[0]->userContent), $interaction->inputSha256());
    }

    public function testPatientSummaryUsesTheEncounterAndThePatientHistory(): void
    {
        $suggestion = $this->service()->summarizePatient(self::ENCOUNTER_ID);

        $interaction = $this->interactions->all()[0];
        Assert::same(AiPurpose::PATIENT_SUMMARY, $suggestion->purpose);
        Assert::same(AiPurpose::PATIENT_SUMMARY, $interaction->purpose());
        Assert::same(AiPurpose::SUBJECT_ENCOUNTER, $interaction->subjectType());
        Assert::same(self::ENCOUNTER_ID, $interaction->subjectId());
        Assert::same(AiPromptCatalog::version(AiPurpose::PATIENT_SUMMARY), $interaction->promptVersion());
        Assert::same('fake', $interaction->provider());
        Assert::same('claude-sonnet-5-5', $interaction->model());
        Assert::same(
            [['method' => 'encounterContext', 'id' => self::ENCOUNTER_ID], ['method' => 'patientHistory', 'id' => self::PATIENT_ID]],
            $this->clinical->calls(),
        );
        Assert::stringContains('Otite', $this->gateway->calls()[0]->userContent);
    }

    public function testExamSummaryUsesTheExamResultAsSubject(): void
    {
        $suggestion = $this->service()->summarizeExamResult(self::EXAM_RESULT_ID);

        $interaction = $this->interactions->all()[0];
        Assert::same(AiPurpose::EXAM_SUMMARY, $suggestion->purpose);
        Assert::same(AiPurpose::SUBJECT_EXAM_RESULT, $interaction->subjectType());
        Assert::same(self::EXAM_RESULT_ID, $interaction->subjectId());
        Assert::same(AiPromptCatalog::version(AiPurpose::EXAM_SUMMARY), $interaction->promptVersion());
        Assert::same('fake', $interaction->provider());
        Assert::same('claude-sonnet-5-5', $interaction->model());
        Assert::stringContains('Hematócrito', $this->gateway->calls()[0]->userContent);
    }

    public function testGenerationAuthorizesTheHostActionWithTheSubjectUnit(): void
    {
        $this->service()->summarizeExamResult(self::EXAM_RESULT_ID);

        /** @var FakeAuthorizationPolicy $policy */
        $policy = $this->authorization;
        Assert::count(1, $policy->requests);
        $request = $policy->requests[0];
        Assert::same('ExamResultForm::onAiExamSummary', $request->action());
        Assert::true($request->requiresUnitScope());
        Assert::same(self::TENANT_ID, $request->resourceTenantId());
        Assert::same(self::UNIT_ID, $request->resourceUnitId());
        Assert::same('ai_interaction', $request->entityType());
        Assert::same(['purpose' => AiPurpose::EXAM_SUMMARY], $request->metadata());
    }

    public function testDisabledFlagFailsWithoutCallingTheGateway(): void
    {
        $this->flags = new FakeFeatureFlagRepository();

        $this->assertUnavailableWithoutCall('ai_disabled');
    }

    public function testOutdatedTermsFailWithoutCallingTheGateway(): void
    {
        $this->settings = new FakeAiTenantSettingRepository(self::acceptedSetting('2025-01-v0'));

        $this->assertUnavailableWithoutCall('ai_terms_pending');
    }

    public function testMissingSettingMeansTermsPending(): void
    {
        $this->settings = new FakeAiTenantSettingRepository();

        $this->assertUnavailableWithoutCall('ai_terms_pending');
    }

    public function testExhaustedQuotaFailsWithoutCallingTheGateway(): void
    {
        $this->interactions->setMonthlyTokenUsage(300000);

        $this->assertUnavailableWithoutCall('ai_quota_exceeded');
    }

    public function testUnconfiguredGatewayFailsWithoutCallingIt(): void
    {
        $this->gateway = new FakeAiGateway(configured: false);

        $this->assertUnavailableWithoutCall('ai_not_configured');
    }

    public function testBlankFreeTextFailsWithoutCallingTheGateway(): void
    {
        $exception = self::caught(fn () => $this->service()->organizeAnamnesis(self::ENCOUNTER_ID, "  \n\t "));

        Assert::instanceOf(AiUnavailableException::class, $exception);
        Assert::same('ai_empty_input', $exception->reason());
        $this->assertNothingHappened();
    }

    public function testFreeTextOverTheLimitFailsWithoutCallingTheGateway(): void
    {
        $exception = self::caught(fn () => $this->service()->organizeAnamnesis(self::ENCOUNTER_ID, str_repeat('á', AiAssistService::MAX_FREE_TEXT_CHARS + 1)));

        Assert::instanceOf(AiUnavailableException::class, $exception);
        Assert::same('ai_input_too_long', $exception->reason());
        $this->assertNothingHappened();
    }

    public function testFreeTextAtTheLimitIsAccepted(): void
    {
        $this->service()->organizeAnamnesis(self::ENCOUNTER_ID, str_repeat('á', AiAssistService::MAX_FREE_TEXT_CHARS));

        Assert::count(1, $this->gateway->calls());
    }

    public function testUnknownSubjectFailsWithoutCallingTheGateway(): void
    {
        Assert::throws(AiSubjectNotFoundException::class, fn () => $this->service()->summarizePatient(999));
        Assert::throws(AiSubjectNotFoundException::class, fn () => $this->service()->summarizeExamResult(999));

        $this->assertNothingHappened();
        /** @var FakeAuthorizationPolicy $policy */
        $policy = $this->authorization;
        Assert::same([], $policy->requests);
    }

    public function testDeniedAuthorizationFailsWithoutCallingTheGateway(): void
    {
        $this->authorization = new FakeAuthorizationPolicy(false, 'denied');

        Assert::throws(AuthorizationDenied::class, fn () => $this->service()->summarizePatient(self::ENCOUNTER_ID));
        Assert::throws(AuthorizationDenied::class, fn () => $this->service()->organizeAnamnesis(self::ENCOUNTER_ID, self::FREE_TEXT));

        $this->assertNothingHappened();
    }

    public function testMissingActiveUnitFailsClosed(): void
    {
        $this->context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID);

        Assert::throws(MissingTenantContext::class, fn () => $this->service()->summarizePatient(self::ENCOUNTER_ID));

        $this->assertNothingHappened();
        Assert::same([], $this->clinical->calls());
    }

    public function testProviderFailureRecordsAFailedInteractionAndRaisesTheReason(): void
    {
        $this->gateway = new FakeAiGateway(failure: new AiProviderException('ai_timeout', null, new AiUsage(10, 0), 'claude-sonnet-5-5'));

        $exception = self::caught(fn () => $this->service()->organizeAnamnesis(self::ENCOUNTER_ID, self::FREE_TEXT));

        Assert::instanceOf(AiUnavailableException::class, $exception);
        Assert::same('ai_timeout', $exception->reason());
        Assert::count(1, $this->gateway->calls());

        $stored = $this->interactions->all();
        Assert::count(1, $stored);
        Assert::same(AiInteraction::STATUS_FAILED, $stored[0]->status());
        Assert::same('ai_timeout', $stored[0]->errorCode());
        Assert::same(10, $stored[0]->usage()->inputTokens);
        Assert::same(0, $stored[0]->usage()->outputTokens);
        Assert::same('claude-sonnet-5-5', $stored[0]->model());
        Assert::same('fake', $stored[0]->provider());
        Assert::null($stored[0]->suggestionText());

        Assert::count(1, $this->audit->events);
        $event = $this->audit->events[0];
        Assert::same('ai.suggestion.failed', $event->action);
        Assert::same('ai_interaction', $event->entityType);
        Assert::same($stored[0]->id(), $event->entityId);
        Assert::same('ai_timeout', $event->metadata['error_code']);
        Assert::same(10, $event->metadata['input_tokens']);
    }

    public function testBlankProviderTextIsRecordedAsAProviderError(): void
    {
        $this->gateway = new FakeAiGateway(new AiCompletionResult("  \n", 'fake', 'claude-sonnet-5-5', new AiUsage(30, 2), 80, 'end_turn', 'req_blank'));

        $exception = self::caught(fn () => $this->service()->summarizePatient(self::ENCOUNTER_ID));

        Assert::instanceOf(AiUnavailableException::class, $exception);
        Assert::same('ai_provider_error', $exception->reason());
        Assert::same(AiInteraction::STATUS_FAILED, $this->interactions->all()[0]->status());
        Assert::same(30, $this->interactions->all()[0]->usage()->inputTokens);
    }

    public function testGenerationIsAuditedWithoutClinicalText(): void
    {
        $suggestion = $this->service()->organizeAnamnesis(self::ENCOUNTER_ID, self::FREE_TEXT);

        Assert::count(1, $this->audit->events);
        $event = $this->audit->events[0];
        Assert::same('ai.suggestion.generated', $event->action);
        Assert::same('ai_interaction', $event->entityType);
        Assert::same($suggestion->interactionId, $event->entityId);
        Assert::same(self::TENANT_ID, $event->tenantId);
        Assert::same(self::UNIT_ID, $event->unitId);
        Assert::same(self::USER_ID, $event->userId);
        Assert::same(AiPurpose::ANAMNESIS_ORGANIZE, $event->metadata['purpose']);
        Assert::same(self::ENCOUNTER_ID, $event->metadata['subject_id']);
        Assert::same('claude-sonnet-5-5', $event->metadata['model']);
        Assert::same(100, $event->metadata['input_tokens']);
        Assert::same(50, $event->metadata['output_tokens']);
        Assert::same(700, $event->metadata['cost_micro_usd']);
        $this->assertAuditHasNoText(['Resumo de teste', 'Tosse seca', 'Rex', 'Maria Silva']);
    }

    public function testDecideAcceptedKeepsTheSuggestionAsFinalText(): void
    {
        $service = $this->service();
        $suggestion = $service->summarizePatient(self::ENCOUNTER_ID);

        $decided = $service->decide($suggestion->interactionId, AiInteraction::STATUS_ACCEPTED, null);

        Assert::same(AiInteraction::STATUS_ACCEPTED, $decided->status());
        Assert::same('Resumo de teste', $decided->finalText());
        Assert::same(self::USER_ID, $decided->decidedBySystemUserId());
        Assert::same('2026-10-07 12:00:00', $decided->decidedAt()?->format('Y-m-d H:i:s'));
        $stored = $this->interactions->find($suggestion->interactionId);
        Assert::same(AiInteraction::STATUS_ACCEPTED, $stored?->status());
        Assert::same('Resumo de teste', $stored?->finalText());
    }

    public function testDecideEditedStoresTheProfessionalText(): void
    {
        $service = $this->service();
        $suggestion = $service->organizeAnamnesis(self::ENCOUNTER_ID, self::FREE_TEXT);

        $decided = $service->decide($suggestion->interactionId, AiInteraction::STATUS_EDITED, '  Texto revisado  ');

        Assert::same(AiInteraction::STATUS_EDITED, $decided->status());
        Assert::same('Texto revisado', $decided->finalText());
        Assert::same('Texto revisado', $this->interactions->find($suggestion->interactionId)?->finalText());
    }

    public function testDecideDiscardedKeepsNoFinalTextAndIsAudited(): void
    {
        $service = $this->service();
        $suggestion = $service->summarizeExamResult(self::EXAM_RESULT_ID);

        $decided = $service->decide($suggestion->interactionId, AiInteraction::STATUS_DISCARDED, 'ignorado');

        Assert::same(AiInteraction::STATUS_DISCARDED, $decided->status());
        Assert::null($decided->finalText());

        /** @var FakeAuthorizationPolicy $policy */
        $policy = $this->authorization;
        $request = $policy->requests[1];
        Assert::same('ExamResultForm::onAiDecide', $request->action());
        Assert::same(self::UNIT_ID, $request->resourceUnitId());
        Assert::same($suggestion->interactionId, $request->entityId());

        $event = $this->audit->events[1];
        Assert::same('ai.suggestion.decided', $event->action);
        Assert::same('ai_interaction', $event->entityType);
        Assert::same([
            'purpose' => AiPurpose::EXAM_SUMMARY,
            'decision' => AiInteraction::STATUS_DISCARDED,
            'interaction_id' => $suggestion->interactionId,
            'final_length' => 0,
        ], $event->metadata);
    }

    public function testDecideAuditCarriesTheFinalLengthButNoText(): void
    {
        $service = $this->service();
        $suggestion = $service->summarizePatient(self::ENCOUNTER_ID);

        $service->decide($suggestion->interactionId, AiInteraction::STATUS_EDITED, 'Texto final revisado');

        $event = $this->audit->events[1];
        Assert::same('ai.suggestion.decided', $event->action);
        Assert::same(mb_strlen('Texto final revisado'), $event->metadata['final_length']);
        $this->assertAuditHasNoText(['Texto final revisado', 'Resumo de teste']);
    }

    public function testDecideTwiceFailsAsNotFound(): void
    {
        $service = $this->service();
        $suggestion = $service->summarizePatient(self::ENCOUNTER_ID);
        $service->decide($suggestion->interactionId, AiInteraction::STATUS_ACCEPTED, null);
        $before = self::rows($this->interactions->all());

        $exception = self::caught(fn () => $service->decide($suggestion->interactionId, AiInteraction::STATUS_DISCARDED, null));

        Assert::instanceOf(AiInteractionNotFoundException::class, $exception);
        Assert::same('AI suggestion not found', $exception->getMessage());
        Assert::same($before, self::rows($this->interactions->all()));
    }

    public function testDecideAnotherTenantsInteractionFailsAsNotFound(): void
    {
        $foreign = self::suggested(self::OTHER_TENANT_ID, self::UNIT_ID);
        $this->interactions->seed($foreign);
        $before = self::rows($this->interactions->all());

        $exception = self::caught(fn () => $this->service()->decide((int) $foreign->id(), AiInteraction::STATUS_ACCEPTED, null));

        Assert::instanceOf(AiInteractionNotFoundException::class, $exception);
        Assert::same('AI suggestion not found', $exception->getMessage());
        Assert::same($before, self::rows($this->interactions->all()));
        Assert::same([], $this->audit->events);
    }

    public function testDecideUnknownInteractionFailsAsNotFound(): void
    {
        $exception = self::caught(fn () => $this->service()->decide(404, AiInteraction::STATUS_ACCEPTED, null));

        Assert::instanceOf(AiInteractionNotFoundException::class, $exception);
        Assert::same('AI suggestion not found', $exception->getMessage());
        Assert::same([], $this->interactions->all());
    }

    public function testDecideAnotherUnitsInteractionIsDeniedWithoutChanges(): void
    {
        $this->authorization = self::unitScopedPolicy();
        $other = self::suggested(self::TENANT_ID, self::OTHER_UNIT_ID);
        $this->interactions->seed($other);
        $before = self::rows($this->interactions->all());

        Assert::throws(AuthorizationDenied::class, fn () => $this->service()->decide((int) $other->id(), AiInteraction::STATUS_ACCEPTED, null));

        Assert::same($before, self::rows($this->interactions->all()));
        Assert::same([], $this->audit->events);
    }

    public function testIsAvailableWhenEverythingIsInPlace(): void
    {
        Assert::true($this->service()->isAvailable());
    }

    public function testIsNotAvailableWhenTheFlagIsOff(): void
    {
        $this->flags = new FakeFeatureFlagRepository();

        Assert::false($this->service()->isAvailable());
    }

    public function testIsNotAvailableWhenTheTermsArePending(): void
    {
        $this->settings = new FakeAiTenantSettingRepository(self::acceptedSetting('2025-01-v0'));

        Assert::false($this->service()->isAvailable());
    }

    public function testIsNotAvailableWhenTheQuotaIsExhausted(): void
    {
        $this->interactions->setMonthlyTokenUsage(300000);

        Assert::false($this->service()->isAvailable());
    }

    public function testIsNotAvailableWhenTheGatewayIsNotConfigured(): void
    {
        $this->gateway = new FakeAiGateway(configured: false);

        Assert::false($this->service()->isAvailable());
    }

    public function testIsNotAvailableWithoutAnActiveUnit(): void
    {
        $this->context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID);

        Assert::false($this->service()->isAvailable());
    }

    public function testLatestDecidedReturnsTheUnitsDecidedSuggestion(): void
    {
        $service = $this->service();
        $suggestion = $service->summarizePatient(self::ENCOUNTER_ID);
        Assert::null($service->latestDecided(AiPurpose::PATIENT_SUMMARY, self::ENCOUNTER_ID));

        $service->decide($suggestion->interactionId, AiInteraction::STATUS_ACCEPTED, null);

        Assert::same($suggestion->interactionId, $service->latestDecided(AiPurpose::PATIENT_SUMMARY, self::ENCOUNTER_ID)?->id());
    }

    private function service(): AiAssistService
    {
        return new AiAssistService(
            $this->gateway,
            $this->interactions,
            $this->settings,
            $this->flags,
            $this->clinical,
            $this->authorization,
            $this->audit,
            $this->context,
            'pt',
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-07 12:00:00'),
        );
    }

    private function assertUnavailableWithoutCall(string $reason): void
    {
        foreach ([
            fn () => $this->service()->summarizePatient(self::ENCOUNTER_ID),
            fn () => $this->service()->organizeAnamnesis(self::ENCOUNTER_ID, self::FREE_TEXT),
            fn () => $this->service()->summarizeExamResult(self::EXAM_RESULT_ID),
        ] as $call) {
            $exception = self::caught($call);
            Assert::instanceOf(AiUnavailableException::class, $exception);
            Assert::same($reason, $exception->reason());
        }

        $this->assertNothingHappened();
    }

    private function assertNothingHappened(): void
    {
        Assert::same([], $this->gateway->calls());
        Assert::same([], $this->interactions->all());
        Assert::same([], $this->audit->events);
    }

    /** @param list<string> $texts */
    private function assertAuditHasNoText(array $texts): void
    {
        $encoded = json_encode(array_map(
            static fn (AuditEvent $event): array => [$event->beforeData, $event->afterData, $event->metadata],
            $this->audit->events,
        ), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ($texts as $text) {
            Assert::false(str_contains($encoded, $text), "Audit must not carry clinical text: {$text}");
        }
    }

    private static function caught(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            return $e;
        }

        throw new AssertionFailedException('Expected an exception');
    }

    /**
     * @param list<AiInteraction> $interactions
     * @return list<array<string, mixed>>
     */
    private static function rows(array $interactions): array
    {
        return array_map(static fn (AiInteraction $interaction): array => $interaction->toRow(), $interactions);
    }

    private static function suggested(int $tenantId, int $unitId): AiInteraction
    {
        $interaction = AiInteraction::start($tenantId, $unitId, self::USER_ID, AiPurpose::PATIENT_SUMMARY, self::ENCOUNTER_ID, 'patient_summary.v1', hash('sha256', 'entrada'), 'corr-seed');
        $interaction->recordSuggestion(new AiCompletionResult('Sugestão alheia', 'fake', 'claude-sonnet-5-5', new AiUsage(5, 5), 10, 'end_turn', null), 50);

        return $interaction;
    }

    private static function unitScopedPolicy(): AuthorizationPolicyInterface
    {
        return new class implements AuthorizationPolicyInterface {
            public function decide(AuthorizationRequest $request): AuthorizationDecision
            {
                $allowed = $request->resourceUnitId() === null || $request->resourceUnitId() === $request->context()->unitId();

                return new AuthorizationDecision($allowed, $allowed ? 'granted' : 'unit_mismatch', 'test-correlation-id');
            }
        };
    }

    private static function acceptedSetting(string $version): AiTenantSetting
    {
        $setting = AiTenantSetting::defaults(self::TENANT_ID);
        $setting->acceptTerms($version, self::USER_ID, new DateTimeImmutable('2026-10-01 09:00:00'));

        return $setting;
    }

    /** @return array<string, mixed> */
    private static function encounter(): array
    {
        return [
            'encounter_id' => self::ENCOUNTER_ID,
            'system_unit_id' => self::UNIT_ID,
            'patient_id' => self::PATIENT_ID,
            'status' => 'in_progress',
            'anamnesis_text' => 'Paciente trazido para retorno',
            'patient_name' => 'Rex',
            'tutor_name' => 'Maria Silva',
            'species' => 'Canina',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2020-01-15',
            'weight_kg' => '12.40',
        ];
    }

    /** @return array<string, mixed> */
    private static function history(): array
    {
        return [
            'encounters' => [[
                'started_at' => '2026-08-01 10:00:00',
                'anamnesis_text' => 'Coceira no ouvido',
                'physical_exam_text' => null,
                'diagnosis_text' => 'Otite externa',
                'clinical_plan_text' => null,
            ]],
            'prescriptions' => [],
            'exam_results' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function exam(): array
    {
        return [
            'exam_result_id' => self::EXAM_RESULT_ID,
            'encounter_id' => self::ENCOUNTER_ID,
            'system_unit_id' => self::UNIT_ID,
            'patient_id' => self::PATIENT_ID,
            'exam_name' => 'Hemograma',
            'result_text' => 'Hematócrito 45%',
            'received_at' => '2026-10-01 10:00:00',
            'patient_name' => 'Rex',
            'tutor_name' => 'Maria Silva',
            'species' => 'Canina',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2020-01-15',
        ];
    }
}
