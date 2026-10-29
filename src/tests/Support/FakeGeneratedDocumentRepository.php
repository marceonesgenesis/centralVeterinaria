<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\GeneratedDocumentRepositoryInterface;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for GeneratedDocumentRepositoryInterface (T-05). Stores
 * rows keyed like `generated_document`; reads return fresh
 * GeneratedDocument::reconstitute() copies, like the PDO repository.
 *
 * The tenant comes from the TenantContext. insertNextVersion() numbers the
 * versions per (tenant, kind, source_type, source_id). Every transition
 * mirrors `UPDATE ... WHERE <condition>`: it returns false (changing
 * nothing) when the row is missing, belongs to another tenant or is not in
 * the expected state. claim() takes over a claim older than 10 minutes.
 * simulateConcurrentClaim() stamps `claimed_at` (now, or the given instant
 * when the test drives a fixed clock), as another worker would, to prove
 * races. listStaleQueuedIds() follows the PDO repository: an unclaimed row
 * ages from its last write (`updated_at`), so a released claim waiting in
 * the queue backoff is not stale.
 */
final class FakeGeneratedDocumentRepository implements GeneratedDocumentRepositoryInterface
{
    private const CLAIM_TTL = '-10 minutes';

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    public function __construct(private readonly TenantContext $context)
    {
    }

    /** Row columns the entity does not carry, settable through seed(). */
    private const SEEDABLE_COLUMNS = ['claimed_at', 'failed_at', 'size_bytes', 'sha256', 'created_at', 'updated_at'];

    /**
     * Stores the document as it is (any tenant, no version check). A
     * document without id gets the next id and version of its source.
     * `$columns` sets row columns the entity does not carry (claimed_at,
     * failed_at, size_bytes, sha256, created_at, updated_at; instants as
     * `Y-m-d H:i:s.u`); any other key is refused.
     *
     * @param array<string, int|string|null> $columns
     */
    public function seed(GeneratedDocument $document, array $columns = []): void
    {
        $unknown = array_diff(array_keys($columns), self::SEEDABLE_COLUMNS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown seed column: ' . implode(', ', $unknown));
        }

        if ($document->id() === null) {
            $document->assignIdentity($this->nextId++, $this->nextVersion($document));
        } else {
            $this->nextId = max($this->nextId, $document->id() + 1);
        }

        $this->rows[(int) $document->id()] = array_merge(self::toRow($document), $columns);
    }

    public function insertNextVersion(GeneratedDocument $document): GeneratedDocument
    {
        if ($document->tenantId() !== $this->context->tenantId()) {
            throw new InvalidArgumentException('Generated document belongs to another tenant');
        }

        if ($document->id() !== null) {
            throw new InvalidArgumentException('Generated document already has an id');
        }

        $this->seed($document);

        return $document;
    }

    public function findById(int $id): ?GeneratedDocument
    {
        $row = $this->ownRow($id);

        return $row === null ? null : GeneratedDocument::reconstitute($row);
    }

    public function claim(int $id, DateTimeImmutable $now): bool
    {
        $row = $this->ownRow($id);

        if ($row === null || $row['status'] !== GeneratedDocument::STATUS_QUEUED) {
            return false;
        }

        if ($row['claimed_at'] !== null && $row['claimed_at'] >= self::date($now->modify(self::CLAIM_TTL))) {
            return false;
        }

        return $this->update($id, [
            'claimed_at' => self::date($now),
            'attempt_count' => $row['attempt_count'] + 1,
        ]);
    }

    public function markReady(int $id, int $storedObjectId, string $storageKey, int $sizeBytes, string $sha256, DateTimeImmutable $readyAt): bool
    {
        $row = $this->ownRow($id);

        if ($row === null || $row['status'] !== GeneratedDocument::STATUS_QUEUED || $row['claimed_at'] === null) {
            return false;
        }

        return $this->update($id, [
            'status' => GeneratedDocument::STATUS_READY,
            'stored_object_id' => $storedObjectId,
            'storage_key' => $storageKey,
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            'ready_at' => self::date($readyAt),
            'last_error_code' => null,
        ]);
    }

    public function releaseClaim(int $id, string $errorCode): bool
    {
        $row = $this->ownRow($id);

        if ($row === null || $row['status'] !== GeneratedDocument::STATUS_QUEUED) {
            return false;
        }

        return $this->update($id, ['claimed_at' => null, 'last_error_code' => $errorCode]);
    }

    public function markFailed(int $id, string $errorCode, DateTimeImmutable $failedAt): bool
    {
        $row = $this->ownRow($id);

        if ($row === null || $row['status'] !== GeneratedDocument::STATUS_QUEUED) {
            return false;
        }

        return $this->update($id, [
            'status' => GeneratedDocument::STATUS_FAILED,
            'last_error_code' => $errorCode,
            'failed_at' => self::date($failedAt),
            'claimed_at' => null,
        ]);
    }

