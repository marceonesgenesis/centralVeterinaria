<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\FinancialEntryRepositoryInterface;
use CentralVet\Domain\FinancialEntry;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * Use case for the FinancialEntry aggregate (T-05): recording one ledger
 * row (income or expense) for a system unit. record() is called both
 * directly (a manual entry, from FinancialEntryForm in T-09) and
 * internally by other services in this phase whenever a write on their own
 * aggregate has a cash-flow side effect — CentralVet\Application\
 * PayableService::pay() (entry_type='expense', reference_type='payable')
 * and, in T-06, CentralVet\Application\PaymentService::register()
 * (entry_type='income', reference_type='payment').
 *
 * Unlike CentralVet\Application\StockService/VaccineProtocolService, this
 * task's own Interface spec (tasks.md T-05) has the caller pass the
 * authorization `action` string explicitly instead of hard-coding a single
 * literal per method: this lets a caller like PayableService::pay() reuse
 * its own already-decided action, and leaves room for a screen to register
 * different actions for a manual entry vs. an automatic one, without this
 * class having to know the difference.
 *
 * $tenantId is never accepted as a parameter (not part of this task's
 * Interface spec, and FinancialEntryRepositoryInterface takes none either,
 * per ADR 0002) — the tenant is always resolved from the injected
 * TenantContext.
 */
final class FinancialEntryService
{
    public function __construct(
        private readonly FinancialEntryRepositoryInterface $entries,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * Records one financial_entry row. Authorizes against $systemUnitId
     * (fail-closed, before any write — same discipline as
     * CentralVet\Application\StockService::receiveBatch(), a "simple"
     * write that still authorizes first) using the caller-supplied
     * $action. entry_type validity ('income'/'expense') is enforced by
     * CentralVet\Domain\FinancialEntry::record() itself.
     */
    public function record(
        int $systemUnitId,
        string $entryType,
        string $category,
        int $amountCents,
        ?string $referenceType,
        ?int $referenceId,
        int $systemUserId,
        string $action,
    ): FinancialEntry {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $systemUnitId,
            entityType: 'financial_entry',
            entityId: null,
        ))->assertAllowed();

        $entry = FinancialEntry::record(
            tenantId: $this->context->tenantId(),
            systemUnitId: $systemUnitId,
            entryType: $entryType,
            category: $category,
            amountCents: $amountCents,
            referenceType: $referenceType,
            referenceId: $referenceId,
            occurredAt: new DateTimeImmutable(),
            systemUserId: $systemUserId,
        );

        /** @var FinancialEntry $saved */
        $saved = $this->entries->save($entry);

        return $saved;
    }

    /**
     * Thin passthrough to FinancialEntryRepositoryInterface::
     * listBySystemUnitAndPeriod(), added for FinancialEntryList (T-09) so
     * that controller never has to reach into Persistence directly — same
     * "service passthrough" option this task's own Interface spec allows.
     * No authorization decision here, mirroring
     * CentralVet\Application\PayableService::listOpen()'s own read-only,
     * unauthenticated-read convention.
     *
     * @return list<FinancialEntry>
     */
    public function listByPeriod(int $systemUnitId, string $from, string $to): array
    {
        /** @var list<FinancialEntry> $entries */
        $entries = $this->entries->listBySystemUnitAndPeriod($systemUnitId, $from, $to);

        return $entries;
    }
}
