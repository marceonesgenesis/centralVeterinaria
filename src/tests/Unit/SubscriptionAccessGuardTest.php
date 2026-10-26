<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\AccessDecision;
use CentralVet\Billing\OfferScope;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionAccessGuard;
use CentralVet\Pilot\PilotSettings;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeSubscriptionRepository;
use DateTimeImmutable;

/**
 * Etapa 3, T-15: decisão de acesso por estado da assinatura e por plano.
 * Ordem: ALWAYS_ALLOWED → sem assinatura (legacy) → bloqueio por estado →
 * bloqueio por plano → fora do recorte do piloto (not_released, T-07 da
 * Etapa 5) → full/grace.
 */
final class SubscriptionAccessGuardTest
{
    private const TENANT = 42;

    public function testStarterActiveIsBlockedOnProgramOutsideThePlan(): void
    {
        [$guard] = $this->guardWith('starter');

        $decision = $guard->decide(self::TENANT, 'SaleList', $this->now());

        Assert::false($decision->allowed());
        Assert::same(AccessDecision::REASON_BLOCKED_PLAN, $decision->reason());
        Assert::same('blocked_plan', $decision->reason());
        Assert::same('Your plan does not include this feature', $decision->messageKey());
    }

    public function testProActiveHasFullAccessToSaleList(): void
    {
        [$guard] = $this->guardWith('pro');

        $decision = $guard->decide(self::TENANT, 'SaleList', $this->now());

        Assert::true($decision->allowed());
        Assert::same('full', $decision->reason());
        Assert::null($decision->messageKey());
    }

    public function testStarterActiveReachesProgramIncludedInEveryPlan(): void
    {
        [$guard] = $this->guardWith('starter');

        $decision = $guard->decide(self::TENANT, 'PatientList', $this->now());

        Assert::true($decision->allowed());
        Assert::same('full', $decision->reason());
    }

    public function testPastDueIsGraceInsideToleranceAndBlockedAfter(): void
    {
        $failedAt = new DateTimeImmutable('2026-10-10 12:00:00');
        [$guard] = $this->guardWith('pro', static function (Subscription $s) use ($failedAt): void {
            $s->markPaymentFailed(200, $failedAt);
        });

        $inside = $guard->decide(self::TENANT, 'SaleList', $failedAt->modify('+6 days'));
        Assert::true($inside->allowed());
        Assert::same('grace', $inside->reason());
        Assert::null($inside->messageKey());

        $after = $guard->decide(self::TENANT, 'SaleList', $failedAt->modify('+8 days'));
        Assert::false($after->allowed());
        Assert::same('blocked_status', $after->reason());
        Assert::same('Your subscription is suspended. Regularize the payment to continue', $after->messageKey());
    }

    public function testBlockedStatusWinsOverBlockedPlan(): void
    {
        $failedAt = new DateTimeImmutable('2026-10-10 12:00:00');
        [$guard] = $this->guardWith('starter', static function (Subscription $s) use ($failedAt): void {
            $s->markPaymentFailed(200, $failedAt);
            $s->suspendIfGraceExpired($failedAt->modify('+8 days'));
        });

        $decision = $guard->decide(self::TENANT, 'SaleList', $failedAt->modify('+9 days'));

        Assert::false($decision->allowed());
        Assert::same('blocked_status', $decision->reason());
    }

    public function testSuspendedStillReachesAlwaysAllowedControllers(): void
    {
        $failedAt = new DateTimeImmutable('2026-10-10 12:00:00');
        [$guard] = $this->guardWith('starter', static function (Subscription $s) use ($failedAt): void {
            $s->markPaymentFailed(200, $failedAt);
            $s->suspendIfGraceExpired($failedAt->modify('+8 days'));
        });
        $now = $failedAt->modify('+9 days');

        Assert::same(
            ['LoginForm', 'SubscriptionStatusView', 'SystemPasswordResetForm', 'SystemRequestPasswordResetForm', 'SystemMessageDropdown', 'SystemNotificationDropdown'],
            SubscriptionAccessGuard::ALWAYS_ALLOWED,
        );

        foreach (SubscriptionAccessGuard::ALWAYS_ALLOWED as $controller) {
            $decision = $guard->decide(self::TENANT, $controller, $now);
            Assert::true($decision->allowed(), $controller . ' must stay reachable when suspended');
            Assert::null($decision->messageKey());
        }
    }

