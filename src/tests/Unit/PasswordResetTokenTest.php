<?php
declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\InvalidPasswordResetToken;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Tests\Support\Assert;

final class PasswordResetTokenTest
{
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';
    private const NOW = 1_800_000_000;

    public function testIssuedTokenVerifiesAndMatchesCurrentPassword(): void
    {
        $tokens = new PasswordResetToken(self::KEY);
        $jwt = $tokens->issue('admin', 'hash-A', self::NOW);
        $claims = $tokens->verify($jwt, self::NOW + 60);
        Assert::same('admin', $claims['user']);
        Assert::true($tokens->matchesPassword($claims, 'hash-A'));
        Assert::false(str_contains($jwt, 'hash-A'));
    }

    public function testTokenReusedAfterPasswordChangeNoLongerMatches(): void
    {
        $tokens = new PasswordResetToken(self::KEY);
        $jwt = $tokens->issue('admin', 'hash-A', self::NOW);
        $claims = $tokens->verify($jwt, self::NOW + 60);
        Assert::false($tokens->matchesPassword($claims, 'hash-B'));
        Assert::false($tokens->matchesPassword($tokens->verify($jwt, self::NOW + 120), 'hash-B'));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $tokens = new PasswordResetToken(self::KEY);
        $jwt = $tokens->issue('admin', 'hash-A', self::NOW);
        $tokens->verify($jwt, self::NOW + PasswordResetToken::TTL_SECONDS);
        Assert::throws(
            InvalidPasswordResetToken::class,
            static fn () => $tokens->verify($jwt, self::NOW + PasswordResetToken::TTL_SECONDS + 1),
        );
    }

    public function testTokenSignedWithAnotherKeyOrMalformedIsRejected(): void
    {
        $other = (new PasswordResetToken('another-key-with-enough-length-0123456789abcdef'))->issue('admin', 'hash-A', self::NOW);
        $tokens = new PasswordResetToken(self::KEY);
        Assert::throws(InvalidPasswordResetToken::class, static fn () => $tokens->verify($other, self::NOW));
        Assert::throws(InvalidPasswordResetToken::class, static fn () => $tokens->verify('not-a-jwt', self::NOW));
    }

    public function testTokenWithoutRequiredClaimsIsRejected(): void
    {
        $jwt = \Firebase\JWT\JWT::encode(['expires' => self::NOW + 100], self::KEY, 'HS256');
        $tokens = new PasswordResetToken(self::KEY);
        Assert::throws(InvalidPasswordResetToken::class, static fn () => $tokens->verify($jwt, self::NOW));
    }
}
