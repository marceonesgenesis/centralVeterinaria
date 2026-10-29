<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\AiAssistService;
use CentralVet\Application\AiSettingsService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\Contract\AiInteractionRepositoryInterface;
use CentralVet\Domain\Contract\AiTenantSettingRepositoryInterface;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Domain\Exception\AiUnavailableException;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiClinicalContextQuery;
use CentralVet\Tests\Support\FakeAiGateway;
use CentralVet\Tests\Support\FakeAiInteractionRepository;
use CentralVet\Tests\Support\FakeAiTenantSettingRepository;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeFeatureFlagRepository;
use CentralVet\Tests\Support\SpyAuditLogWriter;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Unit tests for AiSettingsService (T-12, Fase 8A): tenant admin turns the
 * AI assistant on (accepting the current term) or off, changes the monthly
 * token quota and reads the month usage and cost. Every mutation is audited
 * and authorization is checked before anything is written.
 */
final class AiSettingsServiceTest
{
    private const TENANT_ID = 1;
    private const USER_ID = 7;
    private const UNIT_ID = 3;
    private const NOW = '2026-10-15 12:00:00';

    private string $timezone;

    public function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
    }

    public function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /**
     * @return array{0: AiSettingsService, 1: FakeAiTenantSettingRepository, 2: FakeFeatureFlagRepository, 3: FakeAiInteractionRepository, 4: FakeAuthorizationPolicy, 5: SpyAuditLogWriter}
     */
    private function build(?AiTenantSetting $setting = null, array $flags = [], bool $allowed = true): array
    {
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
        $settings = new FakeAiTenantSettingRepository($setting);
        $flagRepo = new FakeFeatureFlagRepository($flags);
        $interactions = new FakeAiInteractionRepository(self::TENANT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: $allowed);
        $audit = new SpyAuditLogWriter();
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));

        return [
            new AiSettingsService($settings, $flagRepo, $interactions, $policy, $audit, $context, $clock),
            $settings,
            $flagRepo,
            $interactions,
            $policy,
            $audit,
        ];
    }

    private static function acceptedSetting(string $version = AiTerms::VERSION): AiTenantSetting
    {
        $setting = AiTenantSetting::defaults(self::TENANT_ID);
        $setting->acceptTerms($version, self::USER_ID, new DateTimeImmutable('2026-10-01 09:00:00'));

        return $setting;
    }

    /** @param int|null $cost */
    private static function interaction(int $id, string $purpose, int $tenantId, string $createdAt, int $in, int $out, ?int $cost): AiInteraction
    {
        return AiInteraction::reconstitute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'system_unit_id' => self::UNIT_ID,
            'system_user_id' => self::USER_ID,
            'purpose' => $purpose,
            'subject_id' => 10 + $id,
            'prompt_version' => 'v1',
            'provider' => 'fake',
            'model' => 'claude-sonnet-5-5',
            'status' => AiInteraction::STATUS_PENDING_REVIEW,
            'input_sha256' => str_repeat('a', 64),
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cost_micro_usd' => $cost,
            'suggestion_text' => 'Resumo de teste',
            'correlation_id' => 'corr-test',
            'created_at' => $createdAt,
        ]);
    }

    /** @return list<string> */
    private static function actions(SpyAuditLogWriter $audit): array
    {
        return array_map(static fn ($event): string => $event->action, $audit->events);
    }

    public function testEnableWithoutTermsThrowsTermsPendingAndKeepsFlagOff(): void
    {
        [$service, $settings, $flags, , , $audit] = $this->build();

        try {
            $service->enable(false);
            Assert::true(false, 'Expected AiUnavailableException');
        } catch (AiUnavailableException $e) {
            Assert::same('ai_terms_pending', $e->reason());
        }

        Assert::false($flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));
        Assert::same([], $flags->changes());
        Assert::null($settings->saved());
        Assert::same([], $audit->events);
    }

    public function testEnableWithOldTermsVersionWithoutAcceptingThrowsTermsPending(): void
    {
        [$service, , $flags] = $this->build(self::acceptedSetting('2025-01-v0'));

        try {
            $service->enable(false);
            Assert::true(false, 'Expected AiUnavailableException');
        } catch (AiUnavailableException $e) {
            Assert::same('ai_terms_pending', $e->reason());
        }

        Assert::false($flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));
    }

    /**
     * T-04 (8A pendências): tenant que aceitou o termo 2026-10-v1 precisa
     * reaceitar a v2 — overview aponta o termo como não vigente, enable sem
     * aceite lança ai_terms_pending e a geração falha sem chamar o provedor.
     */
    public function testTermsV1AcceptedRequiresReacceptanceWithoutCallingTheProvider(): void
    {
        Assert::same('2026-10-v2', AiTerms::VERSION);

        $v1 = self::acceptedSetting('2026-10-v1');
        Assert::false($v1->hasAcceptedTerms(AiTerms::VERSION));

        [$service, $settings, $flags, , , $audit] = $this->build($v1, [FeatureFlagRepositoryInterface::AI_ASSISTANT]);

        $overview = $service->overview();
        Assert::false($overview['terms_current']);
        Assert::same('2026-10-v1', $overview['terms_version']);

        [$disabledService, , $disabledFlags] = $this->build(self::acceptedSetting('2026-10-v1'));

        try {
            $disabledService->enable(false);
            Assert::true(false, 'Expected AiUnavailableException');
        } catch (AiUnavailableException $e) {
            Assert::same('ai_terms_pending', $e->reason());
        }

        Assert::false($disabledFlags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));

        $gateway = new FakeAiGateway();
        $interactions = new FakeAiInteractionRepository(self::TENANT_ID);
        $assist = new AiAssistService(
            $gateway,
            $interactions,
            $settings,
            $flags,
            new FakeAiClinicalContextQuery([10 => [
                'encounter_id' => 10,
                'system_unit_id' => self::UNIT_ID,
                'patient_id' => 20,
                'status' => 'in_progress',
                'anamnesis_text' => 'Retorno',
                'species' => 'Canina',
            ]]),
            new FakeAuthorizationPolicy(),
            $audit,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            'pt',
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );

        try {
            $assist->organizeAnamnesis(10, 'Tosse seca há três dias');
            Assert::true(false, 'Expected AiUnavailableException');
        } catch (AiUnavailableException $e) {
            Assert::same('ai_terms_pending', $e->reason());
        }

        Assert::same([], $gateway->calls());
        Assert::same([], $interactions->all());
        Assert::same([], $audit->events);
    }

    public function testEnableAcceptingTermsRecordsTermAndTurnsFlagOn(): void
    {
        [$service, $settings, $flags, , , $audit] = $this->build();

        $service->enable(true);

        $saved = $settings->saved();
        Assert::notNull($saved);
        Assert::same(AiTerms::VERSION, $saved->termsVersion());
        Assert::same(self::USER_ID, $saved->termsAcceptedBySystemUserId());
        Assert::same(self::USER_ID, $settings->savedBySystemUserId());
        Assert::same('2026-10-15 12:00:00', $saved->termsAcceptedAt()?->format('Y-m-d H:i:s'));
        Assert::true($flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));
        Assert::same(['ai.terms.accepted', 'ai.settings.enabled'], self::actions($audit));
        Assert::same(['terms_version' => AiTerms::VERSION], $audit->events[0]->afterData);
        Assert::same(self::TENANT_ID, $audit->events[0]->tenantId);
        Assert::same(self::USER_ID, $audit->events[0]->userId);
    }

    public function testEnableWithCurrentTermsAlreadyAcceptedOnlyTurnsFlagOn(): void
    {
        [$service, $settings, $flags, , , $audit] = $this->build(self::acceptedSetting());

        $service->enable(false);

        Assert::null($settings->saved());
        Assert::true($flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));
        Assert::same(['ai.settings.enabled'], self::actions($audit));
    }

    public function testDisableKeepsAcceptedTerms(): void
    {
        [$service, $settings, $flags, , , $audit] = $this->build(self::acceptedSetting(), [FeatureFlagRepositoryInterface::AI_ASSISTANT]);

        $service->disable();

        Assert::false($flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));
        Assert::null($settings->saved());
        Assert::true((bool) $settings->find()?->hasAcceptedTerms(AiTerms::VERSION));
        Assert::same(['ai.settings.disabled'], self::actions($audit));

        $overview = $service->overview();
        Assert::false($overview['enabled']);
        Assert::true($overview['terms_current']);
        Assert::same(AiTerms::VERSION, $overview['terms_version']);
    }

    public function testChangeMonthlyQuotaOutOfRangeThrows(): void
    {
        [$service, $settings, , , , $audit] = $this->build();

        foreach ([-1, 100000001] as $tokens) {
            try {
                $service->changeMonthlyQuota($tokens);
                Assert::true(false, "Expected InvalidArgumentException for {$tokens}");
            } catch (InvalidArgumentException $e) {
                Assert::same('Invalid monthly token quota', $e->getMessage());
            }
        }

        Assert::null($settings->saved());
        Assert::same([], $audit->events);
    }

    public function testChangeMonthlyQuotaSavesAndAuditsBeforeAndAfter(): void
    {
        [$service, $settings, , , , $audit] = $this->build();

        $service->changeMonthlyQuota(100000000);

        Assert::same(100000000, $settings->saved()?->monthlyTokenQuota());
        Assert::same(['ai.settings.quota_changed'], self::actions($audit));
        Assert::same(['monthly_token_quota' => 300000], $audit->events[0]->beforeData);
        Assert::same(['monthly_token_quota' => 100000000], $audit->events[0]->afterData);

        $service->changeMonthlyQuota(0);
        Assert::same(0, $settings->saved()?->monthlyTokenQuota());
    }

    /**
     * T-10 (8A pendências): a cota igual à atual, com o registro existente,
     * lê o repositório uma única vez e não grava nem audita; a cota diferente
     * grava e audita uma vez, também com uma única leitura.
     */
    public function testChangeMonthlyQuotaReadsTheSettingOnceAndSkipsUnchangedQuota(): void
    {
        $settings = self::countingSettings(new FakeAiTenantSettingRepository(self::acceptedSetting()));
        $audit = new SpyAuditLogWriter();
        $service = new AiSettingsService(
            $settings,
            new FakeFeatureFlagRepository(),
            new FakeAiInteractionRepository(self::TENANT_ID),
            new FakeAuthorizationPolicy(),
            $audit,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
        );

        $service->changeMonthlyQuota(AiTenantSetting::DEFAULT_MONTHLY_TOKEN_QUOTA);

        Assert::same(1, $settings->finds);
        Assert::same(0, $settings->saves);
        Assert::same([], $audit->events);

        $service->changeMonthlyQuota(5000);

        Assert::same(2, $settings->finds);
        Assert::same(1, $settings->saves);
        Assert::same(['ai.settings.quota_changed'], self::actions($audit));
        Assert::same(5000, $settings->find()?->monthlyTokenQuota());
    }

    /** Sem registro, a cota padrão é gravada (e auditada) mesmo sem mudar o valor. */
    public function testChangeMonthlyQuotaWithoutStoredSettingPersistsTheDefaultOnce(): void
    {
        $settings = self::countingSettings(new FakeAiTenantSettingRepository());
        $audit = new SpyAuditLogWriter();
        $service = new AiSettingsService(
            $settings,
            new FakeFeatureFlagRepository(),
            new FakeAiInteractionRepository(self::TENANT_ID),
            new FakeAuthorizationPolicy(),
            $audit,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
        );

        $service->changeMonthlyQuota(AiTenantSetting::DEFAULT_MONTHLY_TOKEN_QUOTA);

        Assert::same(1, $settings->finds);
        Assert::same(1, $settings->saves);
        Assert::same(['ai.settings.quota_changed'], self::actions($audit));
    }

    /** T-10: às 22:00 de 31/10 em America/Fortaleza o mês do overview ainda é outubro. */
    public function testOverviewMonthStartsInTheLocalTimezoneLateOnTheLastDay(): void
    {
        date_default_timezone_set('America/Fortaleza');
        $interactions = self::monthSpy(new FakeAiInteractionRepository(self::TENANT_ID));
        $service = new AiSettingsService(
            new FakeAiTenantSettingRepository(self::acceptedSetting()),
            new FakeFeatureFlagRepository(),
            $interactions,
            new FakeAuthorizationPolicy(),
            new SpyAuditLogWriter(),
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-31 22:00:00', new DateTimeZone('America/Fortaleza')),
        );

        $service->overview();

        Assert::same(['monthlySummary', 'monthlyTokenUsage'], array_keys($interactions->monthStarts));

        foreach ($interactions->monthStarts as $monthStart) {
            Assert::same('2026-10-01 00:00:00', $monthStart->format('Y-m-d H:i:s'));
            Assert::same('America/Fortaleza', $monthStart->getTimezone()->getName());
        }
    }

    private static function countingSettings(FakeAiTenantSettingRepository $inner): AiTenantSettingRepositoryInterface
    {
        return new class ($inner) implements AiTenantSettingRepositoryInterface {
            public int $finds = 0;
            public int $saves = 0;

            public function __construct(private readonly FakeAiTenantSettingRepository $inner)
            {
            }

            public function find(): ?AiTenantSetting
            {
                ++$this->finds;

                return $this->inner->find();
            }

            public function save(AiTenantSetting $setting, int $systemUserId): void
            {
                ++$this->saves;
                $this->inner->save($setting, $systemUserId);
            }
        };
    }

    private static function monthSpy(FakeAiInteractionRepository $inner): AiInteractionRepositoryInterface
    {
        return new class ($inner) implements AiInteractionRepositoryInterface {
            /** @var array<string, DateTimeImmutable> */
            public array $monthStarts = [];

            public function __construct(private readonly FakeAiInteractionRepository $inner)
            {
            }

            public function insert(AiInteraction $interaction): AiInteraction
            {
                return $this->inner->insert($interaction);
            }

            public function saveDecision(AiInteraction $interaction): bool
            {
                return $this->inner->saveDecision($interaction);
            }

            public function find(int $id): ?AiInteraction
            {
                return $this->inner->find($id);
            }

            public function monthlyTokenUsage(DateTimeImmutable $monthStart): int
            {
                $this->monthStarts['monthlyTokenUsage'] = $monthStart;

                return $this->inner->monthlyTokenUsage($monthStart);
            }

            public function monthlySummary(DateTimeImmutable $monthStart): array
            {
                $this->monthStarts['monthlySummary'] = $monthStart;

                return $this->inner->monthlySummary($monthStart);
            }

            public function latestDecidedFor(string $purpose, int $subjectId): ?AiInteraction
            {
                return $this->inner->latestDecidedFor($purpose, $subjectId);
            }

            public function listRecent(int $limit): array
            {
                return $this->inner->listRecent($limit);
            }
        };
    }

    public function testOverviewUsesDefaultsAndSumsMonthCost(): void
    {
        [$service, , , $interactions, $policy] = $this->build();
        $interactions->seed(self::interaction(1, AiPurpose::PATIENT_SUMMARY, self::TENANT_ID, '2026-10-02 08:00:00', 100, 50, 700));
        $interactions->seed(self::interaction(2, AiPurpose::ANAMNESIS_ORGANIZE, self::TENANT_ID, '2026-10-10 08:00:00', 200, 100, 1400));
        $interactions->seed(self::interaction(3, AiPurpose::ANAMNESIS_ORGANIZE, self::TENANT_ID, '2026-10-11 08:00:00', 10, 0, null));
        $interactions->seed(self::interaction(4, AiPurpose::PATIENT_SUMMARY, self::TENANT_ID, '2026-09-30 23:00:00', 999, 999, 99999));
        $interactions->seed(self::interaction(5, AiPurpose::PATIENT_SUMMARY, 2, '2026-10-05 08:00:00', 999, 999, 99999));

        $overview = $service->overview();

        Assert::false($overview['enabled']);
        Assert::null($overview['terms_version']);
        Assert::null($overview['terms_accepted_at']);
        Assert::false($overview['terms_current']);
        Assert::same(300000, $overview['monthly_token_quota']);
        Assert::same(460, $overview['month_tokens']);
        Assert::same(2100, $overview['month_cost_micro_usd']);
        Assert::same([
            ['purpose' => 'anamnesis_organize', 'requests' => 2, 'tokens' => 310, 'cost_micro_usd' => 1400],
            ['purpose' => 'patient_summary', 'requests' => 1, 'tokens' => 150, 'cost_micro_usd' => 700],
        ], $overview['by_purpose']);
        Assert::same('AiSettingsForm::onReload', $policy->requests[0]->action());
    }

    public function testRecentInteractionsReturnsOwnTenantMostRecentFirst(): void
    {
        [$service, , , $interactions, $policy] = $this->build();
        $interactions->seed(self::interaction(1, AiPurpose::PATIENT_SUMMARY, self::TENANT_ID, '2026-10-02 08:00:00', 1, 1, 1));
        $interactions->seed(self::interaction(2, AiPurpose::EXAM_SUMMARY, self::TENANT_ID, '2026-10-03 08:00:00', 1, 1, 1));
        $interactions->seed(self::interaction(3, AiPurpose::EXAM_SUMMARY, 2, '2026-10-04 08:00:00', 1, 1, 1));

        $recent = $service->recentInteractions();

        Assert::same([2, 1], array_map(static fn (AiInteraction $i): ?int => $i->id(), $recent));
        Assert::same('AiInteractionList::onReload', $policy->requests[0]->action());
        Assert::count(1, $service->recentInteractions(1));
    }

    public function testAuthorizationRequestsAreTenantWideOnTheSettingsProgram(): void
    {
        [$service, , , , $policy] = $this->build(self::acceptedSetting());

        $service->enable(false);
        $service->disable();
        $service->changeMonthlyQuota(5000);

        Assert::count(3, $policy->requests);

        foreach ($policy->requests as $request) {
            Assert::same('AiSettingsForm::onSave', $request->action());
            Assert::false($request->requiresUnitScope());
            Assert::same(self::TENANT_ID, $request->resourceTenantId());
        }
    }

    public function testDeniedAuthorizationThrowsWithoutWriting(): void
    {
        [$service, $settings, $flags, , , $audit] = $this->build(null, [FeatureFlagRepositoryInterface::AI_ASSISTANT], false);

        foreach ([
            fn () => $service->enable(true),
            fn () => $service->disable(),
            fn () => $service->changeMonthlyQuota(1000),
            fn () => $service->overview(),
            fn () => $service->recentInteractions(),
        ] as $call) {
            Assert::throws(AuthorizationDenied::class, $call);
        }

        Assert::null($settings->saved());
        Assert::same([], $flags->changes());
        Assert::true($flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));
        Assert::same([], $audit->events);
    }

    public function testSetCopilotManagementTurnsTheFlagOnWithTheContextUser(): void
    {
        [$service, , $flags, , $policy, $audit] = $this->build(self::acceptedSetting(), [FeatureFlagRepositoryInterface::AI_ASSISTANT]);

        Assert::false($service->overview()['copilot_management']);

        $service->setCopilotManagement(true);

        Assert::same([
            ['flag_key' => FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT, 'enabled' => true, 'system_user_id' => self::USER_ID],
        ], $flags->changes());
        Assert::true($service->overview()['copilot_management']);
        Assert::true($service->overview()['enabled']);
        Assert::same('AiSettingsForm::onSave', $policy->requests[1]->action());
        Assert::false($policy->requests[1]->requiresUnitScope());
        Assert::same(['ai.settings.copilot_management_enabled'], self::actions($audit));
    }

    public function testSetCopilotManagementOffWritesOnlyWhenItChanges(): void
    {
        [$service, , $flags, , , $audit] = $this->build(null, [FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT]);

        $service->setCopilotManagement(true);
        Assert::same([], $flags->changes());

        $service->setCopilotManagement(false);
        $service->setCopilotManagement(false);

        Assert::same([
            ['flag_key' => FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT, 'enabled' => false, 'system_user_id' => self::USER_ID],
        ], $flags->changes());
        Assert::false($service->overview()['copilot_management']);
        Assert::same(['ai.settings.copilot_management_disabled'], self::actions($audit));
    }

    public function testSetCopilotManagementDeniedThrowsWithoutChangingTheFlag(): void
    {
        [$service, , $flags, , , $audit] = $this->build(null, [], false);

        Assert::throws(AuthorizationDenied::class, fn () => $service->setCopilotManagement(true));

        Assert::same([], $flags->changes());
        Assert::false($flags->isEnabled(FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT));
        Assert::same([], $audit->events);
    }
}
