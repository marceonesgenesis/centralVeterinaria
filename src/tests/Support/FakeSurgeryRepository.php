<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SurgeryRepositoryInterface;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Surgery;
use DateTimeImmutable;
use InvalidArgumentException;
use WeakMap;

/**
 * In-memory double for SurgeryRepositoryInterface (T-05). Stores rows (not
 * objects) and findById() returns a fresh Surgery::reconstitute() copy, like
 * the PDO repository, so loadedStatus() is the status persisted at load time.
 *
 * save() of an existing surgery mirrors the conditional UPDATE of T-06: it
 * only writes while the stored status equals the status the entity was
 * loaded with (loadedStatus(), or the status it was inserted with when the
 * same object was created in-process); otherwise it throws
 * `Surgery <id> changed status concurrently`. forceStatus() simulates
 * another tab changing the status in the database.
 */
final class FakeSurgeryRepository implements SurgeryRepositoryInterface
{
    private const BLOCKING_STATUSES = [
        Surgery::STATUS_SCHEDULED,
        Surgery::STATUS_PRE_OP,
        Surgery::STATUS_IN_PROGRESS,
    ];

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    /** @var WeakMap<Surgery, string> status an in-process (never reconstituted) entity was last saved with */
    private WeakMap $savedStatus;

    /** Number of save() calls after construction (seed not counted). */
    public int $saveCount = 0;

    public function __construct(private readonly int $tenantId, Surgery ...$seed)
    {
        $this->savedStatus = new WeakMap();

        foreach ($seed as $item) {
            $this->store($item);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $row = $this->ownRow((int) $id);

        return $row === null ? null : Surgery::reconstitute($row);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Surgery) {
            throw new InvalidArgumentException('FakeSurgeryRepository only stores Surgery entities');
        }

        if ($entity->id() !== null && isset($this->rows[$entity->id()])) {
            $expected = $entity->loadedStatus() ?? ($this->savedStatus[$entity] ?? null);

            if ($expected === null || $this->rows[$entity->id()]['status'] !== $expected) {
                throw new InvalidStatusTransitionException("Surgery {$entity->id()} changed status concurrently");
            }
        }

        $this->store($entity);
        $this->saveCount++;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Surgery && $entity->id() !== null) {
            unset($this->rows[$entity->id()]);
        }
    }

    /** Simulates another session changing the surgery status in the database. */
    public function forceStatus(int $surgeryId, string $status): void
    {
        if (!isset($this->rows[$surgeryId])) {
            throw new InvalidArgumentException("Surgery {$surgeryId} not found");
        }

        $this->rows[$surgeryId]['status'] = $status;
    }

    public function listByUnitAndDay(int $systemUnitId, DateTimeImmutable $day): array
    {
        $date = $day->format('Y-m-d');
        $surgeries = array_values(array_filter(
            $this->ownTenant(),
            static fn (Surgery $s): bool => $s->systemUnitId() === $systemUnitId
                && $s->scheduledStartAt()->format('Y-m-d') === $date,
        ));
        usort($surgeries, static fn (Surgery $a, Surgery $b): int => [$a->scheduledStartAt(), $a->id()] <=> [$b->scheduledStartAt(), $b->id()]);

        return $surgeries;
    }

    public function hasOverlapInRoom(
        int $roomId,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        ?int $exceptSurgeryId,
    ): bool {
        foreach ($this->ownTenant() as $surgery) {
            if (
                $surgery->roomId() === $roomId
                && $surgery->id() !== $exceptSurgeryId
                && in_array($surgery->status(), self::BLOCKING_STATUSES, true)
                && $surgery->scheduledStartAt() < $endAt
                && $surgery->scheduledEndAt() > $startAt
            ) {
                return true;
            }
        }

        return false;
    }

    public function lockStatus(int $surgeryId): ?string
    {
        return $this->ownRow($surgeryId)['status'] ?? null;
    }

    private function store(Surgery $entity): void
    {
        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $entity->id() + 1);
        }

        $this->rows[(int) $entity->id()] = self::toRow($entity);

        if ($entity->loadedStatus() === null) {
            $this->savedStatus[$entity] = $entity->status();
        }
    }

    /** @return array<string, mixed>|null */
    private function ownRow(int $id): ?array
    {
        $row = $this->rows[$id] ?? null;

        return $row !== null && $row['tenant_id'] === $this->tenantId ? $row : null;
    }

    /** @return list<Surgery> */
    private function ownTenant(): array
    {
        $surgeries = [];

        foreach (array_keys($this->rows) as $id) {
            $surgery = $this->findById($id);

            if ($surgery !== null) {
                $surgeries[] = $surgery;
            }
        }

        return $surgeries;
    }

    /** @return array<string, mixed> */
    private static function toRow(Surgery $s): array
    {
        $date = static fn (?DateTimeImmutable $d): ?string => $d?->format('Y-m-d H:i:s.u');

        return [
            'id' => $s->id(),
            'tenant_id' => $s->tenantId(),
            'system_unit_id' => $s->systemUnitId(),
            'patient_id' => $s->patientId(),
            'encounter_id' => $s->encounterId(),
            'room_id' => $s->roomId(),
            'procedure_catalog_item_id' => $s->procedureCatalogItemId(),
            'procedure_name' => $s->procedureName(),
            'procedure_price_cents' => $s->procedurePriceCents(),
            'surgeon_system_user_id' => $s->surgeonSystemUserId(),
            'scheduled_by_system_user_id' => $s->scheduledBySystemUserId(),
            'scheduled_start_at' => $date($s->scheduledStartAt()),
            'scheduled_end_at' => $date($s->scheduledEndAt()),
            'status' => $s->status(),
            'notes_text' => $s->notesText(),
            'consent_signer_name' => $s->consentSignerName(),
            'consent_text' => $s->consentText(),
            'consent_recorded_at' => $date($s->consentRecordedAt()),
            'consent_recorded_by_system_user_id' => $s->consentRecordedBySystemUserId(),
            'started_at' => $date($s->startedAt()),
            'completed_at' => $date($s->completedAt()),
            'completed_by_system_user_id' => $s->completedBySystemUserId(),
            'cancelled_at' => $date($s->cancelledAt()),
            'cancelled_by_system_user_id' => $s->cancelledBySystemUserId(),
            'cancellation_reason_text' => $s->cancellationReasonText(),
            'followup_appointment_id' => $s->followupAppointmentId(),
            'created_at' => $date($s->createdAt()),
            'updated_at' => $date(new DateTimeImmutable()),
        ];
    }
}
