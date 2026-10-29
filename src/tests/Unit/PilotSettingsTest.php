<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Pilot\PilotSettings;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 5, T-03: configuração do piloto lida do ambiente (planos ofertados,
 * recursos em prévia, canal de suporte e retenção após o cancelamento).
 * E-mails de teste em @example.test.
 */
final class PilotSettingsTest
{
    public function testDefaultsWithEmptyEnvironment(): void
    {
        $settings = PilotSettings::fromArray([]);

        Assert::same(['starter', 'pro', 'business', 'enterprise'], $settings->offeredPlans());
        Assert::same([], $settings->previewFeatures());
        Assert::null($settings->supportEmail());
        Assert::null($settings->supportWhatsapp());
        Assert::same(PilotSettings::DEFAULT_SUPPORT_HOURS, $settings->supportHours());
        Assert::same('Segunda a sexta, das 8h às 18h', PilotSettings::DEFAULT_SUPPORT_HOURS);
        Assert::same(30, $settings->retentionDaysAfterCancel());
        Assert::same(30, PilotSettings::DEFAULT_RETENTION_DAYS);
    }

    public function testOfferedPlansIgnoreSpacesAndUnknownIds(): void
    {
        Assert::same(['pro'], PilotSettings::fromArray(['OFFER_PLANS' => ' pro , gold'])->offeredPlans());
    }

    public function testOnlyUnknownPlansFallBackToAllPlans(): void
    {
        Assert::same(
            ['starter', 'pro', 'business', 'enterprise'],
            PilotSettings::fromArray(['OFFER_PLANS' => 'gold'])->offeredPlans(),
        );
    }

    public function testOfferedPlansFollowCatalogOrderWithoutDuplicates(): void
    {
        Assert::same(
            ['pro', 'enterprise'],
            PilotSettings::fromArray(['OFFER_PLANS' => 'enterprise,pro,pro'])->offeredPlans(),
        );
    }

    public function testPreviewFeaturesKeepOnlyKnownFeatures(): void
    {
        $settings = PilotSettings::fromArray(['OFFER_PREVIEW_FEATURES' => ' hospital, teleport ,ai_advanced']);

        Assert::same(['hospital', 'ai_advanced'], $settings->previewFeatures());
    }

    public function testSupportEmailMustBeValid(): void
    {
        Assert::same('suporte@example.test', PilotSettings::fromArray(['SUPPORT_EMAIL' => ' suporte@example.test '])->supportEmail());
        Assert::null(PilotSettings::fromArray(['SUPPORT_EMAIL' => 'suporte'])->supportEmail());
    }

    public function testWhatsappAcceptsOnlyDigits(): void
    {
        Assert::null(PilotSettings::fromArray(['SUPPORT_WHATSAPP' => '+55 (85) 99999-0000'])->supportWhatsapp());
        Assert::same('5585999990000', PilotSettings::fromArray(['SUPPORT_WHATSAPP' => '5585999990000'])->supportWhatsapp());
        Assert::null(PilotSettings::fromArray(['SUPPORT_WHATSAPP' => '123456789'])->supportWhatsapp());
        Assert::null(PilotSettings::fromArray(['SUPPORT_WHATSAPP' => '1234567890123456'])->supportWhatsapp());
    }

    public function testSupportHoursOverride(): void
    {
        Assert::same('24h', PilotSettings::fromArray(['SUPPORT_HOURS' => ' 24h '])->supportHours());
        Assert::same(PilotSettings::DEFAULT_SUPPORT_HOURS, PilotSettings::fromArray(['SUPPORT_HOURS' => '  '])->supportHours());
    }

    public function testRetentionDaysOutOfRangeFallBackToDefault(): void
    {
        Assert::same(30, PilotSettings::fromArray(['DATA_RETENTION_DAYS_AFTER_CANCEL' => '0'])->retentionDaysAfterCancel());
        Assert::same(30, PilotSettings::fromArray(['DATA_RETENTION_DAYS_AFTER_CANCEL' => '3651'])->retentionDaysAfterCancel());
        Assert::same(30, PilotSettings::fromArray(['DATA_RETENTION_DAYS_AFTER_CANCEL' => '7.5'])->retentionDaysAfterCancel());
        Assert::same(60, PilotSettings::fromArray(['DATA_RETENTION_DAYS_AFTER_CANCEL' => '60'])->retentionDaysAfterCancel());
    }
}
