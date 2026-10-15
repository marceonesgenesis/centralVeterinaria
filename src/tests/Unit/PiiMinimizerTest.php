<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\PiiMinimizer;
use CentralVet\Tests\Support\Assert;

final class PiiMinimizerTest
{
    public function testClinicalTermsAllowlistIsPublic(): void
    {
        foreach (['dor', 'dores', 'luz', 'flor', 'clara', 'claro', 'branca', 'branco', 'rosa', 'febre', 'pena', 'cruz'] as $term) {
            Assert::true(in_array($term, PiiMinimizer::CLINICAL_TERMS, true), "CLINICAL_TERMS lacks '{$term}'");
        }
    }

    public function testClinicalTermInsideNameIsKept(): void
    {
        $names = ['Maria das Dores Silva', 'Rex'];

        Assert::same(
            '[NOME] relatou dores abdominais',
            PiiMinimizer::minimize('Maria das Dores Silva relatou dores abdominais', $names),
        );
        Assert::same(
            'Tutora [NOME] diz que o [NOME] tem dor e febre.',
            PiiMinimizer::minimize('Tutora Maria diz que o Rex tem dor e febre.', $names),
        );
    }

    public function testReferenceRangeIsNotPhone(): void
    {
        $text = 'Linfócitos 20 1000-4800 /µL; VR 10–20 mg/dL';
        Assert::same($text, PiiMinimizer::minimize($text, []));

        $withValidDdd = 'Plaquetas 25 2000-4000 /µL; hemácias 55 3500-4500 mg/dL; VCM 62 3000-4000 fL';
        Assert::same($withValidDdd, PiiMinimizer::minimize($withValidDdd, []));
    }

    public function testPhonesWithAndWithoutDddAreMasked(): void
    {
        Assert::same('[TELEFONE]', PiiMinimizer::minimize('(85) 98765-4321', []));
        Assert::same('[TELEFONE]', PiiMinimizer::minimize('85 3261-4321', []));
        Assert::same('[TELEFONE]', PiiMinimizer::minimize('98765-4321', []));
        Assert::same('ligar [TELEFONE] hoje', PiiMinimizer::minimize('ligar 987654321 hoje', []));
        Assert::same('peso 12.5 kg', PiiMinimizer::minimize('peso 12.5 kg', []));
    }

    public function testDddInParenthesesWithSubscriberSixToNineIsMasked(): void
    {
        Assert::same('Contato [TELEFONE]', PiiMinimizer::minimize('Contato (85) 8765-4321', []));
        Assert::same('[TELEFONE]', PiiMinimizer::minimize('+55 85 8765-4321', []));
    }

    public function testLooseDddWithSubscriberSixToNineIsMasked(): void
    {
        Assert::same('[TELEFONE]', PiiMinimizer::minimize('85 8765-4321', []));
        Assert::same('tel: [TELEFONE]', PiiMinimizer::minimize('tel: 85 3500-4500', []));
    }

    public function testPhoneShapedNumbersAreMaskedByDefault(): void
    {
        foreach ([
            'Tutor ligou do 98765-4321 ontem' => 'Tutor ligou do [TELEFONE] ontem',
            'Ref 11 98765-4321' => 'Ref [TELEFONE]',
            'Tutor: 11 3400-5600' => 'Tutor: [TELEFONE]',
            'telefone: 1198765-4321' => 'telefone: [TELEFONE]',
            'Contato 9 8765-4321' => 'Contato [TELEFONE]',
            '(85) 8765-4321' => '[TELEFONE]',
            '85 8765-4321' => '[TELEFONE]',
            '(85) 9 8765-4321' => '[TELEFONE]',
            '+55 85 8765-4321' => '[TELEFONE]',
            'Ligar p/ tutor 98765-4321' => 'Ligar p/ tutor [TELEFONE]',
            'contagem 98765-4321' => 'contagem [TELEFONE]',
            'Leucócitos 12 3500-4500' => 'Leucócitos [TELEFONE]',
            'Plaquetas 95000 1200' => 'Plaquetas [TELEFONE]',
            'Plaquetas 95000-1200' => 'Plaquetas [TELEFONE]',
            'Contato com tutor feito;  Plaquetas 95000-1200' => 'Contato com tutor feito;  Plaquetas [TELEFONE]',
            "Contato com tutor feito.\nPlaquetas 95000 1200" => "Contato com tutor feito.\nPlaquetas [TELEFONE]",
            '[TELEFONE] / Plaquetas 95000-1200' => '[TELEFONE] / Plaquetas [TELEFONE]',
            'VR (85) 3456-7890' => 'VR [TELEFONE]',
            'Ref +55 85 3456-7890' => 'Ref [TELEFONE]',
        ] as $text => $expected) {
            Assert::same($expected, PiiMinimizer::minimize($text, []), "not masked: {$text}");
        }
    }

