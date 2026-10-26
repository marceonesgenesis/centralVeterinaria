<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Pilot\PilotSettings;

/**
 * Recorte homologado da oferta (Etapa 5, T-03). Os recursos fora do recorte
 * continuam no código e nos direitos do plano (PlanEntitlements), mas ficam
 * desligados até o ambiente listá-los em OFFER_PREVIEW_FEATURES. Controller
 * fora de PlanEntitlements::gatedPrograms() é sempre liberado.
 */
final class OfferScope
{
    public const RELEASED_FEATURES = [
        PlanEntitlements::FEATURE_INVENTORY_POS,
        PlanEntitlements::FEATURE_FINANCE_FULL,
        PlanEntitlements::FEATURE_MULTI_UNIT,
    ];

    public function __construct(private readonly PilotSettings $settings)
    {
    }

    public static function fromEnvironment(): self
    {
        return new self(PilotSettings::fromEnvironment());
    }

    public function isFeatureReleased(string $feature): bool
    {
        return in_array($feature, self::RELEASED_FEATURES, true)
            || in_array($feature, $this->settings->previewFeatures(), true);
    }

    public function isProgramReleased(string $controller): bool
    {
        $feature = PlanEntitlements::gatedPrograms()[$controller] ?? null;

        return $feature === null || $this->isFeatureReleased($feature);
    }

    public function isPlanOffered(string $planId): bool
    {
        return in_array($planId, $this->settings->offeredPlans(), true);
    }

    /** @return list<string> */
    public function offeredPlans(): array
    {
        return $this->settings->offeredPlans();
    }
}
