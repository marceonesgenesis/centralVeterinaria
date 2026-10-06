<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\DocumentContent;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\DocumentTemplate;
use CentralVet\Domain\Exception\DocumentGenerationFailed;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeDocumentContentFactory;
use CentralVet\Tests\Support\FakeDocumentRenderer;
use CentralVet\Tests\Support\FakeDocumentSourceQuery;
use CentralVet\Tests\Support\FakeDocumentTemplateRepository;
use CentralVet\Tests\Support\FakeGeneratedDocumentRepository;
use CentralVet\Tests\Support\FakeSenderNamesQuery;
use DateTimeImmutable;
use RuntimeException;

/**
 * Document test doubles (T-05): the semantics the services of the Fase 7B
 * rely on (versions per source, conditional transitions, tenant filter,
 * recorded calls and injected failures).
 */
final class DocumentFakesTest
{
    private const TENANT_ID = 1;
    private const OTHER_TENANT_ID = 2;
    private const UNIT_ID = 5;
    private const USER_ID = 3;

    private static function context(int $tenantId = self::TENANT_ID): TenantContext
    {
        return TenantContext::authenticated($tenantId, self::USER_ID, self::UNIT_ID);
    }

    private static function card(int $patientId = 10, int $tenantId = self::TENANT_ID): GeneratedDocument
    {
        return GeneratedDocument::request($tenantId, self::UNIT_ID, $patientId, 7, DocumentKind::VACCINATION_CARD, $patientId, null, null, false, self::USER_ID);
    }

    private static function content(string $title = 'Receita'): DocumentContent
    {
        return new DocumentContent($title, 'Clinic', 'Unit', [], [], [], [], null, new DateTimeImmutable('2026-10-06 10:00:00'));
    }

    public function testInsertNextVersionNumbersPerSource(): void
    {
        $repo = new FakeGeneratedDocumentRepository(self::context());

        $first = $repo->insertNextVersion(self::card());
        $second = $repo->insertNextVersion(self::card());
        $otherSource = $repo->insertNextVersion(self::card(11));

        Assert::same(1, $first->version());
        Assert::same(2, $second->version());
        Assert::same(1, $otherSource->version());
        Assert::false($first->id() === $second->id());
        Assert::count(3, $repo->all());
    }

    public function testClaimTwiceAtTheSameInstantOnlyWinsOnce(): void
    {
        $repo = new FakeGeneratedDocumentRepository(self::context());
        $id = (int) $repo->insertNextVersion(self::card())->id();
        $now = new DateTimeImmutable('2026-10-06 10:00:00');

        Assert::true($repo->claim($id, $now));
        Assert::false($repo->claim($id, $now));
        Assert::same(1, $repo->findById($id)->attemptCount());
        Assert::true($repo->claim($id, $now->modify('+11 minutes')), 'abandoned claim is taken over after 10 min');
        Assert::same(2, $repo->findById($id)->attemptCount());
    }

    public function testSimulateConcurrentClaimBlocksTheNextClaim(): void
    {
        $repo = new FakeGeneratedDocumentRepository(self::context());
        $id = (int) $repo->insertNextVersion(self::card())->id();

        $repo->simulateConcurrentClaim($id);

        Assert::false($repo->claim($id, new DateTimeImmutable()));
    }

    public function testMarkReadyOfFailedDocumentReturnsFalse(): void
    {
        $repo = new FakeGeneratedDocumentRepository(self::context());
        $id = (int) $repo->insertNextVersion(self::card())->id();
        $now = new DateTimeImmutable('2026-10-06 10:00:00');

        Assert::false($repo->markReady($id, 9, 'key', 10, str_repeat('a', 64), $now), 'not claimed yet');
        Assert::true($repo->claim($id, $now));
        Assert::true($repo->markFailed($id, DocumentGenerationFailed::RENDER_FAILED, $now));
        Assert::same(GeneratedDocument::STATUS_FAILED, $repo->findById($id)->status());
        Assert::false($repo->markReady($id, 9, 'key', 10, str_repeat('a', 64), $now));

        Assert::true($repo->requeueFailed($id));
        Assert::true($repo->claim($id, $now));
        Assert::true($repo->markReady($id, 9, 'key', 10, str_repeat('a', 64), $now));
        $ready = $repo->findById($id);
        Assert::true($ready->isDownloadable());
        Assert::null($ready->lastErrorCode());
        Assert::true($repo->markNotified($id, $now));
        Assert::false($repo->markNotified($id, $now), 'already notified');
    }

