<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\UserMessage;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 3, T-24: textos pt-BR das telas de equipe e de unidades da clínica
 * (as de unidades são usadas pela T-25) e as mensagens de domínio dos
 * serviços em UserMessage::STATIC (chave = texto).
 */
final class Etapa3TeamMessagesTest
{
    private const KEYS = [
        'Team' => 'Equipe',
        'Invite user' => 'Convidar usuário',
        'Deactivate' => 'Desativar',
        'Reactivate' => 'Reativar',
        'Invited' => 'Convidado',
        'Inactive' => 'Inativo',
        'Clinic units' => 'Unidades da clínica',
        'New unit' => 'Nova unidade',
        'Group not allowed for this clinic' => 'Grupo não permitido para esta clínica',
        'A user with this e-mail already exists' => 'Já existe um usuário com este e-mail',
        'Invalid e-mail' => 'E-mail inválido',
        'You cannot deactivate your own user' => 'Você não pode desativar o próprio usuário',
        'No plan group available for this clinic' => 'Nenhum grupo do plano disponível para esta clínica',
        'Unit name is required' => 'Informe o nome da unidade',
        'Unit name must have at most 120 characters' => 'O nome da unidade deve ter no máximo 120 caracteres',
    ];

    private const STATIC_DOMAIN = [
        'Group not allowed for this clinic',
        'A user with this e-mail already exists',
        'Invalid e-mail',
        'You cannot deactivate your own user',
        'No plan group available for this clinic',
        'Unit name is required',
        'Unit name must have at most 120 characters',
    ];

    public function testEveryTeamKeyHasTheExactPortugueseText(): void
    {
        $translations = [];
        foreach (self::decoded() as $entry) {
            $translations[$entry['en']] = $entry['pt'];
        }

        foreach (self::KEYS as $en => $pt) {
            Assert::true(isset($translations[$en]), "Missing translation for team key: {$en}");
            Assert::same($pt, $translations[$en], "Wrong pt for team key: {$en}");
        }
    }

    public function testNoKeyIsDuplicated(): void
    {
        $counts = array_count_values(array_column(self::decoded(), 'en'));
        foreach (array_keys(self::KEYS) as $key) {
            Assert::same(1, $counts[$key] ?? 0, "Key must exist exactly once: {$key}");
        }
    }

    public function testDomainMessagesResolveToThemselves(): void
    {
        foreach (self::STATIC_DOMAIN as $message) {
            Assert::same(['key' => $message, 'params' => []], UserMessage::resolve($message), "UserMessage must map: {$message}");
        }
    }

    /** @return list<array{en: string, pt: string}> */
    private static function decoded(): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/config/translations.json'), true);
        Assert::true(is_array($decoded), 'translations.json must decode to a list');

        return $decoded;
    }
}
