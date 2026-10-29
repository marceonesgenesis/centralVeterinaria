<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Etapa 5, T-16: no login, quem tem Termos de Uso ou Política de Privacidade
 * pendentes (LegalAcceptanceService de T-10) aceita antes de seguir. Lê o
 * fonte de LoginForm: o formulário depende do Adianti e do banco, que o teste
 * unitário não sobe.
 */
final class LoginLegalAcceptanceWiringTest
{
    public function testOnLoginChecksLegalAcceptanceAfterAuthenticateAndBeforeTwoFactor(): void
    {
        $onLogin = $this->method('onLogin');

        $authenticate = strpos($onLogin, 'ApplicationAuthenticationService::authenticate(');
        $legal = strpos($onLogin, 'self::legalAcceptanceVerification(');
        $twoFactor = strpos($onLogin, 'self::checkTwoFactor(');
        $session = strpos($onLogin, 'loadSessionVars(');

        Assert::true($authenticate !== false, 'onLogin não chama ApplicationAuthenticationService::authenticate(');
        Assert::true($legal !== false, 'onLogin não chama self::legalAcceptanceVerification(');
        Assert::true($twoFactor !== false, 'onLogin não chama self::checkTwoFactor(');
        Assert::true($session !== false, 'onLogin não chama loadSessionVars(');
        Assert::true($legal > $authenticate, 'o aceite legal vem antes da autenticação');
        Assert::true($legal < $twoFactor, 'o aceite legal vem depois do segundo fator');
        Assert::true($legal < $session, 'o aceite legal vem depois de loadSessionVars');
    }

    public function testVerificationUsesTheVersionedAcceptanceService(): void
    {
        $method = $this->method('legalAcceptanceVerification');

        Assert::stringContains('private static function legalAcceptanceVerification($user, $param)', $this->loginForm());
        Assert::stringContains('LegalAcceptanceService', $method);
        Assert::stringContains('pendingDocuments(', $method);
        Assert::stringContains('->accept(', $method);
        Assert::stringContains("'accept_legal'", $method);
        Assert::stringContains('BootstrapFormBuilder', $method);
    }

    public function testDialogLinksBothDocumentsAndUsesTheStage5Messages(): void
    {
        $method = $this->method('legalAcceptanceVerification');

        Assert::stringContains('LegalDocuments::TERMS_URL', $method);
        Assert::stringContains('LegalDocuments::PRIVACY_URL', $method);
        Assert::stringContains("_t('I have read and accept the Terms of Use and the Privacy Policy')", $method);
        Assert::stringContains("_t('Accept the Terms of Use and the Privacy Policy to continue')", $method);
    }

    public function testVerificationDoesNotStoreTheWholeServerArray(): void
    {
        $method = $this->method('legalAcceptanceVerification');

        Assert::false(str_contains($method, 'json_encode($_SERVER)'), 'o aceite ainda grava json_encode($_SERVER)');
        Assert::stringContains('REMOTE_ADDR', $method);
        Assert::stringContains('HTTP_USER_AGENT', $method);
    }

    /**
     * Corpo de um método de LoginForm: da assinatura até a próxima declaração
     * de método (ou o fim do arquivo).
     */
    private function method(string $name): string
    {
        $source = $this->loginForm();
        $match = preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $source, $found, PREG_OFFSET_CAPTURE);
        Assert::true($match === 1, "LoginForm sem o método {$name}");

        $start = $found[0][1];
        $rest = substr($source, $start + strlen($found[0][0]));
        $next = preg_match('/\n\s*(?:public|private|protected)\s+(?:static\s+)?function\s/', $rest, $after, PREG_OFFSET_CAPTURE);

        return $next === 1
            ? substr($source, $start, strlen($found[0][0]) + $after[0][1])
            : substr($source, $start);
    }

    private function loginForm(): string
    {
        $path = dirname(__DIR__, 2) . '/app/control/admin/LoginForm.php';
        Assert::true(is_file($path), "arquivo ausente: {$path}");

        return (string) file_get_contents($path);
    }
}
