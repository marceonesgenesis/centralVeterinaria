<?php

declare(strict_types=1);

namespace CentralVet\Ai;

/**
 * Single locale rule for AI prompts: `en` when the language starts with
 * `en` (case-insensitive), otherwise `pt`. Same rule as
 * AiPromptCatalog::build; the AI screens use it to pick the prompt locale.
 */
final class AiLocale
{
    private function __construct()
    {
    }

    public static function fromLanguage(?string $language): string
    {
        return str_starts_with(strtolower((string) $language), 'en') ? 'en' : 'pt';
    }
}