    public function testSeparatorsTrunkPrefixAndCellBeforeUnitAreMasked(): void
    {
        foreach ([
            'Cel 98765 - 4321' => 'Cel [TELEFONE]',
            'Tel (85) 3456 - 7890' => 'Tel [TELEFONE]',
            "Cel 98765\u{2013}4321" => 'Cel [TELEFONE]',
            "Cel 98765\u{2014}4321" => 'Cel [TELEFONE]',
            "Cel 98765\u{2010}4321" => 'Cel [TELEFONE]',
            "Cel 98765\u{2011}4321" => 'Cel [TELEFONE]',
            'Cel 98765/4321' => 'Cel [TELEFONE]',
            'Cel 98765.4321' => 'Cel [TELEFONE]',
            'Cel 085987654321' => 'Cel [TELEFONE]',
            'Cel 0418598765432' => 'Cel [TELEFONE]',
            'Cel 0218598765432' => 'Cel [TELEFONE]',
            'Cel 98765-4321 pg 2' => 'Cel [TELEFONE] pg 2',
            'Cel 98765-4321 fl. 3' => 'Cel [TELEFONE] fl. 3',
            'Cel 98765-4321 FL' => 'Cel [TELEFONE] FL',
            'Cel 98765-4321 fL' => 'Cel [TELEFONE] fL',
            'Cel 98765-4321 %' => 'Cel [TELEFONE] %',
            'Cel 98765-4321 mg/dL' => 'Cel [TELEFONE] mg/dL',
            'Cel 85 98765-4321 mg/dL' => 'Cel [TELEFONE] mg/dL',
            'CPF 52998224725' => 'CPF [CPF]',
            'Cel 08598765-4321' => 'Cel [TELEFONE]',
            'Cel 0418598765-4321' => 'Cel [TELEFONE]',
            'Tel 0853456-7890' => 'Tel [TELEFONE]',
            'Tel 0 85 98765-4321' => 'Tel [TELEFONE]',
            'Tel (085) 98765-4321' => 'Tel [TELEFONE]',
            'Tel 0 41 85 3456-7890' => 'Tel [TELEFONE]',
        ] as $text => $expected) {
            Assert::same($expected, PiiMinimizer::minimize($text, []), "not masked: {$text}");
        }
        foreach ([
            "Leucócitos 12 3500 \u{2013} 4500 x10³/µL",
            'HCM 62 3000-4000 pg',
            'VCM 62 3000-4000 fL',
            'Hb 8,0 3500-4500 mg/dL',
            'Tel: 3456-7890',
            'Hb 12,5 3500-4500',
            'Hb 8,0 3500-4500',
            'Tel 3456-7890 PG',
            'Tel 3456-7890 pg 2',
        ] as $text) {
            Assert::same($text, PiiMinimizer::minimize($text, []), "masked: {$text}");
        }
    }

