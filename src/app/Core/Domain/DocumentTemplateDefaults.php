<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Built-in pt-BR bodies used when a tenant has no active template for a
 * kind (Fase 7B). Only kinds that use templates have a default; they use
 * only `DocumentTemplateRenderer::PLACEHOLDERS`.
 */
final class DocumentTemplateDefaults
{
    private const BODIES = [
        DocumentKind::MEDICAL_CERTIFICATE => 'Atesto, para os devidos fins, que o animal {{patient_name}}, da espécie {{species}}, sob a responsabilidade de {{tutor_name}}, foi examinado nesta data, {{today}}, e encontra-se em condições clínicas compatíveis com o descrito neste documento.',
    ];

    private function __construct()
    {
    }

    public static function bodyFor(string $kind): string
    {
        DocumentKind::assertValid($kind);

        if (!isset(self::BODIES[$kind])) {
            throw new InvalidArgumentException("Document kind \"{$kind}\" has no default template");
        }

        return self::BODIES[$kind];
    }
}
