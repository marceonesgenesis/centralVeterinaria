<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ExamResultRepositoryInterface;
use CentralVet\Domain\ExamResult;
use InvalidArgumentException;

/**
 * In-memory double for ExamResultRepositoryInterface (T-11): the
 * `exam_result` table does not exist yet (migration T-01 not applied), so
 * ExamServiceTest exercises ExamService::recordResult() against this instead
 * of a real database. Tenant-scoped like the real ExamResultRepository (ADR
 * 0002): findById() and every listing/lookup method only ever return a
 * result whose tenantId() matches this instance's own $tenantId.
 */
final class FakeExamResultRepository implements ExamResultRepositoryInterface
{
    /** @var array<int, ExamResult> */
    private array $results = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, ExamResult ...$seed)
    {
        foreach ($seed as $result) {
            $this->save($result);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $result = $this->results[(int) $id] ?? null;

        if ($result === null || $result->tenantId() !== $this->tenantId) {
            return null;
        }

        return $result;
    }

    public function findByExamRequest(int $examRequestId): ?object
    {
        foreach ($this->results as $result) {
            if ($result->tenantId() === $this->tenantId && $result->examRequestId() === $examRequestId) {
                return $result;
            }
        }

        return null;
    }

    /** @return list<ExamResult> */
    public function listPendingReview(): array
    {
        return array_values(array_filter(
            $this->results,
            fn (ExamResult $result): bool => $result->tenantId() === $this->tenantId && $result->pendingReview(),
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ExamResult) {
            throw new InvalidArgumentException('FakeExamResultRepository only stores ExamResult entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->results[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof ExamResult && $entity->id() !== null) {
            unset($this->results[$entity->id()]);
        }
    }
}
