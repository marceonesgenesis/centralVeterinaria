<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * T-07 (S3): os formulários de reset usam o núcleo de T-03 (PasswordResetRequest,
 * PublicUrl, PasswordResetToken de uso único) e o link público vem de
 * APP_PUBLIC_URL, não do HTTP_REFERER. Lê o fonte: os formulários dependem do
 * Adianti e do banco, que o teste unitário não sobe.
 *
 * `.env.example` e `docker-compose.yml` ficam fora de src/; o comando da task
 * os monta em /var/www/html (um nível acima de src/).
 */
final class PasswordResetFormsWiringTest
{
    public function testRequestFormDoesNotBuildTheLinkFromTheReferer(): void
    {
        Assert::false(str_contains($this->requestForm(), 'HTTP_REFERER'), 'HTTP_REFERER ainda é lido');
    }

    public function testRequestFormDoesNotRevealWhetherTheLoginExists(): void
    {
        $source = $this->requestForm();

        Assert::false(str_contains($source, "'User not found'"), "'User not found' ainda aparece");
        Assert::false(str_contains($source, "'Inactive user'"), "'Inactive user' ainda aparece");
        Assert::stringContains(
            "_t('If the login is registered and active, you will receive an e-mail with the instructions to reset the password.')",
            $source,
        );
    }

    public function testRequestFormUsesTheResetCore(): void
    {
        $source = $this->requestForm();

        Assert::stringContains('PasswordResetRequest(', $source);
        Assert::stringContains('PublicUrl::fromEnvironment()', $source);
        Assert::stringContains('LoginRateLimiter::fromEnvironment(PasswordResetRequest::PREFIX)', $source);
        Assert::stringContains('TooManyPasswordResetAttempts', $source);
    }

    /**
     * Etapa 4, T-04: o reset sai pelo provedor de e-mail do núcleo (remetente
     * SMTP_FROM_ADDRESS/SMTP_FROM_NAME), não pelo MailService com mail_from.
     */
    public function testRequestFormSendsThroughPasswordResetMailer(): void
    {
        $source = $this->requestForm();

        Assert::stringContains(
            "(new PasswordResetMailer(EmailProviderFactory::fromEnvironment(JsonLogger::fromEnvironment('password-reset')), Closure::fromCallable('_t')))->send(\$email, \$name, \$link)",
            $source,
        );
        Assert::false(str_contains($source, 'MailService::send'), 'MailService::send ainda é chamado');
        Assert::false(str_contains($source, 'mail_from'), 'mail_from ainda é lido');
    }

    public function testResetFormChecksTheTokenIsSingleUse(): void
    {
        $source = $this->source(dirname(__DIR__, 2) . '/app/control/admin/SystemPasswordResetForm.php');

        Assert::stringContains('->verify(', $source);
        Assert::stringContains('matchesPassword(', $source);
        Assert::false(str_contains($source, "\$token['expires']"), "\$token['expires'] ainda é lido");
        Assert::stringContains(
            "_t('This password reset link is invalid, expired or has already been used.')",
            $source,
        );
    }

    public function testEnvExampleDocumentsAppPublicUrl(): void
    {
        Assert::stringContains('APP_PUBLIC_URL=', $this->source(dirname(__DIR__, 3) . '/.env.example'));
    }

    public function testComposePassesAppPublicUrlToTheApp(): void
    {
        Assert::stringContains(
            'APP_PUBLIC_URL: ${APP_PUBLIC_URL:-http://localhost:8081}',
            $this->source(dirname(__DIR__, 3) . '/docker-compose.yml'),
        );
    }

    private function requestForm(): string
    {
        return $this->source(dirname(__DIR__, 2) . '/app/control/admin/SystemRequestPasswordResetForm.php');
    }

    private function source(string $path): string
    {
        Assert::true(is_file($path), "arquivo ausente: {$path}");

        return (string) file_get_contents($path);
    }
}
