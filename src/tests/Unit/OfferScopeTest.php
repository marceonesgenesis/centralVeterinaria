<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\OfferScope;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Pilot\PilotSettings;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 5, T-03: recorte homologado da oferta. Recursos fora do recorte
 * seguem nos direitos do plano, mas ficam desligados até o ambiente
 * listá-los em OFFER_PREVIEW_FEATURES.
 */
final class OfferScopeTest
{
    public function testDefaultScopeReleasesPilotModulesOnly(): void
    {
        $scope = new OfferScope(PilotSettings::fromArray([]));

        Assert::true($scope->isProgramReleased('SaleList'));
        Assert::true($scope->isProgramReleased('TutorList'));
        Assert::true($scope->isProgramReleased('SystemUnitForm'));
        Assert::true($scope->isProgramReleased('PayableList'));
        Assert::false($scope->isProgramReleased('HospitalizationBoard'));
        Assert::false($scope->isProgramReleased('SurgeryList'));
        Assert::false($scope->isProgramReleased('AiCopilotPanel'));
        Assert::false($scope->isProgramReleased('CommunicationComposeForm'));
    }

    public function testReleasedFeaturesConstant(): void
    {
        Assert::same([
            PlanEntitlements::FEATURE_INVENTORY_POS,
            PlanEntitlements::FEATURE_FINANCE_FULL,
            PlanEntitlements::FEATURE_MULTI_UNIT,
        ], OfferScope::RELEASED_FEATURES);
    }

    public function testPreviewFeatureTurnsOnOnlyThatFeature(): void
    {
        $scope = new OfferScope(PilotSettings::fromArray(['OFFER_PREVIEW_FEATURES' => 'hospital']));

        Assert::true($scope->isProgramReleased('HospitalizationBoard'));
        Assert::true($scope->isFeatureReleased(PlanEntitlements::FEATURE_HOSPITAL));
        Assert::false($scope->isProgramReleased('AiCopilotPanel'));
        Assert::false($scope->isFeatureReleased(PlanEntitlements::FEATURE_COMMUNICATION));
    }

    public function testOfferedPlansFollowSettings(): void
    {
        $scope = new OfferScope(PilotSettings::fromArray(['OFFER_PLANS' => 'pro']));

        Assert::false($scope->isPlanOffered('starter'));
        Assert::true($scope->isPlanOffered('pro'));
        Assert::false($scope->isPlanOffered('gold'));
        Assert::same(['pro'], $scope->offeredPlans());
    }

    public function testDefaultOffersAllPlans(): void
    {
        $scope = new OfferScope(PilotSettings::fromArray([]));

        Assert::same(PlanEntitlements::PLAN_IDS, $scope->offeredPlans());
        foreach (PlanEntitlements::PLAN_IDS as $planId) {
            Assert::true($scope->isPlanOffered($planId), $planId);
        }
    }
}
