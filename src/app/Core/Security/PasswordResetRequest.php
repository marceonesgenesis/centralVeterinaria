<?php

declare(strict_types=1);

namespace CentralVet\Security;

/**
 * Handles a "forgot my password" request.
 *
 * Throttled per account (login|ip) and per IP (*|ip). The outcome is the
 * same for unknown, inactive and active logins (void, no exception), so the
 * response does not reveal whether a login exists; only the throttle is
 * observable, and it does not depend on the login existing.
 */
final class PasswordResetRequest
{
    public const PREFIX = 'centralvet:password-reset-throttle:';

    public function __construct(
        private readonly LoginRateLimiter $limiter,
        private readonly PasswordResetToken $tokens,
        private readonly PublicUrl $url,
    ) {
    }

    /**
     * @param callable(string): ?array{login: string, name: string, email: string, active: string, password: string} $findUser
     * @param callable(string, string, string): void $send email, name, link
     *
     * @throws TooManyPasswordResetAttempts
     */
    public function handle(string $login, string $ip, callable $findUser, callable $send, int $now): void
    {
        $identifiers = [
            LoginRateLimiter::identifierFor($login, $ip),
            LoginRateLimiter::identifierFor('*', $ip),
        ];

        $wait = null;
        foreach ($identifiers as $identifier) {
            if ($this->limiter->tooManyAttempts($identifier)) {
                $wait = max($wait ?? 0, $this->limiter->secondsUntilAvailable($identifier));
            }
        }
        if ($wait !== null) {
            throw new TooManyPasswordResetAttempts($wait);
        }

        foreach ($identifiers as $identifier) {
            $this->limiter->hit($identifier);
        }

        $user = $findUser($login);
        if (!is_array($user) || ($user['active'] ?? null) !== 'Y') {
            return;
        }

        $jwt = $this->tokens->issue((string) $user['login'], (string) $user['password'], $now);
        $send(
            (string) $user['email'],
            (string) $user['name'],
            $this->url->to('index.php?class=SystemPasswordResetForm&method=onLoad&jwt=' . rawurlencode($jwt)),
        );
    }
}
