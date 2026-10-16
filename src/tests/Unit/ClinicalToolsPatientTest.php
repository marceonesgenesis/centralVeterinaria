<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\Clinical\BuscarPacienteTool;
use CentralVet\Ai\Tools\Clinical\ObterHistoricoAtendimentosTool;
use CentralVet\Ai\Tools\Clinical\ObterPacienteTool;
use CentralVet\Ai\Tools\Clinical\ObterProntuarioTool;
use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolEntityNotFoundException;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Domain\AiPurpose;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCopilotClinicalQuery;
use DateTimeImmutable;

final class ClinicalToolsPatientTest
{
    private const FORBIDDEN_KEYS = ['cpf', 'phone', 'telefone', 'email', 'address', 'endereco'];

    private function context(?int $anchorPatientId = null): ToolContext
    {
        return ToolContext::create(
            TenantContext::authenticated(7, 11, 3),
            'corr-t10',
            null,
            AiPurpose::COPILOT_CLINICAL,
            $anchorPatientId,
        );
    }

    private function clock(): \Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-15 10:00:00');
    }

    /** @return array<string, mixed> */
    private function patientRow(int $id, string $tutor = 'Maria Souza Lima'): array
    {
        return [
            'id' => $id,
            'name' => 'Rex',
            'species' => 'canine',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2023-09-01',
            'allergies' => 'Dipirona',
            'weight_kg' => '12.40',
            'weight_measured_at' => '2026-10-01 09:00:00',
            'tutor_full_name' => $tutor,
            'cpf' => '12345678900',
            'phone' => '11999990000',
            'email' => 'maria@example.test',
            'address' => 'Rua A, 1',
        ];
    }

    /** @return list<AiTool> */
    private function tools(FakeCopilotClinicalQuery $query): array
    {
        return [
            new BuscarPacienteTool($query, $this->clock()),
            new ObterPacienteTool($query, $this->clock()),
            new ObterHistoricoAtendimentosTool($query, $this->clock()),
            new ObterProntuarioTool($query, $this->clock()),
        ];
    }

    public function testToolsDeclareNamePermissionFamilyAndValidSchema(): void
    {
        $expected = [
            'buscar_paciente' => 'GlobalSearchController::onSearch',
            'obter_paciente' => 'PatientForm::onEdit',
            'obter_historico_atendimentos' => 'EncounterView::onReload',
            'obter_prontuario' => 'EncounterView::onReload',
        ];

        $tools = $this->tools(new FakeCopilotClinicalQuery());
        Assert::same(array_keys($expected), array_map(static fn (AiTool $t): string => $t->name(), $tools));

        foreach ($tools as $tool) {
            Assert::same($expected[$tool->name()], $tool->requiredPermission());
            Assert::same(ToolFamily::CLINICAL, $tool->family());
            Assert::true(ToolFamily::isAllowed(ToolFamily::CLINICAL, $tool->name()));
            Assert::true($tool->description() !== '');
            Assert::true($tool->label('pt') !== '' && $tool->label('en') !== '');
            Assert::same('object', $tool->inputSchema()['type']);
            // Running the validator proves every keyword in the schema is supported.
            JsonSchemaValidator::validate($tool->inputSchema(), []);
        }
    }

    public function testSchemasRejectOutOfRangeInput(): void
    {
        [$buscar, $paciente, $historico, $prontuario] = $this->tools(new FakeCopilotClinicalQuery());

        Assert::same([], JsonSchemaValidator::validate($buscar->inputSchema(), ['termo' => 'Rex', 'limite' => 20]));
        Assert::true(JsonSchemaValidator::validate($buscar->inputSchema(), ['termo' => 'Rex', 'limite' => 21]) !== []);
        Assert::true(JsonSchemaValidator::validate($buscar->inputSchema(), ['termo' => 'R']) !== []);
        Assert::true(JsonSchemaValidator::validate($buscar->inputSchema(), []) !== []);

        Assert::same([], JsonSchemaValidator::validate($paciente->inputSchema(), []));
        Assert::true(JsonSchemaValidator::validate($paciente->inputSchema(), ['patient_id' => 0]) !== []);

        Assert::same([], JsonSchemaValidator::validate($historico->inputSchema(), ['limite' => 10]));
        Assert::true(JsonSchemaValidator::validate($historico->inputSchema(), ['limite' => 11]) !== []);

        Assert::true(JsonSchemaValidator::validate($prontuario->inputSchema(), []) !== []);
        Assert::same([], JsonSchemaValidator::validate($prontuario->inputSchema(), ['encounter_id' => 9]));
    }

    public function testBuscarPacienteReturnsOnlyTutorFirstNameAndNoContactData(): void
    {
        $query = new FakeCopilotClinicalQuery();
        $query->seed('searchPatients', [$this->patientRow(5)]);

        $result = (new BuscarPacienteTool($query, $this->clock()))->execute($this->context(), ['termo' => 'Rex']);

        Assert::same(1, $result->rowCount);
        $row = $result->data[0];
        Assert::same(['id', 'nome', 'especie', 'raca', 'idade', 'tutor'], array_keys($row));
        Assert::same('Maria', $row['tutor']);
        Assert::same('3 anos', $row['idade']);
        foreach (self::FORBIDDEN_KEYS as $key) {
            Assert::false(array_key_exists($key, $row), "Unexpected key {$key}");
        }
        Assert::same(['Maria Souza Lima'], $result->knownNames);
        Assert::same(['searchPatients', 'Rex', 10], [$query->calls()[0]['method'], ...$query->calls()[0]['args']]);
    }

    public function testBuscarPacienteRespectsLimit(): void
    {
        $query = new FakeCopilotClinicalQuery();
        $query->seed('searchPatients', array_map(fn (int $id): array => $this->patientRow($id), range(1, 25)));

        $result = (new BuscarPacienteTool($query, $this->clock()))->execute($this->context(), ['termo' => 'Rex', 'limite' => 20]);

        Assert::same(20, $result->rowCount);
    }

    public function testObterPacienteUsesAnchorWhenPatientIdIsMissing(): void
    {
        $query = new FakeCopilotClinicalQuery();
        $query->seed('patient', [42 => $this->patientRow(42)]);
        $query->seed('tutorNames', [42 => ['Maria Souza Lima']]);
        $query->setInUnit('patient', 42, true);
        $tool = new ObterPacienteTool($query, $this->clock());
        $context = $this->context(42);

        $tool->assertInScope($context, []);
        $result = $tool->execute($context, []);

        Assert::same(1, $result->rowCount);
        $row = $result->data[0];
        Assert::same(42, $row['id']);
        Assert::same('Maria', $row['tutor']);
        Assert::same('Dipirona', $row['alergias']);
        Assert::same('12.40', $row['peso_kg']);
        foreach (self::FORBIDDEN_KEYS as $key) {
            Assert::false(array_key_exists($key, $row), "Unexpected key {$key}");
        }
        Assert::same(['Maria Souza Lima'], $result->knownNames);
        Assert::same(['patientInUnit', 42], [$query->calls()[0]['method'], ...$query->calls()[0]['args']]);
    }

    public function testObterPacienteWithoutIdAndWithoutAnchorIsInputError(): void
    {
        $tool = new ObterPacienteTool(new FakeCopilotClinicalQuery(), $this->clock());

        Assert::throws(ToolInputException::class, fn () => $tool->assertInScope($this->context(), []));
        Assert::throws(ToolInputException::class, fn () => $tool->execute($this->context(), []));
    }

    public function testObterPacienteOutsideUnitIsNotFound(): void
    {
        $query = new FakeCopilotClinicalQuery();
        $query->setInUnit('patient', 8, false);
        $tool = new ObterPacienteTool($query, $this->clock());

        Assert::throws(ToolEntityNotFoundException::class, fn () => $tool->assertInScope($this->context(42), ['patient_id' => 8]));
    }

    public function testObterHistoricoMapsEncountersWithExcerptMotive(): void
    {
        $query = new FakeCopilotClinicalQuery();
        $query->setInUnit('patient', 42, true);
        $query->seed('tutorNames', [42 => ['Maria Souza Lima']]);
        $query->seed('encounters', [[
            'id' => 9,
            'started_at' => '2026-10-01 09:00:00',
            'status' => 'closed',
            'anamnesis_text' => str_repeat('Vômito há dois dias. ', 20),
            'diagnosis_text' => 'Gastrite',
            'clinical_plan_text' => 'Dieta leve',
        ]]);
        $tool = new ObterHistoricoAtendimentosTool($query, $this->clock());
        $context = $this->context(42);

        $tool->assertInScope($context, []);
        $result = $tool->execute($context, []);

        $row = $result->data[0];
        Assert::same(['encounter_id', 'data', 'motivo', 'diagnostico', 'conduta'], array_keys($row));
        Assert::same(9, $row['encounter_id']);
        Assert::true(mb_strlen((string) $row['motivo']) <= 160);
        Assert::same(['motivo', 'diagnostico', 'conduta'], $result->freeTextFields);
        Assert::same(['Maria Souza Lima'], $result->knownNames);
        Assert::same(['encounters', 42, 5], [$query->calls()[1]['method'], ...$query->calls()[1]['args']]);
    }

    public function testObterProntuarioOutsideUnitIsNotFound(): void
    {
        $query = new FakeCopilotClinicalQuery();
        $query->setInUnit('encounter', 9, false);
        $tool = new ObterProntuarioTool($query, $this->clock());

        Assert::throws(ToolEntityNotFoundException::class, fn () => $tool->assertInScope($this->context(), ['encounter_id' => 9]));
    }

    public function testObterProntuarioMarksClinicalFreeTextAndTutorNames(): void
    {
        $query = new FakeCopilotClinicalQuery();
        $query->setInUnit('encounter', 9, true);
        $query->seed('encounter', [9 => [
            'id' => 9,
            'patient_id' => 42,
            'started_at' => '2026-10-01 09:00:00',
            'status' => 'closed',
            'anamnesis_text' => 'Vômito',
            'physical_exam_text' => 'Abdome doloroso',
            'diagnosis_text' => 'Gastrite',
            'clinical_plan_text' => 'Dieta leve',
            'weight_kg' => '12.40',
        ]]);
        $query->seed('tutorNames', [42 => ['Maria Souza Lima']]);
        $tool = new ObterProntuarioTool($query, $this->clock());

        $tool->assertInScope($this->context(), ['encounter_id' => 9]);
        $result = $tool->execute($this->context(), ['encounter_id' => 9]);

        Assert::same(
            ['encounter_id', 'data', 'anamnese', 'exame_fisico', 'diagnostico', 'plano', 'peso_kg'],
            array_keys($result->data[0]),
        );
        Assert::same(['anamnese', 'exame_fisico', 'diagnostico', 'plano'], $result->freeTextFields);
        Assert::same(['Maria Souza Lima'], $result->knownNames);
    }

    public function testObterProntuarioMissingEncounterIsNotFound(): void
    {
        $query = new FakeCopilotClinicalQuery();
        $query->setInUnit('encounter', 9, true);
        $tool = new ObterProntuarioTool($query, $this->clock());

        Assert::throws(ToolEntityNotFoundException::class, fn () => $tool->execute($this->context(), ['encounter_id' => 9]));
    }
}
