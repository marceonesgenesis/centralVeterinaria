<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\AiSettingsService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Domain\Exception\AiUnavailableException;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
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

    public function testEnableAcceptingTermsRecordsTermAndTurnsFlagOn(): void
    {
        [$service, $settings, $flags, , , $audit] = $this->build();

        $service->enable(true);

        $saved = $settings->saved();
        Assert::notNull($saved);
        Assert::same('2026-10-v1', $saved->termsVersion());
        Assert::same(self::USER_ID, $saved->termsAcceptedBySystemUserId());
        Assert::same(self::USER_ID, $settings->savedBySystemUserId());
        Assert::same('2026-10-15 12:00:00', $saved->termsAcceptedAt()?->format('Y-m-d H:i:s'));
        Assert::true($flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));
        Assert::same(['ai.terms.accepted', 'ai.settings.enabled'], self::actions($audit));
        Assert::same(['terms_version' => '2026-10-v1'], $audit->events[0]->afterData);
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
}
