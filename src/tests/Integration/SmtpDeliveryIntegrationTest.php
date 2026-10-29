<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\AccountInviteService;
use CentralVet\Communication\MessageDeliveryFailed;
use CentralVet\Communication\OutgoingMessage;
use CentralVet\Communication\SmtpConfig;
use CentralVet\Communication\SmtpEmailProvider;
use CentralVet\Security\PasswordResetMailer;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeSmtpServer;

/**
 * Etapa 4, T-04: convite e reset de senha saem pelo SmtpEmailProvider de
 * ponta a ponta até um SMTP falso em 127.0.0.1 (sem rede, sem TLS). O
 * remetente é o de SmtpConfig (SMTP_FROM_ADDRESS/SMTP_FROM_NAME) e os textos
 * vêm do pt de translations.json, como o `_t` faria.
 */
final class SmtpDeliveryIntegrationTest
{
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';
    private const RESET_LINK = 'https://app.test/index.php?class=SystemPasswordResetForm&method=onLoad&jwt=abc.def.ghi';

    public function testInviteReachesTheSmtpServerWithConfiguredSender(): void
    {
        $server = FakeSmtpServer::start();
        try {
            $invite = new AccountInviteService(
                new PasswordResetToken(self::KEY),
                new PublicUrl('https://app.test'),
                $this->provider($server->port()),
                $this->translator(),
            );
            $id = $invite->send('dra.ana', 'ana@clinica.test', 'hash-A', 'Clínica Bicho Feliz', time());
        } finally {
            $transcript = $server->stop();
        }

        Assert::true($id !== null && $id !== '', 'SMTP provider must return a message id');
        $this->assertEnvelope($transcript, 'ana@clinica.test');
        Assert::same('Convite do Central Vet Pro', $this->subject($transcript));
        Assert::stringContains('https://app.test/index.php?class=SystemPasswordResetForm&method=onLoad&jwt=', $this->body($transcript));
        Assert::stringContains('Sua clínica Clínica Bicho Feliz está pronta', $this->body($transcript));
    }

    public function testPasswordResetReachesTheSmtpServerWithConfiguredSender(): void
    {
        $server = FakeSmtpServer::start();
        try {
            $mailer = new PasswordResetMailer($this->provider($server->port()), $this->translator());
            $mailer->send('bruno@clinica.test', 'Bruno', self::RESET_LINK);
        } finally {
            $transcript = $server->stop();
        }

        $this->assertEnvelope($transcript, 'bruno@clinica.test');
        Assert::same('Redefinição de senha', $this->subject($transcript));
        $body = $this->body($transcript);
        Assert::stringContains('Olá Bruno, foi solicitada a troca de senha', $body);
        Assert::stringContains(self::RESET_LINK, $body);
        Assert::stringContains('Se você não fez esta solicitação, ignore este e-mail.', $body);
    }

    public function testClosedPortFailsWithSmtpConnect(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        Assert::true($probe !== false, 'could not reserve a loopback port');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        try {
            $this->provider($port, 2)->deliver(new OutgoingMessage('ana@clinica.test', 'x', 'y', 'password-reset'));
            Assert::true(false, 'MessageDeliveryFailed esperado');
        } catch (MessageDeliveryFailed $e) {
            Assert::same('smtp_connect', $e->errorCode());
        }
    }

    private function provider(int $port, int $timeout = 5): SmtpEmailProvider
    {
        return new SmtpEmailProvider(new SmtpConfig('127.0.0.1', $port, '', '', 'none', 'clinica@example.test', 'Clinica Teste', $timeout));
    }

    private function assertEnvelope(string $transcript, string $recipient): void
    {
        Assert::true(preg_match('/^MAIL FROM:<clinica@example\.test>/mi', $transcript) === 1, 'MAIL FROM ausente: ' . $transcript);
        Assert::true(preg_match('/^RCPT TO:<' . preg_quote($recipient, '/') . '>/mi', $transcript) === 1, 'RCPT TO ausente');
        Assert::true(preg_match('/^From: Clinica Teste <clinica@example\.test>/mi', $transcript) === 1, 'From ausente');
    }

    private function subject(string $transcript): string
    {
        Assert::true(preg_match('/^Subject: (.+(?:\r?\n[ \t].+)*)/mi', $transcript, $m) === 1, 'Subject ausente');

        $decoded = iconv_mime_decode('Subject: ' . $m[1], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim(substr((string) $decoded, strlen('Subject:')));
    }

    private function body(string $transcript): string
    {
        $parts = preg_split('/\r?\n\r?\n/', $transcript, 2);
        Assert::true(is_array($parts) && count($parts) === 2, 'DATA sem corpo');
        $body = (string) preg_replace('/\r?\n\.\r?\n(?:QUIT\r?\n)?$/', '', $parts[1]);
        if (preg_match('/^Content-Transfer-Encoding: quoted-printable/mi', $parts[0]) === 1) {
            $body = quoted_printable_decode($body);
        }

        return str_replace("\r\n", "\n", $body);
    }

    /** @return \Closure(string, string...): string */
    private function translator(): \Closure
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/config/translations.json'), true);
        $pt = [];
        foreach ((array) $decoded as $entry) {
            $pt[$entry['en']] = $entry['pt'];
        }

        return static function (string $key, string ...$params) use ($pt): string {
            $text = $pt[$key] ?? $key;
            foreach ($params as $i => $param) {
                $text = str_replace('^' . ($i + 1), $param, $text);
            }

            return $text;
        };
    }
}
