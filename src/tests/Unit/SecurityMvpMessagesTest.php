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
        'Too many password reset attempts. Try again in ^1 seconds.' => 'Muitas tentativas de redefinição de senha. Tente novamente em ^1 segundos.',
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

    /**
     * T-12: _t() só substitui ^1..^4 (ApplicationTranslator::translate); com
     * %s o usuário via "Tente novamente em %s segundos." no reset e, no login,
     * "Message not found" (a chave nem existia em translations.json).
     */
    private const RATE_LIMIT = [
        'app/control/admin/SystemRequestPasswordResetForm.php' => [
            'Too many password reset attempts. Try again in ^1 seconds.',
            'Muitas tentativas de redefinição de senha. Tente novamente em 120 segundos.',
        ],
        'app/control/admin/LoginForm.php' => [
            'Too many login attempts. Try again in ^1 seconds.',
            'Muitas tentativas de login. Tente novamente em 120 segundos.',
        ],
    ];

    public function testRateLimitMessagesRenderTheSecondsThroughT(): void
    {
        $src = dirname(__DIR__, 2);
        $decoded = json_decode((string) file_get_contents($src . '/app/config/translations.json'), true);
        $english = array_column($decoded, 'en');

        foreach (self::RATE_LIMIT as $file => [$key, $expectedPt]) {
            Assert::true(in_array($key, $english, true), "Missing translation for rate limit key: {$key}");
            Assert::true(
                str_contains((string) file_get_contents($src . '/' . $file), "_t('{$key}', "),
                "{$file} must call _t with the ^1 key: {$key}"
            );
        }

        foreach (self::RATE_LIMIT as [$key, $expectedPt]) {
            Assert::same($expectedPt, self::renderWithRealT($src, 'pt', $key, 120), "Wrong pt rendering for: {$key}");

            $en = self::renderWithRealT($src, 'en', $key, 120);
            Assert::same(str_replace('^1', '120', $key), $en, "Wrong en rendering for: {$key}");
            Assert::true(!str_contains($en, '%s') && !str_contains($en, '^1'), "Unreplaced marker in: {$en}");
        }
    }

    /**
     * Roda o _t() real num processo separado: CvFormatUserErrorTest declara
     * um _t de teste no processo da suíte, e ApplicationTranslator.php
     * redeclararia a função.
     */
    private static function renderWithRealT(string $src, string $lang, string $key, int $param): string
    {
        $code = 'chdir($argv[1]); require "app/lib/util/ApplicationTranslator.php";'
            . ' ApplicationTranslator::setLanguage($argv[2], false); echo _t($argv[3], (int) $argv[4]);';
        $cmd = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-r', $code, '--', $src, $lang, $key, (string) $param]));
        $out = shell_exec($cmd);
        Assert::true(is_string($out), "Could not run the real _t for: {$key}");

        return $out;
    }

    public function testDomainMessagesResolveToTheirKeysWithoutParams(): void
    {
        foreach (self::DOMAIN as $message => $key) {
            Assert::same(['key' => $key, 'params' => []], UserMessage::resolve($message), "Wrong resolution for: {$message}");
        }
    }
}
