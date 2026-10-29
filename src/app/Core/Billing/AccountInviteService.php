<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Communication\MessageChannelProviderInterface;
use CentralVet\Communication\OutgoingMessage;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;

/**
 * First access invite for the clinic administrator created by provisioning.
 *
 * Reuses the single-use password reset token (it stops matching once the
 * password hash changes) with a 72 h lifetime, and links to the regular
 * password reset form. The link is built from PublicUrl, never from the
 * request Host. $translate is `_t` in the endpoint:
 * fn (string $key, string ...$params): string.
 */
final class AccountInviteService
{
    public const TTL_SECONDS = 259200;

    public const REFERENCE = 'account-invite';

    public function __construct(
        private readonly PasswordResetToken $tokens,
        private readonly PublicUrl $url,
        private readonly MessageChannelProviderInterface $email,
        private readonly \Closure $translate,
    ) {
    }

    /**
     * Sends the invite and returns the provider message id from deliver().
     * Delivery failures (MessageDeliveryFailed) propagate to the caller.
     */
    public function send(string $login, string $email, string $passwordHash, string $clinicName, int $now): ?string
    {
        $jwt = $this->tokens->issue($login, $passwordHash, $now, self::TTL_SECONDS);
        $link = $this->url->to('index.php?class=SystemPasswordResetForm&method=onLoad&jwt=' . rawurlencode($jwt));
        $translate = $this->translate;

        $body = $translate('Your clinic ^1 is ready. Set your password to access Central Vet Pro: ^2', $clinicName, $link)
            . "\n\n"
            . $translate('This link expires in ^1 hours', (string) intdiv(self::TTL_SECONDS, 3600));

        return $this->email->deliver(new OutgoingMessage(
            $email,
            $translate('Central Vet Pro invitation'),
            $body,
            self::REFERENCE,
        ));
    }
}
