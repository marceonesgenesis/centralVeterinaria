<?php

declare(strict_types=1);

namespace CentralVet\Billing\Contract;

use CentralVet\Billing\Subscription;
use DateTimeImmutable;

/**
 * Persistência de `subscription` (T-02). Tabela da plataforma, fora do
 * escopo por tenant: o isolamento vem de cada consulta filtrar pela chave
 * exata (tenant, pedido, id do Stripe), nunca por entrada livre.
 * Os métodos *ForUpdate exigem transação aberta (LogicException).
 */
interface SubscriptionRepositoryInterface
{
    /** Grava a assinatura nova e devolve o id (a entidade não muda; use withId). */
    public function insert(Subscription $subscription): int;

    public function save(Subscription $subscription): void;

    public function findByTenantId(int $tenantId): ?Subscription;

    public function findByBillingOrderId(int $billingOrderId): ?Subscription;

    public function findByStripeSubscriptionIdForUpdate(string $stripeSubscriptionId): ?Subscription;

    public function findByIdForUpdate(int $id): ?Subscription;

    /** @return list<int> ids com status `past_due` e `grace_until <= now`, id crescente */
    public function listGraceExpired(DateTimeImmutable $now, int $limit): array;

    /**
     * @return list<int> ids Pix em `active`/`past_due` com `current_period_end <= until`,
     *                   sem cancelamento agendado (`cancel_at_period_end = 0`)
     *                   e nenhuma fatura com `period_start >= current_period_end`
     */
    public function listPixDueForIssue(DateTimeImmutable $until, int $limit): array;

    /**
     * @return list<int> ids Pix com `cancel_at_period_end = 1`, `current_period_end <= now`
     *                   e status diferente de `cancelled`, id crescente (T-08)
     */
    public function listScheduledCancellationsDue(DateTimeImmutable $now, int $limit): array;
}
