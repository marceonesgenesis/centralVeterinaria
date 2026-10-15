<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\AiPromptCatalog;
use CentralVet\Ai\PiiMinimizer;
use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

final class AiPromptCatalogTest
{
    private const TODAY = '2026-10-07';

    public function testAnamnesisFreeTextLeavesNoPersonalData(): void
    {
        $freeText = 'Tutor F8A teste Tutor relata vômito há 2 dias. Contato: f8a.teste@example.invalid, '
            . '(11) 98765-4321, CPF 123.456.789-09. O F8A teste Paciente comeu ração nova. '
            . 'Glicemia 120 mg/dL, temperatura 38.5.';

        $request = AiPromptCatalog::build(
            'anamnesis_organize',
            ['encounter' => $this->encounter(), 'free_text' => $freeText],
            'pt',
            new DateTimeImmutable(self::TODAY),
        );

        Assert::instanceOf(AiCompletionRequest::class, $request);
        Assert::same('anamnesis_organize', $request->purpose);
        Assert::same('anamnesis_organize.v1', $request->promptVersion);

        foreach (['F8A teste', 'Tutor', 'Paciente', 'f8a.teste@example.invalid', '98765-4321', '123.456.789-09'] as $leak) {
            Assert::false(str_contains($request->userContent, $leak), "userContent leaks '{$leak}'");
        }

        foreach (['[NOME]', '[EMAIL]', '[TELEFONE]', '[CPF]', '120 mg/dL', '38.5', 'vômito'] as $kept) {
            Assert::stringContains($kept, $request->userContent);
        }
    }

    public function testBirthDateIsReplacedByAgeAndIdsAreNotSent(): void
    {
        $request = AiPromptCatalog::build(
            'anamnesis_organize',
            ['encounter' => $this->encounter(), 'free_text' => 'Apatia.'],
            'pt',
            new DateTimeImmutable(self::TODAY),
        );

        Assert::false(str_contains($request->userContent, '2023-08-01'), 'birth_date must not be sent');
        Assert::stringContains('3 anos e 2 meses', $request->userContent);
        Assert::stringContains('Canina', $request->userContent);
        Assert::stringContains('Labrador', $request->userContent);
        Assert::stringContains('Macho', $request->userContent);
        Assert::stringContains('12.4 kg', $request->userContent);
        Assert::false(str_contains($request->userContent, '987654'), 'ids must not be sent');
        Assert::false(str_contains($request->userContent, '424242'), 'ids must not be sent');
    }

    public function testPatientSummaryMinimizesHistoryTexts(): void
    {
        $history = [
            'encounters' => [[
                'started_at' => '2026-09-01 10:00:00',
                'anamnesis_text' => 'F8A TESTE PACIENTE com prurido; tutor F8A Teste Tutor (11987654321) informa.',
                'physical_exam_text' => 'Paciente Paciénte estável, T 38.5.',
                'diagnosis_text' => 'Dermatite. Falar com tutor@example.invalid.',
                'clinical_plan_text' => 'Retorno em 15 dias.',
            ]],
            'prescriptions' => [[
                'created_at' => '2026-09-01 10:30:00',
                'items' => ['Prednisolona 5 mg, 1 comprimido a cada 24 h'],
            ]],
            'exam_results' => [[
                'received_at' => '2026-09-02 08:00:00',
                'exam_name' => 'Hemograma',
                'result_text' => 'Hematócrito 45%. Glicose 120 mg/dL. Responsável F8A teste Tutor.',
            ]],
        ];

        $request = AiPromptCatalog::build(
            'patient_summary',
            ['encounter' => $this->encounter(), 'history' => $history],
            'pt',
            new DateTimeImmutable(self::TODAY),
        );

        Assert::same('patient_summary.v1', $request->promptVersion);
        foreach (['F8A', 'teste', 'TESTE', 'Tutor', 'Paciente', 'Paciénte', '11987654321', 'tutor@example.invalid'] as $leak) {
            Assert::false(str_contains($request->userContent, $leak), "userContent leaks '{$leak}'");
        }
        foreach (['prurido', 'Dermatite', 'Prednisolona 5 mg', 'Hemograma', 'Hematócrito 45%', '120 mg/dL', '38.5', '2026-09-01'] as $kept) {
            Assert::stringContains($kept, $request->userContent);
        }
        Assert::stringContains('Histórico relevante', $request->system);
        Assert::stringContains('Pontos de atenção registrados', $request->system);
    }

