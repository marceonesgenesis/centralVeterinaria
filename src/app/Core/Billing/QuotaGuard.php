<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use CentralVet\Billing\Contract\TenantUsageReaderInterface;

/**
 * Quota de usuários e de unidades pelo plano da assinatura do tenant.
 * Lança QuotaExceededException quando o uso atual já é >= limite; limite
 * nulo (sem limite) ou tenant sem assinatura (legacy) não lança. A única
 * aplicação fica nos serviços de equipe e unidades (T-24, T-25).
 */
final class QuotaGuard
{
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly TenantUsageReaderInterface $usage,
    ) {
    }

    /** @throws QuotaExceededException */
    public function assertCanAddUser(int $tenantId): void
    {
        $limit = $this->plan($tenantId)?->maxUsers();
        if ($limit !== null && $this->usage->countUsers($tenantId) >= $limit) {
            throw QuotaExceededException::users($limit);
        }
    }

    /** @throws QuotaExceededException */
    public function assertCanAddUnit(int $tenantId): void
    {
        $limit = $this->plan($tenantId)?->maxUnits();
        if ($limit !== null && $this->usage->countUnits($tenantId) >= $limit) {
            throw QuotaExceededException::units($limit);
        }
    }

    private function plan(int $tenantId): ?PlanEntitlements
    {
        $subscription = $this->subscriptions->findByTenantId($tenantId);

        return $subscription === null ? null : PlanEntitlements::forPlan($subscription->planId());
    }
}
