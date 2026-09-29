<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\ProcedureCatalogItemInputRepositoryInterface;
use CentralVet\Domain\Contract\ProcedureCatalogRepositoryInterface;
use CentralVet\Domain\ProcedureCatalogItem;
use CentralVet\Domain\ProcedureCatalogItemInput;
use CentralVet\Tenancy\TenantContext;

/**
 * Use cases for the procedure catalog aggregate (T-04): registering a
 * procedure catalog item (name, price, optional duration/preparation note)
 * and its bill-of-materials inputs (the products it consumes on execution),
 * and reading both back for the T-08 screens and T-05's
 * ProcedureExecutionService::execute().
 *
 * Depends only on Domain contracts and TenantContext — no TPage or any
 * other Adianti class (ADR 0001). Mirrors VaccineCatalogService/
 * VaccineProtocolService's shape: tenant id is resolved from TenantContext
 * internally, never accepted as a caller-supplied parameter, matching how
 * this project's TenantRepositoryInterface implementations resolve tenant
 * scope (ADR 0002 — tenant scoping is never accepted from caller input).
 *
 * No update() is exposed for either aggregate, mirroring
 * VaccineProtocolService's minimal surface: a catalog item or one of its
 * inputs is created or removed, never edited in place, per this phase's plan.
 */
final class ProcedureCatalogService
{
    public function __construct(
        private readonly ProcedureCatalogRepositoryInterface $catalog,
        private readonly ProcedureCatalogItemInputRepositoryInterface $inputs,
        private readonly TenantContext $context,
    ) {
    }

    public function create(
        string $name,
        int $priceCents,
        ?int $durationMinutes,
        ?string $preparationText,
    ): ProcedureCatalogItem {
        $item = ProcedureCatalogItem::create(
            tenantId: $this->context->tenantId(),
            name: $name,
            priceCents: $priceCents,
            durationMinutes: $durationMinutes,
            preparationText: $preparationText,
        );

        /** @var ProcedureCatalogItem $saved */
        $saved = $this->catalog->save($item);

        return $saved;
    }

    /** @return list<ProcedureCatalogItem> */
    public function listActive(): array
    {
        /** @var list<ProcedureCatalogItem> $items */
        $items = $this->catalog->findActive();

        return $items;
    }

    public function findById(int $id): ?ProcedureCatalogItem
    {
        /** @var ProcedureCatalogItem|null $item */
        $item = $this->catalog->findById($id);

        return $item;
    }

    /**
     * Registers one product the procedure consumes on execution.
     * ProcedureCatalogItemInput::create() refuses (InvalidArgumentException,
     * a domain exception, not a `TMessage`) a $quantityPerExecution below 1
     * before this method ever reaches the repository, so an invalid
     * quantity is never persisted — the UI controller is responsible for
     * translating that exception into user feedback.
     */
    public function addInput(int $procedureCatalogItemId, int $productId, int $quantityPerExecution): ProcedureCatalogItemInput
    {
        $input = ProcedureCatalogItemInput::create(
            tenantId: $this->context->tenantId(),
            procedureCatalogItemId: $procedureCatalogItemId,
            productId: $productId,
            quantityPerExecution: $quantityPerExecution,
        );

        /** @var ProcedureCatalogItemInput $saved */
        $saved = $this->inputs->save($input);

        return $saved;
    }

    /**
     * Returns a procedure catalog item's inputs in the order they were
     * registered — see ProcedureCatalogItemInputRepository::
     * listByProcedureCatalogItem() for how that ordering is guaranteed.
     * Consumed by T-05's ProcedureExecutionService::execute() to drive one
     * StockService::consume() call per input.
     *
     * @return list<ProcedureCatalogItemInput>
     */
    public function listInputs(int $procedureCatalogItemId): array
    {
        /** @var list<ProcedureCatalogItemInput> $items */
        $items = $this->inputs->listByProcedureCatalogItem($procedureCatalogItemId);

        return $items;
    }
}
