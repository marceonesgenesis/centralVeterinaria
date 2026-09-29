<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ExamRequestRepositoryInterface;
use CentralVet\Domain\ExamRequest;
use InvalidArgumentException;

/**
 * In-memory double for ExamRequestRepositoryInterface (T-11): the
 * `exam_request` table does not exist yet (migration T-01 not applied), so
 * ExamServiceTest exercises ExamService::requestExam()/recordResult()
 * against this instead of a real database. Tenant-scoped like the real
 * ExamRequestRepository (ADR 0002): findById() and every listing method only
 * ever return a request whose tenantId() matches this instance's own
 * $tenantId.
 */
final class FakeExamRequestRepository implements ExamRequestRepositoryInterface
{
    /** @var array<int, ExamRequest> */
    private array $requests = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, ExamRequest ...$seed)
    {
        foreach ($seed as $request) {
            $this->save($request);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $request = $this->requests[(int) $id] ?? null;

        if ($request === null || $request->tenantId() !== $this->tenantId) {
            return null;
        }

        return $request;
    }

    /** @return list<ExamRequest> */
    public function listByPatient(int $patientId): array
    {
        return array_values(array_filter(
            $this->requests,
            fn (ExamRequest $request): bool => $request->tenantId() === $this->tenantId
                && $request->patientId() === $patientId,
        ));
    }

    /** @return list<ExamRequest> */
    public function listByEncounter(int $encounterId): array
    {
        return array_values(array_filter(
            $this->requests,
            fn (ExamRequest $request): bool => $request->tenantId() === $this->tenantId
                && $request->encounterId() === $encounterId,
        ));
    }

    /** @return list<ExamRequest> */
    public function listPending(): array
    {
        return array_values(array_filter(
            $this->requests,
            fn (ExamRequest $request): bool => $request->tenantId() === $this->tenantId
                && $request->status() === ExamRequest::STATUS_REQUESTED,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ExamRequest) {
            throw new InvalidArgumentException('FakeExamRequestRepository only stores ExamRequest entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->requests[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof ExamRequest && $entity->id() !== null) {
            unset($this->requests[$entity->id()]);
        }
    }
}
