<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The 1:1 result of an {@see ExamRequest} (domain aggregate for the
 * `exam_result` table): free-text structured result and/or a stored object
 * key (e.g. a PDF/image uploaded to per-tenant storage), plus a
 * `pending_review` flag the requesting professional clears once read.
 *
 * PENDING / DO NOT WIRE YET: `exam_result` is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * Plain PHP entity, mutable for `pending_review` (a result can be marked
 * reviewed after being read). No Adianti dependency (ADR 0001).
 */
final class ExamResult
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $examRequestId,
        private readonly ?string $structuredResultText,
        private readonly ?string $storedObjectKey,
        private bool $pendingReview,
        private readonly DateTimeImmutable $receivedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * Records a result for an exam request. `structuredResultText` and
     * `storedObjectKey` are both optional individually, but at least one of
     * them must be present — an empty result carries no information.
     */
    public static function record(
        int $tenantId,
        int $examRequestId,
        ?string $structuredResultText,
        ?string $storedObjectKey,
        bool $pendingReview,
        DateTimeImmutable $now,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($examRequestId <= 0) {
            throw new InvalidArgumentException('exam_request_id must be positive');
        }

        $structuredResultText = $structuredResultText !== null ? trim($structuredResultText) : null;
        $structuredResultText = $structuredResultText === '' ? null : $structuredResultText;

        $storedObjectKey = $storedObjectKey !== null ? trim($storedObjectKey) : null;
        $storedObjectKey = $storedObjectKey === '' ? null : $storedObjectKey;

        if ($structuredResultText === null && $storedObjectKey === null) {
            throw new InvalidArgumentException(
                'At least one of structured_result or stored_object_key must be present'
            );
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            examRequestId: $examRequestId,
            structuredResultText: $structuredResultText,
            storedObjectKey: $storedObjectKey,
            pendingReview: $pendingReview,
            receivedAt: $now,
        );
    }

    /**
     * Rebuilds an existing exam result from persisted data. Repositories use
     * this to hydrate rows; application code should use record() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $examRequestId,
        ?string $structuredResultText,
        ?string $storedObjectKey,
        bool $pendingReview,
        DateTimeImmutable $receivedAt,
        ?DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $tenantId,
            $examRequestId,
            $structuredResultText,
            $storedObjectKey,
            $pendingReview,
            $receivedAt,
            $createdAt,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('ExamResult already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    public function markReviewed(): void
    {
        $this->pendingReview = false;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function examRequestId(): int
    {
        return $this->examRequestId;
    }

    public function structuredResultText(): ?string
    {
        return $this->structuredResultText;
    }

    public function storedObjectKey(): ?string
    {
        return $this->storedObjectKey;
    }

    public function pendingReview(): bool
    {
        return $this->pendingReview;
    }

    public function receivedAt(): DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
