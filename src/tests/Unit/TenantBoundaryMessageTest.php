<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\UserMessage;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 3, T-29: a TenantBoundaryViolation (recurso de outra clínica) chega à
 * tela em pt-BR pela chave já existente em translations.json.
 */
final class TenantBoundaryMessageTest
{
    private const MESSAGE = 'Resource does not belong to the authenticated tenant';
    private const KEY = 'The selected record does not belong to this clinic';

    public function testTenantBoundaryMessageResolvesToClinicKey(): void
    {
        Assert::same(['key' => self::KEY, 'params' => []], UserMessage::resolve(self::MESSAGE));
    }

    public function testClinicKeyHasPortugueseTranslation(): void
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/config/translations.json'), true);
        Assert::true(is_array($decoded), 'translations.json must decode to a list');

        $matches = array_values(array_filter($decoded, static fn (array $entry): bool => ($entry['en'] ?? null) === self::KEY));
        Assert::count(1, $matches);
        $pt = (string) ($matches[0]['pt'] ?? '');
        Assert::true($pt !== '', 'pt must not be empty');
        Assert::true($pt !== self::KEY, 'pt must differ from en');
    }
}
