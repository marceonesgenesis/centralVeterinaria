<?php

declare(strict_types=1);

namespace CentralVet\Security;

use CentralVet\Communication\MessageChannelProviderInterface;
use CentralVet\Communication\OutgoingMessage;

/**
 * Password reset e-mail through the core e-mail provider (EmailProviderFactory:
 * sender from SMTP_FROM_ADDRESS/SMTP_FROM_NAME), the same channel as the
 * account invite. Plain-text body (the SMTP provider sends isHTML(false)).
 * $translate is `_t` in the form: fn (string $key, string ...$params): string.
 */
final class PasswordResetMailer
{
    public const REFERENCE = 'password-reset';

    public function __construct(
        private readonly MessageChannelProviderInterface $email,
        private readonly \Closure $translate,
    ) {
    }

    /**
     * Returns the provider message id. MessageDeliveryFailed propagates.
     */
    public function send(string $email, string $name, string $link): ?string
    {
        $translate = $this->translate;

        $body = $translate('Hello ^1, a password reset was requested for your Central Vet Pro user. To set a new password, open: ^2', $name, $link)
            . "\n\n"
            . $translate('If you did not request it, ignore this e-mail.');

        return $this->email->deliver(new OutgoingMessage(
            $email,
            $translate('Password reset'),
            $body,
            self::REFERENCE,
        ));
    }
}
