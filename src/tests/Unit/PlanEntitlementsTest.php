<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\PlanEntitlements;
use CentralVet\Landing\LandingCatalog;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use InvalidArgumentException;

/**
 * Etapa 3, T-07: direitos por plano (quotas, recursos e programas
 * liberados), com preço e nome lidos do LandingCatalog.
 */
final class PlanEntitlementsTest
{
    public function testPricesAndNamesComeFromLandingCatalog(): void
    {
        $catalogIds = array_map(static fn (array $plan): string => $plan['id'], LandingCatalog::plans());
        Assert::same($catalogIds, PlanEntitlements::PLAN_IDS);

        foreach (LandingCatalog::plans() as $plan) {
            $entitlements = PlanEntitlements::forPlan($plan['id']);
            Assert::same($plan['id'], $entitlements->planId());
            Assert::same($plan['name'], $entitlements->name());
            Assert::same($plan['price_cents'], $entitlements->priceCents());
        }

        Assert::same(5700, PlanEntitlements::forPlan('starter')->priceCents());
        Assert::same(9700, PlanEntitlements::forPlan('pro')->priceCents());
        Assert::same(13700, PlanEntitlements::forPlan('business')->priceCents());
        Assert::same(19700, PlanEntitlements::forPlan('enterprise')->priceCents());
    }

    public function testQuotasPerPlan(): void
    {
        Assert::same([2, 1], [PlanEntitlements::forPlan('starter')->maxUsers(), PlanEntitlements::forPlan('starter')->maxUnits()]);
        Assert::same([10, 1], [PlanEntitlements::forPlan('pro')->maxUsers(), PlanEntitlements::forPlan('pro')->maxUnits()]);
        Assert::same([30, 5], [PlanEntitlements::forPlan('business')->maxUsers(), PlanEntitlements::forPlan('business')->maxUnits()]);
        Assert::null(PlanEntitlements::forPlan('enterprise')->maxUsers());
        Assert::null(PlanEntitlements::forPlan('enterprise')->maxUnits());
    }

    public function testStarterDeniesSaleListAndAllowsTutorList(): void
    {
        $starter = PlanEntitlements::forPlan('starter');

        Assert::false($starter->allowsProgram('SaleList'));
        Assert::true($starter->allowsProgram('TutorList'));
        Assert::false($starter->hasFeature(PlanEntitlements::FEATURE_INVENTORY_POS));
    }

    public function testBusinessAllowsSystemUnitFormAndProDenies(): void
    {
        Assert::true(PlanEntitlements::forPlan('business')->allowsProgram('SystemUnitForm'));
        Assert::false(PlanEntitlements::forPlan('pro')->allowsProgram('SystemUnitForm'));
        Assert::true(PlanEntitlements::forPlan('pro')->allowsProgram('SaleList'));
        Assert::true(PlanEntitlements::forPlan('pro')->allowsProgram('PayableList'));
        Assert::true(PlanEntitlements::forPlan('pro')->allowsProgram('CommunicationComposeForm'));
    }

    public function testHospitalAndAiOnlyOnEnterprise(): void
    {
        foreach (['starter', 'pro', 'business'] as $planId) {
            $plan = PlanEntitlements::forPlan($planId);
            Assert::false($plan->allowsProgram('HospitalizationBoard'), $planId . ' must deny HospitalizationBoard');
            Assert::false($plan->allowsProgram('SurgeryList'), $planId . ' must deny SurgeryList');
            Assert::false($plan->allowsProgram('BedList'), $planId . ' must deny BedList');
            Assert::false($plan->allowsProgram('AiCopilotPanel'), $planId . ' must deny AiCopilotPanel');
        }

        $enterprise = PlanEntitlements::forPlan('enterprise');
        Assert::true($enterprise->allowsProgram('HospitalizationBoard'));
        Assert::true($enterprise->allowsProgram('AiCopilotPanel'));
        Assert::true($enterprise->hasFeature(PlanEntitlements::FEATURE_HOSPITAL));
        Assert::true($enterprise->hasFeature(PlanEntitlements::FEATURE_AI));
    }

    public function testGatedProgramsMapControllersToKnownFeatures(): void
    {
        $gated = PlanEntitlements::gatedPrograms();
        $features = [
            PlanEntitlements::FEATURE_INVENTORY_POS,
            PlanEntitlements::FEATURE_FINANCE_FULL,
            PlanEntitlements::FEATURE_COMMUNICATION,
            PlanEntitlements::FEATURE_MULTI_UNIT,
            PlanEntitlements::FEATURE_HOSPITAL,
            PlanEntitlements::FEATURE_AI,
        ];

        foreach ($gated as $controller => $feature) {
            Assert::true(in_array($feature, $features, true), $controller . ' maps to unknown feature ' . $feature);
        }

        Assert::same(PlanEntitlements::FEATURE_INVENTORY_POS, $gated['SaleCancelForm']);
        Assert::same(PlanEntitlements::FEATURE_MULTI_UNIT, $gated['SystemUnitList']);
        Assert::same(PlanEntitlements::FEATURE_HOSPITAL, $gated['SurgeryRoomForm']);
        Assert::same(PlanEntitlements::FEATURE_AI, $gated['AiToolCallList']);
        Assert::false(isset($gated['CashSessionForm']), 'cash stays available on every plan');
    }

    public function testUnknownPlanThrows(): void
    {
        try {
            PlanEntitlements::forPlan('gold');
        } catch (InvalidArgumentException $e) {
            Assert::same('Unknown plan "gold"', $e->getMessage());

            return;
        }

        throw new AssertionFailedException('forPlan("gold") must throw');
    }
}
