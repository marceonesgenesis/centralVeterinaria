<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\DocumentTemplateRepositoryInterface;
use CentralVet\Domain\DocumentTemplate;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for DocumentTemplateRepositoryInterface (T-05). Stores
 * rows keyed like `document_template`; reads are filtered by the tenant of
 * the TenantContext and return fresh reconstitute() copies.
 */
final class FakeDocumentTemplateRepository implements DocumentTemplateRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    public function __construct(private readonly TenantContext $context)
    {
    }

    /** Stores the template as it is (any tenant), assigning an id when missing. */
    public function seed(DocumentTemplate $template): void
    {
        $this->store($template);
    }

    public function findById(int $id): ?DocumentTemplate
    {
        $row = $this->rows[$id] ?? null;

        return $row !== null && $row['tenant_id'] === $this->context->tenantId() ? DocumentTemplate::reconstitute($row) : null;
    }

    public function listAll(): array
    {
        return array_map(static fn (array $row): DocumentTemplate => DocumentTemplate::reconstitute($row), $this->ownRows());
    }

    public function listActive(string $kind): array
    {
        $rows = array_filter(
            $this->ownRows(),
            static fn (array $row): bool => $row['kind'] === $kind && $row['status'] === DocumentTemplate::STATUS_ACTIVE,
        );

        return array_values(array_map(static fn (array $row): DocumentTemplate => DocumentTemplate::reconstitute($row), $rows));
    }

    public function save(DocumentTemplate $template): DocumentTemplate
    {
        if ($template->tenantId() !== $this->context->tenantId()) {
            throw new InvalidArgumentException('Document template belongs to another tenant');
        }

        $this->store($template);

        return $template;
    }

    /** @return list<array<string, mixed>> ordered by name, then id */
    private function ownRows(): array
    {
        $rows = array_values(array_filter($this->rows, fn (array $row): bool => $row['tenant_id'] === $this->context->tenantId()));
        usort($rows, static fn (array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);

        return $rows;
    }

    private function store(DocumentTemplate $t): void
    {
        if ($t->id() === null) {
            $t->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $t->id() + 1);
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $this->rows[(int) $t->id()] = [
            'id' => $t->id(),
            'tenant_id' => $t->tenantId(),
            'kind' => $t->kind(),
            'name' => $t->name(),
            'body_text' => $t->bodyText(),
            'status' => $t->status(),
            'created_by_system_user_id' => $t->createdBySystemUserId(),
            'updated_by_system_user_id' => $t->updatedBySystemUserId(),
            'created_at' => $this->rows[(int) $t->id()]['created_at'] ?? $t->createdAt()?->format('Y-m-d H:i:s.u') ?? $now,
            'updated_at' => $now,
        ];
    }
}
