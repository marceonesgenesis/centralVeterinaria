<?php

declare(strict_types=1);

namespace CentralVet\Presentation;

/**
 * Parser único de valor em dinheiro digitado nos formulários → centavos
 * inteiros (rodada 2, T-48). Sem Adianti e sem float: a conversão é feita
 * sobre a string, então não há arredondamento nem estouro silencioso.
 *
 * Aceita:
 *  - com vírgula (pt-BR): "1.234,56", "1234,5", "0,01";
 *  - sem vírgula: ponto decimal "1234.56" (replaceOnPost das máscaras) ou
 *    milhar pt-BR sem centavos "1.234" (→ 123400);
 *  - vazio (depois de trim) → 0.
 *
 * Qualquer outra entrada, parte inteira com mais de MAX_INTEGER_DIGITS
 * dígitos ou sinal negativo sem $allowNegative lança
 * \InvalidArgumentException('Invalid amount') (traduzida pelo catálogo
 * UserMessage).
 */
final class MoneyInput
{
    public const MAX_INTEGER_DIGITS = 13;

    private const INVALID = 'Invalid amount';

    public static function toCents(string $raw, bool $allowNegative = false): int
    {
        $raw = trim($raw);

        if ($raw === '') {
            return 0;
        }

        if (str_contains($raw, ',')) {
            if (!preg_match('/^-?\d{1,3}(\.\d{3})*(,\d{1,2})?$/', $raw) && !preg_match('/^-?\d+(,\d{1,2})?$/', $raw)) {
                throw new \InvalidArgumentException(self::INVALID);
            }
            [$integer, $fraction] = array_pad(explode(',', $raw, 2), 2, '');
            $integer = str_replace('.', '', $integer);
        } elseif (preg_match('/^-?\d+(\.\d{1,2})?$/', $raw)) {
            [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $raw)) {
            $integer = str_replace('.', '', $raw);
            $fraction = '';
        } else {
            throw new \InvalidArgumentException(self::INVALID);
        }

        $negative = str_starts_with($integer, '-');
        if ($negative) {
            if (!$allowNegative) {
                throw new \InvalidArgumentException(self::INVALID);
            }
            $integer = substr($integer, 1);
        }

        if (strlen($integer) > self::MAX_INTEGER_DIGITS) {
            throw new \InvalidArgumentException(self::INVALID);
        }

        $cents = (int) $integer * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }
}