    public function testOnlyLabUnitOrReferenceMarkerKeepsTheNumber(): void
    {
        foreach ([
            'Leucócitos 12 3500-4500 x10³/µL',
            'VR: 12 3512-4587',
            '20 1000-4800 mm³',
            "Leucócitos 8 6000-17000 /\u{03BC}L",
            "Leucócitos 12 3500-4500 x10³/\u{03BC}L",
            'Leucócitos 12 3512-4587 x10³/µL',
            'Leucócitos 8,5 (VR: 12 3512-4587)',
            'VR 12 3500-4500',
            'VR 3500-4500',
            'Hb 12,5 3500-4500 mg/dL',
            'Leucócitos 3.500-4.500',
            'VR: 3.500-4.500 /µL',
        ] as $text) {
            Assert::same($text, PiiMinimizer::minimize($text, []), "masked: {$text}");
        }
    }

    public function testCellWithoutDddIsMaskedWithOrWithoutContactWord(): void
    {
        Assert::same('Cel: [TELEFONE]', PiiMinimizer::minimize('Cel: 98765-4321', []));
        Assert::same('WhatsApp - [TELEFONE]', PiiMinimizer::minimize('WhatsApp - 98765-4321', []));
        Assert::same('ligar [TELEFONE] hoje', PiiMinimizer::minimize('ligar 987654321 hoje', []));
        Assert::same('Tutor ligou do [TELEFONE] ontem', PiiMinimizer::minimize('Tutor ligou do 98765-4321 ontem', []));
    }

    public function testContactContextInTheSameSentenceIsMasked(): void
    {
        Assert::same('Telefone do tutor: [TELEFONE]', PiiMinimizer::minimize('Telefone do tutor: 98765-4321', []));
        Assert::same(
            'celular do responsável - [TELEFONE]',
            PiiMinimizer::minimize('celular do responsável - 98765-4321', []),
        );
        Assert::same(
            'Ref: consulta anterior; responsável [TELEFONE]',
            PiiMinimizer::minimize('Ref: consulta anterior; responsável 85 8765-4321', []),
        );
        Assert::same(
            'VR 10–20 mg/dL, contato do tutor [TELEFONE]',
            PiiMinimizer::minimize('VR 10–20 mg/dL, contato do tutor 98765-4321', []),
        );
        Assert::same(
            'VR 3500-4500; whatsapp do tutor [TELEFONE]',
            PiiMinimizer::minimize('VR 3500-4500; whatsapp do tutor 85 8765-4321', []),
        );
        Assert::same('[TELEFONE]', PiiMinimizer::minimize('(85) 9 8765-4321', []));
        Assert::same(
            'Contato da Sra. Maria Silva [TELEFONE]',
            PiiMinimizer::minimize('Contato da Sra. Maria Silva 98765-4321', []),
        );
        Assert::same('Tel. Dra. Ana [TELEFONE]', PiiMinimizer::minimize('Tel. Dra. Ana 98765-4321', []));
        Assert::same('Cel. Sr. João: [TELEFONE]', PiiMinimizer::minimize('Cel. Sr. João: (85) 98765-4321', []));
        Assert::same('cel [TELEFONE] / [TELEFONE]', PiiMinimizer::minimize('cel 98765-4321 / 98765-1111', []));
        Assert::same(
            'Contato [TELEFONE] / [TELEFONE] / Leucócitos [TELEFONE]',
            PiiMinimizer::minimize('Contato (85) 8765-4321 / 85 8765-4321 / Leucócitos 12 3500-4500', []),
        );
    }

    public function testSlashAbbreviationsDoNotChangeMasking(): void
    {
        Assert::same('Ligar p/ tutor [TELEFONE]', PiiMinimizer::minimize('Ligar p/ tutor 98765-4321', []));
        Assert::same(
            'Falar c/ responsável [TELEFONE]',
            PiiMinimizer::minimize('Falar c/ responsável (85) 98765-4321', []),
        );
        Assert::same(
            'Contato s/ resposta, tentar [TELEFONE]',
            PiiMinimizer::minimize('Contato s/ resposta, tentar 98765-4321', []),
        );
        Assert::same(
            'Contato [TELEFONE] / Plaquetas [TELEFONE]',
            PiiMinimizer::minimize('Contato 98765-4321 / Plaquetas 95000-1200', []),
        );
        Assert::same(
            'Contato [TELEFONE] / [TELEFONE] / Leucócitos [TELEFONE]',
            PiiMinimizer::minimize('Contato (85) 8765-4321 / 85 8765-4321 / Leucócitos 12 3500-4500', []),
        );
        foreach ([
            'VR 12 3500-4500',
            'Leucócitos 8,5 (VR: 12 3512-4587)',
        ] as $text) {
            Assert::same($text, PiiMinimizer::minimize($text, []), "masked: {$text}");
        }
    }

