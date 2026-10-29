<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\AiConfig;
use CentralVet\Application\AiCopilotAvailability;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiInteractionRepository;
use CentralVet\Tests\Support\FakeAiTenantSettingRepository;
use CentralVet\Tests\Support\FakeFeatureFlagRepository;
use DateTimeImmutable;

/**
 * Fase 8B, T-26: lightweight copilot availability for the host screens
 * (PatientForm, EncounterView). Same rule as AiCopilotService::availability,
 * reading only flag, terms, quota and configuration — no gateway, limiter
 * or registry.
 */
final class AiCopilotAvailabilityTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 3;

    public function testClinicalReasonFollowsFlagTermsQuotaConfigurationOrder(): void
    {
        $interactions = new FakeAiInteractionRepository(self::TENANT_ID);

        Assert::same('ai_disabled', $this->availability([], self::setting(AiTerms::VERSION), $interactions, true)->reason('clinical'));

        $on = [FeatureFlagRepositoryInterface::AI_ASSISTANT];
        Assert::same('ai_terms_pending', $this->availability($on, self::setting('2025-01-v0'), $interactions, true)->reason('clinical'));
        Assert::same('ai_terms_pending', $this->availability($on, null, $interactions, true)->reason('clinical'));

        $interactions->setMonthlyTokenUsage(PHP_INT_MAX);
        Assert::same('ai_quota_exceeded', $this->availability($on, self::setting(AiTerms::VERSION), $interactions, true)->reason('clinical'));

        $interactions->setMonthlyTokenUsage(0);
        Assert::same('ai_not_configured', $this->availability($on, self::setting(AiTerms::VERSION), $interactions, false)->reason('clinical'));

        Assert::null($this->availability($on, self::setting(AiTerms::VERSION), $interactions, true)->reason('clinical'));
    }

    public function testManagementNeedsItsOwnFlag(): void
    {
        $interactions = new FakeAiInteractionRepository(self::TENANT_ID);

        Assert::same(
            'ai_disabled',
            $this->availability([FeatureFlagRepositoryInterface::AI_ASSISTANT], self::setting(AiTerms::VERSION), $interactions, true)->reason('management'),
        );
        Assert::null($this->availability(
            [FeatureFlagRepositoryInterface::AI_ASSISTANT, FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT],
            self::setting(AiTerms::VERSION),
            $interactions,
            true,
        )->reason('management'));
    }

    public function testConfigurationIsCheckedOnlyAfterFlagTermsAndQuota(): void
    {
        $calls = 0;
        $configured = static function () use (&$calls): bool {
            $calls++;

            return true;
        };

        $availability = new AiCopilotAvailability(
            new FakeFeatureFlagRepository([]),
            new FakeAiTenantSettingRepository(self::setting(AiTerms::VERSION)),
            new FakeAiInteractionRepository(self::TENANT_ID),
            $configured,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
        );

        Assert::same('ai_disabled', $availability->reason('clinical'));
        Assert::same(0, $calls);
    }

    public function testUnknownFamilyIsRejected(): void
    {
        $availability = $this->availability([FeatureFlagRepositoryInterface::AI_ASSISTANT], self::setting(AiTerms::VERSION), new FakeAiInteractionRepository(self::TENANT_ID), true);

        Assert::throws(\InvalidArgumentException::class, static fn () => $availability->reason('other'));
    }

    public function testProviderConfiguredFollowsProviderAndApiKey(): void
    {
        Assert::true(AiCopilotAvailability::providerConfigured(AiConfig::fromArray(['AI_PROVIDER' => 'stub'])));
        Assert::false(AiCopilotAvailability::providerConfigured(AiConfig::fromArray([])));
        Assert::true(AiCopilotAvailability::providerConfigured(AiConfig::fromArray(['ANTHROPIC_API_KEY' => 'sk-ant-test-f8b'])));
    }

    /** @param list<string> $flags */
    private function availability(array $flags, ?AiTenantSetting $setting, FakeAiInteractionRepository $interactions, bool $configured): AiCopilotAvailability
    {
        return new AiCopilotAvailability(
            new FakeFeatureFlagRepository($flags),
            new FakeAiTenantSettingRepository($setting),
            $interactions,
            static fn (): bool => $configured,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-16 10:00:00'),
        );
    }

    private static function setting(string $version): AiTenantSetting
    {
        $setting = AiTenantSetting::defaults(self::TENANT_ID);
        $setting->acceptTerms($version, self::USER_ID, new DateTimeImmutable('2026-10-01 09:00:00'));

        return $setting;
    }
}