    public function testListStaleQueuedIdsIgnoresOtherTenant(): void
    {
        $repo = new FakeGeneratedDocumentRepository(self::context());
        $old = new DateTimeImmutable('2026-10-06 09:00:00');
        $other = GeneratedDocument::reconstitute([
            'id' => 50, 'tenant_id' => self::OTHER_TENANT_ID, 'system_unit_id' => self::UNIT_ID, 'patient_id' => 10,
            'tutor_id' => 7, 'kind' => DocumentKind::VACCINATION_CARD, 'source_type' => 'patient', 'source_id' => 10,
            'version' => 1, 'title' => 'Carteira de vacinação', 'status' => GeneratedDocument::STATUS_QUEUED,
            'requested_by_system_user_id' => self::USER_ID, 'created_at' => $old->format('Y-m-d H:i:s'),
        ]);
        $repo->seed($other);
        $own = GeneratedDocument::reconstitute([
            'id' => 51, 'tenant_id' => self::TENANT_ID, 'system_unit_id' => self::UNIT_ID, 'patient_id' => 10,
            'tutor_id' => 7, 'kind' => DocumentKind::VACCINATION_CARD, 'source_type' => 'patient', 'source_id' => 10,
            'version' => 1, 'title' => 'Carteira de vacinação', 'status' => GeneratedDocument::STATUS_QUEUED,
            'requested_by_system_user_id' => self::USER_ID, 'created_at' => $old->format('Y-m-d H:i:s'),
        ]);
        $repo->seed($own);

        Assert::same([51], $repo->listStaleQueuedIds(new DateTimeImmutable('2026-10-06 09:30:00'), 10));
        Assert::null($repo->findById(50), 'other tenant is invisible');
        Assert::false($repo->claim(50, new DateTimeImmutable()));
        Assert::count(1, $repo->listForUnit(self::UNIT_ID, null, 10));
    }

    public function testRendererFailWithMakesRenderThrow(): void
    {
        $renderer = new FakeDocumentRenderer();

        Assert::same('%PDF-FAKE Receita', $renderer->render(self::content()));
        $renderer->failWith(new RuntimeException('boom'));
        Assert::throws(RuntimeException::class, static fn () => $renderer->render(self::content()));
        Assert::count(2, $renderer->calls());
        Assert::same('%PDF-FAKE Atestado', $renderer->render(self::content('Atestado')), 'failure is one-shot');
    }

    public function testContentFactoryBuildsTitleAndFailsOnDemand(): void
    {
        $factory = new FakeDocumentContentFactory();
        $document = self::card();

        Assert::same($document->title(), $factory->build($document)->title);
        $factory->failWith(new DocumentGenerationFailed(DocumentGenerationFailed::SOURCE_NOT_FOUND));
        Assert::throws(DocumentGenerationFailed::class, static fn () => $factory->build($document));
    }

    public function testTemplateRepositoryFiltersTenantAndKind(): void
    {
        $repo = new FakeDocumentTemplateRepository(self::context());
        $saved = $repo->save(DocumentTemplate::create(self::TENANT_ID, DocumentKind::MEDICAL_CERTIFICATE, 'B', 'Texto {{patient_name}}', self::USER_ID));
        $repo->seed(DocumentTemplate::create(self::OTHER_TENANT_ID, DocumentKind::MEDICAL_CERTIFICATE, 'A', 'Texto', self::USER_ID));

        Assert::notNull($saved->id());
        Assert::count(1, $repo->listAll());
        Assert::count(1, $repo->listActive(DocumentKind::MEDICAL_CERTIFICATE));
        Assert::notNull($repo->findById((int) $saved->id()));
    }

    public function testSourceQueryReturnsSeededShapes(): void
    {
        $query = new FakeDocumentSourceQuery();
        $query->seedPatient(['patient_id' => 10, 'patient_name' => 'F7B teste', 'species' => 'Canina', 'breed' => null, 'tutor_id' => 7, 'tutor_name' => 'F7B teste']);
        $query->seedVaccinations(10, [['vaccine_name' => 'V10', 'dose_number' => 1, 'applied_at' => '2026-01-01', 'lot' => null, 'next_dose_at' => null, 'professional_name' => 'Vet']]);
        $query->seedTutorContact(7, ['tutor_name' => 'F7B teste', 'email' => 'f7b.teste@example.invalid', 'phone' => '5585999990000']);

        Assert::same(7, $query->patientSummary(10)['tutor_id']);
        Assert::null($query->patientSummary(99));
        Assert::count(1, $query->vaccinations(10));
        Assert::same([], $query->vaccinations(99));
        Assert::null($query->prescription(1));
        Assert::null($query->surgery(1));
        Assert::notNull($query->tutorContact(7));
    }

    public function testSenderNamesQueryReturnsNullsForUnknownUnit(): void
    {
        $query = new FakeSenderNamesQuery([self::UNIT_ID => ['unit_name' => 'Unit', 'clinic_name' => 'Clinic']]);

        Assert::same('Unit', $query->namesForUnit(self::UNIT_ID)['unit_name']);
        Assert::same(['unit_name' => null, 'clinic_name' => null], $query->namesForUnit(99));
    }
}
