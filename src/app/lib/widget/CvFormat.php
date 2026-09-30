<?php
/**
 * CvFormat — formatação de apresentação do kit Cv* (Central Vet Pro).
 *
 * Valores monetários trafegam em centavos (int) e só viram texto aqui.
 */
class CvFormat
{
    /**
     * Centavos → "R$ 1.234,56" (negativos: "-R$ 1.234,56").
     */
    public static function money(int $cents): string
    {
        $sign  = $cents < 0 ? '-' : '';
        $abs   = abs($cents);
        $units = intdiv($abs, 100);
        $rest  = $abs % 100;

        return $sign . 'R$ ' . number_format($units, 0, ',', '.') . ',' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Variação percentual de $current sobre $previous, com 1 casa.
     * Retorna null quando $previous é 0 (variação indefinida).
     */
    public static function delta(int $current, int $previous): ?float
    {
        if ($previous === 0)
        {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * Percentual com sinal e vírgula decimal: 12.5 → "+12,5%".
     */
    public static function percent(float $value): string
    {
        $sign = $value > 0 ? '+' : ($value < 0 ? '-' : '');
        return $sign . number_format(abs($value), 1, ',', '.') . '%';
    }

    /**
     * Forma de pagamento gravada como categoria (Payment::METHOD_*) → rótulo traduzido.
     * Qualquer outro valor (categoria digitada) volta igual.
     */
    public static function paymentMethod(string $value): string
    {
        $labels = [
            \CentralVet\Domain\Payment::METHOD_CASH          => 'Cash',
            \CentralVet\Domain\Payment::METHOD_DEBIT_CARD    => 'Debit card',
            \CentralVet\Domain\Payment::METHOD_CREDIT_CARD   => 'Credit card',
            \CentralVet\Domain\Payment::METHOD_PIX           => 'Pix',
            \CentralVet\Domain\Payment::METHOD_BANK_TRANSFER => 'Bank transfer',
        ];

        return isset($labels[$value]) ? _t($labels[$value]) : $value;
    }

    /**
     * Escapa texto para saída HTML dentro de TElement.
     */
    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