    public function testExamSummaryUsesOnlyTheReport(): void
    {
        $request = AiPromptCatalog::build(
            'exam_summary',
            ['exam' => [
                'exam_result_id' => 555001,
                'encounter_id' => 987654,
                'system_unit_id' => 3,
                'patient_id' => 424242,
                'exam_name' => 'Bioquímica',
                'result_text' => 'Creatinina 2.8 mg/dL (ref 0.5-1.5). Laudo para F8A teste Tutor.',
                'received_at' => '2026-10-06 09:00:00',
                'patient_name' => 'F8A teste Paciente',
                'tutor_name' => 'F8A teste Tutor',
                'species' => 'Felina',
                'breed' => null,
                'sex' => 'F',
                'birth_date' => '2016-01-15',
            ]],
            'pt',
            new DateTimeImmutable(self::TODAY),
        );

        Assert::same('exam_summary', $request->purpose);
        Assert::same('exam_summary.v1', $request->promptVersion);
        Assert::stringContains('Creatinina 2.8 mg/dL (ref 0.5-1.5)', $request->userContent);
        Assert::stringContains('Bioquímica', $request->userContent);
        Assert::stringContains('10 anos e 8 meses', $request->userContent);
        Assert::stringContains('Fêmea', $request->userContent);
        Assert::stringContains('não informado', $request->userContent);
        foreach (['F8A', 'Tutor', 'Paciente', '2016-01-15', '555001', '424242'] as $leak) {
            Assert::false(str_contains($request->userContent, $leak), "userContent leaks '{$leak}'");
        }
        Assert::stringContains('Valores fora da referência informados no laudo', $request->system);
    }

    public function testVersionsOfTheThreePurposes(): void
    {
        Assert::same('patient_summary.v1', AiPromptCatalog::version('patient_summary'));
        Assert::same('anamnesis_organize.v1', AiPromptCatalog::version('anamnesis_organize'));
        Assert::same('exam_summary.v1', AiPromptCatalog::version('exam_summary'));
    }

    public function testEnglishLocaleUsesEnglishSystemPrompt(): void
    {
        $pt = AiPromptCatalog::build('anamnesis_organize', ['encounter' => $this->encounter(), 'free_text' => 'Tosse.'], 'pt');
        $en = AiPromptCatalog::build('anamnesis_organize', ['encounter' => $this->encounter(), 'free_text' => 'Tosse.'], 'en');

        Assert::stringContains('Queixa principal', $pt->system);
        Assert::stringContains('português do Brasil', $pt->system);
        Assert::stringContains('Chief complaint', $en->system);
        Assert::stringContains('in English', $en->system);
        Assert::false(str_contains($en->system, 'Queixa principal'), 'en system prompt must be in English');
        Assert::stringContains('Species', $en->userContent);
        Assert::same('anamnesis_organize.v1', $en->promptVersion);
    }

    public function testUnknownPurposeThrows(): void
    {
        Assert::throws(InvalidArgumentException::class, static fn () => AiPromptCatalog::version('next_steps'));
        Assert::throws(InvalidArgumentException::class, fn () => AiPromptCatalog::build('next_steps', ['encounter' => $this->encounter()], 'pt'));
    }

    public function testIncompleteContextThrows(): void
    {
        Assert::throws(InvalidArgumentException::class, static fn () => AiPromptCatalog::build('exam_summary', [], 'pt'));
        Assert::throws(InvalidArgumentException::class, static fn () => AiPromptCatalog::build('patient_summary', ['history' => []], 'pt'));
    }

    public function testMinimizerMarkersAndPreservedNumbers(): void
    {
        $text = 'José da Silva, jose.silva@example.invalid, CNPJ 12.345.678/0001-95, CNPJ 12345678000195, '
            . 'CPF 52998224725, fone +55 11 98765-4321, fixo (11) 3456-7890, 1134567890, '
            . 'peso 12.5 kg, glicose 120 mg/dL, T 38.5, JOSE SILVA voltou da consulta.';

        $out = PiiMinimizer::minimize($text, ['José da Silva']);

        foreach (['José', 'JOSE', 'Silva', 'SILVA', 'jose.silva', '12.345.678/0001-95', '12345678000195', '52998224725', '98765-4321', '3456-7890', '1134567890'] as $leak) {
            Assert::false(str_contains($out, $leak), "minimized text leaks '{$leak}'");
        }
        foreach (['[NOME]', '[EMAIL]', '[CNPJ]', '[CPF]', '[TELEFONE]', '12.5 kg', '120 mg/dL', '38.5', ' da '] as $kept) {
            Assert::stringContains($kept, $out);
        }
        Assert::same('Sem nomes.', PiiMinimizer::minimize('Sem nomes.', [null, '', '  ']));
    }

    /** @return array<string, mixed> */
    private function encounter(): array
    {
        return [
            'encounter_id' => 987654,
            'system_unit_id' => 3,
            'patient_id' => 424242,
            'status' => 'in_progress',
            'anamnesis_text' => 'Anamnese anterior de F8A teste Paciente.',
            'patient_name' => 'F8A teste Paciente',
            'tutor_name' => 'F8A teste Tutor',
            'species' => 'Canina',
            'breed' => 'Labrador',
            'sex' => 'M',
            'birth_date' => '2023-08-01',
            'weight_kg' => '12.4',
        ];
    }
}