    /**
     * Correção pós-onda (Onda 6): com a assinatura suspensa, o shell da
     * SubscriptionStatusView (contexto do usuário e os dropdowns de
     * mensagens e notificações, só leitura) segue aberto; a troca de unidade
     * (CvShellController::onSwitchUnit) e os programas clínicos, não.
     */
    public function testSuspendedStillReachesTheReadOnlyShellButNotClinicalPrograms(): void
    {
        $failedAt = new DateTimeImmutable('2026-10-10 12:00:00');
        [$guard] = $this->guardWith('starter', static function (Subscription $s) use ($failedAt): void {
            $s->markPaymentFailed(200, $failedAt);
            $s->suspendIfGraceExpired($failedAt->modify('+8 days'));
        });
        $now = $failedAt->modify('+9 days');

        foreach ([['CvShellController', 'onContext'], ['SystemMessageDropdown', null], ['SystemNotificationDropdown', null], ['SystemMessageDropdown', 'show']] as [$controller, $method]) {
            $decision = $guard->decide(self::TENANT, $controller, $now, $method);
            Assert::true($decision->allowed(), "{$controller}::{$method} must stay reachable when suspended");
            Assert::null($decision->messageKey());
        }

        foreach ([['CvShellController', 'onSwitchUnit'], ['CvShellController', null], ['PatientList', null], ['SaleForm', 'onSave']] as [$controller, $method]) {
            $decision = $guard->decide(self::TENANT, $controller, $now, $method);
            Assert::false($decision->allowed(), "{$controller}::{$method} must stay blocked when suspended");
            Assert::same(AccessDecision::REASON_BLOCKED_STATUS, $decision->reason());
        }
    }

    public function testAccessKeySeparatesOnlyTheMethodLevelExceptions(): void
    {
        Assert::same(['CvShellController' => ['onContext']], SubscriptionAccessGuard::ALWAYS_ALLOWED_METHODS);
        Assert::same('CvShellController::onContext', SubscriptionAccessGuard::accessKey('CvShellController', 'onContext'));
        Assert::same('CvShellController', SubscriptionAccessGuard::accessKey('CvShellController', 'onSwitchUnit'));
        Assert::same('CvShellController', SubscriptionAccessGuard::accessKey('CvShellController', null));
        Assert::same('PatientList', SubscriptionAccessGuard::accessKey('PatientList', 'onReload'));
    }

    /**
     * Correção pós-onda, rodada 2: classe e método vêm da mesma fonte que o
     * dispatch executa (regra da MutationRequestPolicy: query canônica,
     * divergência query × corpo × $_REQUEST negada).
     */
    public function testRequestTargetDeniesMethodDivergenceBetweenQueryAndBody(): void
    {
        // POST engine.php?class=CvShellController&method=onSwitchUnit, corpo method=onContext (request_order=GP: o POST vence).
        $query = ['class' => 'CvShellController', 'method' => 'onSwitchUnit'];
        $body = ['method' => 'onContext', 'unit_id' => '9'];
        $request = array_merge($query, $body);

        Assert::null(SubscriptionAccessGuard::requestTarget($request, $query, $body), 'corpo onContext com query onSwitchUnit é negado');
    }

    public function testRequestTargetDeniesClassDivergenceBetweenQueryAndBody(): void
    {
        $query = ['class' => 'PatientList', 'method' => 'onReload'];
        $body = ['class' => 'SubscriptionStatusView'];
        $request = array_merge($query, $body);

        Assert::null(SubscriptionAccessGuard::requestTarget($request, $query, $body), 'corpo SubscriptionStatusView com query PatientList é negado');

        $query = ['class' => 'CvShellController', 'method' => 'onSwitchUnit'];
        $body = ['class' => 'SystemMessageDropdown', 'method' => 'onSwitchUnit'];
        Assert::null(SubscriptionAccessGuard::requestTarget(array_merge($query, $body), $query, $body));
    }

