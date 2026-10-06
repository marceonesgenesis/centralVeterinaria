<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\PendingItem;
use CentralVet\Domain\PendingItemPriority;
use CentralVet\Persistence\PendingItemQuery;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Fase 7B, T-09: failed generated documents reach the Central de Pendências
 * as `document_failed` (PendingItemQuery against the real schema, 0013
 * applied in centralvet_test). Throwaway tenants inside the test
 * transaction, rolled back in tearDown().
 *
 * Fixture, tenant A: prescription v2 `failed` in unit A, vaccination card v1
 * `ready` in unit A, medical certificate v1 `failed` in unit B. Tenant B:
 * one `failed` document in unit A.
 */
final class DocumentPendingItemIntegrationTest extends MysqlIntegrationTestCase
{
    private const NOW = '2031-10-10 12:00:00';

    private int $userId;
    private int $unitA;
    private int $unitB;
    private int $tenantA;
    private int $tenantB;

    /** @var array<string, int> */
    private array $a = [];

    /** @var array<string, int> */
    private array $b = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $units = array_map('intval', $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        Assert::count(2, $units, 'Fixture requires two existing system_unit rows');
        [$this->unitA, $this->unitB] = $units;

        $this->tenantA = $this->createTenant('f7b-pend-a');
        $this->tenantB = $this->createTenant('f7b-pend-b');

        $this->a = $this->seedPatient($this->tenantA, 'A');
        $this->a['failed'] = $this->createDocument($this->tenantA, $this->unitA, $this->a, 'prescription', 'prescription', 2, 'failed', '2031-10-09 18:00:00');
        $this->a['ready'] = $this->createDocument($this->tenantA, $this->unitA, $this->a, 'vaccination_card', 'patient', 1, 'ready', null);
        $this->a['failed_unit_b'] = $this->createDocument($this->tenantA, $this->unitB, $this->a, 'medical_certificate', 'patient', 1, 'failed', '2031-10-09 19:00:00');

        $this->b = $this->seedPatient($this->tenantB, 'B');
        $this->b['failed'] = $this->createDocument($this->tenantB, $this->unitA, $this->b, 'prescription', 'prescription', 1, 'failed', '2031-10-09 20:00:00');
    }

    public function testFailedDocumentAppearsWithDeepLinkToDocumentListByPatientIdOnly(): void
    {
        $items = $this->ofType($this->pendingFor($this->tenantA, $this->unitA), PendingItem::TYPE_DOCUMENT_FAILED);

        Assert::count(1, $items, 'only the failed document of unit A');
        $item = $items[0];
        Assert::same($this->a['failed'], $item->sourceId());
        Assert::same('index.php?class=DocumentList&patient_id=' . $this->a['patient'], $item->deepLinkUrl());
        Assert::false(str_contains($item->deepLinkUrl(), 'Rex'), 'patient name must not reach the URL');
        Assert::same('Receita v2', $item->subjectLabel());
        Assert::same('F7B teste Rex A', $item->patientName());
        Assert::same('2031-10-09 18:00:00', $item->dueAt()->format('Y-m-d H:i:s'), 'due_at = failed_at');
        Assert::same($this->userId, $item->responsibleSystemUserId());
        Assert::same(PendingItemPriority::HIGH, $item->priority(new DateTimeImmutable(self::NOW)));
    }

    public function testReadyDocumentIsNotPending(): void
    {
        $sourceIds = array_map(
            static fn (PendingItem $i): int => $i->sourceId(),
            $this->ofType($this->pendingFor($this->tenantA, $this->unitA), PendingItem::TYPE_DOCUMENT_FAILED),
        );

        Assert::false(in_array($this->a['ready'], $sourceIds, true), 'ready document must not be pending');
    }

    public function testFailedDocumentOfUnitAIsNotListedForUnitB(): void
    {
        $items = $this->ofType($this->pendingFor($this->tenantA, $this->unitB), PendingItem::TYPE_DOCUMENT_FAILED);

        Assert::count(1, $items, 'unit B sees only its own failed document');
        Assert::same($this->a['failed_unit_b'], $items[0]->sourceId());
        Assert::same('Atestado v1', $items[0]->subjectLabel());
    }

