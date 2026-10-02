<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Landing\LeadCsvExport;
use CentralVet\Support\CsvCell;
use CentralVet\Tests\Support\Assert;

/**
 * Landing pública, T-08: célula de CSV à prova de fórmula (`CsvCell::safe`)
 * e linha do CSV de leads da tela LandingLeadList (`LeadCsvExport`).
 */
final class LeadCsvExportTest
{
    public function testSafePrefixesFormulaTriggers(): void
    {
        Assert::same("'=HYPERLINK(\"x\")", CsvCell::safe('=HYPERLINK("x")'));
        Assert::same("'-1", CsvCell::safe('-1'));
        Assert::same("'+1", CsvCell::safe('+1'));
        Assert::same("'@SUM(A1)", CsvCell::safe('@SUM(A1)'));
        Assert::same("'\tx", CsvCell::safe("\tx"));
        Assert::same("'\rx", CsvCell::safe("\rx"));
    }

    public function testSafeKeepsPlainAndEmptyText(): void
    {
        Assert::same('', CsvCell::safe(''));
        Assert::same('Ana', CsvCell::safe('Ana'));
        Assert::same('a=b', CsvCell::safe('a=b'));
    }

    public function testHeaderHasTwelveColumns(): void
    {
        $header = LeadCsvExport::header();

        Assert::count(12, $header);
        Assert::same(
            ['Data', 'Nome', 'Clínica', 'E-mail', 'WhatsApp', 'Veterinários', 'Cidade', 'UF', 'Plano', 'Preço mensal (R$)', 'Consentimento em', 'IP do consentimento'],
            $header
        );
    }

    public function testRowFormatsAndNeutralizesLead(): void
    {
        $row = LeadCsvExport::row($this->lead());

        Assert::count(12, $row);
        Assert::same('01/10/2026 14:05', $row[0]);
        Assert::same("'=cmd", $row[1]);
        Assert::same('Patas & Cia "LP teste"', $row[2]);
        Assert::same('ana+lp@exemplo.com.br', $row[3]);
        Assert::same('11912345678', $row[4]);
        Assert::same('2 a 4', $row[5]);
        Assert::same('São Paulo', $row[6]);
        Assert::same('SP', $row[7]);
        Assert::same('Business', $row[8]);
        Assert::same('137,00', $row[9]);
        Assert::same('01/10/2026 14:05', $row[10]);
        Assert::same('192.168.16.1', $row[11]);
    }

    public function testRowPriceUsesThousandsSeparator(): void
    {
        $lead = $this->lead();
        $lead['plan_price_cents'] = 123456;

        Assert::same('1.234,56', LeadCsvExport::row($lead)[9]);
    }

    /** @return array<string, mixed> */
    private function lead(): array
    {
        return [
            'id' => 7,
            'created_at' => '2026-10-01 14:05:09.123456',
            'name' => '=cmd',
            'clinic_name' => 'Patas & Cia "LP teste"',
            'email' => 'ana+lp@exemplo.com.br',
            'phone' => '11912345678',
            'vets_range' => '2-4',
            'city' => 'São Paulo',
            'uf' => 'SP',
            'plan_id' => 'business',
            'plan_name' => 'Business',
            'plan_price_cents' => 13700,
            'consent_version' => 'lgpd-contato-2026-10',
            'consent_at' => '2026-10-01 14:05:09.123456',
            'consent_ip' => '192.168.16.1',
        ];
    }
}
