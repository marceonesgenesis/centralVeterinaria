<?php

declare(strict_types=1);

namespace CentralVet\Communication;

/**
 * Builds wa.me links for manual WhatsApp sending (Brazilian numbers).
 */
final class WhatsAppLinkBuilder
{
    /**
     * Digits only; 10 or 11 digits get the "55" prefix; 12 or 13 digits
     * starting with "55" are kept; anything else is invalid (null).
     */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $length = strlen($digits);

        if ($length === 10 || $length === 11) {
            return '55' . $digits;
        }
        if (($length === 12 || $length === 13) && str_starts_with($digits, '55')) {
            return $digits;
        }

        return null;
    }

    public static function build(string $phone, string $text): string
    {
        $normalized = self::normalizePhone($phone);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Invalid phone number for WhatsApp');
        }

        return 'https://wa.me/' . $normalized . '?text=' . rawurlencode($text);
    }
}