    public function testLandlineWithoutPrefixDecimalsAndSentenceEndUnitStay(): void
    {
        foreach ([
            'Plaquetas 2000-5000',
            'Ano 2025/2026',
            'Lote 2024-0001',
            'CRMV 3456 7890',
            'Densidade 1,23456789',
            'Fator 0,987654321',
            'Hematócrito 37 3500-4500 /µL. 2 doses',
            'VR: 12 3512-4587',
            'Leucócitos 12 3500-4500 x10³/µL',
        ] as $text) {
            Assert::same($text, PiiMinimizer::minimize($text, []), "masked: {$text}");
        }
        foreach ([
            'Tel 85 3456-7890' => 'Tel [TELEFONE]',
            '(85) 3456-7890' => '[TELEFONE]',
            '98765-4321' => '[TELEFONE]',
            'Tutor ligou do 98765-4321' => 'Tutor ligou do [TELEFONE]',
            '98765-4321,98765-1111' => '[TELEFONE],[TELEFONE]',
            'Ver 85 3456-7890 pg 2' => 'Ver [TELEFONE] pg 2',
            'Leucócitos 12 3500-4500' => 'Leucócitos [TELEFONE]',
            'Fator 1,85 98765-4321' => 'Fator 1,[TELEFONE]',
            '85 3456-7890,85 3456-7891.98765-1111' => '[TELEFONE],[TELEFONE].[TELEFONE]',
            'Tel 85,98765-4321' => 'Tel [TELEFONE]',
            '1,98765-4321' => '1,[TELEFONE]',
            'Tel 85/3456-7890' => 'Tel [TELEFONE]',
            'Tel 85,3456-7890' => 'Tel [TELEFONE]',
        ] as $text => $expected) {
            Assert::same($expected, PiiMinimizer::minimize($text, []), "not masked: {$text}");
        }
    }

