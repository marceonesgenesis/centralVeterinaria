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
    /**
     * Telas que seguem abertas com a assinatura bloqueada (login, assinatura,
     * senha) e os dropdowns de mensagens e notificações do shell, que só leem.
     */
    public const ALWAYS_ALLOWED = [
        'LoginForm', 'SubscriptionStatusView', 'SystemPasswordResetForm', 'SystemRequestPasswordResetForm',
        'SystemMessageDropdown', 'SystemNotificationDropdown',
    ];

    /**
     * Controllers com mutação em que só o método de leitura do shell segue
     * aberto: CvShellController::onContext (contexto do usuário); a troca de
     * unidade (onSwitchUnit) continua bloqueada.
     */
    public const ALWAYS_ALLOWED_METHODS = ['CvShellController' => ['onContext']];

    public function __construct(private readonly SubscriptionRepositoryInterface $subscriptions)
    {
    }

    /**
     * Chave da decisão (e do cache na sessão): `Classe::método` para os pares
     * de ALWAYS_ALLOWED_METHODS, senão a classe.
     */
    public static function accessKey(string $controller, ?string $method): string
    {
        return $method !== null && in_array($method, self::ALWAYS_ALLOWED_METHODS[$controller] ?? [], true)
            ? $controller . '::' . $method
            : $controller;
    }

    public function decide(int $tenantId, string $controller, DateTimeImmutable $now, ?string $method = null): AccessDecision
    {
        $alwaysAllowed = in_array($controller, self::ALWAYS_ALLOWED, true)
            || ($method !== null && in_array($method, self::ALWAYS_ALLOWED_METHODS[$controller] ?? [], true));
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
