<?php

declare(strict_types=1);

namespace CentralVet\Communication;

/**
 * A delivery channel provider (e-mail via log/SMTP today; WhatsApp official
 * API later). Implementations must never put the recipient or the body in
 * logs, return values or exception messages.
 */
interface MessageChannelProviderInterface
{
    /** Channel code this provider delivers (e.g. "email"). */
    public function channel(): string;

    /** Provider name recorded with the message (e.g. "log", "smtp"). */
    public function name(): string;

    /**
     * @return string|null provider message id, when the provider has one
     *
     * @throws MessageDeliveryFailed
     */
    public function deliver(OutgoingMessage $message): ?string;
}
