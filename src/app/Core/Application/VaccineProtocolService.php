<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\VaccineProtocolRepositoryInterface;
use CentralVet\Domain\VaccineProtocol;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Use cases for the VaccineProtocol aggregate: registering/removing one
 * dose-number/interval entry of a vaccine catalog item's schedule, and
 * reading a catalog item's full schedule back for VaccineProtocolForm and
 * (indirectly, via VaccineProtocolRepositoryInterface::listByVaccineCatalogItem())
 * VaccinationService::apply()'s next_dose_at lookup.
 *
 * Added alongside T-08 (Presentation layer for the Vaccine screens): the
 * VaccineProtocol Domain entity and VaccineProtocolRepository/
 * VaccineProtocolRepositoryInterface already existed, but no Application
 * service wrapped them, unlike every other aggregate in this phase
 * (Prescription, ExamCatalog, ExamRequest, ExamResult, VaccineCatalog,
 * Vaccination). Without this class, VaccineProtocolForm would have had to
 * reach into Persistence/Domain directly, which the phase's own convention
 * forbids from a controller — this fills that gap with the same thin shape
 * used by VaccineCatalogService.
 *
 * Depends only on Domain contracts and TenantContext — no TPage or any
 * other Adianti class (ADR 0001).
 *
 * No update() is exposed: VaccineProtocolRepository::save() only rewrites
 * interval_days_from_previous for an existing row, but VaccineProtocol has
 * no mutator for that property (it is immutable by design, per its own
 * docblock) — a schedule entry is created or removed, never edited in
 * place, mirroring VaccineCatalogService's own minimal surface.
 */
final class VaccineProtocolService
{
    public function __construct(
        private readonly VaccineProtocolRepositoryInterface $protocols,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @param array{
     *     vaccine_catalog_item_id: int|string,
     *     dose_number: int|string,
     *     interval_days_from_previous?: int|string|null,
     * } $data
     */
    public function create(array $data): VaccineProtocol
    {
        foreach (['vaccine_catalog_item_id', 'dose_number'] as $required) {
            if (!array_key_exists($required, $data) || $data[$required] === '' || $data[$required] === null) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $intervalDaysFromPrevious = isset($data['interval_days_from_previous']) && $data['interval_days_from_previous'] !== ''
            ? (int) $data['interval_days_from_previous']
            : null;

        $protocol = VaccineProtocol::create(
            tenantId: $this->context->tenantId(),
            vaccineCatalogItemId: (int) $data['vaccine_catalog_item_id'],
            doseNumber: (int) $data['dose_number'],
            intervalDaysFromPrevious: $intervalDaysFromPrevious,
        );

        /** @var VaccineProtocol $saved */
        $saved = $this->protocols->save($protocol);

        return $saved;
    }

    /** @return list<VaccineProtocol> */
    public function listByVaccineCatalogItem(int $vaccineCatalogItemId): array
    {
        /** @var list<VaccineProtocol> $schedule */
        $schedule = $this->protocols->listByVaccineCatalogItem($vaccineCatalogItemId);

        return $schedule;
    }

    public function findById(int $id): ?VaccineProtocol
    {
        /** @var VaccineProtocol|null $protocol */
        $protocol = $this->protocols->findById($id);

        return $protocol;
    }

    /**
     * Removes one dose-schedule entry. A no-op (not an error) when the id
     * does not resolve within the authenticated tenant, mirroring the
     * "findById() returns null across tenants" convention used elsewhere in
     * this phase (e.g. VaccinationService::apply()).
     */
    public function remove(int $id): void
    {
        $protocol = $this->findById($id);

        if ($protocol === null) {
            return;
        }

        $this->protocols->remove($protocol);
    }
}
