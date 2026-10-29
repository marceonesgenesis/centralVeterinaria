<?php
declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\AccountInviteService;
use CentralVet\Security\InvalidPasswordResetToken;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEmailProvider;

final class AccountInviteServiceTest
{
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';
    private const NOW = 1_800_000_000;
    private const LINK_PREFIX = 'https://app.test/index.php?class=SystemPasswordResetForm&method=onLoad&jwt=';

    public function testSendDeliversOneInviteWithResetLinkValidFor72Hours(): void
    {
        $tokens = new PasswordResetToken(self::KEY);
        $email = new FakeEmailProvider();
        $service = $this->service($tokens, $email);

        $result = $service->send('dra.ana', 'ana@clinica.test', 'hash-A', 'Clínica Bicho Feliz', self::NOW);

        Assert::same(259200, AccountInviteService::TTL_SECONDS);
        Assert::same('fake-1', $result);
        Assert::count(1, $email->deliveries());
        $message = $email->deliveries()[0];
        Assert::same('ana@clinica.test', $message->recipient);
        Assert::same('[Central Vet Pro invitation]', $message->subject);
        Assert::same('account-invite', $message->reference);
        Assert::stringContains('[Your clinic ^1 is ready. Set your password to access Central Vet Pro: ^2|Clínica Bicho Feliz|' . self::LINK_PREFIX, $message->body);
        Assert::stringContains('[This link expires in ^1 hours|72]', $message->body);

        $jwt = $this->jwtFrom($message->body);
        $claims = $tokens->verify($jwt, self::NOW + 259199);
        Assert::same('dra.ana', $claims['user']);
        Assert::true($tokens->matchesPassword($claims, 'hash-A'));
        Assert::throws(InvalidPasswordResetToken::class, static fn () => $tokens->verify($jwt, self::NOW + 259201));
    }

    public function testInviteIsSingleUseOncePasswordHashChanges(): void
    {
        $tokens = new PasswordResetToken(self::KEY);
        $email = new FakeEmailProvider();
        $this->service($tokens, $email)->send('dra.ana', 'ana@clinica.test', 'hash-A', 'Clínica', self::NOW);

        $claims = $tokens->verify($this->jwtFrom($email->deliveries()[0]->body), self::NOW + 60);
        Assert::false($tokens->matchesPassword($claims, 'hash-B'));
    }

    public function testIssueWithoutTtlKeepsResetDefaultOfThreeHours(): void
    {
        $tokens = new PasswordResetToken(self::KEY);
        $jwt = $tokens->issue('admin', 'hash-A', self::NOW);
        $tokens->verify($jwt, self::NOW + 10800);
        Assert::throws(InvalidPasswordResetToken::class, static fn () => $tokens->verify($jwt, self::NOW + 10801));

        $long = $tokens->issue('admin', 'hash-A', self::NOW, 259200);
        $tokens->verify($long, self::NOW + 259200);
        Assert::throws(InvalidPasswordResetToken::class, static fn () => $tokens->verify($long, self::NOW + 259201));
    }

    private function service(PasswordResetToken $tokens, FakeEmailProvider $email): AccountInviteService
    {
        $translate = static fn (string $key, string ...$params): string => '[' . implode('|', [$key, ...$params]) . ']';

        return new AccountInviteService($tokens, new PublicUrl('https://app.test'), $email, \Closure::fromCallable($translate));
    }

    private function jwtFrom(string $body): string
    {
        $start = strpos($body, self::LINK_PREFIX);
        Assert::true($start !== false, 'invite body must contain the reset link');
        $rest = substr($body, (int) $start + strlen(self::LINK_PREFIX));
        Assert::true(preg_match('/^[A-Za-z0-9%._-]+/', $rest, $m) === 1, 'link must carry a jwt');

        return rawurldecode($m[0]);
    }
}
