<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Contract\ProcedureExecutionRepositoryInterface;
use CentralVet\Domain\Contract\TenantUserDirectoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InsufficientStockException;
use CentralVet\Domain\ProcedureExecution;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for the ProcedureExecution aggregate (T-05): executing a
 * procedure catalog item for a patient inside an encounter — the action
 * that both records the execution and drains the procedure's declared
 * inputs (its bill of materials, T-04) from stock — and reading an
 * encounter's execution history.
 *
 * Depends only on Domain contracts, `CentralVet\Application\
 * ProcedureCatalogService` and `CentralVet\Application\StockService`, and
 * `TenantContext` — no TPage or any other Adianti class (ADR 0001).
 *
 * Dependency note: this class consumes
 * `CentralVet\Application\ProcedureCatalogService::listInputs()` (T-04) and
 * `CentralVet\Application\StockService::consume()` (T-03) directly, the same
 * way `AppointmentService`/`QueueEntryService` already consume
 * `CentralVet\Application\PatientService` for a cross-aggregate lookup — one
 * Application service depending on another is an established pattern in
 * this codebase, not a new one introduced here. It also consumes
 * `CentralVet\Domain\Contract\EncounterRepositoryInterface` directly (an
 * existing Phase 2 contract, not redefined here) purely to read back the
 * origin encounter's own `system_unit_id`, exactly like
 * `VaccinationService::apply()` does for the same reason.
 *
 * Unit-scope authorization (same pattern as `VaccinationService::apply()`
 * and `EncounterService::finish()`): execute() asks the injected
 * AuthorizationPolicyInterface whether the caller's active unit
 * (TenantContext::unitId()) is allowed to act on the *origin encounter's own*
 * system_unit_id — never a unit supplied by the caller — since a procedure
 * execution only exists in the context of an already-open encounter. The
 * "ClassName::method" action string is supplied by the caller (the
 * Presentation-layer controller) through the $action parameter, matching
 * every other Application service with an authorization check in this
 * codebase (EncounterService::start()/finish(), VaccinationService::apply(),
 * AppointmentService::schedule(), ...); tasks.md's Interface line for
 * execute() does not spell this parameter out explicitly, but omitting it
 * would mean either hardcoding an action string here (violating ADR 0001,
 * which reserves Adianti-class-name literals for the Presentation layer) or
 * calling AuthorizationPolicyInterface without one, which no other service
 * in this codebase does.
 *
 * IMPORTANT LIMITATION — no multi-product atomic consumption: when a
 * procedure has more than one declared input, execute() calls
 * StockService::consume() once per input, in the order
 * ProcedureCatalogService::listInputs() returns them. Each individual
 * consume() call is atomic on its own (StockService::consume()'s own
 * all-or-nothing guarantee: it sums the available balance across every
 * batch BEFORE touching any of them, see its docblock). But there is no
 * multi-product atomic operation across *several* consume() calls, and this
 * project does not offer a cross-service database transaction primitive to
 * simulate one. So if input N (N > 1) throws InsufficientStockException,
 * inputs 1..N-1 have already been PHYSICALLY DECREMENTED from stock before N
 * was attempted — that partial consumption is NOT rolled back. This is an
 * accepted, documented exception to "tudo ou nada" (all-or-nothing): what IS
 * guaranteed, and what the acceptance criteria actually require, is that
 * `procedure_execution` itself is never written when any input's stock
 * consumption fails — the execution record, not the stock ledger, is what
 * stays all-or-nothing here.
 */
final class ProcedureExecutionService
{
    public function __construct(
        private readonly ProcedureExecutionRepositoryInterface $executions,
        private readonly EncounterRepositoryInterface $encounters,
        private readonly ProcedureCatalogService $catalog,
        private readonly StockService $stock,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        private readonly TenantUserDirectoryInterface $tenantUsers,
    ) {
    }

