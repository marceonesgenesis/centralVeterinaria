<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\DocumentSourceQueryInterface;
use CentralVet\Domain\Contract\DocumentTemplateRepositoryInterface;
use CentralVet\Domain\Contract\SenderNamesQueryInterface;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\DocumentTemplate;
use CentralVet\Domain\DocumentTemplateDefaults;
use CentralVet\Domain\DocumentTemplateRenderer;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\DocumentSourceNotFoundException;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;

/**
 * Tenant-wide catalog of document templates (T-13, Fase 7B): list, create
 * or edit (only `medical_certificate`, closed placeholders, name unique in
 * the tenant) and merge a template, or the built-in default, with the
 * patient, unit and clinic names and today's date. Templates belong to the
 * tenant, so authorization does not require a unit scope. No transaction is
 * opened: the controller owns it.
 */
final class DocumentTemplateService
{
    private const ENTITY_TYPE = 'document_template';
    private const STATUSES = [DocumentTemplate::STATUS_ACTIVE, DocumentTemplate::STATUS_INACTIVE];
    private const DUPLICATE_NAME = 'A document template with this name already exists';
    private const MYSQL_DUPLICATE_KEY = 1062;

    private readonly Closure $clock;

    public function __construct(
        private readonly DocumentTemplateRepositoryInterface $templates,
        private readonly DocumentSourceQueryInterface $sources,
        private readonly SenderNamesQueryInterface $names,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Creates (no `id`) or edits (`id`) a template.
     *
     * @param array<string, mixed> $data keys `id` (optional), `kind`, `name`, `body_text`, `status`
     *
     * @throws InvalidArgumentException for invalid fields, unknown placeholders or a duplicate name.
     * @throws CrossTenantReferenceException when `id` is not a template of the tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied (nothing is written)
     */
    public function save(array $data, string $action): DocumentTemplate
    {
        $id = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : null;
        $this->authorize($action, $id);

        $status = (string) ($data['status'] ?? DocumentTemplate::STATUS_ACTIVE);

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown document template status \"{$status}\"");
        }

        $kind = (string) ($data['kind'] ?? '');
        DocumentKind::assertValid($kind);

        if ($kind !== DocumentKind::MEDICAL_CERTIFICATE) {
            throw new InvalidArgumentException("Document kind \"{$kind}\" does not use templates");
        }

        $name = trim((string) ($data['name'] ?? ''));
        $bodyText = (string) ($data['body_text'] ?? '');
        $unknown = DocumentTemplateRenderer::unknownPlaceholders($bodyText);

        if ($unknown !== []) {
            throw new InvalidArgumentException("Unknown placeholder: {{{$unknown[0]}}}");
        }

        if ($id !== null) {
            $template = $this->templates->findById($id);

            if (!$template instanceof DocumentTemplate || $template->kind() !== $kind) {
                throw new CrossTenantReferenceException("template_id {$id} was not found for the authenticated tenant");
            }

            $template->update($name, $bodyText, $status, $this->context->userId());
        } else {
            $template = DocumentTemplate::create($this->context->tenantId(), $kind, $name, $bodyText, $this->context->userId());

            if ($status === DocumentTemplate::STATUS_INACTIVE) {
                $template->update($name, $bodyText, $status, $this->context->userId());
            }
        }

        $this->assertUniqueName($template);

        try {
            return $this->templates->save($template);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_KEY) {
                throw new InvalidArgumentException(self::DUPLICATE_NAME, 0, $e);
            }

            throw $e;
        }
    }

    /**
     * One template of the tenant, any status.
     *
     * @throws CrossTenantReferenceException when `$id` is not a template of the tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied (nothing is read)
     */
    public function findById(int $id, string $action): DocumentTemplate
    {
        $this->authorize($action, $id);

        $template = $this->templates->findById($id);

        if (!$template instanceof DocumentTemplate) {
            throw new CrossTenantReferenceException("template_id {$id} was not found for the authenticated tenant");
        }

        return $template;
    }

    /** @return list<DocumentTemplate> every template of the tenant, any status, ordered by name. */
    public function listAll(string $action): array
    {
        $this->authorize($action, null);

        return array_values($this->templates->listAll());
    }

    /**
     * Active templates of one kind. Empty means the caller falls back to
     * `DocumentTemplateDefaults::bodyFor()` (or `mergeForPatient(0, ...)`).
     *
     * @return list<DocumentTemplate>
     */
    public function listActive(string $kind, string $action): array
    {
        $this->authorize($action, null);
        DocumentKind::assertValid($kind);

        return array_values($this->templates->listActive($kind));
    }

    /**
     * Merges the active template `$templateId` (0 = built-in default of the
     * medical certificate) with the patient summary, the names of the active
     * unit and today's date (`d/m/Y`). A variable without value stays as
     * `{{name}}`, except an optional one (the breed), which becomes `—`
     * (see DocumentTemplateRenderer).
     *
     * @throws InvalidArgumentException when the template is missing, foreign or inactive.
     * @throws DocumentSourceNotFoundException when the patient is not in the tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function mergeForPatient(int $templateId, int $patientId, string $action): string
    {
        $this->authorize($action, $templateId > 0 ? $templateId : null);

        $body = $this->bodyFor($templateId);
        $patient = $this->sources->patientSummary($patientId);

        if ($patient === null) {
            throw new DocumentSourceNotFoundException();
        }

        $unitId = $this->context->unitId();
        $names = $unitId !== null ? $this->names->namesForUnit($unitId) : ['unit_name' => null, 'clinic_name' => null];

        return DocumentTemplateRenderer::render($body, [
            'patient_name' => $patient['patient_name'],
            'species' => $patient['species'],
            'breed' => $patient['breed'],
            'tutor_name' => $patient['tutor_name'],
            'unit_name' => $names['unit_name'],
            'clinic_name' => $names['clinic_name'],
            'today' => ($this->clock)()->format('d/m/Y'),
        ]);
    }

    private function bodyFor(int $templateId): string
    {
        if ($templateId === 0) {
            return DocumentTemplateDefaults::bodyFor(DocumentKind::MEDICAL_CERTIFICATE);
        }

        $template = $templateId > 0 ? $this->templates->findById($templateId) : null;

        if (!$template instanceof DocumentTemplate || !$template->isActive()) {
            throw new InvalidArgumentException('Document template is not available');
        }

        return $template->bodyText();
    }

    private function assertUniqueName(DocumentTemplate $template): void
    {
        $name = mb_strtolower($template->name());

        foreach ($this->templates->listAll() as $other) {
            if ($other->id() !== $template->id() && mb_strtolower($other->name()) === $name) {
                throw new InvalidArgumentException(self::DUPLICATE_NAME);
            }
        }
    }

    private function authorize(string $action, ?int $templateId): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: false,
            entityType: self::ENTITY_TYPE,
            entityId: $templateId,
        ))->assertAllowed();
    }
}
