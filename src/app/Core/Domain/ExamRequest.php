<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A patient's request for a catalog exam inside a clinical encounter
 * (domain aggregate for the `exam_request` table). Its 1:1 result is a
 * separate aggregate, {@see ExamResult}, linked back by `exam_request_id`.
 *
 * PENDING / DO NOT WIRE YET: `exam_request` is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * Plain PHP entity, mutable like `CentralVet\Domain\QueueEntry`: it owns a
 * real (if minimal) state machine ({@see self::markResultAvailable()}), so
 * "mutate in place, persist the mutation" reads clearer here than rebuilding
 * a new immutable copy. No Adianti dependency (ADR 0001).
 *
 * Deliberately has no `system_unit_id` column of its own (the migration does
 * not add one): the unit an exam request belongs to is always read back from
 * its origin `encounter` ({@see \CentralVet\Domain\Encounter::systemUnitId()}),
 * exactly the same indirection `ExamService` uses for unit-scope
 * authorization on both `requestExam()` and `recordResult()`.
 *
 * ## Status state machine
 * Only one forward transition is legal: `requested` -> `result_available`,
 * matching the migration's `exam_request_status_ck` CHECK constraint.
 * `markResultAvailable()` is the only way to change status; there is no
 * "set status" method.
 */
final class ExamRequest
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_RESULT_AVAILABLE = 'result_available';

    private const VALID_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_RESULT_AVAILABLE,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $encounterId,
        private readonly int $patientId,
        private readonly int $examCatalogItemId,
        private readonly int $professionalSystemUserId,
        private string $status,
        private readonly DateTimeImmutable $requestedAt,
        private readonly ?DateTimeImmutable $createdUpdatedAt = null,
    ) {
    }

    /**
     * Requests a catalog exam. Always starts at `requested` — a request is
     * only ever created without a result; recording one is a separate step
     * ({@see self::markResultAvailable()}).
     */
    public static function request(
        int $tenantId,
        int $encounterId,
        int $patientId,
        int $examCatalogItemId,
        int $professionalSystemUserId,
        DateTimeImmutable $now,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($encounterId <= 0) {
            throw new InvalidArgumentException('encounter_id must be positive');
        }

        if ($patientId <= 0) {
            throw new InvalidArgumentException('patient_id must be positive');
        }

        if ($examCatalogItemId <= 0) {
            throw new InvalidArgumentException('exam_catalog_item_id must be positive');
        }

        if ($professionalSystemUserId <= 0) {
            throw new InvalidArgumentException('professional_system_user_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            encounterId: $encounterId,
            patientId: $patientId,
            examCatalogItemId: $examCatalogItemId,
            professionalSystemUserId: $professionalSystemUserId,
            status: self::STATUS_REQUESTED,
            requestedAt: $now,
        );
    }

    /**
     * Rebuilds an existing exam request from persisted data. Repositories
     * use this to hydrate rows; application code should use request()
     * instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $encounterId,
        int $patientId,
        int $examCatalogItemId,
        int $professionalSystemUserId,
        string $status,
        DateTimeImmutable $requestedAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown exam_request status \"{$status}\"");
        }

        return new self(
            $id,
            $tenantId,
            $encounterId,
            $patientId,
            $examCatalogItemId,
            $professionalSystemUserId,
            $status,
            $requestedAt,
            $updatedAt,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('ExamRequest already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Moves the request from `requested` to `result_available`. This is the
     * ONLY way status changes.
     *
     * @throws InvalidStatusTransitionException when the request is not
     *         currently `requested` (e.g. a result was already recorded).
     */
    public function markResultAvailable(): void
    {
        if ($this->status !== self::STATUS_REQUESTED) {
            throw new InvalidStatusTransitionException(
                sprintf(
                    'Exam request %s cannot move to "result_available" from status "%s"',
                    $this->id !== null ? (string) $this->id : '(new)',
                    $this->status,
                )
            );
        }

        $this->status = self::STATUS_RESULT_AVAILABLE;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function encounterId(): int
    {
        return $this->encounterId;
    }

    public function patientId(): int
    {
        return $this->patientId;
    }

    public function examCatalogItemId(): int
    {
        return $this->examCatalogItemId;
    }

    public function professionalSystemUserId(): int
    {
        return $this->professionalSystemUserId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function requestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->createdUpdatedAt;
    }
}
