<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\OfferScope;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Pilot\PilotSettings;
use CentralVet\Tests\Support\Assert;

/**
 * Recorte homologado da oferta atualizado com os novos módulos do MVP.
 */
final class OfferScopeTest
{
    public function testDefaultScopeReleasesAllMvpModules(): void
    {
        $scope = new OfferScope(PilotSettings::fromArray([]));

        Assert::true($scope->isProgramReleased('SaleList'));
        Assert::true($scope->isProgramReleased('TutorList'));
        Assert::true($scope->isProgramReleased('SystemUnitForm'));
        Assert::true($scope->isProgramReleased('PayableList'));
        Assert::true($scope->isProgramReleased('HospitalizationBoard'));
        Assert::true($scope->isProgramReleased('SurgeryList'));
        Assert::true($scope->isProgramReleased('AiCopilotPanel'));
        Assert::true($scope->isProgramReleased('CommunicationComposeForm'));
    }

    public function testReleasedFeaturesConstant(): void
    {
        Assert::same([
            PlanEntitlements::FEATURE_INVENTORY_POS,
            PlanEntitlements::FEATURE_FINANCE_FULL,
            PlanEntitlements::FEATURE_COMMUNICATION,
            PlanEntitlements::FEATURE_MULTI_UNIT,
            PlanEntitlements::FEATURE_HOSPITAL,
            PlanEntitlements::FEATURE_AI,
        ], OfferScope::RELEASED_FEATURES);
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
