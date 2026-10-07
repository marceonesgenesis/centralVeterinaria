<?php

declare(strict_types=1);

namespace CentralVet\Landing;

use CentralVet\Support\CsvCell;

/**
 * Cabeçalho e linhas do CSV de leads da landing (tela LandingLeadList,
 * T-08). Recebe as linhas de LeadRepository::search() como vêm do banco:
 * datas `Y-m-d H:i:s.uuuuuu` viram `d/m/Y H:i`, `vets_range` vira o rótulo de
 * LandingCatalog::VETS_OPTIONS, o preço gravado (centavos) vira `1.234,56`
 * e todo texto passa por CsvCell::safe. Sem Adianti.
 */
final class LeadCsvExport
{
    /** Idioma do Adianti → `lang` do HTML (mesmo mapa de `src/landing/i18n.js`). */
    private const HTML_LANG = ['pt' => 'pt-BR', 'en' => 'en-US', 'es' => 'es'];

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function header(): array
    {
        return [
            'Data', 'Nome', 'Clínica', 'E-mail', 'WhatsApp', 'Veterinários', 'Cidade', 'UF',
            'Plano', 'Preço mensal (R$)', 'Consentimento em', 'IP do consentimento',
        ];
    }

    /**
     * @param array<string, mixed> $lead linha de LeadRepository::search()
     * @return list<string>
     */
    public static function row(array $lead): array
    {
        return [
            self::dateTime($lead['created_at'] ?? ''),
            self::text($lead['name'] ?? ''),
            self::text($lead['clinic_name'] ?? ''),
            self::text($lead['email'] ?? ''),
            self::text($lead['phone'] ?? ''),
            self::vets($lead['vets_range'] ?? ''),
            self::text($lead['city'] ?? ''),
            self::text($lead['uf'] ?? ''),
            self::text($lead['plan_name'] ?? ''),
            self::price($lead['plan_price_cents'] ?? 0),
            self::dateTime($lead['consent_at'] ?? ''),
            self::text($lead['consent_ip'] ?? ''),
        ];
    }

    /** Rótulo de VETS_OPTIONS; valor desconhecido segue como texto neutralizado. */
    public static function vetsLabel(string $range): string
    {
        return LandingCatalog::VETS_OPTIONS[$range] ?? $range;
    }

    /** Preço mensal em reais (`1.234,56`) a partir dos centavos gravados. */
    public static function priceLabel(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    /** `Y-m-d H:i:s[.u]` do banco → `d/m/Y H:i`; texto fora do formato segue cru. */
    public static function dateTimeLabel(string $value): string
    {
        $value = trim($value);
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('d/m/Y H:i');
            }
        }

        return $value;
    }

    /**
     * Página HTML mínima para a aba `_blank` do onExport quando a exportação
     * falha: mensagem escapada, sem Adianti. O idioma ativo (`pt`|`en`|`es`)
     * vem por parâmetro e só o valor mapeado vai para o `lang`.
     */
    public static function failurePage(string $message, string $language = 'pt'): string
    {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lang = self::HTML_LANG[$language] ?? self::HTML_LANG['pt'];

        return "<!doctype html>\n"
            . "<html lang=\"{$lang}\">\n"
            . "<head>\n"
            . "<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<title>{$safe}</title>\n"
            . "</head>\n"
            . "<body>\n"
            . "<p>{$safe}</p>\n"
            . "</body>\n"
            . "</html>\n";
    }

    private static function text(mixed $value): string
    {
        return CsvCell::safe((string) $value);
    }

    private static function vets(mixed $value): string
    {
        return CsvCell::safe(self::vetsLabel((string) $value));
    }

    private static function price(mixed $value): string
    {
        return self::priceLabel((int) $value);
    }

    private static function dateTime(mixed $value): string
    {
        return CsvCell::safe(self::dateTimeLabel((string) $value));
    }
}
