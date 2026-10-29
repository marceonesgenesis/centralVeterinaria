<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Closed list of message purposes and the LGPD legal basis of each one
 * (Fase 7A). The literals mirror the `*_purpose_ck` and
 * `communication_message_legal_basis_ck` CHECKs of migration
 * 20261006_0012_phase7a_communication.
 *
 * Appointment confirmation and return reminder go out on legitimate
 * interest (sent unless the tutor opted out on the channel); every other
 * purpose needs an explicit opt-in on the channel.
 */
final class MessagePurpose
{
    public const APPOINTMENT_CONFIRMATION = 'appointment_confirmation';
    public const VACCINE_DUE = 'vaccine_due';
    public const RETURN_REMINDER = 'return_reminder';
    public const RECEIVABLE_OPEN = 'receivable_open';
    public const DOCUMENT_READY = 'document_ready';
    public const CUSTOM = 'custom';

    public const LEGAL_BASIS_LEGITIMATE_INTEREST = 'legitimate_interest';
    public const LEGAL_BASIS_CONSENT = 'consent';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::APPOINTMENT_CONFIRMATION,
            self::VACCINE_DUE,
            self::RETURN_REMINDER,
            self::RECEIVABLE_OPEN,
            self::DOCUMENT_READY,
            self::CUSTOM,
        ];
    }

    /** @return list<string> */
    public static function legalBases(): array
    {
        return [self::LEGAL_BASIS_LEGITIMATE_INTEREST, self::LEGAL_BASIS_CONSENT];
    }

    public static function assertValid(string $purpose): void
    {
        if (!in_array($purpose, self::all(), true)) {
            throw new InvalidArgumentException("Unknown message purpose \"{$purpose}\"");
        }
    }

    public static function assertValidLegalBasis(string $legalBasis): void
    {
        if (!in_array($legalBasis, self::legalBases(), true)) {
            throw new InvalidArgumentException("Unknown legal basis \"{$legalBasis}\"");
        }
    }

    public static function legalBasisFor(string $purpose): string
    {
        self::assertValid($purpose);

        return match ($purpose) {
            self::APPOINTMENT_CONFIRMATION, self::RETURN_REMINDER => self::LEGAL_BASIS_LEGITIMATE_INTEREST,
            default => self::LEGAL_BASIS_CONSENT,
        };
    }
}