    public function testAnyShortPunctuationOrSpaceSeparatesDddAndHalves(): void
    {
        foreach ([
            'Tel 85\3456-7890' => 'Tel [TELEFONE]',
            'Tel 85_3456-7890' => 'Tel [TELEFONE]',
            'Tel 85|3456-7890' => 'Tel [TELEFONE]',
            "Tel 85\u{00A0}3456-7890" => 'Tel [TELEFONE]',
            'Cel 98765\4321' => 'Cel [TELEFONE]',
            'Cel 98765_4321' => 'Cel [TELEFONE]',
            'Cel 98765|4321' => 'Cel [TELEFONE]',
            "Cel 98765\u{00A0}4321" => 'Cel [TELEFONE]',
            'Cel 85\98765\4321' => 'Cel [TELEFONE]',
            'Cel 85_98765_4321' => 'Cel [TELEFONE]',
            "Cel 85\u{00A0}98765\u{00A0}4321" => 'Cel [TELEFONE]',
            "Cel 98765\u{00B7}4321" => 'Cel [TELEFONE]',
            "Tel 85 \u{00B7} 3456\u{00B7}7890" => 'Tel [TELEFONE]',
            'Cel 98765 _ 4321' => 'Cel [TELEFONE]',
            'Cel 98765~4321' => 'Cel [TELEFONE]',
            'Cel 98765*4321' => 'Cel [TELEFONE]',
            'Tel 85:3456-7890' => 'Tel [TELEFONE]',
            'Tel 85 -- 3456-7890' => 'Tel [TELEFONE]',
            'Tel 85 3456\7890' => 'Tel [TELEFONE]',
            "Tel 85\u{202F}3456\u{2009}7890" => 'Tel [TELEFONE]',
            'Cel 9 8765|4321' => 'Cel [TELEFONE]',
            'Cel 9|8765|4321' => 'Cel [TELEFONE]',
            'Tel (85)_3456_7890' => 'Tel [TELEFONE]',
            'Tel 85#3456#7890' => 'Tel [TELEFONE]',
            'Cel 85;98765;4321' => 'Cel [TELEFONE]',
            'Tel 0_85_3456-7890' => 'Tel [TELEFONE]',
            'Tel +55|85|3456-7890' => 'Tel [TELEFONE]',
            'Tel 85.34567890' => 'Tel [TELEFONE]',
            'Cel 98765,4321' => 'Cel [TELEFONE]',
            '98765|4321,98765_1111' => '[TELEFONE],[TELEFONE]',
        ] as $text => $expected) {
            Assert::same($expected, PiiMinimizer::minimize($text, []), "not masked: {$text}");
        }
        foreach ([
            'Densidade 1,23456789',
            'Fator 0,987654321',
            'Hb 8,0 3500-4500 mg/dL',
            'Plaquetas 2000-5000',
            'Plaquetas 2000|5000',
            'Ano 2025/2026',
            'Ano 2025\2026',
            'Lote 2024-0001',
            'Lote 2024_0001',
            'Densidade 10,23456789',
            'Fator 20,987654321',
            'Hb 8,0 3500|4500 mg/dL',
            'CRMV 3456 7890',
            'VR: 12 3512-4587',
            'VR: 12|3512-4587',
            'Leucócitos 12 3500-4500 x10³/µL',
            'Leucócitos 12_3500_4500 x10³/µL',
            'Hematócrito 37 3500-4500 /µL. 2 doses',
        ] as $text) {
            Assert::same($text, PiiMinimizer::minimize($text, []), "masked: {$text}");
        }
    }

    public function testCommaGluedSubscriberAfterValidDddIsPhone(): void
    {
        foreach ([
            'Tel 85,987654321' => 'Tel [TELEFONE]',
            'Cel 85,987654321' => 'Cel [TELEFONE]',
            '85,987654321' => '[TELEFONE]',
            'Tel 85,34567890' => 'Tel [TELEFONE]',
            'Densidade 11,23456789' => 'Densidade [TELEFONE]',
            'Fator 12,987654321' => 'Fator [TELEFONE]',
            '98765-4321,98765-1111' => '[TELEFONE],[TELEFONE]',
        ] as $text => $expected) {
            Assert::same($expected, PiiMinimizer::minimize($text, []), "not masked: {$text}");
        }
        foreach ([
            'Fator 0,987654321',
            'Fator 10,987654321',
            'Fator 20,987654321',
            'Fator 23,987654321',
            'Densidade 30,23456789',
            'Densidade 1,23456789',
            'Densidade 85,87654321',
            'Densidade 85,812345678',
            'Hb 8,0 3500-4500 mg/dL',
            'Plaquetas 2000-5000',
            'Ano 2025/2026',
            'Lote 2024-0001',
            'CRMV 3456 7890',
            'VR: 12 3512-4587',
            'Leucócitos 12 3500-4500 x10³/µL',
            'Hematócrito 37 3500-4500 /µL. 2 doses',
        ] as $text) {
            Assert::same($text, PiiMinimizer::minimize($text, []), "masked: {$text}");
        }
    }

    public function testCellAfterDecimalIsKeptOnlyAfterShortNonDddPart(): void
    {
        foreach ([
            'Rua das Flores 123,987654321' => 'Rua das Flores 123,[TELEFONE]',
            '123,85987654321' => '123,[TELEFONE]',
            'temp 38,5,987654321' => 'temp 38,5,[TELEFONE]',
            '085,987654321' => '[TELEFONE]',
            '85,987654321' => '[TELEFONE]',
            '98765-4321,98765-1111' => '[TELEFONE],[TELEFONE]',
        ] as $text => $expected) {
            Assert::same($expected, PiiMinimizer::minimize($text, []), "not masked: {$text}");
        }
        foreach ([
            'Fator 0,987654321',
            'Fator 10,987654321',
            'Densidade 1,23456789',
            'Plaquetas 2000-5000',
            'Ano 2025/2026',
            'Hematócrito 37 3500-4500 /µL. 2 doses',
            'VR: 12 3512-4587',
        ] as $text) {
            Assert::same($text, PiiMinimizer::minimize($text, []), "masked: {$text}");
        }
    }

