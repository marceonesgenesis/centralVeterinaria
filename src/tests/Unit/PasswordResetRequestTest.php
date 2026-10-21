<?php
declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\LoginRateLimiter;
use CentralVet\Security\PasswordResetRequest;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Security\TooManyPasswordResetAttempts;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeRedis;

final class PasswordResetRequestTest
{
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';
    private const NOW = 1_800_000_000;
    private const LINK = 'https://app.centralvet.com.br/index.php?class=SystemPasswordResetForm&method=onLoad&jwt=';

    private function request(int $maxAttempts = 5): PasswordResetRequest
    {
        return new PasswordResetRequest(
            new LoginRateLimiter(new FakeRedis(), $maxAttempts, 900, PasswordResetRequest::PREFIX),
            new PasswordResetToken(self::KEY),
            new PublicUrl('https://app.centralvet.com.br/'),
        );
    }

    /** @return array<string, string> */
    private static function user(string $active = 'Y'): array
    {
        return ['login' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.com', 'active' => $active, 'password' => 'hash-A'];
    }

    public function testActiveUserReceivesOneLinkWithVerifiableToken(): void
    {
        $sent = [];
        $result = $this->request()->handle('admin', '10.0.0.1', static fn (string $l) => self::user(), static function (string $email, string $name, string $link) use (&$sent): void {
            $sent[] = [$email, $name, $link];
        }, self::NOW);
        Assert::null($result);
        Assert::count(1, $sent);
        Assert::same('admin@example.com', $sent[0][0]);
        Assert::same('Admin', $sent[0][1]);
        Assert::true(str_starts_with($sent[0][2], self::LINK), $sent[0][2]);
        $jwt = rawurldecode(substr($sent[0][2], strlen(self::LINK)));
        $tokens = new PasswordResetToken(self::KEY);
        Assert::true($tokens->matchesPassword($tokens->verify($jwt, self::NOW), 'hash-A'));
    }

    public function testUnknownAndExistingLoginReturnTheSameAndOnlyExistingSends(): void
    {
        $calls = 0;
        $send = static function () use (&$calls): void {
            $calls++;
        };
        $request = $this->request();
        $unknown = $request->handle('ghost', '10.0.0.1', static fn () => null, $send, self::NOW);
        Assert::same(0, $calls);
        $known = $request->handle('admin', '10.0.0.1', static fn () => self::user(), $send, self::NOW);
        Assert::same($unknown, $known);
        Assert::same(1, $calls);
    }

    public function testInactiveUserDoesNotReceiveLink(): void
    {
        $calls = 0;
        $this->request()->handle('admin', '10.0.0.1', static fn () => self::user('N'), static function () use (&$calls): void {
            $calls++;
        }, self::NOW);
        Assert::same(0, $calls);
    }

    public function testSixthAttemptIsThrottledWithoutLookingUpUser(): void
    {
        $request = $this->request(5);
        $lookups = 0;
        $find = static function () use (&$lookups): ?array {
            $lookups++;
            return null;
        };
        for ($i = 0; $i < 5; $i++) {
            $request->handle('admin', '10.0.0.1', $find, static fn () => null, self::NOW);
        }
        Assert::same(5, $lookups);
        try {
            $request->handle('admin', '10.0.0.1', $find, static fn () => null, self::NOW);
            Assert::true(false, 'Expected TooManyPasswordResetAttempts');
        } catch (TooManyPasswordResetAttempts $e) {
            Assert::true($e->secondsUntilAvailable > 0);
        }
        Assert::same(5, $lookups);
    }

    public function testIpLimitAppliesAcrossDifferentLogins(): void
    {
        $request = $this->request(2);
        $request->handle('a', '10.0.0.9', static fn () => null, static fn () => null, self::NOW);
        $request->handle('b', '10.0.0.9', static fn () => null, static fn () => null, self::NOW);
        Assert::throws(TooManyPasswordResetAttempts::class, static fn () => $request->handle('c', '10.0.0.9', static fn () => null, static fn () => null, self::NOW));
    }
}
