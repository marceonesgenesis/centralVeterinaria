<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\DocumentTemplateRepositoryInterface;
use CentralVet\Domain\DocumentTemplate;
use CentralVet\Tenancy\TenantContext;
use PDO;

/**
 * PDO-backed persistence for document templates (`document_template`,
 * migration 20261006_0013_phase7b_documents). Every query starts from
 * TenantQuery::forTenant() with the tenant of the context (ADR 0002). The
 * name is unique per tenant (`document_template_tenant_name_uq`): a
 * duplicate surfaces as a PDOException for the service to translate.
 *
 * It does not extend AbstractTenantRepository: the T-02 contract declares
 * `findById(int)` and `save(DocumentTemplate)`.
 */
final class DocumentTemplateRepository implements DocumentTemplateRepositoryInterface
{
    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function findById(int $id): ?DocumentTemplate
    {
        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("SELECT * FROM document_template WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : DocumentTemplate::reconstitute($row);
    }

    public function listAll(): array
    {
        return $this->fetchAll($this->tenantQuery());
    }

    public function listActive(string $kind): array
    {
        return $this->fetchAll(
            $this->tenantQuery()
                ->andEquals('kind', $kind)
                ->andEquals('status', DocumentTemplate::STATUS_ACTIVE),
        );
    }

    public function save(DocumentTemplate $template): DocumentTemplate
    {
        $this->context->assertTenant($template->tenantId());

        if ($template->id() === null) {
            $this->insert($template);

            return $template;
        }

        $query = $this->tenantQuery()->andEquals('id', $template->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE document_template SET
                name = :name,
                body_text = :body_text,
                status = :status,
                updated_by_system_user_id = :updated_by_system_user_id
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':name' => $template->name(),
            ':body_text' => $template->bodyText(),
            ':status' => $template->status(),
            ':updated_by_system_user_id' => $template->updatedBySystemUserId(),
        ]);

        return $template;
    }

    private function tenantQuery(): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId());
    }

    /** @return list<DocumentTemplate> */
    private function fetchAll(TenantQuery $query): array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM document_template WHERE {$query->whereSql()} ORDER BY name ASC, id ASC",
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): DocumentTemplate => DocumentTemplate::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function insert(DocumentTemplate $template): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO document_template (
                tenant_id, kind, name, body_text, status,
                created_by_system_user_id, updated_by_system_user_id
            ) VALUES (
                :tenant_id, :kind, :name, :body_text, :status,
                :created_by_system_user_id, :updated_by_system_user_id
            )
            SQL
        );
        $statement->execute([
            ':tenant_id' => $this->context->tenantId(),
            ':kind' => $template->kind(),
            ':name' => $template->name(),
            ':body_text' => $template->bodyText(),
            ':status' => $template->status(),
            ':created_by_system_user_id' => $template->createdBySystemUserId(),
            ':updated_by_system_user_id' => $template->updatedBySystemUserId(),
        ]);

        $template->assignId((int) $this->connection->lastInsertId());
    }
}