    /**
     * Executes a procedure catalog item for a patient inside an encounter.
     * Business rules, in the order they run:
     *   1. Unit-scope authorization against the origin encounter's own
     *      system_unit_id (read back from the persisted Encounter, never
     *      from caller input), checked BEFORE any write. A denial throws
     *      AuthorizationDenied with nothing persisted and no stock touched.
     *   2. Only after authorization passes: the procedure catalog item's
     *      declared inputs (its bill of materials) are loaded via
     *      ProcedureCatalogService::listInputs(), and StockService::consume()
     *      is called once per input, with reason='procedure_consumption'
     *      and referenceType='procedure_execution'. If any consume() call
     *      throws InsufficientStockException, it propagates unmodified and
     *      procedure_execution is NOT written — see this class's docblock
     *      for the documented limitation on inputs already consumed before
     *      the failing one.
     *   3. Only after every input's consumption succeeds is the
     *      ProcedureExecution itself built and persisted.
     *
     * @throws CrossTenantReferenceException when encounter_id does not
     *         resolve within the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match the origin
     *         encounter's own system_unit_id, or the caller lacks
     *         permission for $action. Nothing is persisted and no stock is
     *         consumed when this is thrown.
     * @throws InsufficientStockException when any declared input lacks
     *         enough stock. procedure_execution is not written when this is
     *         thrown; inputs already consumed before the failing one are
     *         not rolled back (see class docblock).
     */
    public function execute(
        int $encounterId,
        int $procedureCatalogItemId,
        int $professionalSystemUserId,
        ?string $notesText,
        string $action,
    ): ProcedureExecution {
        // Tenant-scoped, read-only lookup (ADR 0002): findById() returns
        // null both when the referenced row does not exist and when it
        // belongs to another tenant (mirrors VaccinationService::apply()'s
        // treatment of encounter_id). Safe to run before the authorization
        // check below since nothing is mutated here.
        $encounter = $this->encounters->findById($encounterId);

        if (!$encounter instanceof Encounter) {
            throw new CrossTenantReferenceException(
                "encounter_id {$encounterId} was not found for the authenticated tenant"
            );
        }

        $procedureCatalogItem = $this->catalog->findById($procedureCatalogItemId);

        if ($procedureCatalogItem === null) {
            throw new CrossTenantReferenceException(
                "procedure_catalog_item_id {$procedureCatalogItemId} was not found for the authenticated tenant"
            );
        }

        // final-fix: the professional comes from caller input, so it must
        // resolve within the authenticated tenant like any other reference
        // (nonexistent and other-tenant users are indistinguishable).
        if (!$this->tenantUsers->isActiveMember($professionalSystemUserId)) {
            throw new CrossTenantReferenceException(
                "professional_system_user_id {$professionalSystemUserId} was not found for the authenticated tenant"
            );
        }

        // Rule 1: unit-scope authorization against the origin encounter's
        // REAL unit, run after the cross-tenant reference checks above and
        // before any write. assertAllowed() throws AuthorizationDenied on
        // denial, left to propagate; nothing has been mutated or persisted
        // at this point, so stock is still untouched and procedure_execution
        // is not written.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $encounter->systemUnitId(),
            entityType: 'procedure_execution',
            entityId: null,
        ))->assertAllowed();

        $tenantId = $this->context->tenantId();
        $systemUnitId = $encounter->systemUnitId();
        $executedAt = new DateTimeImmutable();

        // Rule 2: consume every declared input, one StockService::consume()
        // call per input, in listInputs() order. InsufficientStockException
        // on any call propagates as-is and stops execute() here — see the
        // class docblock for why inputs already consumed before the failing
        // one are not (and cannot be) rolled back in this project.
        $inputs = $this->catalog->listInputs($procedureCatalogItemId);

        foreach ($inputs as $input) {
            // referenceId is null here, not the future procedure_execution
            // id: per Rule 3 below, the execution row is only written AFTER
            // every consume() call succeeds, so its id does not exist yet at
            // this point — there is no value to pass. referenceType alone
            // ('procedure_execution') is enough for stock_movement rows to
            // be traceable to this use case.
            $this->stock->consume(
                tenantId: $tenantId,
                systemUnitId: $systemUnitId,
                productId: $input->productId(),
                quantity: $input->quantityPerExecution(),
                reason: 'procedure_consumption',
                referenceType: 'procedure_execution',
                referenceId: null,
                professionalSystemUserId: $professionalSystemUserId,
            );
        }

        // Rule 3: only reached once every input above was consumed
        // successfully — procedure_execution is written last, never before
        // authorization passes and never before stock consumption succeeds.
        $execution = ProcedureExecution::record(
            tenantId: $tenantId,
            encounterId: $encounterId,
            patientId: $encounter->patientId(),
            procedureCatalogItemId: $procedureCatalogItemId,
            professionalSystemUserId: $professionalSystemUserId,
            notesText: $notesText,
            executedAt: $executedAt,
        );

        /** @var ProcedureExecution $saved */
        $saved = $this->executions->save($execution);

        return $saved;
    }

    /**
     * Lists procedure executions for an encounter, oldest first
     * (passthrough to ProcedureExecutionRepositoryInterface::
     * listByEncounter(), already tenant-scoped via
     * AbstractTenantRepository::tenantQuery(), ADR 0002).
     *
     * @return list<ProcedureExecution>
     */
    public function listByEncounter(int $encounterId): array
    {
        /** @var list<ProcedureExecution> $executions */
        $executions = $this->executions->listByEncounter($encounterId);

        return $executions;
    }
}
