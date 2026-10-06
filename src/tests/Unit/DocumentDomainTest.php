<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\DocumentContent;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\DocumentTemplate;
use CentralVet\Domain\DocumentTemplateDefaults;
use CentralVet\Domain\DocumentTemplateRenderer;
use CentralVet\Domain\Exception\DocumentGenerationFailed;
use CentralVet\Domain\Exception\DocumentNotAvailableException;
use CentralVet\Domain\Exception\DocumentSourceNotFoundException;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

final class DocumentDomainTest
{
    public function testSourceTypeForEachKind(): void
    {
        Assert::same('surgery', DocumentKind::sourceTypeFor('surgery_consent'));
        Assert::same('patient', DocumentKind::sourceTypeFor('vaccination_card'));
        Assert::same('patient', DocumentKind::sourceTypeFor('medical_certificate'));
        Assert::same('prescription', DocumentKind::sourceTypeFor('prescription'));
        Assert::count(4, DocumentKind::ALL);
    }

    public function testKindTitlesAndTemplateUse(): void
    {
        Assert::same('Atestado', DocumentKind::titleFor(DocumentKind::MEDICAL_CERTIFICATE));
        Assert::same('Termo de consentimento cirúrgico', DocumentKind::titleFor(DocumentKind::SURGERY_CONSENT));
        Assert::true(DocumentKind::usesTemplate(DocumentKind::MEDICAL_CERTIFICATE));
        Assert::false(DocumentKind::usesTemplate(DocumentKind::PRESCRIPTION));
    }

    public function testUnknownKindIsRejected(): void
    {
        Assert::throws(InvalidArgumentException::class, fn () => DocumentKind::assertValid('budget'));
    }

    public function testMedicalCertificateWithEmptyBodyIsRejected(): void
    {
        Assert::throws(InvalidArgumentException::class, fn () => self::request('medical_certificate', '   '));
        Assert::throws(InvalidArgumentException::class, fn () => self::request('surgery_consent', null));
    }

    public function testBodyIsRejectedForKindsWithoutText(): void
    {
        Assert::throws(InvalidArgumentException::class, fn () => self::request('vaccination_card', 'texto'));
    }

    public function testBodyLongerThanLimitIsRejected(): void
    {
        Assert::throws(InvalidArgumentException::class, fn () => self::request('medical_certificate', str_repeat('a', 20001)));
    }

    public function testRequestStartsQueuedWithDerivedTitleAndSource(): void
    {
        $document = self::request('surgery_consent', 'Termo assinado');

        Assert::null($document->id());
        Assert::same(GeneratedDocument::STATUS_QUEUED, $document->status());
        Assert::same(0, $document->version());
        Assert::same(0, $document->attemptCount());
        Assert::same('surgery', $document->sourceType());
        Assert::same('Termo de consentimento cirúrgico', $document->title());
        Assert::false($document->isDownloadable());
    }

    public function testFileNameAfterAssignIdentity(): void
    {
        $document = self::request('vaccination_card', null);
        $document->assignIdentity(42, 3);

        Assert::same(42, $document->id());
        Assert::same(3, $document->version());
        Assert::same('vaccination_card-42-v3.pdf', $document->fileName());
    }

    public function testReadyDocumentWithKeyIsDownloadable(): void
    {
        $document = GeneratedDocument::reconstitute([
            'id' => 7, 'tenant_id' => 1, 'system_unit_id' => 2, 'patient_id' => 3, 'tutor_id' => 4,
            'kind' => 'prescription', 'source_type' => 'prescription', 'source_id' => 9, 'version' => 1,
            'template_id' => null, 'title' => 'Receita', 'body_text' => null, 'notify_tutor' => 1,
            'status' => 'ready', 'attempt_count' => 1, 'stored_object_id' => 11,
            'storage_key' => 'documents/1/7.pdf', 'ready_at' => '2026-10-06 10:00:00',
            'requested_by_system_user_id' => 5, 'created_at' => '2026-10-06 09:59:00',
        ]);

        Assert::true($document->isDownloadable());
        Assert::true($document->notifyTutor());
        Assert::same('prescription-7-v1.pdf', $document->fileName());
    }

    public function testUnknownPlaceholders(): void
    {
        Assert::same(['cpf'], DocumentTemplateRenderer::unknownPlaceholders('{{cpf}} {{patient_name}}'));
    }

    public function testRenderKeepsMissingValuesAndReportsUnresolved(): void
    {
        $text = DocumentTemplateRenderer::render('{{patient_name}} / {{tutor_name}}', ['patient_name' => 'Rex']);

        Assert::same('Rex / {{tutor_name}}', $text);
        Assert::same(['tutor_name'], DocumentTemplateRenderer::unresolvedPlaceholders($text));
    }

    public function testDefaultCertificateBodyUsesExpectedPlaceholders(): void
    {
        $body = DocumentTemplateDefaults::bodyFor('medical_certificate');

        foreach (['patient_name', 'species', 'tutor_name', 'today'] as $name) {
            Assert::stringContains('{{' . $name . '}}', $body);
        }

        Assert::same([], DocumentTemplateRenderer::unknownPlaceholders($body));
    }

    public function testTemplateRejectsUnknownPlaceholderAndOtherKinds(): void
    {
        $template = DocumentTemplate::create(1, 'medical_certificate', 'Atestado padrão', 'Atesto que {{patient_name}}', 5);

        Assert::true($template->isActive());
        Assert::throws(InvalidArgumentException::class, fn () => DocumentTemplate::create(1, 'medical_certificate', 'X', '{{cpf}}', 5));
        Assert::throws(InvalidArgumentException::class, fn () => DocumentTemplate::create(1, 'prescription', 'X', 'texto', 5));

        $template->update('Atestado', 'Atesto {{species}}', DocumentTemplate::STATUS_INACTIVE, 6);
        Assert::false($template->isActive());
    }

    public function testGenerationFailedExposesCode(): void
    {
        $exception = new DocumentGenerationFailed(DocumentGenerationFailed::RENDER_FAILED);

        Assert::same('render_failed', $exception->errorCode());
        Assert::same('Document generation failed: render_failed', $exception->getMessage());
        Assert::same('Document not found', (new DocumentNotAvailableException())->getMessage());
        Assert::same('Document source not found', (new DocumentSourceNotFoundException())->getMessage());
    }

    public function testContentExposesReadonlyProperties(): void
    {
        $content = new DocumentContent('Receita', 'Clínica', 'Unidade', ['Paciente: X'], ['p'], ['A'], [['1']], null, new DateTimeImmutable('2026-10-06'));

        Assert::same('Receita', $content->title);
        Assert::same([['1']], $content->tableRows);
        Assert::null($content->signatureName);
    }

    private static function request(string $kind, ?string $body): GeneratedDocument
    {
        return GeneratedDocument::request(1, 2, 3, 4, $kind, 9, null, $body, false, 5);
    }
}
