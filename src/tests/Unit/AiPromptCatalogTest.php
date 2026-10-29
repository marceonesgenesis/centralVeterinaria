<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\AiPromptCatalog;
use CentralVet\Ai\PiiMinimizer;
use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiPurpose;
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
        Assert::same('anamnesis_organize.v2', $request->promptVersion);

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

        Assert::same('patient_summary.v2', $request->promptVersion);
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
        Assert::same('exam_summary.v2', $request->promptVersion);
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
        Assert::same('patient_summary.v2', AiPromptCatalog::version('patient_summary'));
        Assert::same('anamnesis_organize.v2', AiPromptCatalog::version('anamnesis_organize'));
        Assert::same('exam_summary.v2', AiPromptCatalog::version('exam_summary'));
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
        Assert::same('anamnesis_organize.v2', $en->promptVersion);
    }

    public function testPatientSummaryMarksDraftPrescriptionsAndUnreviewedExams(): void
    {
        $history = [
            'encounters' => [],
            'prescriptions' => [
                ['created_at' => '2026-09-03 10:00:00', 'status' => 'draft', 'items' => ['Meloxicam 0.1 mg/kg, oral, 24/24 h, 3 dias']],
                ['created_at' => '2026-09-01 10:00:00', 'status' => 'issued', 'items' => ['Prednisolona 5 mg, oral, 24/24 h, 5 dias']],
            ],
            'exam_results' => [
                ['received_at' => '2026-09-04 08:00:00', 'exam_name' => 'Bioquímica', 'result_text' => 'Creatinina 2.8 mg/dL.', 'pending_review' => true],
                ['received_at' => '2026-09-02 08:00:00', 'exam_name' => 'Hemograma', 'result_text' => 'Hematócrito 45%.', 'pending_review' => false],
            ],
        ];

        $pt = AiPromptCatalog::build('patient_summary', ['encounter' => $this->encounter(), 'history' => $history], 'pt', new DateTimeImmutable(self::TODAY));
        $lines = explode("\n", $pt->userContent);

        Assert::true(in_array('- Prescrição de 2026-09-03 (rascunho, não emitida — provisória)', $lines, true), 'draft prescription is marked as provisional');
        Assert::true(in_array('- Prescrição de 2026-09-01', $lines, true), 'issued prescription has no mark');
        Assert::true(in_array('- Bioquímica (2026-09-04) (pendente de revisão — não validado): Creatinina 2.8 mg/dL.', $lines, true), 'exam pending review is marked as not validated');
        Assert::true(in_array('- Hemograma (2026-09-02): Hematócrito 45%.', $lines, true), 'reviewed exam has no mark');
        Assert::same(1, substr_count($pt->userContent, '(rascunho, não emitida — provisória)'));
        Assert::same(1, substr_count($pt->userContent, '(pendente de revisão — não validado)'));
        Assert::stringContains('- Itens marcados como provisórios ou não validados podem mudar: cite-os como tal e não os trate como confirmados.', $pt->system);

        $en = AiPromptCatalog::build('patient_summary', ['encounter' => $this->encounter(), 'history' => $history], 'en', new DateTimeImmutable(self::TODAY));
        Assert::stringContains('(draft, not issued — provisional)', $en->userContent);
        Assert::stringContains('(pending review — not validated)', $en->userContent);
        Assert::stringContains('- Items marked as provisional or not validated may change: mention them as such and do not treat them as confirmed.', $en->system);
        Assert::false(str_contains($en->userContent, 'rascunho'), 'en prompt uses the en labels');
    }

    public function testExamSummaryMarksAReportPendingReview(): void
    {
        $exam = [
            'exam_name' => 'Bioquímica',
            'result_text' => 'Creatinina 2.8 mg/dL.',
            'received_at' => '2026-10-06 09:00:00',
            'pending_review' => true,
            'patient_name' => 'F8A teste Paciente',
            'tutor_name' => 'F8A teste Tutor',
            'species' => 'Felina',
            'breed' => null,
            'sex' => 'F',
            'birth_date' => '2016-01-15',
        ];

        $pending = AiPromptCatalog::build('exam_summary', ['exam' => $exam], 'pt', new DateTimeImmutable(self::TODAY));
        Assert::true(in_array('Exame: Bioquímica (pendente de revisão — não validado)', explode("\n", $pending->userContent), true), 'report pending review is marked');

        $reviewed = AiPromptCatalog::build('exam_summary', ['exam' => ['pending_review' => false] + $exam], 'pt', new DateTimeImmutable(self::TODAY));
        Assert::true(in_array('Exame: Bioquímica', explode("\n", $reviewed->userContent), true), 'reviewed report has no mark');
        Assert::false(str_contains($reviewed->userContent, 'não validado'), 'reviewed report has no mark');
    }

    /**
     * Locks text and version together: changing a system prompt without
     * bumping its version (and this map) fails here.
     */
    public function testPromptTextIsPinnedToItsVersion(): void
    {
        $pinned = [
            'patient_summary.v2' => [
                'pt' => 'df86a8bafc258533823e56f29ebe85c13c8941cdb890628384e6f66671fbdd97',
                'en' => '0996bddb362e1ceca5466fa977c905d1f3079e5994fc3905adfdf67fd9222c79',
            ],
            'anamnesis_organize.v2' => [
                'pt' => '48f304c2288b6d00501dfa2850c6c79b9eec39f2e085ba3e748063e7e0d58d0f',
                'en' => 'a21cffc4a39f879db1a6ec9c9963cfbfc2703f8fefd6c7277f52669d7b563ed7',
            ],
            'exam_summary.v2' => [
                'pt' => 'da623781e0e18d4ae14dc14cbf87542a6612141639f92e286cdba8f0b54582c4',
                'en' => 'be799f09c82f0cd22b3bf62a4b8d40a519ecf0ee4de80b602b744979aea94c63',
            ],
        ];
        $contexts = [
            'patient_summary' => ['encounter' => $this->encounter(), 'history' => []],
            'anamnesis_organize' => ['encounter' => $this->encounter(), 'free_text' => 'Tosse.'],
            'exam_summary' => ['exam' => ['exam_name' => 'Hemograma'] + $this->encounter()],
        ];

        $copilotPinned = [
            'copilot_clinical.v1' => [
                'pt' => '9a18e0ed606b81ea4e677839b16845f90a701b92b9b07ded16c0db5546efa5b0',
                'en' => '48ad4fbbcf2685efaa5734b5b63d1eaf0f93877a0e2d04e6d04bef40bca48324',
            ],
            'copilot_management.v1' => [
                'pt' => 'de132f598d7789891d67fbede46a2df4489fa9ccf18368c63135db1c286a99bb',
                'en' => 'd7ffd28749392ea8c3782ca216c048ef1a90f75e17a9b0a3a81b3e1e2f3d42d1',
            ],
        ];

        Assert::same(array_merge(array_keys($pinned), array_keys($copilotPinned)), array_values(AiPromptCatalog::VERSIONS));
        foreach (AiPurpose::ALL as $purpose) {
            $version = AiPromptCatalog::VERSIONS[$purpose];
            foreach (['pt', 'en'] as $lang) {
                $request = AiPromptCatalog::build($purpose, $contexts[$purpose], $lang, new DateTimeImmutable(self::TODAY));
                Assert::same($version, $request->promptVersion);
                Assert::same($pinned[$version][$lang], hash('sha256', $request->system), "{$version}/{$lang}: prompt text changed; bump the version and this map");
            }
        }
        foreach (AiPurpose::COPILOT as $purpose) {
            $version = AiPromptCatalog::version($purpose);
            foreach (['pt', 'en'] as $lang) {
                Assert::same($copilotPinned[$version][$lang], hash('sha256', AiPromptCatalog::copilotSystem($purpose, $lang)), "{$version}/{$lang}: prompt text changed; bump the version and this map");
            }
        }
    }

    /** T-03 (8B): prompts do copiloto versionados, somente leitura, pt/en. */
    public function testCopilotSystemPromptsPerPurposeAndLocale(): void
    {
        Assert::same('copilot_clinical.v1', AiPromptCatalog::version('copilot_clinical'));
        Assert::same('copilot_management.v1', AiPromptCatalog::version('copilot_management'));

        $clinicalPt = AiPromptCatalog::copilotSystem('copilot_clinical', 'pt');
        Assert::stringContains('somente leitura', $clinicalPt);
        Assert::stringContains('diga que não encontrou', $clinicalPt);
        Assert::stringContains('Não faça diagnóstico, não prescreva', $clinicalPt);
        Assert::stringContains('[NOME]', $clinicalPt);
        Assert::stringContains('pendente de revisão', $clinicalPt);
        Assert::stringContains('prontuário', $clinicalPt);

        $managementEn = AiPromptCatalog::copilotSystem('copilot_management', 'en');
        Assert::stringContains('read-only', $managementEn);
        Assert::stringContains('stock', $managementEn);
        Assert::stringContains('schedule', $managementEn);
        Assert::false(str_contains($managementEn, 'somente leitura'), 'en copilot prompt must be in English');
        Assert::false(str_contains($managementEn, 'medical record'), 'management prompt has no clinical context');

        Assert::same(AiPromptCatalog::copilotSystem('copilot_clinical', 'en'), AiPromptCatalog::copilotSystem('copilot_clinical', 'en-US'));
        Assert::true($clinicalPt !== AiPromptCatalog::copilotSystem('copilot_management', 'pt'));

        Assert::throws(InvalidArgumentException::class, static fn () => AiPromptCatalog::copilotSystem('patient_summary', 'pt'));
        Assert::throws(InvalidArgumentException::class, static fn () => AiPromptCatalog::copilotSystem('next_steps', 'pt'));
        Assert::throws(InvalidArgumentException::class, fn () => AiPromptCatalog::build('copilot_clinical', ['encounter' => $this->encounter()], 'pt'));
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