    public function testRequestTargetDeniesMethodOnlyOutsideTheQuery(): void
    {
        // A query nomeia a classe sem método; o método só existe em $_REQUEST: não é o que o dispatch executa.
        Assert::null(SubscriptionAccessGuard::requestTarget(['class' => 'CvShellController', 'method' => 'onContext'], ['class' => 'CvShellController'], []));
    }

    public function testLegitimateShellRequestsStayAllowedWhenSuspended(): void
    {
        $failedAt = new DateTimeImmutable('2026-10-10 12:00:00');
        [$guard] = $this->guardWith('starter', static function (Subscription $s) use ($failedAt): void {
            $s->markPaymentFailed(200, $failedAt);
            $s->suspendIfGraceExpired($failedAt->modify('+8 days'));
        });
        $now = $failedAt->modify('+9 days');

        $get = ['class' => 'CvShellController', 'method' => 'onContext'];
        $engineStatic = ['class' => 'CvShellController', 'method' => 'onContext', 'static' => '1'];
        $enginePost = [[$engineStatic, $engineStatic, []], [$engineStatic + ['x' => '1'], $engineStatic, ['x' => '1']], [$engineStatic, [], $engineStatic]];

        foreach (array_merge([[$get, $get, []]], $enginePost) as [$request, $query, $body]) {
            $target = SubscriptionAccessGuard::requestTarget($request, $query, $body);
            Assert::same(['class' => 'CvShellController', 'method' => 'onContext', 'key' => 'CvShellController::onContext'], $target);
            Assert::true($guard->decide(self::TENANT, $target['class'], $now, $target['method'])->allowed(), 'onContext por GET e por engine static segue aberto');
        }

        $switch = ['class' => 'CvShellController', 'method' => 'onSwitchUnit', 'static' => '1'];
        $target = SubscriptionAccessGuard::requestTarget($switch, $switch, ['unit_id' => '9']);
        Assert::same('CvShellController', $target['key'] ?? null);
        Assert::false($guard->decide(self::TENANT, $target['class'], $now, $target['method'])->allowed(), 'onSwitchUnit coerente segue bloqueado');
    }

    public function testRequestTargetWithoutMethodKeepsTheClassKey(): void
    {
        Assert::same(
            ['class' => 'PatientList', 'method' => null, 'key' => 'PatientList'],
            SubscriptionAccessGuard::requestTarget(['class' => 'PatientList'], ['class' => 'PatientList'], []),
        );
        Assert::same(
            ['class' => '', 'method' => null, 'key' => ''],
            SubscriptionAccessGuard::requestTarget([], [], []),
        );
    }

    public function testTenantWithoutSubscriptionIsLegacy(): void
    {
        [$guard] = $this->guardWith('starter');

        $decision = $guard->decide(999, 'SaleList', $this->now());

        Assert::true($decision->allowed());
        Assert::same('legacy', $decision->reason());
        Assert::null($decision->messageKey());
    }

    /**
     * Etapa 5, T-07: controller liberado pelo plano mas cuja feature está
     * fora do recorte do piloto (OfferScope) é bloqueado com not_released.
     */
    public function testEnterpriseActiveIsNotReleasedOnHospitalAndAdvancedAi(): void
    {
        [$guard] = $this->guardWith('enterprise', null, $this->pilotScope());

        foreach (['HospitalizationBoard', 'AiCopilotPanel'] as $controller) {
            $decision = $guard->decide(self::TENANT, $controller, $this->now());
            Assert::false($decision->allowed(), $controller . ' must be outside the pilot');
            Assert::same(AccessDecision::REASON_NOT_RELEASED, $decision->reason());
            Assert::same('not_released', $decision->reason());
            Assert::same('This module is not available in the pilot yet', $decision->messageKey());
        }
    }

