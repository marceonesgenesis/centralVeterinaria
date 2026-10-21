<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use DateTimeImmutable;

/**
 * Decide se o tenant pode abrir o controller pelo estado da assinatura e
 * pelo plano. Ordem: sem assinatura → legacy (acesso inalterado, notes.md
 * T-19 onda 4); ALWAYS_ALLOWED → permitido, com motivo grace na tolerância
 * e full nos demais estados (inclusive bloqueado: a tela da assinatura e a
 * troca de senha seguem abertas);
 * accessLevel blocked → blocked_status; controller fora do plano →
 * blocked_plan; senão full ou grace.
 */
final class SubscriptionAccessGuard
{
    /** Telas que seguem abertas com a assinatura bloqueada (login, assinatura, senha). */
    public const ALWAYS_ALLOWED = ['LoginForm', 'SubscriptionStatusView', 'SystemPasswordResetForm', 'SystemRequestPasswordResetForm'];

    public function __construct(private readonly SubscriptionRepositoryInterface $subscriptions)
    {
    }

    public function decide(int $tenantId, string $controller, DateTimeImmutable $now): AccessDecision
    {
        $alwaysAllowed = in_array($controller, self::ALWAYS_ALLOWED, true);
        $subscription = $this->subscriptions->findByTenantId($tenantId);

        if ($subscription === null) {
            return AccessDecision::legacy();
        }

        $level = $subscription->accessLevel($now);

        if ($alwaysAllowed) {
            return $level === Subscription::ACCESS_GRACE ? AccessDecision::grace() : AccessDecision::full();
        }

        if ($level === Subscription::ACCESS_BLOCKED) {
            return AccessDecision::blockedStatus();
        }

        if (!PlanEntitlements::forPlan($subscription->planId())->allowsProgram($controller)) {
            return AccessDecision::blockedPlan();
        }

        return $level === Subscription::ACCESS_GRACE ? AccessDecision::grace() : AccessDecision::full();
    }
}
