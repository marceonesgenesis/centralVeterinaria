<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\PayableRepositoryInterface;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Payable;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for the Payable aggregate (T-05): registering a bill owed by
 * the tenant at a system unit, and settling it.
 *
 * pay() calls CentralVet\Application\FinancialEntryService::record()
 * internally (entry_type='expense', reference_type='payable') after
 * marking the payable paid and saving it, reusing the same $action the
 * caller authorized this call with. This project has no cross-service
 * database transaction primitive (documented limitation, see
 * CentralVet\Application\ProcedureExecutionService/SaleService from Phase
 * 4): if FinancialEntryService::record()'s own authorization or validation
 * were to fail after the Payable has already been saved as 'paid', the
 * financial_entry would be missing for an already-settled payable. This is
 * an accepted, documented gap — not silently ignored — mirroring the same
 * project-wide limitation those two Phase 4 services already carry; a real
 * fix requires a real database transaction wrapping both writes, which
 * does not exist yet.
 *
 * create() authorizes before writing even though it looks like a "simple"
 * insert — the same discipline CentralVet\Application\StockService::
 * receiveBatch()/CentralVet\Application\CashSessionService::open() (T-04)
 * follow, per this phase's own lesson from the Phase 4 final review.
 *
 * $tenantId is never accepted as a parameter (not part of this task's
 * Interface spec, and PayableRepositoryInterface takes none either, per
 * ADR 0002) — the tenant is always resolved from the injected
 * TenantContext.
 */
final class PayableService
{
    public function __construct(
        private readonly PayableRepositoryInterface $payables,
        private readonly FinancialEntryService $financialEntries,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    public function create(
        int $systemUnitId,
        string $descriptionText,
        string $category,
        int $amountCents,
        ?string $dueDate,
        int $systemUserId,
        string $action,
    ): Payable {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $systemUnitId,
            entityType: 'payable',
            entityId: null,
        ))->assertAllowed();

        $payable = Payable::create(
            tenantId: $this->context->tenantId(),
            systemUnitId: $systemUnitId,
            descriptionText: $descriptionText,
            category: $category,
            amountCents: $amountCents,
            dueDate: $dueDate !== null && $dueDate !== '' ? new DateTimeImmutable($dueDate) : null,
            systemUserId: $systemUserId,
        );

        /** @var Payable $saved */
        $saved = $this->payables->save($payable);

        return $saved;
    }

    /**
     * Settles a payable. Loads the real Payable first (repository reads
     * are already tenant-scoped, so a cross-tenant id resolves to null
     * below, same convention as VaccineProtocolService::findById()),
     * authorizes against ITS real system_unit_id — never a caller-supplied
     * one, same pattern T-06's PaymentService::register() spec uses for
     * cash_session's unit — then only mutates when status='open'.
     *
     * @throws InvalidStatusTransitionException when the payable is not
     *         'open' (already paid or cancelled). Nothing is written:
     *         Payable::markPaid() itself throws before any field changes,
     *         so this method never reaches ->save() or
     *         FinancialEntryService::record() in that case.
     */
    public function pay(int $payableId, int $systemUserId, string $action): Payable
    {
        /** @var Payable|null $payable */
        $payable = $this->payables->findById($payableId);

        if ($payable === null) {
            throw new InvalidArgumentException("Payable {$payableId} not found");
        }

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $payable->systemUnitId(),
            entityType: 'payable',
            entityId: $payableId,
        ))->assertAllowed();

        $payable->markPaid(new DateTimeImmutable());

        /** @var Payable $saved */
        $saved = $this->payables->save($payable);

        $this->financialEntries->record(
            systemUnitId: $saved->systemUnitId(),
            entryType: 'expense',
            category: $saved->category(),
            amountCents: $saved->amountCents(),
            referenceType: 'payable',
            referenceId: $payableId,
            systemUserId: $systemUserId,
            action: $action,
        );

        return $saved;
    }

    /** @return list<Payable> */
    public function listOpen(int $systemUnitId): array
    {
        /** @var list<Payable> $payables */
        $payables = $this->payables->listOpenBySystemUnit($systemUnitId);

        return $payables;
    }
}
