<?php

declare(strict_types=1);

namespace CentralVet\Landing;

/**
 * Single-use token for the public lead form (no session available).
 *
 * Each landing view gets a random token; Redis keeps only its SHA-256 as the
 * key (never the raw token) and the issue instant (seconds) as the value,
 * with a TTL of TTL_SECONDS. A token is usable once it is at least
 * MIN_AGE_SECONDS old (bots that post instantly are refused) and at most
 * TTL_SECONDS old. consume() relies on DEL returning 1, so when two
 * submissions race with the same token exactly one of them wins.
 */
final class LeadFormToken
{
    public const TTL_SECONDS = 7200;
    public const MIN_AGE_SECONDS = 3;

    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'centralvet:lead-token:',
    ) {
    }

    public function issue(int $now): string
    {
        $token = bin2hex(random_bytes(32));
        $this->redis->set($this->key($token), (string) $now, ['EX' => self::TTL_SECONDS]);

        return $token;
    }

    public function isUsable(string $token, int $now): bool
    {
        if (!self::isWellFormed($token)) {
            return false;
        }

        $issuedAt = $this->redis->get($this->key($token));

        if (!is_string($issuedAt) || !ctype_digit($issuedAt)) {
            return false;
        }

        $age = $now - (int) $issuedAt;

        return $age >= self::MIN_AGE_SECONDS && $age <= self::TTL_SECONDS;
    }

    public function consume(string $token): bool
    {
        if (!self::isWellFormed($token)) {
            return false;
        }

        return (int) $this->redis->del($this->key($token)) === 1;
    }

    private static function isWellFormed(string $token): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $token) === 1;
    }

    private function key(string $token): string
    {
        return $this->prefix . hash('sha256', $token);
    }
}
