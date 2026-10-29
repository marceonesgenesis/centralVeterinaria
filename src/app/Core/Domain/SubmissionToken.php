<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Form submission token (F2 idempotência, Etapa 2): one per form render
 * (`THidden('submission_token')`), claimed once per tenant/scope in
 * `submission_key` (migration 0016) so a resent or double-clicked form does
 * not record the same sale or payment twice. The scopes mirror
 * `submission_key_scope_ck`.
 */
final class SubmissionToken
{
    public const SCOPE_SALE = 'sale';

    public const SCOPE_PAYMENT = 'payment';

    private const PATTERN = '/\A[0-9a-f]{32}\z/';

    private function __construct()
    {
    }

    /** 32 lowercase hex characters (128 random bits). */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Returns the token unchanged when it is exactly 32 lowercase hex
     * characters (`\z`, so a trailing newline is refused).
     *
     * @throws InvalidArgumentException `Invalid submission token`
     */
    public static function assertValid(string $token): string
    {
        if (preg_match(self::PATTERN, $token) !== 1) {
            throw new InvalidArgumentException('Invalid submission token');
        }

        return $token;
    }
}
