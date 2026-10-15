<?php

declare(strict_types=1);

namespace CentralVet\Ai;

/**
 * Removes what identifies the tutor or the patient from clinical text before
 * it leaves the system for an AI provider. Known names (whole and each part
 * with 3+ characters, ignoring case and accents), e-mails, CPF, CNPJ and
 * Brazilian phone numbers become fixed markers; clinical numbers such as
 * `12.5` or `120 mg/dL` are kept.
 */
final class PiiMinimizer
{
    public const NAME = '[NOME]';
    public const EMAIL = '[EMAIL]';
    public const PHONE = '[TELEFONE]';
    public const CPF = '[CPF]';
    public const CNPJ = '[CNPJ]';

    /** Name particles that are never replaced on their own. */
    private const PARTICLES = ['das', 'dos', 'des', 'del', 'della', 'van', 'von', 'der'];

    /** Base letter → accented variants accepted in the text. */
    private const ACCENT_CLASSES = [
        'a' => 'aàáâãäå',
        'e' => 'eèéêë',
        'i' => 'iìíîï',
        'o' => 'oòóôõö',
        'u' => 'uùúûü',
        'c' => 'cç',
        'n' => 'nñ',
        'y' => 'yýÿ',
    ];

    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<int, string|null> $knownNames tutor and patient names
     */
    public static function minimize(string $text, array $knownNames): string
    {
        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u', self::EMAIL, $text);
        $text = (string) preg_replace('/(?<!\d)\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}(?!\d)/', self::CNPJ, $text);
        $text = (string) preg_replace('/(?<!\d)\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)/', self::CPF, $text);
        $text = (string) preg_replace(
            '/(?<!\d)(?:\+\s?55[\s.-]*)?(?:\(\s*\d{2}\s*\)\s*|\d{2}[\s.-]+)9?\d{4}[\s.-]?\d{4}(?!\d)/',
            self::PHONE,
            $text,
        );
        $text = (string) preg_replace_callback(
            '/(?<!\d)(?<!\d[.,])\d{10,14}(?!\d)/',
            static fn (array $m): string => self::classifyDigits($m[0]),
            $text,
        );

        $pattern = self::namePattern($knownNames);
        if ($pattern !== null) {
            $text = (string) preg_replace($pattern, self::NAME, $text);
        }

        return $text;
    }

    private static function classifyDigits(string $digits): string
    {
        $length = strlen($digits);
        $validDdd = $digits[0] !== '0' && $digits[1] !== '0';

        return match (true) {
            $length === 14 => self::CNPJ,
            $length === 11 && self::isValidCpf($digits) => self::CPF,
            $length === 11 && $validDdd && $digits[2] === '9' => self::PHONE,
            $length === 11 => self::CPF,
            $length === 10 && $validDdd => self::PHONE,
            ($length === 12 || $length === 13) && str_starts_with($digits, '55') => self::PHONE,
            default => $digits,
        };
    }

    private static function isValidCpf(string $digits): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $digits) === 1) {
            return false;
        }

        for ($position = 9; $position < 11; $position++) {
            $sum = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $digits[$i] * ($position + 1 - $i);
            }
            $check = ($sum * 10) % 11 % 10;
            if ($check !== (int) $digits[$position]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string|null> $knownNames
     */
    private static function namePattern(array $knownNames): ?string
    {
        $candidates = [];
        foreach ($knownNames as $name) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
            if ($name === '') {
                continue;
            }
            $folded = self::fold($name);
            $candidates[$folded] = true;
            foreach (explode(' ', $folded) as $part) {
                if (mb_strlen($part) >= 3 && !in_array($part, self::PARTICLES, true)) {
                    $candidates[$part] = true;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        $candidates = array_keys($candidates);
        usort($candidates, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $alternatives = array_map(self::accentInsensitive(...), $candidates);

        return '/(?<![\p{L}\p{N}])(?:' . implode('|', $alternatives) . ')(?![\p{L}\p{N}])/iu';
    }

    private static function fold(string $value): string
    {
        return strtr(mb_strtolower($value), self::FOLD);
    }

    private static function accentInsensitive(string $folded): string
    {
        $pattern = '';
        foreach (mb_str_split($folded) as $char) {
            if ($char === ' ') {
                $pattern .= '\s+';
            } elseif (isset(self::ACCENT_CLASSES[$char])) {
                $pattern .= '[' . self::ACCENT_CLASSES[$char] . ']';
            } else {
                $pattern .= preg_quote($char, '/');
            }
        }

        return $pattern;
    }
}