    public function testNoDocumentOfAnotherTenant(): void
    {
        $items = $this->ofType($this->pendingFor($this->tenantB, $this->unitA), PendingItem::TYPE_DOCUMENT_FAILED);

        Assert::count(1, $items, 'tenant B sees only its own failed document');
        Assert::same($this->b['failed'], $items[0]->sourceId());
    }

    public function testLimitPerTypeAndNewestFirst(): void
    {
        $newer = $this->createDocument($this->tenantA, $this->unitA, $this->a, 'prescription', 'prescription', 3, 'failed', '2031-10-10 08:00:00');

        $all = $this->ofType($this->pendingFor($this->tenantA, $this->unitA), PendingItem::TYPE_DOCUMENT_FAILED);
        Assert::same([$newer, $this->a['failed']], array_map(static fn (PendingItem $i): int => $i->sourceId(), $all), 'newest failure first');

        $limited = $this->ofType($this->pendingFor($this->tenantA, $this->unitA, 1), PendingItem::TYPE_DOCUMENT_FAILED);
        Assert::count(1, $limited);
        Assert::same($newer, $limited[0]->sourceId());
    }

    /**
     * @return list<PendingItem>
     */
    private function pendingFor(int $tenantId, int $unitId, int $limit = 50): array
    {
        return (new PendingItemQuery(TenantContext::authenticated($tenantId, $this->userId), $this->pdo))
            ->listForUnit($unitId, new DateTimeImmutable(self::NOW), $limit);
    }

    /**
     * @param list<PendingItem> $items
     * @return list<PendingItem>
     */
    private function ofType(array $items, string $type): array
    {
        return array_values(array_filter($items, static fn (PendingItem $i): bool => $i->type() === $type));
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));

        return $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'F7B teste tenant (' . $slug . ')',
            'status' => 'active',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function seedPatient(int $tenantId, string $suffix): array
    {
        $tutor = $this->insert('tutor', [
            'tenant_id' => $tenantId,
            'public_id' => $this->uuid(),
            'full_name' => 'F7B teste Tutor ' . $suffix,
            'phone' => '85999990000',
            'email' => 'f7b.teste@example.invalid',
        ]);
        $patient = $this->insert('patient', [
            'tenant_id' => $tenantId,
            'tutor_id' => $tutor,
            'name' => 'F7B teste Rex ' . $suffix,
            'species' => 'canino',
        ]);

        return ['tutor' => $tutor, 'patient' => $patient];
    }

    /**
     * @param array<string, int> $owner
     */
    private function createDocument(
        int $tenantId,
        int $unitId,
        array $owner,
        string $kind,
        string $sourceType,
        int $version,
        string $status,
        ?string $failedAt,
    ): int {
        $row = [
            'tenant_id' => $tenantId,
            'system_unit_id' => $unitId,
            'patient_id' => $owner['patient'],
            'tutor_id' => $owner['tutor'],
            'kind' => $kind,
            'source_type' => $sourceType,
            'source_id' => $sourceType === 'patient' ? $owner['patient'] : 900000 + $owner['patient'],
            'version' => $version,
            'title' => 'F7B teste documento',
            'status' => $status,
            'attempt_count' => $status === 'failed' ? 3 : 1,
            'requested_by_system_user_id' => $this->userId,
        ];

        if ($status === 'failed') {
            $row['failed_at'] = $failedAt;
            $row['last_error_code'] = 'render_failed';
        }

        if ($status === 'ready') {
            $key = 'cv/test/tenant/' . $tenantId . '/objects/' . bin2hex(random_bytes(8));
            $row['stored_object_id'] = $this->insert('stored_object', [
                'public_id' => $this->uuid(),
                'tenant_id' => $tenantId,
                'system_unit_id' => $unitId,
                'storage_provider' => 'local',
                'bucket' => 'local',
                'object_key' => $key,
                'original_name' => $kind . '-0-v' . $version . '.pdf',
                'content_type' => 'application/pdf',
                'size_bytes' => 1024,
                'sha256' => str_repeat('a', 64),
                'status' => 'available',
                'created_by' => $this->userId,
            ]);
            $row['storage_key'] = $key;
            $row['ready_at'] = '2031-10-09 17:00:00';
        }

        return $this->insert('generated_document', $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));
        $statement->execute($row);

        return (int) $this->pdo->lastInsertId();
    }

    private function uuid(): string
    {
        return (string) $this->pdo->query('SELECT UUID()')->fetchColumn();
    }
}
