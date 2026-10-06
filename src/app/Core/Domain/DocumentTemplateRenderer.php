<?php

declare(strict_types=1);

namespace CentralVet\Domain;

/**
 * Plain-text `{{placeholder}}` renderer for document templates (Fase 7B).
 *
 * Only the closed list of placeholders is replaced. A listed name whose
 * value is missing (absent or null) stays as `{{name}}`, so the caller can
 * detect it through `unresolvedPlaceholders()`; any other `{{token}}` is
 * left untouched and reported by `unknownPlaceholders()`. No HTML escaping:
 * the output is plain text.
 */
final class DocumentTemplateRenderer
{
    public const PLACEHOLDERS = ['patient_name', 'species', 'breed', 'tutor_name', 'unit_name', 'clinic_name', 'today'];

    private const TOKEN_PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    private function __construct()
    {
    }

    /** @param array<string, scalar|null> $variables */
    public static function render(string $body, array $variables): string
    {
        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            static function (array $match) use ($variables): string {
                $name = $match[1];

                if (!in_array($name, self::PLACEHOLDERS, true)) {
                    return $match[0];
                }

                $value = $variables[$name] ?? null;

                return $value === null ? $match[0] : (string) $value;
            },
            $body,
        );
    }

    /**
     * Distinct placeholder names outside the closed list, in order of appearance.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(string $body): array
    {
        return array_values(array_filter(
            self::names($body),
            static fn (string $name): bool => !in_array($name, self::PLACEHOLDERS, true),
        ));
    }

    /**
     * Distinct listed placeholders still present in the text, in order of appearance.
     *
     * @return list<string>
     */
    public static function unresolvedPlaceholders(string $body): array
    {
        return array_values(array_filter(
            self::names($body),
            static fn (string $name): bool => in_array($name, self::PLACEHOLDERS, true),
        ));
    }

    /** @return list<string> */
    private static function names(string $body): array
    {
        preg_match_all(self::TOKEN_PATTERN, $body, $matches);

        return array_values(array_unique($matches[1]));
    }
}