    public function testProActiveIsNotReleasedOnCommunication(): void
    {
        [$guard] = $this->guardWith('pro', null, $this->pilotScope());

        $decision = $guard->decide(self::TENANT, 'CommunicationComposeForm', $this->now());

        Assert::false($decision->allowed());
        Assert::same('not_released', $decision->reason());
    }

    public function testProActiveKeepsFullAccessToReleasedSaleList(): void
    {
        [$guard] = $this->guardWith('pro', null, $this->pilotScope());

        $decision = $guard->decide(self::TENANT, 'SaleList', $this->now());

        Assert::true($decision->allowed());
        Assert::same('full', $decision->reason());
        Assert::null($decision->messageKey());
    }

    public function testBlockedPlanWinsOverNotReleased(): void
    {
        [$guard] = $this->guardWith('starter', null, $this->pilotScope());

        $decision = $guard->decide(self::TENANT, 'HospitalizationBoard', $this->now());

        Assert::false($decision->allowed());
        Assert::same('blocked_plan', $decision->reason());
    }

    public function testBlockedStatusWinsOverNotReleased(): void
    {
        $failedAt = new DateTimeImmutable('2026-10-10 12:00:00');
        [$guard] = $this->guardWith('enterprise', static function (Subscription $s) use ($failedAt): void {
            $s->markPaymentFailed(200, $failedAt);
            $s->suspendIfGraceExpired($failedAt->modify('+8 days'));
        }, $this->pilotScope());

        $decision = $guard->decide(self::TENANT, 'HospitalizationBoard', $failedAt->modify('+9 days'));

        Assert::false($decision->allowed());
        Assert::same('blocked_status', $decision->reason());
    }

    public function testTenantWithoutSubscriptionStaysLegacyOutsideThePilot(): void
    {
        [$guard] = $this->guardWith('enterprise', null, $this->pilotScope());

        $decision = $guard->decide(999, 'HospitalizationBoard', $this->now());

        Assert::true($decision->allowed());
        Assert::same('legacy', $decision->reason());
        Assert::null($decision->messageKey());
    }

    public function testPreviewFeaturesReleaseHospitalForEnterprise(): void
    {
        [$guard] = $this->guardWith('enterprise', null, $this->pilotScope(['OFFER_PREVIEW_FEATURES' => 'hospital,ai_advanced']));

        foreach (['HospitalizationBoard', 'AiCopilotPanel'] as $controller) {
            $decision = $guard->decide(self::TENANT, $controller, $this->now());
            Assert::true($decision->allowed(), $controller . ' must be released by preview');
            Assert::same('full', $decision->reason());
        }
    }

    public function testSubscriptionStatusViewStaysAllowedInThePilot(): void
    {
        [$guard] = $this->guardWith('starter', null, $this->pilotScope());

        $decision = $guard->decide(self::TENANT, 'SubscriptionStatusView', $this->now());

        Assert::true($decision->allowed());
        Assert::same('full', $decision->reason());
    }

    /**
     * @param array<string, string> $env
     */
    private function pilotScope(array $env = []): OfferScope
    {
        return new OfferScope(PilotSettings::fromArray($env));
    }

    /**
     * @param (callable(Subscription): void)|null $mutate
     * @return array{SubscriptionAccessGuard, FakeSubscriptionRepository}
     */
    private function guardWith(string $planId, ?callable $mutate = null, ?OfferScope $offer = null): array
    {
        $subscription = Subscription::start(
            10, $planId, 'card', 'cus_1', 'sub_1', 'active',
            new DateTimeImmutable('2026-10-01 00:00:00'), new DateTimeImmutable('2026-11-01 00:00:00'), 100,
        );
        $subscription->assignTenant(self::TENANT);
        if ($mutate !== null) {
            $mutate($subscription);
        }

        $repository = new FakeSubscriptionRepository();
        $repository->insert($subscription);

        return [
            $offer === null ? new SubscriptionAccessGuard($repository) : new SubscriptionAccessGuard($repository, $offer),
            $repository,
        ];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-05 12:00:00');
    }
}
