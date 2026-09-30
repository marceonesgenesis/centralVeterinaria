<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\EncounterAccountItemRepositoryInterface;
use CentralVet\Domain\Contract\EncounterAccountRepositoryInterface;
use CentralVet\Domain\Contract\ExamCatalogRepositoryInterface;
use CentralVet\Domain\Contract\ExamRequestRepositoryInterface;
use CentralVet\Domain\Contract\PatientRepositoryInterface;
use CentralVet\Domain\Contract\ProcedureExecutionRepositoryInterface;
use CentralVet\Domain\Contract\ReceivableRepositoryInterface;
use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Contract\TenantUserDirectoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\EncounterAccount;
use CentralVet\Domain\EncounterAccountItem;
use CentralVet\Domain\ExamCatalogItem;
use CentralVet\Domain\ExamRequest;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Patient;
use CentralVet\Domain\ProcedureCatalogItem;
use CentralVet\Domain\ProcedureExecution;
use CentralVet\Domain\Receivable;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for the EncounterAccount aggregate (T-03): opening/reading the
 * running bill for a clinical encounter, syncing it against the
 * procedure/exam activity already recorded for that encounter, adding
 * manual lines, applying an authorized discount, and closing it into a
 * {@see Receivable} — the central piece of Phase 5's financial core.
 *
 * Depends only on Domain contracts, one sibling Application service
 * (`CentralVet\Application\ProcedureCatalogService`, for procedure catalog
 * pricing — the same "one Application service depending on another"
 * pattern `SaleService`/`ProcedureExecutionService` already establish) and
 * `TenantContext` — no TPage or any other Adianti class (ADR 0001).
 *
 * Dependency note, mirroring `ProcedureExecutionService`'s own docblock:
 * this class also consumes `EncounterRepositoryInterface` (to read back an
 * encounter's real patient_id/system_unit_id for openOrGet()),
 * `PatientRepositoryInterface` (to read back a patient's real tutor_id, the
 * same kind of cross-aggregate lookup), `ProcedureExecutionRepositoryInterface`
 * and `ExamRequestRepositoryInterface` (to list an encounter's billable
 * activity for syncAutomaticItems()), and `ExamCatalogRepositoryInterface`
 * directly (`ExamCatalogService` exposes no findById(), unlike
 * `ProcedureCatalogService::findById()`, so the catalog contract is used
 * directly here for exam pricing) — none of these are redefined, all are
 * existing Phase 1-4 contracts, consumed exactly like
 * `ProcedureExecutionService` already consumes `EncounterRepositoryInterface`
 * for the same "read a real cross-aggregate value before authorizing"
 * reason.
 *
 * --- subtotal/total bookkeeping (the T-01 migration's own documented risk) ---
 * Nothing in the schema keeps `encounter_account.total_cents` in sync with
 * the sum of `encounter_account_item.amount_cents` — the migration
 * explicitly assigns that responsibility to this service. Every method that
 * adds an item (`syncAutomaticItems()`, `addManualItem()`) recomputes the
 * account's subtotal from the live item sum and re-saves the account
 * immediately afterwards; `applyDiscount()` and `close()` do the same
 * recompute right before they touch discount_cents/status, so
 * `EncounterAccount::refreshSubtotal()`'s CHECK-mirroring invariants
 * (`discount_cents <= subtotal_cents`, `total_cents = subtotal_cents -
 * discount_cents`) always hold for what actually gets persisted.
 *
 * --- discount authorization (T-03's "autorização própria e distinta") ---
 * applyDiscount() takes its own `$action`, supplied by the caller (the
 * Presentation-layer controller) exactly like every other action string in
 * this class — but because it is registered as its own RBAC permission
 * (T-12, not this task), passing a distinct action string here (e.g.
 * `'EncounterAccountService::applyDiscount'`, never reused for
 * openOrGet()/syncAutomaticItems()/addManualItem()/close()) is what makes
 * discounting a permission an operator can be granted independently of
 * ordinary account access.
 */
final class EncounterAccountService
{
    public function __construct(
        private readonly EncounterAccountRepositoryInterface $accounts,
        private readonly EncounterAccountItemRepositoryInterface $items,
        private readonly ReceivableRepositoryInterface $receivables,
        private readonly EncounterRepositoryInterface $encounters,
        private readonly PatientRepositoryInterface $patients,
        private readonly ProcedureExecutionRepositoryInterface $procedureExecutions,
        private readonly ExamRequestRepositoryInterface $examRequests,
        private readonly ProcedureCatalogService $procedureCatalog,
        private readonly ExamCatalogRepositoryInterface $examCatalog,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        private readonly TenantUserDirectoryInterface $tenantUsers,
    ) {
    }

    /**
     * Opens the account for an encounter, or returns the existing one —
     * idempotent by design (UNIQUE encounter_id at the schema level backs
     * this up). Authorization always runs against the origin encounter's
     * own system_unit_id, read back from the persisted Encounter, never
     * from caller input — same indirection ProcedureExecutionService::
     * execute() uses, since an account only exists in the context of an
     * already-started encounter.
     *
     * @throws CrossTenantReferenceException when encounter_id (or its
     *         patient_id) does not resolve within the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match the origin
     *         encounter's own system_unit_id, or the caller lacks
     *         permission for $action. Nothing is persisted when this is
     *         thrown.
     */
    public function openOrGet(int $encounterId, string $action): EncounterAccount
    {
        if ($encounterId <= 0) {
            throw new InvalidArgumentException('encounter_id must be positive');
        }

        $encounter = $this->encounters->findById($encounterId);

        if (!$encounter instanceof Encounter) {
            throw new CrossTenantReferenceException(
                "encounter_id {$encounterId} was not found for the authenticated tenant"
            );
        }

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $encounter->systemUnitId(),
            entityType: 'encounter_account',
            entityId: null,
        ))->assertAllowed();

        $existing = $this->accounts->findByEncounterId($encounterId);

        if ($existing instanceof EncounterAccount) {
            return $existing;
        }

        $patient = $this->patients->findById($encounter->patientId());

        if (!$patient instanceof Patient) {
            throw new CrossTenantReferenceException(
                "patient_id {$encounter->patientId()} was not found for the authenticated tenant"
            );
        }

        $account = EncounterAccount::open(
            tenantId: $this->context->tenantId(),
            encounterId: $encounterId,
            patientId: $encounter->patientId(),
            tutorId: $patient->tutorId,
            systemUnitId: $encounter->systemUnitId(),
        );

        /** @var EncounterAccount $saved */
        $saved = $this->accounts->save($account);

        return $saved;
    }

    /**
     * Adds one `encounter_account_item` for every `procedure_execution`/
     * `exam_request` of the account's own encounter not already present —
     * matched by source_type+source_id against the account's existing
     * items, exactly matching the UNIQUE key the T-01 migration declares.
     * Idempotent: a second call with nothing new to add returns an empty
     * list and re-saves nothing (T-03's acceptance criterion).
     *
     * @return list<EncounterAccountItem> only the items added by this call.
     *
     * @throws CrossTenantReferenceException when the account, or a catalog
     *         entry one of its source rows references, does not resolve
     *         within the authenticated tenant.
     * @throws InvalidStatusTransitionException when the account is not
     *         currently 'open'.
     */
    public function syncAutomaticItems(int $accountId, string $action): array
    {
        $account = $this->requireAccount($accountId, $action);
        $this->assertAccountOpen($account);

        $existingKeys = [];

        foreach ($this->items->listByAccount($accountId) as $item) {
            /** @var EncounterAccountItem $item */
            $existingKeys[$item->sourceType() . ':' . ($item->sourceId() ?? '')] = true;
        }

        $newItems = [];

        foreach ($this->procedureExecutions->listByEncounter($account->encounterId()) as $execution) {
            /** @var ProcedureExecution $execution */
            $key = EncounterAccountItem::TYPE_PROCEDURE_EXECUTION . ':' . $execution->id();

            if (isset($existingKeys[$key])) {
                continue;
            }

            $catalogItem = $this->procedureCatalog->findById($execution->procedureCatalogItemId());

            if (!$catalogItem instanceof ProcedureCatalogItem) {
                throw new CrossTenantReferenceException(
                    "procedure_catalog_item_id {$execution->procedureCatalogItemId()} referenced by "
                    . "procedure_execution {$execution->id()} was not found for the authenticated tenant"
                );
            }

            $newItem = EncounterAccountItem::create(
                tenantId: $this->context->tenantId(),
                accountId: $accountId,
                sourceType: EncounterAccountItem::TYPE_PROCEDURE_EXECUTION,
                sourceId: $execution->id(),
                descriptionText: $catalogItem->name(),
                amountCents: $catalogItem->priceCents(),
            );

            /** @var EncounterAccountItem $savedItem */
            $savedItem = $this->items->save($newItem);
            $newItems[] = $savedItem;
            $existingKeys[$key] = true;
        }

        foreach ($this->examRequests->listByEncounter($account->encounterId()) as $examRequest) {
            /** @var ExamRequest $examRequest */
            $key = EncounterAccountItem::TYPE_EXAM_REQUEST . ':' . $examRequest->id();

            if (isset($existingKeys[$key])) {
                continue;
            }

            /** @var ExamCatalogItem|null $catalogItem */
            $catalogItem = $this->examCatalog->findById($examRequest->examCatalogItemId());

            if (!$catalogItem instanceof ExamCatalogItem) {
                throw new CrossTenantReferenceException(
                    "exam_catalog_item_id {$examRequest->examCatalogItemId()} referenced by "
                    . "exam_request {$examRequest->id()} was not found for the authenticated tenant"
                );
            }

            $newItem = EncounterAccountItem::create(
                tenantId: $this->context->tenantId(),
                accountId: $accountId,
                sourceType: EncounterAccountItem::TYPE_EXAM_REQUEST,
                sourceId: $examRequest->id(),
                descriptionText: $catalogItem->name(),
                amountCents: $catalogItem->priceCents(),
            );

            /** @var EncounterAccountItem $savedItem */
            $savedItem = $this->items->save($newItem);
            $newItems[] = $savedItem;
            $existingKeys[$key] = true;
        }

        if ($newItems !== []) {
            $this->refreshAccountTotals($account);
        }

        return $newItems;
    }

    /**
     * Adds one free-form `manual` item (source_type='manual', source_id
     * always null) to an open account.
     *
     * @throws CrossTenantReferenceException when the account does not
     *         resolve within the authenticated tenant.
     * @throws InvalidStatusTransitionException when the account is not
     *         currently 'open'.
     */
    public function addManualItem(
        int $accountId,
        string $descriptionText,
        int $amountCents,
        string $action,
    ): EncounterAccountItem {
        $account = $this->requireAccount($accountId, $action);
        $this->assertAccountOpen($account);

        $item = EncounterAccountItem::create(
            tenantId: $this->context->tenantId(),
            accountId: $accountId,
            sourceType: EncounterAccountItem::TYPE_MANUAL,
            sourceId: null,
            descriptionText: $descriptionText,
            amountCents: $amountCents,
        );

        /** @var EncounterAccountItem $savedItem */
        $savedItem = $this->items->save($item);

        $this->refreshAccountTotals($account);

        return $savedItem;
    }

    /**
     * Applies (or replaces) the account's authorized discount. Uses its own
     * distinct `$action` (see class docblock). Recomputes subtotal_cents
     * from the live item sum before validating, so "exceeds the account's
     * items" is always checked against the true current total — nothing is
     * persisted when it is exceeded, because the mutation happens on the
     * in-memory `$account` object and `DiscountExceedsSubtotalException` is
     * thrown by `EncounterAccount::applyDiscount()` itself, before this
     * method ever calls `EncounterAccountRepositoryInterface::save()`.
     *
     * @throws CrossTenantReferenceException when the account does not
     *         resolve within the authenticated tenant, or when the
     *         authorizer (authorized_by_system_user_id) is not an active
     *         user of the authenticated tenant.
     * @throws InvalidArgumentException when the discount is invalid
     *         (negative discount_cents, non-positive account_id or
     *         authorized_by_system_user_id).
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     *         when the policy denies $action for the account's unit.
     * @throws InvalidStatusTransitionException when the account is not
     *         currently 'open'.
     * @throws \CentralVet\Domain\Exception\DiscountExceedsSubtotalException
     *         when $discountCents exceeds the sum of the account's items.
     *         Neither discount_cents nor total_cents is persisted when this
     *         is thrown.
     */
    public function applyDiscount(
        int $accountId,
        int $discountCents,
        int $authorizedBySystemUserId,
        string $action,
    ): EncounterAccount {
        // T-25: the authorizer comes from caller input (the form combo can be
        // bypassed by a tampered POST), so it must resolve to an active user
        // of the authenticated tenant before anything is loaded or saved.
        // Ids <= 0 fall through to the domain's InvalidArgumentException.
        if ($authorizedBySystemUserId > 0 && !$this->tenantUsers->isActiveMember($authorizedBySystemUserId)) {
            throw new CrossTenantReferenceException(
                "authorized_by_system_user_id {$authorizedBySystemUserId} was not found for the authenticated tenant"
            );
        }

        $account = $this->requireAccount($accountId, $action);

        $itemsSumCents = $this->sumItemsCents($accountId);
        $account->refreshSubtotal($itemsSumCents);
        $account->applyDiscount($discountCents, $authorizedBySystemUserId);

        /** @var EncounterAccount $saved */
        $saved = $this->accounts->save($account);

        return $saved;
    }

    /**
     * Closes the account: recalculates subtotal from the live item sum,
     * applies the discount already registered on the account (unchanged if
     * applyDiscount() was never called — defaults to zero), grabs
     * total_cents, moves status to 'closed', and creates the corresponding
     * {@see Receivable} with the exact same total (T-03's acceptance
     * criterion: never recomputed differently).
     *
     * @throws CrossTenantReferenceException when the account does not
     *         resolve within the authenticated tenant.
     * @throws InvalidStatusTransitionException when the account is not
     *         currently 'open'.
     */
    public function close(int $accountId, string $action): Receivable
    {
        $account = $this->requireAccount($accountId, $action);

        $itemsSumCents = $this->sumItemsCents($accountId);
        $account->refreshSubtotal($itemsSumCents);
        $account->close(new DateTimeImmutable());

        /** @var EncounterAccount $savedAccount */
        $savedAccount = $this->accounts->save($account);

        $receivable = Receivable::open(
            tenantId: $this->context->tenantId(),
            encounterAccountId: (int) $savedAccount->id(),
            tutorId: $savedAccount->tutorId(),
            totalCents: $savedAccount->totalCents(),
        );

        /** @var Receivable $savedReceivable */
        $savedReceivable = $this->receivables->save($receivable);

        return $savedReceivable;
    }

    /**
     * Loads an account by id and authorizes the caller against its own
     * system_unit_id (never a unit supplied by the caller), shared by
     * every method above that operates on an existing account.
     *
     * @throws CrossTenantReferenceException when account_id does not
     *         resolve within the authenticated tenant.
     */
    private function requireAccount(int $accountId, string $action): EncounterAccount
    {
        if ($accountId <= 0) {
            throw new InvalidArgumentException('account_id must be positive');
        }

        $account = $this->accounts->findById($accountId);

        if (!$account instanceof EncounterAccount) {
            throw new CrossTenantReferenceException(
                "account_id {$accountId} was not found for the authenticated tenant"
            );
        }

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $account->systemUnitId(),
            entityType: 'encounter_account',
            entityId: $accountId,
        ))->assertAllowed();

        return $account;
    }

    /** @throws InvalidStatusTransitionException when the account is not currently 'open'. */
    private function assertAccountOpen(EncounterAccount $account): void
    {
        if ($account->status() !== EncounterAccount::STATUS_OPEN) {
            throw new InvalidStatusTransitionException(
                sprintf(
                    'Encounter account %d cannot be modified: status is "%s", not "open"',
                    $account->id() ?? 0,
                    $account->status(),
                )
            );
        }
    }

    /** Sums amount_cents across every item currently registered for an account. */
    private function sumItemsCents(int $accountId): int
    {
        $sum = 0;

        foreach ($this->items->listByAccount($accountId) as $item) {
            /** @var EncounterAccountItem $item */
            $sum += $item->amountCents();
        }

        return $sum;
    }

    /** Recomputes and persists subtotal_cents/total_cents from the live item sum. */
    private function refreshAccountTotals(EncounterAccount $account): void
    {
        $account->refreshSubtotal($this->sumItemsCents((int) $account->id()));
        $this->accounts->save($account);
    }
}
