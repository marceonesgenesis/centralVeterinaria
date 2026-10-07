<?php

declare(strict_types=1);

namespace CentralVet\Support;

/**
 * Célula de CSV à prova de injeção de fórmula em planilha (landing, T-08).
 * Texto que começa com `=`, `+`, `-`, `@`, tab ou CR ganha um apóstrofo na
 * frente, para o Excel/LibreOffice não interpretá-lo como fórmula. Mesma
 * regra de FinancialOverview::csvSafe (privado, intocado por ora).
 */
final class CsvCell
{
    private const TRIGGERS = "=+-@\t\r";

    private function __construct()
    {
    }

    public static function safe(string $value): string
    {
        return $value !== '' && strpbrk($value[0], self::TRIGGERS) !== false ? "'" . $value : $value;
    }
}
