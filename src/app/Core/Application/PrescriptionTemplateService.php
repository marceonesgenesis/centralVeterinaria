<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\PrescriptionTemplateRepositoryInterface;
use CentralVet\Domain\PrescriptionTemplate;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Use cases for prescription templates (rodada 2, T-13): "Salvar como
 * modelo" and "Aplicar modelo" in PrescriptionForm (T-19). The tenant comes
 * from TenantContext only, never from caller input (ADR 0002). No Adianti
 * dependency (ADR 0001).
 */
final class PrescriptionTemplateService
{
    public function __construct(
        private readonly PrescriptionTemplateRepositoryInterface $repository,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * Saves a new template from the prescription's current lines.
     *
     * @param list<array<string, mixed>> $items same shape as
     *        PrescriptionService::create()'s `items`.
     *
     * @throws InvalidArgumentException when the name already exists for the
     *         tenant (A template named "{name}" already exists for this
     *         tenant), or per PrescriptionTemplate::create().
     */
    public function saveFromItems(string $name, ?string $orientationText, array $items, int $systemUserId): PrescriptionTemplate
    {
        $template = PrescriptionTemplate::create(
            tenantId: $this->context->tenantId(),
            name: $name,
            orientationText: $orientationText,
            items: $items,
            createdBySystemUserId: $systemUserId,
        );

        if ($this->repository->findByName($template->name()) !== null) {
            throw new InvalidArgumentException(
                "A template named \"{$template->name()}\" already exists for this tenant"
            );
        }

        /** @var PrescriptionTemplate $saved */
        $saved = $this->repository->save($template);

        return $saved;
    }

    /** @return list<PrescriptionTemplate> ordered by name */
    public function listAll(): array
    {
        /** @var list<PrescriptionTemplate> $templates */
        $templates = $this->repository->listAll();

        return $templates;
    }

    public function findById(int $id): ?PrescriptionTemplate
    {
        $template = $this->repository->findById($id);

        return $template instanceof PrescriptionTemplate ? $template : null;
    }
}
