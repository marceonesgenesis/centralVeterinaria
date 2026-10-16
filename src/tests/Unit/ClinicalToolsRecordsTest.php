<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\Clinical\ConsultarVacinacaoTool;
use CentralVet\Ai\Tools\Clinical\ObterExamesTool;
use CentralVet\Ai\Tools\Clinical\ObterPrescricoesTool;
use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolEntityNotFoundException;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\AiPurpose;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCopilotClinicalQuery;
use DateTimeImmutable;

/** T-11 — obter_prescricoes, obter_exames, consultar_vacinacao. */
final class ClinicalToolsRecordsTest
{
    private const PATIENT = 42;
    private const CONTACT_KEYS = ['phone', 'telefone', 'email', 'e-mail', 'cpf', 'document', 'documento', 'address', 'endereco', 'whatsapp'];

    private function context(?int $anchor = self::PATIENT): ToolContext
    {
        return ToolContext::create(
            TenantContext::authenticated(7, 3, 11),
            'corr-t11',
            null,
            AiPurpose::COPILOT_CLINICAL,
            $anchor,
        );
    }

    private function clock(): \Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-15 09:30:00');
    }

    private function query(): FakeCopilotClinicalQuery
    {
        $query = new FakeCopilotClinicalQuery();
        $query->setInUnit('patient', self::PATIENT, true);
        $query->seed('tutorNames', [self::PATIENT => ['Maria Souza']]);

        return $query;
    }

    /** @return list<AiTool> */
    private function tools(FakeCopilotClinicalQuery $query): array
    {
        return [
            new ObterPrescricoesTool($query, $this->clock()),
            new ObterExamesTool($query, $this->clock()),
            new ConsultarVacinacaoTool($query, $this->clock()),
        ];
    }

    private function run(AiTool $tool, array $input, ?ToolContext $context = null): ToolResult
    {
        $context ??= $this->context();
        Assert::same([], JsonSchemaValidator::validate($tool->inputSchema(), $input));
        $tool->assertInScope($context, $input);

        return $tool->execute($context, $input);
    }

    private function prescription(string $status, int $id = 1): array
    {
        return [
            'prescription_id' => $id,
            'created_at' => '2026-10-10 14:00:00',
            'valid_until' => '2026-11-10',
            'status' => $status,
            'medication_name' => 'Amoxicilina',
            'dose' => '250',
            'dose_unit' => 'mg',
            'route' => 'oral',
            'frequency' => 'a cada 12 h',
            'duration' => '7 dias',
        ];
    }

    private function exam(?bool $pendingReview, ?string $text = 'Hemograma sem alterações'): array
    {
        return [
            'exam_request_id' => 5,
            'exam_name' => 'Hemograma',
            'requested_at' => '2026-10-12 10:00:00',
            'status' => 'received',
            'pending_review' => $pendingReview,
            'structured_result_text' => $text,
            'received_at' => '2026-10-13 08:00:00',
        ];
    }

    private function vaccine(?string $nextDose, bool $superseded): array
    {
        return [
            'vaccine_catalog_item_id' => 9,
            'vaccine_name' => 'V10',
            'applied_at' => '2025-10-01 10:00:00',
            'next_dose_at' => $nextDose,
            'superseded' => $superseded,
        ];
    }

    public function testMetadataSchemaAndFamily(): void
    {
        [$prescricoes, $exames, $vacinacao] = $this->tools($this->query());

        Assert::same('obter_prescricoes', $prescricoes->name());
        Assert::same('PrescriptionForm::__construct', $prescricoes->requiredPermission());
        Assert::same('obter_exames', $exames->name());
        Assert::same('ExamResultForm::__construct', $exames->requiredPermission());
        Assert::same('consultar_vacinacao', $vacinacao->name());
        Assert::same('VaccinationCardView::onReload', $vacinacao->requiredPermission());

        foreach ([$prescricoes, $exames, $vacinacao] as $tool) {
            Assert::same('clinical', $tool->family());
            Assert::same([], JsonSchemaValidator::validate($tool->inputSchema(), []));
            Assert::same([], JsonSchemaValidator::validate($tool->inputSchema(), ['patient_id' => 42]));
            Assert::true($tool->label('pt') !== '' && $tool->label('en') !== '');
            Assert::true($tool->description() !== '');
        }
    }

    public function testSituacaoOutsideEnumIsRejectedBySchema(): void
    {
        [$prescricoes, $exames] = $this->tools($this->query());

        Assert::same([], JsonSchemaValidator::validate($prescricoes->inputSchema(), ['situacao' => 'todas']));
        Assert::true(JsonSchemaValidator::validate($prescricoes->inputSchema(), ['situacao' => 'vencidas']) !== []);
        Assert::same([], JsonSchemaValidator::validate($exames->inputSchema(), ['situacao' => 'liberados']));
        Assert::true(JsonSchemaValidator::validate($exames->inputSchema(), ['situacao' => 'cancelados']) !== []);
    }

    public function testVaccinationOverdueUnlessSuperseded(): void
    {
        $query = $this->query();
        $query->seed('vaccinations', [
            $this->vaccine('2026-10-01', false),
            $this->vaccine('2026-10-01', true),
            $this->vaccine('2026-10-15', false),
            $this->vaccine(null, false),
        ]);

        $result = $this->run(new ConsultarVacinacaoTool($query, $this->clock()), []);

        Assert::same(true, $result->data[0]['atrasada']);
        Assert::same(false, $result->data[1]['atrasada']);
        Assert::same(false, $result->data[2]['atrasada']);
        Assert::same(false, $result->data[3]['atrasada']);
        Assert::same('V10', $result->data[0]['vacina']);
        Assert::same('2025-10-01', $result->data[0]['aplicada_em']);
        Assert::same('2026-10-01', $result->data[0]['proxima_dose']);
        Assert::null($result->data[3]['proxima_dose']);
    }

    public function testDraftPrescriptionIsProvisional(): void
    {
        $query = $this->query();
        $query->seed('prescriptions', [$this->prescription('draft'), $this->prescription('issued', 2)]);

        $result = $this->run(new ObterPrescricoesTool($query, $this->clock()), []);

        Assert::same(true, $result->data[0]['provisorio']);
        Assert::same(false, $result->data[1]['provisorio']);
        Assert::same('Amoxicilina', $result->data[0]['medicamento']);
        Assert::same('250 mg', $result->data[0]['dose']);
        Assert::same('oral', $result->data[0]['via']);
        Assert::same('a cada 12 h', $result->data[0]['posologia']);
        Assert::same('7 dias', $result->data[0]['duracao']);
        Assert::same('2026-10-10', $result->data[0]['data']);
        Assert::same('2026-11-10', $result->data[0]['validade']);
        Assert::same('draft', $result->data[0]['status']);

        $call = $query->calls()[array_key_last($query->calls())];
        Assert::same('prescriptions', $call['method']);
        Assert::same([self::PATIENT, true, '2026-10-15'], array_slice($call['args'], 0, 3));
    }

    public function testPrescriptionsAllPassesOnlyActiveFalse(): void
    {
        $query = $this->query();
        $this->run(new ObterPrescricoesTool($query, $this->clock()), ['situacao' => 'todas']);

        $call = $query->calls()[array_key_last($query->calls())];
        Assert::same(false, $call['args'][1]);
    }

    public function testPendingReviewExamIsProvisionalWithExcerpt(): void
    {
        $query = $this->query();
        $query->seed('exams', [$this->exam(true, str_repeat('a ', 300)), $this->exam(false), $this->exam(null, null)]);

        $result = $this->run(new ObterExamesTool($query, $this->clock()), ['situacao' => 'pendentes']);

        Assert::same(true, $result->data[0]['provisorio']);
        Assert::same(false, $result->data[1]['provisorio']);
        Assert::same(false, $result->data[2]['provisorio']);
        Assert::true(mb_strlen((string) $result->data[0]['resultado']) <= 400);
        Assert::same('Hemograma sem alterações', $result->data[1]['resultado']);
        Assert::null($result->data[2]['resultado']);
        Assert::same('Hemograma', $result->data[1]['exame']);
        Assert::same('2026-10-12', $result->data[1]['data']);
        Assert::same('received', $result->data[1]['status']);
        Assert::same(['resultado'], $result->freeTextFields);

        $call = $query->calls()[array_key_last($query->calls())];
        Assert::same('exams', $call['method']);
        Assert::same([self::PATIENT, 'pendentes'], array_slice($call['args'], 0, 2));
    }

    public function testExamsDefaultToTodos(): void
    {
        $query = $this->query();
        $this->run(new ObterExamesTool($query, $this->clock()), []);

        $call = $query->calls()[array_key_last($query->calls())];
        Assert::same('todos', $call['args'][1]);
    }

    public function testPatientOutsideUnitIsNotFound(): void
    {
        $query = $this->query();
        $query->setInUnit('patient', 99, false);

        foreach ($this->tools($query) as $tool) {
            Assert::throws(ToolEntityNotFoundException::class, fn () => $tool->assertInScope($this->context(), ['patient_id' => 99]));
            Assert::throws(ToolEntityNotFoundException::class, fn () => $tool->execute($this->context(), ['patient_id' => 99]));
        }
    }

    public function testMissingPatientWithoutAnchorIsInputError(): void
    {
        foreach ($this->tools($this->query()) as $tool) {
            Assert::throws(ToolInputException::class, fn () => $tool->assertInScope($this->context(null), []));
        }
    }

    public function testExplicitPatientIdOverridesAnchor(): void
    {
        $query = $this->query();
        $query->setInUnit('patient', 77, true);
        $this->run(new ConsultarVacinacaoTool($query, $this->clock()), ['patient_id' => 77]);

        $call = $query->calls()[array_key_last($query->calls())];
        Assert::same('vaccinations', $call['method']);
        Assert::same(77, $call['args'][0]);
    }

    public function testAtMostFiftyRowsAndTruncatedFlag(): void
    {
        $query = $this->query();
        $query->seed('prescriptions', array_map(fn (int $i): array => $this->prescription('issued', $i), range(1, 60)));
        $query->seed('exams', array_fill(0, 60, $this->exam(false)));
        $query->seed('vaccinations', array_fill(0, 60, $this->vaccine(null, false)));

        foreach ($this->tools($query) as $tool) {
            $result = $this->run($tool, []);
            Assert::count(ToolResult::MAX_ROWS, $result->data, $tool->name());
            Assert::true($result->truncated, $tool->name() . ' truncated');
        }

        $small = $this->query();
        $small->seed('vaccinations', [$this->vaccine(null, false)]);
        Assert::false($this->run(new ConsultarVacinacaoTool($small, $this->clock()), [])->truncated);
    }

    public function testNoTutorContactKeys(): void
    {
        $query = $this->query();
        $query->seed('prescriptions', [$this->prescription('issued') + ['tutor_phone' => '1199', 'email' => 'x@y.z']]);
        $query->seed('exams', [$this->exam(false) + ['phone' => '1199', 'cpf' => '000']]);
        $query->seed('vaccinations', [$this->vaccine(null, false) + ['address' => 'Rua A']]);

        foreach ($this->tools($query) as $tool) {
            foreach ($this->run($tool, [])->data as $row) {
                foreach (array_keys($row) as $key) {
                    foreach (self::CONTACT_KEYS as $contact) {
                        Assert::false(str_contains(strtolower($key), $contact), $tool->name() . ' leaks ' . $key);
                    }
                }
            }
        }
    }
}
