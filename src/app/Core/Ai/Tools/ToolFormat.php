<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

use DateTimeImmutable;

/**
 * Small formatting helpers shared by the tools to keep output minimal
 * (first name only, age instead of birth date, short excerpts).
 */
final class ToolFormat
{
    private function __construct()
    {
    }

    public static function firstName(?string $fullName): ?string
    {
        $trimmed = trim((string) $fullName);
        if ($trimmed === '') {
            return null;
        }

        return preg_split('/\s+/u', $trimmed)[0];
    }

    /** Age in Portuguese, e.g. "3 anos", "7 meses", "12 dias"; null when unknown or in the future. */
    public static function age(?string $birthDate, DateTimeImmutable $today): ?string
    {
        if ($birthDate === null || trim($birthDate) === '') {
            return null;
        }

        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($birthDate), 0, 10));
        if ($birth === false || $birth > $today) {
            return null;
        }

        $diff = $birth->diff($today->setTime(0, 0));
        if ($diff->y > 0) {
            return $diff->y . ($diff->y === 1 ? ' ano' : ' anos');
        }
        if ($diff->m > 0) {
            return $diff->m . ($diff->m === 1 ? ' mês' : ' meses');
        }

        return $diff->d . ($diff->d === 1 ? ' dia' : ' dias');
    }

    public static function excerpt(?string $text, int $max): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        if ($normalized === '' || $max <= 0) {
            return null;
        }

        if (mb_strlen($normalized) <= $max) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, max(1, $max - 1))) . '…';
    }
}
