<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\UserMessage;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 1 (segurança do MVP), T-01: textos pt-BR das mensagens novas
 * (CSRF, reset de senha, preço de venda, coerência de referências da venda)
 * e o mapeamento das mensagens de domínio em UserMessage.
 */
final class SecurityMvpMessagesTest
{
    private const KEYS = [
        'The request could not be verified. Reload the page and try again.' => 'Não foi possível validar a requisição. Recarregue a página e tente novamente.',
        'If the login is registered and active, you will receive an e-mail with the instructions to reset the password.' => 'Se o login estiver cadastrado e ativo, você receberá um e-mail com as instruções para redefinir a senha.',
        'Too many password reset attempts. Try again in %s seconds.' => 'Muitas tentativas de redefinição de senha. Tente novamente em %s segundos.',
        'This password reset link is invalid, expired or has already been used.' => 'Este link de redefinição de senha é inválido, expirou ou já foi usado.',
        'Product without sale price. Set the sale price in the product registration before selling.' => 'Produto sem preço de venda. Informe o preço de venda no cadastro do produto antes de vender.',
        'The patient does not belong to the selected tutor' => 'O paciente não pertence ao tutor selecionado',
        'The encounter does not belong to the selected patient and unit' => 'O atendimento não pertence ao paciente e à unidade selecionados',
        'Select the patient of the encounter' => 'Selecione o paciente do atendimento',
    ];

    private const DOMAIN = [
        'Product without sale price. Set the sale price in the product registration before selling.' => 'Product without sale price. Set the sale price in the product registration before selling.',
        'The patient does not belong to the selected tutor' => 'The patient does not belong to the selected tutor',
        'The encounter does not belong to the selected patient and unit' => 'The encounter does not belong to the selected patient and unit',
        'patient_id is required when encounter_id is informed' => 'Select the patient of the encounter',
    ];

    public function testEveryEtapa1KeyHasTheExactPortugueseText(): void
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/config/translations.json'), true);
        Assert::true(is_array($decoded), 'translations.json must decode to a list');

        $translations = [];
        foreach ($decoded as $entry) {
            $translations[$entry['en']] = $entry['pt'];
        }

        foreach (self::KEYS as $en => $pt) {
            Assert::true(isset($translations[$en]), "Missing translation for Etapa 1 key: {$en}");
            Assert::same($pt, $translations[$en], "Wrong pt for Etapa 1 key: {$en}");
        }
    }

    public function testDomainMessagesResolveToTheirKeysWithoutParams(): void
    {
        foreach (self::DOMAIN as $message => $key) {
            Assert::same(['key' => $key, 'params' => []], UserMessage::resolve($message), "Wrong resolution for: {$message}");
        }
    }
}
