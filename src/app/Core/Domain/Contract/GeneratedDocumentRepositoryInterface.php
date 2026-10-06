<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\GeneratedDocument;
use DateTimeImmutable;

/**
 * Persistence boundary for generated documents (`generated_document`),
 * always filtered by the tenant of the current context.
 *
 * A document is inserted once (`insertNextVersion`) and then only moves
 * through conditional transitions: each one is an `UPDATE ... WHERE
 * <condition>` and returns true only when one row of the tenant changed.
 *
 * - `insertNextVersion`: assigns `version = MAX(version) + 1` for the same
 *   (`kind`, `source_type`, `source_id`) of the tenant, inserts and calls
 *   `assignIdentity()`; on a duplicate-key error (1062) recomputes and
 *   retries, at most 3 times.
 * - `claim`: `status = 'queued'` and (`claimed_at` null or older than
 *   `$now` - 10 min) → `claimed_at = $now`, `attempt_count` + 1.
 * - `markReady`: `status = 'queued'` and `claimed_at` not null → `ready`
 *   (`stored_object_id`, `storage_key`, `size_bytes`, `sha256`,
 *   `ready_at`, `last_error_code` null).
 * - `releaseClaim`: `status = 'queued'` → `claimed_at` null,
 *   `last_error_code = $errorCode` (a retry will follow).
 * - `markFailed`: `status = 'queued'` → `failed` (`last_error_code`,
 *   `failed_at`, `claimed_at` null).
 * - `requeueFailed`: `failed` → `queued` (clears `failed_at` and
 *   `claimed_at`); the same row and version are reused.
 * - `markNotified`: only when `notified_at` is null → `notified_at`.
 */
interface GeneratedDocumentRepositoryInterface
{
    public function insertNextVersion(GeneratedDocument $document): GeneratedDocument;

    public function findById(int $id): ?GeneratedDocument;

    public function claim(int $id, DateTimeImmutable $now): bool;

    public function markReady(int $id, int $storedObjectId, string $storageKey, int $sizeBytes, string $sha256, DateTimeImmutable $readyAt): bool;

    public function releaseClaim(int $id, string $errorCode): bool;

    public function markFailed(int $id, string $errorCode, DateTimeImmutable $failedAt): bool;

    public function requeueFailed(int $id): bool;

    public function markNotified(int $id, DateTimeImmutable $notifiedAt): bool;

    /**
     * Documents of a unit, newest first (`created_at DESC, id DESC`), at
     * most `$limit`; optionally only one patient's.
     *
     * @return list<GeneratedDocument>
     */
    public function listForUnit(int $systemUnitId, ?int $patientId, int $limit): array;

    /**
     * Ids of `queued` documents stuck before `$olderThan`: never claimed
     * (`claimed_at` null and `created_at < $olderThan`) or with an
     * abandoned claim (`claimed_at < $olderThan`); oldest first, at most
     * `$limit`.
     *
     * @return list<int>
     */
    public function listStaleQueuedIds(DateTimeImmutable $olderThan, int $limit): array;
}
