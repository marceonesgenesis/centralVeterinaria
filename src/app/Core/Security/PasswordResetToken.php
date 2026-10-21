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

    public function __construct(private readonly string $key)
    {
    }

    public function issue(string $login, string $passwordHash, int $now): string
    {
        return JWT::encode([
            'user' => $login,
            'expires' => $now + self::TTL_SECONDS,
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
