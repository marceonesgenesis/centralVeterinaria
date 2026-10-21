<?php

declare(strict_types=1);

namespace CentralVet\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Single-use password reset token (HS256 JWT).
 *
 * The `pwd` claim is an HMAC of the user's current password hash. Saving a
 * new password changes the hash, so the same JWT stops matching: the token
 * is single-use without a token table. The hash itself never leaves the
 * server (only its keyed HMAC is in the token).
 */
final class PasswordResetToken
{
    public const TTL_SECONDS = 10800;

    public const MIN_KEY_BYTES = 32;

    /**
     * php-jwt 7 refuses HMAC keys shorter than the hash output (32 bytes
     * for HS256); fail here first with a clear message.
     */
    public function __construct(#[\SensitiveParameter] private readonly string $key)
    {
        if (strlen($key) < self::MIN_KEY_BYTES) {
            throw new \InvalidArgumentException('JWT HMAC key must have at least 32 bytes');
        }
    }

    /**
     * $ttlSeconds defaults to the password reset lifetime (3 h); the first
     * access invite passes a longer one (AccountInviteService::TTL_SECONDS).
     */
    public function issue(string $login, string $passwordHash, int $now, int $ttlSeconds = self::TTL_SECONDS): string
    {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('Token TTL must be positive');
        }

        return JWT::encode([
            'user' => $login,
            'expires' => $now + $ttlSeconds,
            'pwd' => $this->fingerprint($passwordHash),
        ], $this->key, 'HS256');
    }

    /** @return array{user: string, pwd: string} */
    public function verify(string $jwt, int $now): array
    {
        try {
            $payload = (array) JWT::decode($jwt, new Key($this->key, 'HS256'));
        } catch (\Throwable $e) {
            throw new InvalidPasswordResetToken('Invalid password reset token', 0, $e);
        }

        $user = $payload['user'] ?? null;
        $pwd = $payload['pwd'] ?? null;
        $expires = $payload['expires'] ?? null;

        if (!is_string($user) || $user === '' || !is_string($pwd) || $pwd === '' || !is_int($expires) || $expires < $now) {
            throw new InvalidPasswordResetToken('Invalid password reset token');
        }

        return ['user' => $user, 'pwd' => $pwd];
    }

    /** @param array{user?: string, pwd?: string} $claims */
    public function matchesPassword(array $claims, string $currentPasswordHash): bool
    {
        $pwd = $claims['pwd'] ?? null;

        return is_string($pwd) && hash_equals($this->fingerprint($currentPasswordHash), $pwd);
    }

    private function fingerprint(string $passwordHash): string
    {
        return hash_hmac('sha256', $passwordHash, $this->key);
    }
}
