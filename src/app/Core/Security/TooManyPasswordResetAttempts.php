<?php

declare(strict_types=1);

namespace CentralVet\Security;

/**
 * Password reset requests exceeded the limit for the account or the IP.
 */
final class TooManyPasswordResetAttempts extends \RuntimeException
{
    public function __construct(public readonly int $secondsUntilAvailable)
    {
        parent::__construct('Too many password reset attempts');
    }
}