    public function requeueFailed(int $id): bool
    {
        $row = $this->ownRow($id);

        if ($row === null || $row['status'] !== GeneratedDocument::STATUS_FAILED) {
            return false;
        }

        return $this->update($id, [
            'status' => GeneratedDocument::STATUS_QUEUED,
            'failed_at' => null,
            'claimed_at' => null,
        ]);
    }

    public function markNotified(int $id, DateTimeImmutable $notifiedAt): bool
    {
        $row = $this->ownRow($id);

        if ($row === null || $row['notified_at'] !== null) {
            return false;
        }

        return $this->update($id, ['notified_at' => self::date($notifiedAt)]);
    }

    public function listForUnit(int $systemUnitId, ?int $patientId, int $limit): array
    {
        $rows = array_filter(
            $this->ownRows(),
            static fn (array $row): bool => $row['system_unit_id'] === $systemUnitId
                && ($patientId === null || $row['patient_id'] === $patientId),
        );
        usort($rows, static fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return array_map(
            static fn (array $row): GeneratedDocument => GeneratedDocument::reconstitute($row),
            array_slice($rows, 0, max(0, $limit)),
        );
    }

    public function listStaleQueuedIds(DateTimeImmutable $olderThan, int $limit): array
    {
        $limitDate = self::date($olderThan);
        $rows = array_filter(
            $this->ownRows(),
            static fn (array $row): bool => $row['status'] === GeneratedDocument::STATUS_QUEUED
                && ($row['claimed_at'] === null ? $row['updated_at'] < $limitDate : $row['claimed_at'] < $limitDate),
        );
        usort($rows, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);

        return array_map(static fn (array $row): int => $row['id'], array_slice($rows, 0, max(0, $limit)));
    }

    /**
     * Every stored document (all tenants), as fresh copies ordered by id.
     *
     * @return list<GeneratedDocument>
     */
    public function all(): array
    {
        ksort($this->rows);

        return array_values(array_map(static fn (array $row): GeneratedDocument => GeneratedDocument::reconstitute($row), $this->rows));
    }

    /** The raw stored row (any tenant), or null; for asserting columns the entity does not expose. */
    public function row(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    /**
     * Simulates another worker claiming the document (`claimed_at` = `$at`,
     * default now; nothing else changes). Pass the instant of the test clock
     * when claim() is called with fixed instants.
     */
    public function simulateConcurrentClaim(int $id, ?DateTimeImmutable $at = null): void
    {
        if (!isset($this->rows[$id])) {
            throw new InvalidArgumentException("Generated document {$id} not found");
        }

        $this->rows[$id]['claimed_at'] = self::date($at ?? new DateTimeImmutable());
    }

    private function nextVersion(GeneratedDocument $document): int
    {
        $max = 0;

        foreach ($this->rows as $row) {
            if ($row['tenant_id'] === $document->tenantId()
                && $row['kind'] === $document->kind()
                && $row['source_type'] === $document->sourceType()
                && $row['source_id'] === $document->sourceId()) {
                $max = max($max, $row['version']);
            }
        }

        return $max + 1;
    }

    /** @param array<string, mixed> $changes */
    private function update(int $id, array $changes): bool
    {
        $this->rows[$id] = array_merge($this->rows[$id], $changes, ['updated_at' => self::date(new DateTimeImmutable())]);

        return true;
    }

    /** @return array<string, mixed>|null */
    private function ownRow(int $id): ?array
    {
        $row = $this->rows[$id] ?? null;

        return $row !== null && $row['tenant_id'] === $this->context->tenantId() ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    private function ownRows(): array
    {
        ksort($this->rows);

        return array_values(array_filter($this->rows, fn (array $row): bool => $row['tenant_id'] === $this->context->tenantId()));
    }

    private static function date(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s.u');
    }

    /** @return array<string, mixed> */
    private static function toRow(GeneratedDocument $d): array
    {
        $now = self::date(new DateTimeImmutable());

        return [
            'id' => $d->id(),
            'tenant_id' => $d->tenantId(),
            'system_unit_id' => $d->systemUnitId(),
            'patient_id' => $d->patientId(),
            'tutor_id' => $d->tutorId(),
            'kind' => $d->kind(),
            'source_type' => $d->sourceType(),
            'source_id' => $d->sourceId(),
            'version' => $d->version(),
            'template_id' => $d->templateId(),
            'title' => $d->title(),
            'body_text' => $d->bodyText(),
            'notify_tutor' => $d->notifyTutor() ? 1 : 0,
            'status' => $d->status(),
            'attempt_count' => $d->attemptCount(),
            'stored_object_id' => $d->storedObjectId(),
            'storage_key' => $d->storageKey(),
            'size_bytes' => null,
            'sha256' => null,
            'last_error_code' => $d->lastErrorCode(),
            'claimed_at' => null,
            'ready_at' => self::date($d->readyAt()),
            'failed_at' => null,
            'notified_at' => self::date($d->notifiedAt()),
            'requested_by_system_user_id' => $d->requestedBySystemUserId(),
            'created_at' => self::date($d->createdAt()) ?? $now,
            // Like the INSERT: both default to the same instant.
            'updated_at' => self::date($d->createdAt()) ?? $now,
        ];
    }
}
