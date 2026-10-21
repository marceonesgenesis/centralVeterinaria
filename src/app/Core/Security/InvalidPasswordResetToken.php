<?php

declare(strict_types=1);

namespace CentralVet\Security;

/**
 * The password reset JWT is malformed, badly signed, expired or lacks the
 * required claims.
 */
final class InvalidPasswordResetToken extends \RuntimeException
{
}
