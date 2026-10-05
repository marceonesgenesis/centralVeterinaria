<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Bed;
use CentralVet\Domain\Contract\BedRepositoryInterface;
use CentralVet\Domain\Contract\EncounterAccountRepositoryInterface;
use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationEventRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationRepositoryInterface;
use CentralVet\Domain\Contract\TenantUserDirectoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\EncounterAccount;
use CentralVet\Domain\Exception\BedUnavailableException;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Exception\PatientAlreadyHospitalizedException;
use CentralVet\Domain\Hospitalization;
use CentralVet\Domain\HospitalizationEvent;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases of a hospitalization (T-09): admission from an encounter,
 * bed transfer, clinical evolution, vital signs and unit-scoped reads.
 *
 * Bed occupancy is never decided here by reading the bed status alone: the
 * final word is the repository's conditional `occupy()` (rowCount === 1).
 * The early status check only avoids saving a hospitalization that is bound
 * to fail; when occupy() loses the race, BedUnavailableException is thrown
 * and the caller's single TTransaction rolls the saved row back (this
 * service opens no transaction, like every other Application service).
 *
 * Depends only on Domain contracts and TenantContext (ADR 0001).
 */
final class HospitalizationService
{
    private readonly Closure $clock;

    public function __construct(
        private readonly HospitalizationRepositoryInterface $hospitalizations,
        private readonly BedRepositoryInterface $beds,
        private readonly HospitalizationEventRepositoryInterface $events,
        private readonly EncounterRepositoryInterface $encounters,
        private readonly EncounterAccountRepositoryInterface $accounts,
        private readonly TenantUserDirectoryInterface $users,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Admits the encounter's patient into a bed of the encounter's unit.
     *
     * @throws CrossTenantReferenceException encounter not found for this tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     * @throws BedUnavailableException bed missing, inactive, occupied, from another unit, or occupy() lost the race.
     * @throws InvalidArgumentException responsible user is not an active member, or bad expected date.
     * @throws PatientAlreadyHospitalizedException
     * @throws InvalidStatusTransitionException the encounter account exists and is not open.
     */
    public function admit(
        int $encounterId,
        int $bedId,
        int $responsibleSystemUserId,
        string $reasonText,
        ?string $expectedDischargeDate,
        string $action,
    ): Hospitalization {
        $expectedDate = $this->parseDate($expectedDischargeDate);

        $encounter = $encounterId > 0 ? $this->encounters->findById($encounterId) : null;

        if (!$encounter instanceof Encounter) {
            throw new CrossTenantReferenceException(
                "encounter_id {$encounterId} was not found for the authenticated tenant"
            );
        }

        $unitId = $encounter->systemUnitId();
        $this->authorize($action, $unitId, null);

        $bed = $this->requireBedOfUnit($bedId, $unitId);

        if ($responsibleSystemUserId <= 0 || !$this->users->isActiveMember($responsibleSystemUserId)) {
            throw new InvalidArgumentException('responsible_system_user_id must be an active user of this tenant');
        }

        if ($this->hospitalizations->findActiveByPatient($encounter->patientId()) !== null) {
            throw PatientAlreadyHospitalizedException::forPatient($encounter->patientId());
        }

        $account = $this->accounts->findByEncounterId($encounterId);

        if ($account instanceof EncounterAccount && $account->status() !== EncounterAccount::STATUS_OPEN) {
            throw new InvalidStatusTransitionException(sprintf(
                'Encounter account %d cannot be modified: status is "%s", not "open"',
                $account->id() ?? 0,
                $account->status(),
            ));
        }

        $now = $this->now();

        $hospitalization = Hospitalization::admit(
            tenantId: $this->context->tenantId(),
            systemUnitId: $unitId,
            patientId: $encounter->patientId(),
            encounterId: $encounterId,
            bedId: (int) $bed->id(),
            responsibleSystemUserId: $responsibleSystemUserId,
            admittedBySystemUserId: $this->context->userId(),
            reasonText: $reasonText,
            expectedDischargeDate: $expectedDate,
            dailyRateCents: $bed->dailyRateCents(),
            admittedAt: $now,
        );

        /** @var Hospitalization $hospitalization */
        $hospitalization = $this->hospitalizations->save($hospitalization);

        if (!$this->beds->occupy((int) $bed->id(), (int) $hospitalization->id())) {
            throw BedUnavailableException::forBed((int) $bed->id());
        }

        $this->events->save(HospitalizationEvent::record(
            tenantId: $this->context->tenantId(),
            hospitalizationId: (int) $hospitalization->id(),
            eventType: HospitalizationEvent::TYPE_ADMISSION,
            recordedBySystemUserId: $this->context->userId(),
            recordedAt: $now,
            notesText: $hospitalization->reasonText(),
            toBedId: (int) $bed->id(),
        ));

        return $hospitalization;
    }

    /**
     * Moves an admitted hospitalization to another available bed of the same
     * unit: occupies the new bed first (atomic), then releases the old one.
     *
     * @throws BedUnavailableException target bed missing, inactive, occupied or from another unit.
     */
    public function transfer(int $hospitalizationId, int $toBedId, string $action): Hospitalization
    {
        $hospitalization = $this->requireHospitalization($hospitalizationId);
        $this->authorize($action, $hospitalization->systemUnitId(), $hospitalizationId);
        $this->assertAdmitted($hospitalization);

        $fromBedId = $hospitalization->bedId();

        if ($toBedId === $fromBedId) {
            throw new InvalidArgumentException('to_bed_id must differ from from_bed_id');
        }

        $this->requireBedOfUnit($toBedId, $hospitalization->systemUnitId());

        if (!$this->beds->occupy($toBedId, $hospitalizationId)) {
            throw BedUnavailableException::forBed($toBedId);
        }

        $this->beds->release($fromBedId, $hospitalizationId);
        $hospitalization->moveToBed($toBedId);

        /** @var Hospitalization $hospitalization */
        $hospitalization = $this->hospitalizations->save($hospitalization);

        $this->events->save(HospitalizationEvent::record(
            tenantId: $this->context->tenantId(),
            hospitalizationId: $hospitalizationId,
            eventType: HospitalizationEvent::TYPE_TRANSFER,
            recordedBySystemUserId: $this->context->userId(),
            recordedAt: $this->now(),
            notesText: '',
            fromBedId: $fromBedId,
            toBedId: $toBedId,
        ));

        return $hospitalization;
    }

    public function recordEvolution(int $hospitalizationId, string $notesText, string $action): HospitalizationEvent
    {
        $hospitalization = $this->requireAdmittedForWrite($hospitalizationId, $action);

        /** @var HospitalizationEvent $event */
        $event = $this->events->save(HospitalizationEvent::record(
            tenantId: $this->context->tenantId(),
            hospitalizationId: (int) $hospitalization->id(),
            eventType: HospitalizationEvent::TYPE_EVOLUTION,
            recordedBySystemUserId: $this->context->userId(),
            recordedAt: $this->now(),
            notesText: $notesText,
        ));

        return $event;
    }

    public function recordVitals(
        int $hospitalizationId,
        ?float $temperatureC,
        ?int $heartRateBpm,
        ?int $respiratoryRateRpm,
        ?float $weightKg,
        ?int $painScore,
        string $notesText,
        string $action,
    ): HospitalizationEvent {
        $hospitalization = $this->requireAdmittedForWrite($hospitalizationId, $action);

        /** @var HospitalizationEvent $event */
        $event = $this->events->save(HospitalizationEvent::record(
            tenantId: $this->context->tenantId(),
            hospitalizationId: (int) $hospitalization->id(),
            eventType: HospitalizationEvent::TYPE_VITALS,
            recordedBySystemUserId: $this->context->userId(),
            recordedAt: $this->now(),
            notesText: $notesText,
            temperatureC: $temperatureC,
            heartRateBpm: $heartRateBpm,
            respiratoryRateRpm: $respiratoryRateRpm,
            weightKg: $weightKg,
            painScore: $painScore,
        ));

        return $event;
    }

    /** @throws CrossTenantReferenceException `Hospitalization <id> not found for this tenant` */
    public function get(int $hospitalizationId, string $action): Hospitalization
    {
        $hospitalization = $this->requireHospitalization($hospitalizationId);
        $this->authorize($action, $hospitalization->systemUnitId(), $hospitalizationId);

        return $hospitalization;
    }

    /** @return list<HospitalizationEvent> most recent first */
    public function listEvents(int $hospitalizationId, string $action): array
    {
        $this->get($hospitalizationId, $action);

        /** @var list<HospitalizationEvent> $events */
        $events = $this->events->listByHospitalization($hospitalizationId);

        return $events;
    }

    /** @return list<Hospitalization> admitted hospitalizations of the active unit */
    public function listActiveForCurrentUnit(string $action): array
    {
        $unitId = $this->context->requireUnitId();
        $this->authorize($action, $unitId, null);

        /** @var list<Hospitalization> $list */
        $list = $this->hospitalizations->listActiveByUnit($unitId);

        return $list;
    }

    private function requireHospitalization(int $hospitalizationId): Hospitalization
    {
        $hospitalization = $hospitalizationId > 0 ? $this->hospitalizations->findById($hospitalizationId) : null;

        if (!$hospitalization instanceof Hospitalization) {
            throw new CrossTenantReferenceException("Hospitalization {$hospitalizationId} not found for this tenant");
        }

        return $hospitalization;
    }

    private function requireAdmittedForWrite(int $hospitalizationId, string $action): Hospitalization
    {
        $hospitalization = $this->requireHospitalization($hospitalizationId);
        $this->authorize($action, $hospitalization->systemUnitId(), $hospitalizationId);
        $this->assertAdmitted($hospitalization);

        return $hospitalization;
    }

    private function assertAdmitted(Hospitalization $hospitalization): void
    {
        if ($hospitalization->status() !== Hospitalization::STATUS_ADMITTED) {
            throw new InvalidStatusTransitionException("Hospitalization {$hospitalization->id()} is not admitted");
        }
    }

    /** Active, currently available bed of the given unit; anything else is "not available". */
    private function requireBedOfUnit(int $bedId, int $unitId): Bed
    {
        $bed = $bedId > 0 ? $this->beds->findById($bedId) : null;

        if (!$bed instanceof Bed || $bed->systemUnitId() !== $unitId || !$bed->isAvailable()) {
            throw BedUnavailableException::forBed($bedId);
        }

        return $bed;
    }

    private function authorize(string $action, int $unitId, ?int $hospitalizationId): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $unitId,
            entityType: 'hospitalization',
            entityId: $hospitalizationId,
        ))->assertAllowed();
    }

    private function parseDate(?string $date): ?DateTimeImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));

        if ($parsed === false || $parsed->format('Y-m-d') !== trim($date)) {
            throw new InvalidArgumentException('expected_discharge_date must be a Y-m-d date');
        }

        return $parsed;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
