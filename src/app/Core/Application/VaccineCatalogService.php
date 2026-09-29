<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\VaccineCatalogRepositoryInterface;
use CentralVet\Domain\VaccineCatalogItem;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Use cases for the VaccineCatalogItem aggregate (T-05): registering a
 * vaccine catalog entry (name, manufacturer, initial stock) and reading it
 * back for VaccinationForm/VaccinationService to reference.
 *
 * Depends only on Domain contracts and TenantContext — no TPage or any
 * other Adianti class (ADR 0001). Mirrors ExamCatalogService's shape (T-04,
 * same phase, parallel task): a plain CRUD-ish catalog service with no
 * authorization gate of its own — unlike VaccinationService::apply(),
 * registering/reading a catalog entry is not unit-scoped in this plan.
 */
final class VaccineCatalogService
{
    public function __construct(
        private readonly VaccineCatalogRepositoryInterface $catalog,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @param array{
     *     name: string,
     *     manufacturer?: string|null,
     *     stock_quantity?: int|string|null,
     * } $data
     */
    public function create(array $data): VaccineCatalogItem
    {
        if (!array_key_exists('name', $data) || trim((string) $data['name']) === '') {
            throw new InvalidArgumentException('name is required');
        }

        $name = (string) $data['name'];
        $manufacturer = isset($data['manufacturer']) && $data['manufacturer'] !== ''
            ? (string) $data['manufacturer']
            : null;
        $stockQuantity = isset($data['stock_quantity']) && $data['stock_quantity'] !== ''
            ? (int) $data['stock_quantity']
            : 0;

        $item = VaccineCatalogItem::create(
            tenantId: $this->context->tenantId(),
            name: $name,
            manufacturer: $manufacturer,
            stockQuantity: $stockQuantity,
        );

        /** @var VaccineCatalogItem $saved */
        $saved = $this->catalog->save($item);

        return $saved;
    }

    /** @return list<VaccineCatalogItem> */
    public function listActive(): array
    {
        /** @var list<VaccineCatalogItem> $items */
        $items = $this->catalog->listActive();

        return $items;
    }

    public function findById(int $id): ?VaccineCatalogItem
    {
        /** @var VaccineCatalogItem|null $item */
        $item = $this->catalog->findById($id);

        return $item;
    }
}
