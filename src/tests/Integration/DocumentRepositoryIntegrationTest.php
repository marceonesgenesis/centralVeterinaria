<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\DocumentTemplate;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Persistence\DocumentTemplateRepository;
use CentralVet\Persistence\GeneratedDocumentRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * The two document repositories (migration 0013) against the real MySQL
 * schema: version allocation per source, conditional transitions (claim,
 * markReady, markFailed, requeueFailed, releaseClaim, markNotified), the
 * unit listing, the stale sweep and tenant isolation. Every row lives only
 * inside the test transaction, rolled back in tearDown() — nothing is
 * committed.
 */
final class DocumentRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $unitId;
    private int $tenantA;
    private int $tenantB;
    private int $tutorId;
    private int $patientId;

    public function setUp(): void
    {
        parent::setUp();

        // The worker uses native prepares: a repeated named parameter or a
        // quoted LIMIT must fail here too.
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'generated_document'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0013 must be applied to the test database');

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('f7b-doc-a');
        $this->tenantB = $this->createTenant('f7b-doc-b');

        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), :n, :p)')
            ->execute(['t' => $this->tenantA, 'n' => 'F7B teste Tutor', 'p' => '11999990000']);
        $this->tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, :n, :s)')
            ->execute(['t' => $this->tenantA, 'tutor' => $this->tutorId, 'n' => 'F7B teste Rex', 's' => 'canino']);
        $this->patientId = (int) $this->pdo->lastInsertId();
    }

    public function testInsertNextVersionAllocatesOneThenTwoPerSource(): void
    {
        $documents = $this->documents($this->tenantA);

        $first = $documents->insertNextVersion($this->cardRequest());
        $second = $documents->insertNextVersion($this->cardRequest());

        Assert::true((int) $first->id() > 0);
        Assert::same(1, $first->version());
        Assert::same(2, $second->version());
        Assert::true((int) $second->id() > (int) $first->id());

        $stored = $documents->findById((int) $second->id());
        Assert::notNull($stored);
        Assert::same(2, $stored->version());
        Assert::same(GeneratedDocument::STATUS_QUEUED, $stored->status());
        Assert::same(DocumentKind::SOURCE_PATIENT, $stored->sourceType());

        // Another kind of the same source keeps its own sequence.
        $certificate = $documents->insertNextVersion($this->certificateRequest());
        Assert::same(1, $certificate->version());
        Assert::same('F7B teste atestado', $documents->findById((int) $certificate->id())?->bodyText());
    }

    public function testSecondClaimAtTheSameInstantFailsAndStaleClaimIsRetaken(): void
    {
        $documents = $this->documents($this->tenantA);
        $id = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $now = new DateTimeImmutable('2031-10-01 10:00:00.250000');

        Assert::true($documents->claim($id, $now));
        Assert::false($documents->claim($id, $now));
        Assert::false($documents->claim($id, $now->modify('+9 minutes')));
        Assert::true($documents->claim($id, $now->modify('+11 minutes')));

        Assert::same(2, $documents->findById($id)?->attemptCount());
        Assert::same('2031-10-01 10:11:00.250000', $this->column($id, 'claimed_at'));
    }

    public function testMarkReadyOnlyAfterClaimAndOnlyOnce(): void
    {
        $documents = $this->documents($this->tenantA);
        $id = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $now = new DateTimeImmutable('2031-10-01 10:00:00');
        $objectId = $this->createStoredObject($this->tenantA);
        $sha = str_repeat('a', 64);

        Assert::false($documents->markReady($id, $objectId, 'cv/test/key.pdf', 1234, $sha, $now), 'ready requires a claim');
        Assert::true($documents->claim($id, $now));
        Assert::true($documents->markReady($id, $objectId, 'cv/test/key.pdf', 1234, $sha, $now->modify('+3 seconds')));
        Assert::false($documents->markReady($id, $objectId, 'cv/test/key.pdf', 1234, $sha, $now->modify('+4 seconds')));

        $ready = $documents->findById($id);
        Assert::notNull($ready);
        Assert::same(GeneratedDocument::STATUS_READY, $ready->status());
        Assert::same($objectId, $ready->storedObjectId());
        Assert::same('cv/test/key.pdf', $ready->storageKey());
        Assert::same('2031-10-01 10:00:03', $ready->readyAt()?->format('Y-m-d H:i:s'));
        Assert::same('1234', $this->column($id, 'size_bytes'));
        Assert::same($sha, $this->column($id, 'sha256'));
        Assert::true($ready->isDownloadable());

        Assert::true($documents->markNotified($id, $now->modify('+5 seconds')));
        Assert::false($documents->markNotified($id, $now->modify('+6 seconds')));
        Assert::same('2031-10-01 10:00:05', $documents->findById($id)?->notifiedAt()?->format('Y-m-d H:i:s'));
    }

    public function testMarkFailedThenRequeueReturnsToQueuedWithoutFailedAt(): void
    {
        $documents = $this->documents($this->tenantA);
        $id = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $now = new DateTimeImmutable('2031-10-01 10:00:00');

        Assert::false($documents->requeueFailed($id), 'queued is not failed');
        Assert::true($documents->claim($id, $now));
        Assert::true($documents->markFailed($id, 'render_failed', $now->modify('+1 second')));
        Assert::false($documents->markFailed($id, 'render_failed', $now->modify('+2 seconds')));

        $failed = $documents->findById($id);
        Assert::same(GeneratedDocument::STATUS_FAILED, $failed?->status());
        Assert::same('render_failed', $failed?->lastErrorCode());
        Assert::null($this->column($id, 'claimed_at'));
        Assert::notNull($this->column($id, 'failed_at'));

        Assert::true($documents->requeueFailed($id));
        Assert::false($documents->requeueFailed($id));

        $requeued = $documents->findById($id);
        Assert::same(GeneratedDocument::STATUS_QUEUED, $requeued?->status());
        Assert::same(1, $requeued?->version());
        Assert::null($this->column($id, 'failed_at'));
        Assert::null($this->column($id, 'claimed_at'));
        Assert::true($documents->claim($id, $now->modify('+3 seconds')), 'requeued row can be claimed again');
    }

    public function testReleaseClaimClearsClaimAndKeepsErrorCode(): void
    {
        $documents = $this->documents($this->tenantA);
        $id = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $now = new DateTimeImmutable('2031-10-01 10:00:00');

        Assert::true($documents->claim($id, $now));
        Assert::true($documents->releaseClaim($id, 'storage_failed'));
        Assert::null($this->column($id, 'claimed_at'));
        Assert::same('storage_failed', $documents->findById($id)?->lastErrorCode());
        Assert::true($documents->claim($id, $now->modify('+1 second')), 'released claim is free again');
    }

    public function testOtherTenantSeesNothingAndCannotTransition(): void
    {
        $documents = $this->documents($this->tenantA);
        $other = $this->documents($this->tenantB);
        $id = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $now = new DateTimeImmutable('2031-10-01 10:00:00');

        Assert::null($other->findById($id));
        Assert::false($other->claim($id, $now));
        Assert::false($other->markFailed($id, 'x', $now));
        Assert::same([], $other->listForUnit($this->unitId, null, 10));
        Assert::same([], $other->listStaleQueuedIds($now->modify('+1 year'), 10));
        Assert::notNull($documents->findById($id));
    }

    public function testListForUnitNewestFirstWithPatientFilterAndLimit(): void
    {
        $documents = $this->documents($this->tenantA);
        $first = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $second = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $third = (int) $documents->insertNextVersion($this->certificateRequest())->id();

        $ids = array_map(static fn (GeneratedDocument $d): ?int => $d->id(), $documents->listForUnit($this->unitId, null, 10));
        Assert::same([$third, $second, $first], $ids);

        $limited = $documents->listForUnit($this->unitId, $this->patientId, 2);
        Assert::count(2, $limited);
        Assert::same([], $documents->listForUnit($this->unitId, $this->patientId + 1000000, 10));
        Assert::same([], $documents->listForUnit($this->unitId + 1000000, null, 10));
    }

    public function testListStaleQueuedIdsIgnoresFreshClaimAndOtherStatuses(): void
    {
        $documents = $this->documents($this->tenantA);
        $now = new DateTimeImmutable('2031-10-01 10:00:00');
        $neverClaimed = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $freshClaim = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $abandoned = (int) $documents->insertNextVersion($this->cardRequest())->id();
        $failed = (int) $documents->insertNextVersion($this->cardRequest())->id();

        Assert::true($documents->claim($freshClaim, $now->modify('+1 year')));
        Assert::true($documents->claim($abandoned, $now));
        Assert::true($documents->markFailed($failed, 'render_failed', $now));

        $stale = $documents->listStaleQueuedIds($now->modify('+1 year -10 minutes'), 10);
        Assert::same([$neverClaimed, $abandoned], $stale);
        Assert::count(1, $documents->listStaleQueuedIds($now->modify('+1 year -10 minutes'), 1));
    }

    public function testTemplateListActiveIgnoresInactiveAndOtherTenant(): void
    {
        $templates = $this->templates($this->tenantA);
        $active = $templates->save(DocumentTemplate::create($this->tenantA, DocumentKind::MEDICAL_CERTIFICATE, 'F7B teste B ativo', 'F7B teste atestado {{patient_name}}', $this->userId));
        $inactive = $templates->save(DocumentTemplate::create($this->tenantA, DocumentKind::MEDICAL_CERTIFICATE, 'F7B teste A inativo', 'F7B teste atestado', $this->userId));
        $inactive->update('F7B teste A inativo', 'F7B teste atestado', DocumentTemplate::STATUS_INACTIVE, $this->userId);
        $templates->save($inactive);

        Assert::true((int) $active->id() > 0);

        $listed = $templates->listActive(DocumentKind::MEDICAL_CERTIFICATE);
        Assert::count(1, $listed);
        Assert::same($active->id(), $listed[0]->id());

        $all = array_map(static fn (DocumentTemplate $t): string => $t->name(), $templates->listAll());
        Assert::same(['F7B teste A inativo', 'F7B teste B ativo'], $all);

        $reloaded = $templates->findById((int) $inactive->id());
        Assert::same(DocumentTemplate::STATUS_INACTIVE, $reloaded?->status());
        Assert::same($this->userId, $reloaded?->updatedBySystemUserId());

        $other = $this->templates($this->tenantB);
        Assert::null($other->findById((int) $active->id()));
        Assert::same([], $other->listActive(DocumentKind::MEDICAL_CERTIFICATE));
        Assert::same([], $other->listAll());
    }

    private function cardRequest(): GeneratedDocument
    {
        return GeneratedDocument::request(
            $this->tenantA,
            $this->unitId,
            $this->patientId,
            $this->tutorId,
            DocumentKind::VACCINATION_CARD,
            $this->patientId,
            null,
            null,
            false,
            $this->userId,
        );
    }

    private function certificateRequest(): GeneratedDocument
    {
        return GeneratedDocument::request(
            $this->tenantA,
            $this->unitId,
            $this->patientId,
            $this->tutorId,
            DocumentKind::MEDICAL_CERTIFICATE,
            $this->patientId,
            null,
            'F7B teste atestado',
            true,
            $this->userId,
        );
    }

    private function documents(int $tenantId): GeneratedDocumentRepository
    {
        return new GeneratedDocumentRepository($this->contextFor($tenantId), $this->pdo);
    }

    private function templates(int $tenantId): DocumentTemplateRepository
    {
        return new DocumentTemplateRepository($this->contextFor($tenantId), $this->pdo);
    }

    private function column(int $id, string $column): ?string
    {
        $value = $this->pdo->query("SELECT {$column} FROM generated_document WHERE id = " . $id)->fetchColumn();

        return $value === null || $value === false ? null : (string) $value;
    }

    private function createStoredObject(int $tenantId): int
    {
        $this->pdo->prepare(
            'INSERT INTO stored_object (public_id, tenant_id, system_unit_id, storage_provider, bucket, object_key, '
            . 'original_name, content_type, size_bytes, sha256, status, created_by) '
            . 'VALUES (UUID(), :t, :u, :p, :b, :k, :n, :c, :s, :h, :st, :by)',
        )->execute([
            't' => $tenantId,
            'u' => $this->unitId,
            'p' => 'local',
            'b' => 'local',
            'k' => 'cv/test/f7b-' . bin2hex(random_bytes(6)) . '.pdf',
            'n' => 'vaccination_card-0-v1.pdf',
            'c' => 'application/pdf',
            's' => 1234,
            'h' => str_repeat('a', 64),
            'st' => 'available',
            'by' => $this->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function contextFor(int $tenantId): TenantContext
    {
        return TenantContext::authenticated($tenantId, $this->userId, $this->unitId);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'F7B teste tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
