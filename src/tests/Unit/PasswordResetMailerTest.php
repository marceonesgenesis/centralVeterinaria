<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Communication\MessageDeliveryFailed;
use CentralVet\Security\PasswordResetMailer;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEmailProvider;

/**
 * Etapa 4, T-04: o e-mail de reset sai pelo provedor de e-mail do núcleo
 * (EmailProviderFactory, remetente SMTP_FROM_*), não pelo MailService.
 */
final class PasswordResetMailerTest
{
    private const LINK = 'https://app.test/index.php?class=SystemPasswordResetForm&method=onLoad&jwt=abc.def.ghi';

    private const KEYS = [
        'Hello ^1, a password reset was requested for your Central Vet Pro user. To set a new password, open: ^2'
            => 'Olá ^1, foi solicitada a troca de senha do seu usuário no Central Vet Pro. Para definir uma nova senha, abra: ^2',
        'If you did not request it, ignore this e-mail.' => 'Se você não fez esta solicitação, ignore este e-mail.',
    ];

    public function testSendDeliversTheResetLinkToTheUser(): void
    {
        $email = new FakeEmailProvider();

        $result = $this->mailer($email)->send('ana@clinica.test', 'Ana', self::LINK);

        Assert::same('password-reset', PasswordResetMailer::REFERENCE);
        Assert::same('fake-1', $result);
        Assert::count(1, $email->deliveries());
        $message = $email->deliveries()[0];
        Assert::same('ana@clinica.test', $message->recipient);
        Assert::same('password-reset', $message->reference);
        Assert::same('[Password reset]', $message->subject);
        Assert::same(
            '[Hello ^1, a password reset was requested for your Central Vet Pro user. To set a new password, open: ^2|Ana|' . self::LINK . ']'
                . "\n\n"
                . '[If you did not request it, ignore this e-mail.]',
            $message->body,
        );
    }

    public function testDeliveryFailurePropagates(): void
    {
        $email = new FakeEmailProvider();
        $email->failWith('smtp_connect');

        try {
            $this->mailer($email)->send('ana@clinica.test', 'Ana', self::LINK);
            Assert::true(false, 'MessageDeliveryFailed esperado');
        } catch (MessageDeliveryFailed $e) {
            Assert::same('smtp_connect', $e->errorCode());
        }
        Assert::count(0, $email->deliveries());
    }

    public function testResetKeysHaveTheExactPortugueseText(): void
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/config/translations.json'), true);
        Assert::true(is_array($decoded), 'translations.json must decode to a list');
        $translations = [];
        foreach ($decoded as $entry) {
            $translations[$entry['en']] = $entry['pt'];
        }

        foreach (self::KEYS as $en => $pt) {
            Assert::same($pt, $translations[$en] ?? null, "Wrong or missing pt for: {$en}");
        }
        Assert::same('Redefinição de senha', $translations['Password reset'] ?? null);
    }

    private function mailer(FakeEmailProvider $email): PasswordResetMailer
    {
        $translate = static fn (string $key, string ...$params): string => '[' . implode('|', [$key, ...$params]) . ']';

        return new PasswordResetMailer($email, \Closure::fromCallable($translate));
    }
}
