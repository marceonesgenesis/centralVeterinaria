<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\ExamCatalogRepositoryInterface;
use CentralVet\Domain\ExamCatalogItem;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Use cases for the ExamCatalogItem aggregate (T-04): maintaining the
 * tenant-scoped catalog of billable exams that {@see ExamService::requestExam()}
 * draws from.
 *
 * Depends only on Domain contracts and `TenantContext` — no TPage or any
 * other Adianti class (ADR 0001), so it can run from REST, workers or MCP
 * exactly like from the current Adianti presentation layer.
 *
 * Unlike ExamService's use cases, create()/listActive() are plain
 * tenant-scoped catalog maintenance with no per-unit resource (a catalog
 * item is not tied to any single `system_unit_id`), so neither method
 * performs unit-scope authorization — that check only makes sense once an
 * item is actually used against a patient inside a unit-scoped encounter,
 * which is exactly what ExamService::requestExam() does.
 */
final class ExamCatalogService
{
    public function __construct(
        private readonly ExamCatalogRepositoryInterface $catalog,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @param array{
     *     name: string,
     *     partner_name?: string|null,
     *     price_cents: int|string,
     * } $data
     */
    public function create(array $data): ExamCatalogItem
    {
        foreach (['name', 'price_cents'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $item = ExamCatalogItem::create(
            tenantId: $this->context->tenantId(),
            name: (string) $data['name'],
            partnerName: isset($data['partner_name']) && $data['partner_name'] !== ''
                ? (string) $data['partner_name']
                : null,
            priceCents: (int) $data['price_cents'],
        );

        /** @var ExamCatalogItem $saved */
        $saved = $this->catalog->save($item);

        return $saved;
    }

    /** @return list<ExamCatalogItem> */
    public function listActive(): array
    {
        /** @var list<ExamCatalogItem> $items */
        $items = $this->catalog->listActive();

        return $items;
    }
}
