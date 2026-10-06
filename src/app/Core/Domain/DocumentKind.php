<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Closed list of document kinds generated as PDF (Fase 7B). The literals
 * mirror the `generated_document_kind_ck` CHECK of migration 0013.
 *
 * Each kind reads one source (`patient`, `prescription` or `surgery`) and
 * carries a fixed pt-BR title printed on the PDF. Only the medical
 * certificate is built from a tenant template.
 */
final class DocumentKind
{
    public const VACCINATION_CARD = 'vaccination_card';
    public const PRESCRIPTION = 'prescription';
    public const MEDICAL_CERTIFICATE = 'medical_certificate';
    public const SURGERY_CONSENT = 'surgery_consent';

    public const ALL = [
        self::VACCINATION_CARD,
        self::PRESCRIPTION,
        self::MEDICAL_CERTIFICATE,
        self::SURGERY_CONSENT,
    ];

    public const SOURCE_PATIENT = 'patient';
    public const SOURCE_PRESCRIPTION = 'prescription';
    public const SOURCE_SURGERY = 'surgery';

    private function __construct()
    {
    }

    public static function assertValid(string $kind): void
    {
        if (!in_array($kind, self::ALL, true)) {
            throw new InvalidArgumentException("Unknown document kind \"{$kind}\"");
        }
    }

    public static function sourceTypeFor(string $kind): string
    {
        self::assertValid($kind);

        return match ($kind) {
            self::PRESCRIPTION => self::SOURCE_PRESCRIPTION,
            self::SURGERY_CONSENT => self::SOURCE_SURGERY,
            default => self::SOURCE_PATIENT,
        };
    }

    /** pt-BR title printed on the PDF. */
    public static function titleFor(string $kind): string
    {
        self::assertValid($kind);

        return match ($kind) {
            self::VACCINATION_CARD => 'Carteira de vacinação',
            self::PRESCRIPTION => 'Receita',
            self::MEDICAL_CERTIFICATE => 'Atestado',
            self::SURGERY_CONSENT => 'Termo de consentimento cirúrgico',
        };
    }

    public static function usesTemplate(string $kind): bool
    {
        self::assertValid($kind);

        return $kind === self::MEDICAL_CERTIFICATE;
    }

    /** Kinds whose text is frozen in `generated_document.body_text` at request time. */
    public static function requiresBodyText(string $kind): bool
    {
        self::assertValid($kind);

        return $kind === self::MEDICAL_CERTIFICATE || $kind === self::SURGERY_CONSENT;
    }
}
