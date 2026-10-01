<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\StoredObjectRepositoryInterface;
use CentralVet\Storage\StoredObjectMetadata;

/**
 * In-memory double for StoredObjectRepositoryInterface (rodada 2, T-52).
 * Tenant-scoped like the real StoredObjectRepository: rows are kept per
 * tenant and every read only sees the rows of this instance's current
 * tenant.
 */
final class FakeStoredObjectRepository implements StoredObjectRepositoryInterface
{
    /** @var array<int, array<int, array<string, mixed>>> tenant => id => row */
    private array $rowsByTenant = [];
    private int $nextId = 1;
    private ?\Throwable $failNextRecord = null;

    public function __construct(private readonly int $tenantId)
    {
    }

    /** Next record() throws $error instead of writing. */
    public function failNextRecordWith(\Throwable $error): void
    {
        $this->failNextRecord = $error;
    }

    public function record(StoredObjectMetadata $metadata, string $originalName, ?int $systemUnitId, int $createdBy): array
    {
        if ($this->failNextRecord !== null) {
            $error = $this->failNextRecord;
            $this->failNextRecord = null;
            throw $error;
        }

        $id = $this->nextId++;
        $row = [
            'id' => $id,
            'public_id' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'tenant_id' => $this->tenantId,
            'system_unit_id' => $systemUnitId,
            ...$metadata->toStoredObjectRow(),
            'original_name' => $originalName,
            'status' => 'available',
            'created_by' => $createdBy,
            'created_at' => sprintf('2031-01-01 00:00:%02d.000000', $id % 60),
            'deleted_at' => null,
        ];

        $this->rowsByTenant[$this->tenantId][$id] = $row;

        return $row;
    }

    public function listByObjectKeyFragment(string $fragment): array
    {
        $rows = array_filter(
            $this->rowsByTenant[$this->tenantId] ?? [],
            static fn (array $row): bool => $row['status'] === 'available'
                && $row['deleted_at'] === null
                && str_contains((string) $row['object_key'], $fragment),
        );

        usort($rows, static fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return array_map(static fn (array $row): array => [
            'public_id' => (string) $row['public_id'],
            'original_name' => (string) $row['original_name'],
            'content_type' => (string) $row['content_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'created_at' => (string) $row['created_at'],
            'object_key' => (string) $row['object_key'],
        ], $rows);
    }

    public function findByPublicId(string $publicId): ?array
    {
        foreach ($this->rowsByTenant[$this->tenantId] ?? [] as $row) {
            if ($row['public_id'] === $publicId) {
                return $row;
            }
        }

        return null;
    }

    /** Test helper: overwrites columns (e.g. status, deleted_at) of this tenant's row. */
    public function updateRow(string $publicId, array $fields): void
    {
        foreach ($this->rowsByTenant[$this->tenantId] ?? [] as $id => $row) {
            if ($row['public_id'] === $publicId) {
                $this->rowsByTenant[$this->tenantId][$id] = [...$row, ...$fields];
            }
        }
    }

    /** Test helper: the rows of every tenant, unscoped. */
    public function allRows(): array
    {
        return array_merge([], ...array_values(array_map('array_values', $this->rowsByTenant)));
    }
}
