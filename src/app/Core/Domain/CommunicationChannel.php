<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Closed list of outbound communication channels (Fase 7A). The literals
 * mirror the `*_channel_ck` CHECKs of migration
 * 20261006_0012_phase7a_communication.
 */
final class CommunicationChannel
{
    public const EMAIL = 'email';
    public const WHATSAPP = 'whatsapp';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EMAIL, self::WHATSAPP];
    }

    public static function assertValid(string $channel): void
    {
        if (!in_array($channel, self::all(), true)) {
            throw new InvalidArgumentException("Unknown communication channel \"{$channel}\"");
        }
    }
}
