<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;
use InvalidArgumentException;

/**
 * The AI assistant cannot answer now. The message is fixed per reason and
 * never carries request data (prompt, text, names or keys); the screen
 * translates the reason code.
 */
final class AiUnavailableException extends DomainException
{
    public const REASONS = [
        'ai_disabled',
        'ai_terms_pending',
        'ai_quota_exceeded',
        'ai_not_configured',
        'ai_auth_failed',
        'ai_timeout',
        'ai_rate_limited',
        'ai_overloaded',
        'ai_refused',
        'ai_truncated',
        'ai_provider_error',
        'ai_empty_input',
        'ai_input_too_long',
    ];

    private readonly string $reason;

    public function __construct(string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown AI unavailability reason');
        }

        $this->reason = $reason;
        parent::__construct('AI assistant is unavailable: ' . $reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
