<?php

declare(strict_types=1);

namespace CentralVet\Ai;

/**
 * Removes what identifies the tutor or the patient from clinical text before
 * it leaves the system for an AI provider. Known names (whole, each
 * space-separated token and each part with 3+ letters split on space, `-`,
 * `'` or `’`, ignoring case, accents and Unicode normalization form),
 * e-mails, CPF, CNPJ and Brazilian phone numbers become fixed markers.
 * A name part listed in CLINICAL_TERMS is not masked on its own (the full
 * name still is), and clinical numbers such as `12.5`, `120 mg/dL` or
 * reference ranges like `20 1000-4800 /µL` are kept. A 9-digit cell is
 * always masked; an 8-digit landline only with a prefix (DDD, DDD in
 * parentheses, +55 or trunk `0`), so `2000-5000` alone stays. A match of
 * digits only (no separator inside) right after `\d[.,]` is a decimal part
 * and stays (`1,23456789`, `0,987654321`, `10,987654321`), unless the previous match ends
 * right before the `[.,]` (a list) or it holds a cell and the digits right
 * before the `[.,]` are not 1 digit or 2 non-DDD digits, or follow `\d[.,]`
 * themselves (`85,987654321`, `123,987654321`, `38,5,987654321` are phones); 10–13 glued
 * digits after `\d[.,]` with a valid DDD and a subscriber are a phone (`7,85987654321`); a match with a separator inside is
 * phone-shaped and is never kept by that rule (`1,98765-4321`). The only other exceptions, for a landline
 * without parentheses, +55 or trunk `0`, are a lab unit right after it and a
 * reference marker (VR, ref, referência) right before it.
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

    /**
     * Clinical words (lowercase, unaccented) that, as a single name part, are
     * not masked on their own: "Maria das Dores" must not erase "dores".
     */
    public const CLINICAL_TERMS = [
        'dor', 'dores', 'luz', 'flor', 'clara', 'claro', 'branca', 'branco',
        'rosa', 'febre', 'pena', 'cruz', 'sangue', 'pele', 'pelo', 'cor',
    ];

    /** Lab units that, right after a phone-shaped number without ( ) or +55, keep it. */
    public const LAB_UNITS = [
        'mg/dL', 'g/dL', 'g/L', "/\u{00B5}L", "/\u{03BC}L", '/uL', "\u{00B5}L", "\u{03BC}L",
        '/mm³', '/mm3', 'mm³', 'mm3',
        "x10³/\u{00B5}L", "x10³/\u{03BC}L", "x10^3/\u{00B5}L", "x10^3/\u{03BC}L", 'x10^3/uL', 'x10³/uL',
        'mil/mm³', 'mil/mm3', '%', 'U/L', 'UI/L', 'mmol/L', 'mEq/L', "\u{00B5}mol/L", "\u{03BC}mol/L",
        'ng/mL', 'fL', 'pg',
    ];

    /**
     * Phone shape: optional +55 or trunk `0` (optionally + 2-digit carrier)
     * before a DDD, optional DDD (in parentheses, `(085)` included, loose
     * with a separator, or glued) — all in group `prefix` —, then a cell
     * (9 + 8 digits, `9 8765-4321`/`9|8765|4321` allowed; group `cell`) or a landline
     * (2–9 + 7 digits), never inside a larger number. A separator (after
     * +55, trunk, carrier, DDD and the leading 9, and between the halves) is any run of
     * spaces with up to 3 punctuation or symbol characters (`-`, `–`, `/`,
     * `\`, `_`, `|`, `·`, `,`, NBSP...). A trunk `0` right after `\d[.,]` is
     * the decimal `8,0`, not a prefix; the decimal guard of the match lives
     * in maskPhones().
     */
    private const PHONE_SHAPE = '/(?<!\d)'
        . '(?<prefix>(?:\+' . self::SEPARATOR . '?55' . self::SEPARATOR . '?'
        . '|(?<!\d[.,])0' . self::SEPARATOR . '?(?:[1-9]\d' . self::SEPARATOR . '?)?(?=\(?[\s\p{Z}]*[1-9][1-9]))?'
        . '(?:\([\s\p{Z}]*0?[1-9][1-9][\s\p{Z}]*\)' . self::SEPARATOR . '?|[1-9][1-9]' . self::SEPARATOR . '?)?)'
        . '(?:(?<cell>9' . self::SEPARATOR . '?\d{4}' . self::SEPARATOR . '?\d{4})|[2-9]\d{3}' . self::SEPARATOR . '?\d{4})(?!\d)/u';

    /** Spaces (NBSP included) around up to 3 punctuation/symbol characters, or spaces alone. */
    private const SEPARATOR = '(?:[\s\p{Z}]*[\p{P}\p{S}]{1,3}[\s\p{Z}]*|[\s\p{Z}]+)';

    /** Brazilian area codes (DDD) in use. */
    private const DDDS = [
        '11', '12', '13', '14', '15', '16', '17', '18', '19', '21', '22', '24', '27', '28',
        '31', '32', '33', '34', '35', '37', '38', '41', '42', '43', '44', '45', '46', '47',
        '48', '49', '51', '53', '54', '55', '61', '62', '63', '64', '65', '66', '67', '68',
        '69', '71', '73', '74', '75', '77', '79', '81', '82', '83', '84', '85', '86', '87',
        '88', '89', '91', '92', '93', '94', '95', '96', '97', '98', '99',
    ];

    /** Lab units compared case-sensitively (`PG`/`FL` are page and sheet, not units). */
    private const CASE_SENSITIVE_UNITS = ['pg', 'fL'];

    /** Glued digits with trunk `0` (and optional 2-digit carrier) + DDD + subscriber. */
    private const TRUNK_PHONE = '/^0(?:[1-9]\d)?[1-9][1-9](?:9\d{8}|[2-9]\d{7})$/';

    /** Reference marker right before the number (end of the preceding text). */
    private const REFERENCE_MARKER = '/(?<![\p{L}\p{N}])(?:valor\s+de\s+refer[eê]ncia|refer[eê]ncia|ref|vr)[\s:.(-]{0,3}$/iu';

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
        $text = self::nfc($text);

        $text = (string) preg_replace('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u', self::EMAIL, $text);
        $text = (string) preg_replace('/(?<!\d)\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}(?!\d)/', self::CNPJ, $text);
        $text = (string) preg_replace('/(?<!\d)\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)/', self::CPF, $text);
        $text = self::maskPhones($text);

        $pattern = self::namePattern($knownNames);
        if ($pattern !== null) {
            $text = (string) preg_replace($pattern, self::NAME, $text);
        }

        return $text;
    }

    private static function maskPhones(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/(?<!\d)(?<!\d[.,])\d{10,14}(?!\d)/',
            static fn (array $m): string => self::classifyDigits($m[0]),
            $text,
        );
        $text = (string) preg_replace_callback(
            '/(?<=\d[.,])\d{10,13}(?!\d)/',
            static fn (array $m): string => preg_match(self::gluedPhonePattern(), $m[0]) === 1 ? self::PHONE : $m[0],
            $text,
        );

        $previousEnd = -1;

        return (string) preg_replace_callback(
            self::PHONE_SHAPE,
            static function (array $m) use ($text, &$previousEnd): string {
                [$whole, $offset] = $m[0];
                $isListItem = $offset - 1 === $previousEnd;
                $previousEnd = $offset + strlen($whole);
                if (
                    self::isDecimalPart($text, $whole, $offset, $isListItem, $m['cell'][0] !== null)
                    || self::isClinicalNumber($text, $m)
                ) {
                    return $whole;
                }

                return self::PHONE;
            },
            $text,
            -1,
            $count,
            PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );
    }

    /**
     * A decimal number stays: a match that is 1–3 digits, a decimal comma and
     * 8 digits (`1,23456789`), unless it is a valid DDD followed by a landline
     * 2–5 (`85,34567890`; a rare decimal of that shape is masked, the safe
     * side), or digits only (no separator inside) right after `\d[.,]`,
     * unless the previous match ended right before the `[.,]` (a list such
     * as `98765-4321,98765-1111`). A match holding a cell (9 + 8 digits, with
     * or without a loose or glued DDD) right after `\d[.,]` stays only when
     * the digit run R right before the `[.,]` is not itself preceded by
     * `\d[.,]` and has 1 digit (`0,987654321`) or 2 digits that are not a DDD
     * (`10,987654321`); `85,987654321`, `123,987654321`, `085,987654321` and
     * `38,5,987654321` are phones. `98765.4321` and `85,3456-7890` are phones.
     */
    private static function isDecimalPart(string $text, string $whole, int $offset, bool $isListItem, bool $isCell): bool
    {
        $before = substr($text, 0, $offset);
        if (preg_match('/^(\d{1,3}),(\d{8,9})$/', $whole, $parts) === 1) {
            if ($isCell) {
                return self::isShortDecimalPart($parts[1], $before);
            }

            return !(
                in_array($parts[1], self::DDDS, true)
                && preg_match('/^[2-5]\d{7}$/', $parts[2]) === 1
            );
        }
        if ($isListItem || !ctype_digit($whole) || preg_match('/(\d+)[.,]$/', $before, $run) !== 1) {
            return false;
        }

        return !$isCell || self::isShortDecimalPart($run[1], substr($before, 0, -strlen($run[0])));
    }

    /**
     * The integer part R of a decimal whose fraction looks like a cell: 1
     * digit or 2 digits that are not a DDD, and not itself after `\d[.,]`.
     */
    private static function isShortDecimalPart(string $digits, string $before): bool
    {
        return preg_match('/\d[.,]$/', $before) !== 1
            && (strlen($digits) === 1 || (strlen($digits) === 2 && !in_array($digits, self::DDDS, true)));
    }

    /**
     * A 9-digit cell and a match with parentheses, +55 or trunk `0` are
     * always a phone. A landline without any prefix stays (`2000-5000`). A
     * landline with a loose or glued DDD stays when a lab unit follows it
     * (not followed by a number, as in `pg 2`) or a reference marker comes
     * right before it (`VR: 12 3512-4587`).
     *
     * @param array<int|string, array{0: string|null, 1: int}> $m
     */
    private static function isClinicalNumber(string $text, array $m): bool
    {
        [$whole, $offset] = $m[0];
        if (str_contains($whole, '(') || str_contains($whole, '+') || str_starts_with($whole, '0')) {
            return false;
        }
        if ($m['cell'][0] !== null) {
            return false;
        }
        if ($m['prefix'][0] === '') {
            return true;
        }
        if (preg_match(self::labUnitAheadPattern(), substr($text, $offset + strlen($whole))) === 1) {
            return true;
        }

        return preg_match(self::REFERENCE_MARKER, substr($text, 0, $offset)) === 1;
    }

    /**
     * 10–13 glued digits right after `\d[.,]` that are a phone, not a
     * fraction: optional `55` or trunk `0` (+ optional 2-digit carrier), a
     * valid DDD and a cell (9 + 8) or a landline (2–9 + 7). A fraction of up
     * to 9 digits keeps the decimal guard; `3,14159265358979` has no phone shape.
     */
    private static function gluedPhonePattern(): string
    {
        static $pattern = null;

        return $pattern ??= '/^(?:55|0(?:[1-9]\d)?)?(?:' . implode('|', self::DDDS) . ')(?:9\d{8}|[2-9]\d{7})$/';
    }

    private static function labUnitAheadPattern(): string
    {
        static $pattern = null;

        return $pattern ??= '/^\s*(?:' . implode('|', array_map(
            static fn (string $unit): string => in_array($unit, self::CASE_SENSITIVE_UNITS, true)
                ? '(?-i:' . preg_quote($unit, '/') . ')'
                : preg_quote($unit, '/'),
            self::byLengthDesc(self::LAB_UNITS),
        )) . ')(?![\p{L}\p{N}])(?!\s*\d)(?!\.\d)/iu';
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function byLengthDesc(array $values): array
    {
        usort($values, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $values;
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
            ($length === 12 || $length === 13) && preg_match(self::TRUNK_PHONE, $digits) === 1 => self::PHONE,
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
            $name = trim((string) preg_replace('/\s+/u', ' ', self::nfc((string) $name)));
            if ($name === '') {
                continue;
            }
            $folded = self::fold($name);
            $candidates[$folded] = true;
            foreach (explode(' ', $folded) as $token) {
                $parts = preg_split("/['-]/u", $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($parts) > 1) {
                    $candidates[$token] = true;
                }
                foreach ($parts as $part) {
                    if (
                        mb_strlen($part) >= 3
                        && !in_array($part, self::PARTICLES, true)
                        && !in_array($part, self::CLINICAL_TERMS, true)
                    ) {
                        $candidates[$part] = true;
                    }
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
        return strtr(mb_strtolower($value), self::FOLD + ['’' => "'"]);
    }

    private static function nfc(string $value): string
    {
        if (!class_exists(\Normalizer::class)) {
            return $value;
        }
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $value;
    }

    private static function accentInsensitive(string $folded): string
    {
        $pattern = '';
        foreach (mb_str_split($folded) as $char) {
            if ($char === ' ') {
                $pattern .= '\s+';
            } elseif ($char === "'") {
                $pattern .= "['’]";
            } elseif (isset(self::ACCENT_CLASSES[$char])) {
                $pattern .= '[' . self::ACCENT_CLASSES[$char] . ']';
            } else {
                $pattern .= preg_quote($char, '/');
            }
        }

        return $pattern;
    }
}