    public function testTenOrMoreDigitsAfterDecimalWithPhoneShapeArePhone(): void
    {
        foreach ([
            '7,85987654321' => '7,[TELEFONE]',
            '10,85987654321' => '10,[TELEFONE]',
            '1,8534567890' => '1,[TELEFONE]',
            'Rua das Flores 7,85987654321' => 'Rua das Flores 7,[TELEFONE]',
            'Apto 10,85987654321' => 'Apto 10,[TELEFONE]',
            'Rua X 1,11987654321' => 'Rua X 1,[TELEFONE]',
            'Rua 10.85987654321' => 'Rua 10.[TELEFONE]',
            '1,085987654321' => '1,[TELEFONE]',
            '1,5585987654321' => '1,[TELEFONE]',
            '123,987654321' => '123,[TELEFONE]',
            'temp 38,5,987654321' => 'temp 38,5,[TELEFONE]',
            '85,987654321' => '[TELEFONE]',
        ] as $text => $expected) {
            Assert::same($expected, PiiMinimizer::minimize($text, []), "not masked: {$text}");
        }
        foreach ([
            'Pi 3,14159265358979',
            'Fator 0,987654321',
            'Densidade 1,23456789',
            'Fator 10,987654321',
            'Fator 1,2334567890',
        ] as $text) {
            Assert::same($text, PiiMinimizer::minimize($text, []), "masked: {$text}");
        }
    }

    public function testLabUnitsArePublicAndContactWordsAreGone(): void
    {
        foreach (['mg/dL', "/\u{00B5}L", "/\u{03BC}L", 'mm³', 'x10³/µL', '%', 'fL'] as $unit) {
            Assert::true(in_array($unit, PiiMinimizer::LAB_UNITS, true), "LAB_UNITS lacks '{$unit}'");
        }
        Assert::false(defined(PiiMinimizer::class . '::CONTACT_WORDS'), 'CONTACT_WORDS must not exist');
    }

    public function testNfdAndNfcNamesMatch(): void
    {
        $nfd = "Jose\u{0301}";
        $nfc = "Jos\u{00E9}";

        Assert::same('[NOME] voltou', PiiMinimizer::minimize("{$nfc} voltou", [$nfd]));
        Assert::same('[NOME] voltou', PiiMinimizer::minimize("{$nfd} voltou", [$nfc]));
    }

    public function testHyphenAndApostropheNamePartsAreIsolated(): void
    {
        $names = ["Ana-Clara D'Ávila"];

        Assert::same('[NOME] trouxe', PiiMinimizer::minimize('Ana trouxe', $names));
        Assert::same('Sra. [NOME] ligou', PiiMinimizer::minimize("Sra. D'Ávila ligou", $names));
        Assert::same('Sra. [NOME] ligou', PiiMinimizer::minimize('Sra. D’Ávila ligou', $names));
        Assert::same('[NOME] trouxe', PiiMinimizer::minimize('Ana-Clara trouxe', $names));
        Assert::same('[NOME] trouxe', PiiMinimizer::minimize("Ana-Clara D'Ávila trouxe", $names));
        Assert::same('urina clara', PiiMinimizer::minimize('urina clara', $names));
    }

    public function testEmailCpfCnpjStillMasked(): void
    {
        $out = PiiMinimizer::minimize(
            'mail a.b@example.invalid, CPF 123.456.789-09, CNPJ 12.345.678/0001-95, CPF 52998224725',
            [],
        );

        Assert::same('mail [EMAIL], CPF [CPF], CNPJ [CNPJ], CPF [CPF]', $out);
    }
}
