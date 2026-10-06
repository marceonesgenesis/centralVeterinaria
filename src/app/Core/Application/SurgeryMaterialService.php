<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\ProductRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryEventRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryMaterialRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Product;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Domain\SurgeryMaterial;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;

/**
 * Use cases for the materials of a surgery (T-10): recording and removing
 * products used while the surgery is `in_progress`, each change with a
 * `material` event on the timeline.
 *
 * Every write first locks the surgery row ({@see SurgeryRepositoryInterface::lockStatus()})
 * and decides on that locked status, so a concurrent completion (which
 * consumes stock and bills the materials) wins: a material arriving after
 * it is refused instead of escaping the billing. Stock balance is not
 * checked here — the stock is consumed at completion (T-11).
 *
 * Authorization runs against the persisted surgery's own system_unit_id,
 * with entityType `surgery`. The service opens no transaction: the
 * Presentation controller wraps each call in its TTransaction, which is
 * what keeps the row lock until the write commits.
 */
final class SurgeryMaterialService
{
    private const ENTITY_TYPE = 'surgery';

    private readonly Closure $clock;

    public function __construct(
        private readonly SurgeryRepositoryInterface $surgeries,
        private readonly SurgeryMaterialRepositoryInterface $materials,
        private readonly SurgeryEventRepositoryInterface $events,
        private readonly ProductRepositoryInterface $products,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @throws CrossTenantReferenceException surgery or active product not
     *         found in the tenant.
     * @throws InvalidStatusTransitionException surgery not in progress.
     */
    public function addMaterial(int $surgeryId, int $productId, int $quantity, string $action): SurgeryMaterial
    {
        $this->lockedInProgressSurgery($surgeryId, $action);

        $product = $this->products->findById($productId);

        if (!$product instanceof Product || !$product->isActive()) {
            throw new CrossTenantReferenceException(
                "product_id {$productId} was not found for the authenticated tenant"
            );
        }

        $now = $this->now();
        $material = SurgeryMaterial::record(
            $this->context->tenantId(),
            $surgeryId,
            $productId,
            $quantity,
            $this->context->userId(),
            $now,
        );
        $this->materials->save($material);
        $this->recordEvent($surgeryId, $product->name() . ' × ' . $quantity, $now);

        return $material;
    }

    /**
     * @throws CrossTenantReferenceException material or surgery not found in
     *         the tenant.
     * @throws InvalidStatusTransitionException surgery not in progress.
     */
    public function removeMaterial(int $materialId, string $action): void
    {
        $material = $this->materials->findById($materialId);

        if (!$material instanceof SurgeryMaterial) {
            throw new CrossTenantReferenceException(
                "material_id {$materialId} was not found for the authenticated tenant"
            );
        }

        $surgeryId = $material->surgeryId();
        $this->lockedInProgressSurgery($surgeryId, $action);

        $this->materials->remove($material);
        $this->recordEvent(
            $surgeryId,
            'removido: ' . $this->productName($material->productId()) . ' × ' . $material->quantity(),
            $this->now(),
        );
    }

    /** @return list<array{material: SurgeryMaterial, product_name: string}> */
    public function listMaterials(int $surgeryId, string $action): array
    {
        $this->authorizedSurgery($surgeryId, $action);

        $rows = [];

        foreach ($this->materials->listBySurgery($surgeryId) as $material) {
            $rows[] = [
                'material' => $material,
                'product_name' => $this->productName($material->productId()),
            ];
        }

        return $rows;
    }

    /**
     * Active products of the tenant, for the material picker; authorized
     * against the active unit.
     *
     * @return list<Product>
     */
    public function listActiveProducts(string $action): array
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $this->context->requireUnitId(),
            entityType: self::ENTITY_TYPE,
            entityId: null,
        ))->assertAllowed();

        return array_values($this->products->findActive());
    }

    /**
     * Locks the surgery row, then loads and authorizes it, and only then
     * decides on the locked status.
     */
    private function lockedInProgressSurgery(int $surgeryId, string $action): Surgery
    {
        $lockedStatus = $this->surgeries->lockStatus($surgeryId);

        if ($lockedStatus === null) {
            throw new CrossTenantReferenceException(
                "surgery_id {$surgeryId} was not found for the authenticated tenant"
            );
        }

        $surgery = $this->authorizedSurgery($surgeryId, $action);

        if ($lockedStatus !== Surgery::STATUS_IN_PROGRESS) {
            throw new InvalidStatusTransitionException("Surgery {$surgeryId} is not in progress");
        }

        return $surgery;
    }

    private function authorizedSurgery(int $surgeryId, string $action): Surgery
    {
        $surgery = $this->surgeries->findById($surgeryId);

        if (!$surgery instanceof Surgery) {
            throw new CrossTenantReferenceException(
                "surgery_id {$surgeryId} was not found for the authenticated tenant"
            );
        }

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $surgery->systemUnitId(),
            entityType: self::ENTITY_TYPE,
            entityId: $surgeryId,
        ))->assertAllowed();

        return $surgery;
    }

    private function recordEvent(int $surgeryId, string $notes, DateTimeImmutable $at): void
    {
        $this->events->save(SurgeryEvent::record(
            $this->context->tenantId(),
            $surgeryId,
            SurgeryEvent::TYPE_MATERIAL,
            $this->context->userId(),
            $at,
            $notes,
        ));
    }

    private function productName(int $productId): string
    {
        $product = $this->products->findById($productId);

        return $product instanceof Product ? $product->name() : "#{$productId}";
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
