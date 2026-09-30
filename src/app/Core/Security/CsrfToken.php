<?php

declare(strict_types=1);

namespace CentralVet\Security;

/**
 * Pure CSRF token rules for endpoints that are not Adianti forms
 * (e.g. CvShellController::onSwitchUnit).
 *
 * Mirrors BootstrapFormBuilder::enableCSRFProtection/validateCSRFToken:
 * a 32-byte random hex token kept in the session and compared with
 * hash_equals against the posted value. State-changing requests are only
 * accepted via POST.
 */
final class CsrfToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValid(string $requestMethod, mixed $sentToken, mixed $expectedToken): bool
    {
        if (strtoupper($requestMethod) !== 'POST') {
            return false;
        }

        if (!is_string($sentToken) || $sentToken === '' || !is_string($expectedToken) || $expectedToken === '') {
            return false;
        }

        return hash_equals($expectedToken, $sentToken);
    }
}
