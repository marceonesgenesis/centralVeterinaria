<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\DocumentTemplate;

/**
 * Persistence boundary for document templates (`document_template`),
 * always filtered by the tenant of the current context.
 */
interface DocumentTemplateRepositoryInterface
{
    public function findById(int $id): ?DocumentTemplate;

    /**
     * Every template of the tenant, ordered by name.
     *
     * @return list<DocumentTemplate>
     */
    public function listAll(): array;

    /**
     * Active templates of the tenant for one kind, ordered by name.
     *
     * @return list<DocumentTemplate>
     */
    public function listActive(string $kind): array;

    /** Inserts (assigning the id) or updates the template. */
    public function save(DocumentTemplate $template): DocumentTemplate;
}
