<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Plain-text `{{placeholder}}` renderer for message templates (Fase 7A).
 *
 * Only the closed list of placeholders is replaced; a missing variable
 * becomes an empty string; any other `{{token}}` is left untouched by
 * `render()` and refused by `assertKnownPlaceholders()`. No HTML escaping
 * or markup: the output is plain text.
 */
final class MessageTemplateRenderer
{
    public const PLACEHOLDERS = [
        'tutor_name',
        'patient_name',
        'unit_name',
        'clinic_name',
        'appointment_date',
        'appointment_time',
        'vaccine_name',
        'due_date',
        'amount_due',
    ];

    private const TOKEN_PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    private function __construct()
    {
    }

    /** @param array<string, scalar|null> $variables */
    public static function render(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            static function (array $match) use ($variables): string {
                $name = $match[1];

                if (!in_array($name, self::PLACEHOLDERS, true)) {
                    return $match[0];
                }

                $value = $variables[$name] ?? null;

                return $value === null ? '' : (string) $value;
            },
            $text,
        );
    }

    public static function assertKnownPlaceholders(string $text): void
    {
        preg_match_all(self::TOKEN_PATTERN, $text, $matches);

        foreach ($matches[1] as $name) {
            if (!in_array($name, self::PLACEHOLDERS, true)) {
                throw new InvalidArgumentException("Unknown placeholder \"{$name}\" in template");
            }
        }
    }
}
