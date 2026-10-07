<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\DocumentTemplateService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\DocumentTemplate;
use CentralVet\Domain\DocumentTemplateDefaults;
use CentralVet\Domain\DocumentTemplateRenderer;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\DocumentSourceNotFoundException;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeDocumentSourceQuery;
use CentralVet\Tests\Support\FakeDocumentTemplateRepository;
use CentralVet\Tests\Support\FakeSenderNamesQuery;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;

/**
 * Unit tests for DocumentTemplateService (T-13): tenant-wide catalog of
 * medical certificate templates (closed placeholders, unique name, status)
 * and the merge of a template (or the built-in default) with the patient,
 * unit and clinic names and today's date.
 */
final class DocumentTemplateServiceTest
{
    private const ACTION = 'test::document_template';
    private const TENANT_ID = 1;
    private const USER_ID = 7;
    private const UNIT_ID = 3;
    private const PATIENT_ID = 50;

    /** @return array{0: DocumentTemplateService, 1: FakeDocumentTemplateRepository, 2: FakeAuthorizationPolicy} */
    private function build(bool $allowed = true, DocumentTemplate ...$seed): array
    {
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
        $templates = new FakeDocumentTemplateRepository($context);

        foreach ($seed as $template) {
            $templates->seed($template);
        }

        $sources = new FakeDocumentSourceQuery();
        $sources->seedPatient([
            'patient_id' => self::PATIENT_ID,
            'patient_name' => 'F7B teste Rex',
            'species' => 'Canina',
            'breed' => 'SRD',
            'tutor_id' => 9,
            'tutor_name' => 'F7B teste Tutor',
        ]);
        $names = new FakeSenderNamesQuery([self::UNIT_ID => ['unit_name' => 'Unidade Centro', 'clinic_name' => 'Clínica F7B']]);
        $policy = new FakeAuthorizationPolicy(allowed: $allowed);
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-06 10:00:00');

        return [new DocumentTemplateService($templates, $sources, $names, $policy, $context, $clock), $templates, $policy];
    }

    private static function template(int $id, string $name, string $body, string $status = DocumentTemplate::STATUS_ACTIVE): DocumentTemplate
    {
        return DocumentTemplate::reconstitute([
            'id' => $id,
            'tenant_id' => self::TENANT_ID,
            'kind' => DocumentKind::MEDICAL_CERTIFICATE,
            'name' => $name,
            'body_text' => $body,
            'status' => $status,
            'created_by_system_user_id' => self::USER_ID,
        ]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function data(array $overrides = []): array
    {
        return $overrides + [
            'kind' => DocumentKind::MEDICAL_CERTIFICATE,
            'name' => '  F7B teste atestado  ',
            'body_text' => 'Atesto que {{patient_name}} ({{species}}) está apto. {{today}}',
            'status' => 'active',
        ];
    }

    /** @param class-string<\Throwable> $class */
    private static function expectMessage(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Assert::instanceOf($class, $e, "Expected {$class}, got " . $e::class . ': ' . $e->getMessage());
            Assert::same($message, $e->getMessage());

            return;
        }

        Assert::true(false, "Expected exception {$class} was not thrown");
    }

    public function testUnknownPlaceholderIsRejected(): void
    {
        [$service, $templates] = $this->build();

        self::expectMessage(
            InvalidArgumentException::class,
            'Unknown placeholder: {{cpf}}',
            fn () => $service->save(self::data(['body_text' => 'CPF {{cpf}} de {{patient_name}}']), self::ACTION),
        );
        Assert::count(0, $templates->listAll());
    }

    public function testValidSaveStoresActiveTemplate(): void
    {
        [$service, $templates, $policy] = $this->build();

        $template = $service->save(self::data(), self::ACTION);

        Assert::notNull($template->id());
        Assert::true($template->isActive());
        Assert::same('F7B teste atestado', $template->name());
        Assert::same(self::USER_ID, $template->createdBySystemUserId());
        Assert::count(1, $templates->listActive(DocumentKind::MEDICAL_CERTIFICATE));
        Assert::same('document_template', $policy->requests[0]->entityType());
        Assert::false($policy->requests[0]->requiresUnitScope());
    }

    public function testSaveCreatesInactiveTemplate(): void
    {
        [$service, $templates] = $this->build();

        $template = $service->save(self::data(['status' => 'inactive']), self::ACTION);

        Assert::false($template->isActive());
        Assert::count(0, $templates->listActive(DocumentKind::MEDICAL_CERTIFICATE));
        Assert::count(1, $templates->listAll());
    }

    public function testSaveUpdatesExistingTemplate(): void
    {
        [$service, $templates] = $this->build(true, self::template(1, 'F7B teste antigo', 'Texto {{patient_name}}'));

        $template = $service->save(self::data(['id' => 1, 'name' => 'F7B teste novo', 'status' => 'inactive']), self::ACTION);

        Assert::same(1, $template->id());
        Assert::same('F7B teste novo', $templates->findById(1)->name());
        Assert::false($templates->findById(1)->isActive());
        Assert::same(self::USER_ID, $templates->findById(1)->updatedBySystemUserId());
        Assert::count(1, $templates->listAll());
    }

    public function testKindOtherThanMedicalCertificateIsRejected(): void
    {
        [$service, $templates] = $this->build();

        Assert::throws(InvalidArgumentException::class, fn () => $service->save(self::data(['kind' => DocumentKind::PRESCRIPTION]), self::ACTION));
        Assert::count(0, $templates->listAll());
    }

    public function testInvalidNameAndBodyAreRejected(): void
    {
        [$service, $templates] = $this->build();

        Assert::throws(InvalidArgumentException::class, fn () => $service->save(self::data(['name' => '   ']), self::ACTION));
        Assert::throws(InvalidArgumentException::class, fn () => $service->save(self::data(['name' => str_repeat('a', 121)]), self::ACTION));
        Assert::throws(InvalidArgumentException::class, fn () => $service->save(self::data(['body_text' => '']), self::ACTION));
        Assert::throws(InvalidArgumentException::class, fn () => $service->save(self::data(['body_text' => str_repeat('a', 20001)]), self::ACTION));
        Assert::count(0, $templates->listAll());
    }

    public function testDuplicateNameInTenantIsRejected(): void
    {
        [$service, $templates] = $this->build(true, self::template(1, 'F7B teste atestado', 'Texto {{patient_name}}'));

        self::expectMessage(
            InvalidArgumentException::class,
            'A document template with this name already exists',
            fn () => $service->save(self::data(), self::ACTION),
        );
        Assert::count(1, $templates->listAll());

        $service->save(self::data(['id' => 1]), self::ACTION);
        Assert::count(1, $templates->listAll());
    }

    public function testDeniedSaveDoesNotWrite(): void
    {
        [$service, $templates] = $this->build(false);

        Assert::throws(AuthorizationDenied::class, fn () => $service->save(self::data(), self::ACTION));
        Assert::count(0, $templates->listAll());
    }

    public function testListAllAndListActive(): void
    {
        [$service, , $policy] = $this->build(
            true,
            self::template(1, 'F7B teste B', 'Texto {{patient_name}}'),
            self::template(2, 'F7B teste A', 'Texto {{species}}', DocumentTemplate::STATUS_INACTIVE),
        );

        Assert::count(2, $service->listAll(self::ACTION));
        $active = $service->listActive(DocumentKind::MEDICAL_CERTIFICATE, self::ACTION);
        Assert::count(1, $active);
        Assert::same(1, $active[0]->id());
        Assert::count(2, $policy->requests);
    }

    public function testMergeWithoutTemplateUsesDefaultBody(): void
    {
        [$service] = $this->build();

        $text = $service->mergeForPatient(0, self::PATIENT_ID, self::ACTION);

        $expected = DocumentTemplateRenderer::render(DocumentTemplateDefaults::bodyFor(DocumentKind::MEDICAL_CERTIFICATE), [
            'patient_name' => 'F7B teste Rex',
            'species' => 'Canina',
            'tutor_name' => 'F7B teste Tutor',
            'today' => '06/10/2026',
        ]);
        Assert::same($expected, $text);
        Assert::stringContains('F7B teste Rex', $text);
        Assert::false(str_contains($text, '{{patient_name}}'));
    }

    public function testMergeWithActiveTemplateFillsEveryVariable(): void
    {
        [$service] = $this->build(true, self::template(
            1,
            'F7B teste completo',
            '{{patient_name}}|{{species}}|{{breed}}|{{tutor_name}}|{{unit_name}}|{{clinic_name}}|{{today}}',
        ));

        Assert::same(
            'F7B teste Rex|Canina|SRD|F7B teste Tutor|Unidade Centro|Clínica F7B|06/10/2026',
            $service->mergeForPatient(1, self::PATIENT_ID, self::ACTION),
        );
    }

    public function testMergeWithInactiveTemplateIsRejected(): void
    {
        [$service] = $this->build(true, self::template(1, 'F7B teste inativo', 'Texto {{patient_name}}', DocumentTemplate::STATUS_INACTIVE));

        Assert::throws(InvalidArgumentException::class, fn () => $service->mergeForPatient(1, self::PATIENT_ID, self::ACTION));
    }

    public function testMergeWithUnknownPatientIsRejected(): void
    {
        [$service] = $this->build();

        Assert::throws(DocumentSourceNotFoundException::class, fn () => $service->mergeForPatient(0, 999, self::ACTION));
    }

    public function testDeniedMergeThrows(): void
    {
        [$service] = $this->build(false);

        Assert::throws(AuthorizationDenied::class, fn () => $service->mergeForPatient(0, self::PATIENT_ID, self::ACTION));
    }

    public function testFindByIdReturnsTenantTemplateAndRejectsUnknownId(): void
    {
        [$service, , $policy] = $this->build(true, self::template(1, 'F7B teste busca', 'Texto {{patient_name}}'));

        $template = $service->findById(1, self::ACTION);

        Assert::same(1, $template->id());
        Assert::same('F7B teste busca', $template->name());
        Assert::count(1, $policy->requests);
        Assert::same(1, $policy->requests[0]->entityId());

        self::expectMessage(
            CrossTenantReferenceException::class,
            'template_id 999 was not found for the authenticated tenant',
            fn () => $service->findById(999, self::ACTION),
        );
    }

    public function testDeniedFindByIdThrows(): void
    {
        [$service] = $this->build(false, self::template(1, 'F7B teste busca', 'Texto {{patient_name}}'));

        Assert::throws(AuthorizationDenied::class, fn () => $service->findById(1, self::ACTION));
    }

    public function testDuplicateKeyFromTheDatabaseBecomesTheDuplicateNameMessage(): void
    {
        $service = $this->serviceSavingWith(self::pdoException(1062));

        self::expectMessage(
            InvalidArgumentException::class,
            'A document template with this name already exists',
            fn () => $service->save(self::data(), self::ACTION),
        );
    }

    public function testOtherDatabaseErrorsAreRethrown(): void
    {
        $error = self::pdoException(1213);
        $service = $this->serviceSavingWith($error);
        $caught = null;

        try {
            $service->save(self::data(), self::ACTION);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::same($error, $caught);
    }

    private static function pdoException(int $driverCode): PDOException
    {
        $exception = new PDOException("SQLSTATE driver error {$driverCode}");
        $exception->errorInfo = ['23000', $driverCode, 'driver message'];

        return $exception;
    }

    /** A service whose repository has no templates and fails every save with $error (race after the name check). */
    private function serviceSavingWith(PDOException $error): DocumentTemplateService
    {
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
        $templates = new class ($error) implements \CentralVet\Domain\Contract\DocumentTemplateRepositoryInterface {
            public function __construct(private readonly PDOException $error)
            {
            }

            public function findById(int $id): ?DocumentTemplate
            {
                return null;
            }

            public function listAll(): array
            {
                return [];
            }

            public function listActive(string $kind): array
            {
                return [];
            }

            public function save(DocumentTemplate $template): DocumentTemplate
            {
                throw $this->error;
            }
        };

        return new DocumentTemplateService(
            $templates,
            new FakeDocumentSourceQuery(),
            new FakeSenderNamesQuery([]),
            new FakeAuthorizationPolicy(),
            $context,
        );
    }
}
