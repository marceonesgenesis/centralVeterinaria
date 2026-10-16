<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\Management\IndicadoresClinicaTool;
use CentralVet\Ai\Tools\Management\ListarDebitosTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\AiPurpose;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCopilotFinanceQuery;
use DateTimeImmutable;

/** T-21 — listar_debitos e indicadores_clinica. */
final class FinanceToolsTest
{
    private const CONTACT_KEYS = ['phone', 'telefone', 'email', 'e-mail', 'cpf', 'document', 'documento', 'address', 'endereco', 'whatsapp', 'tutor_full_name'];

    private function context(): ToolContext
    {
        return ToolContext::create(TenantContext::authenticated(7, 3, 11), 'corr-t21', null, AiPurpose::COPILOT_MANAGEMENT, null);
    }

    private function clock(): \Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-15 09:30:00');
    }

    private function run(AiTool $tool, array $input): ToolResult
    {
        Assert::same([], JsonSchemaValidator::validate($tool->inputSchema(), $input));
        $tool->assertInScope($this->context(), $input);

        return $tool->execute($this->context(), $input);
    }

    /** @return array{method: string, args: list<mixed>} */
    private function lastCall(FakeCopilotFinanceQuery $query): array
    {
        $calls = $query->calls();
        Assert::true($calls !== [], 'the query was not called');

        return $calls[count($calls) - 1];
    }

    public function testListarDebitosMetadata(): void
    {
        $tool = new ListarDebitosTool(new FakeCopilotFinanceQuery(), $this->clock());

        Assert::same('listar_debitos', $tool->name());
        Assert::same('PendingReceivableList::onReload', $tool->requiredPermission());
        Assert::same(ToolFamily::MANAGEMENT, $tool->family());
        Assert::true(ToolFamily::isAllowed(ToolFamily::MANAGEMENT, $tool->name()));
        Assert::same(['vencidos', 'a_vencer'], $tool->inputSchema()['properties']['situacao']['enum']);
        Assert::same(1, $tool->inputSchema()['properties']['limite']['minimum']);
        Assert::same(50, $tool->inputSchema()['properties']['limite']['maximum']);
        Assert::true($tool->label('pt') !== '' && $tool->label('en') !== '' && $tool->label('pt') !== $tool->label('en'));
        Assert::true(JsonSchemaValidator::validate($tool->inputSchema(), ['situacao' => 'pagos']) !== [], 'unknown situation is rejected');
        Assert::true(JsonSchemaValidator::validate($tool->inputSchema(), ['situacao' => 'vencidos', 'limite' => 51]) !== [], 'limit above 50 is rejected');
    }

    public function testListarDebitosReturnsFirstNameMoneyAndDaysOverdueWithoutContact(): void
    {
        $query = new FakeCopilotFinanceQuery();
        $query->seed('openReceivables', [[
            'receivable_id' => 901,
            'tutor_full_name' => 'João Pedro Alves',
            'open_cents' => 123456,
            'due_date' => '2026-10-01',
        ]]);

        $result = $this->run(new ListarDebitosTool($query, $this->clock()), ['situacao' => 'vencidos']);

        Assert::same(1, $result->rowCount);
        $row = $result->data[0];
        Assert::same('João', $row['tutor']);
        Assert::same('R$ 1.234,56', $row['valor']);
        Assert::same('2026-10-01', $row['vencimento']);
        Assert::same(14, $row['dias_em_atraso']);
        foreach (self::CONTACT_KEYS as $key) {
            Assert::false(array_key_exists($key, $row), 'contact key leaked: ' . $key);
        }
        Assert::false(in_array('João Pedro Alves', array_map('strval', $row), true), 'full name leaked');
        Assert::false($result->truncated);

        $call = $this->lastCall($query);
        Assert::same('openReceivables', $call['method']);
        Assert::same('vencidos', $call['args'][0]);
        Assert::same('2026-10-15', substr((string) $call['args'][1], 0, 10));
        Assert::same(21, $call['args'][2], 'default limit 20, plus one row to detect truncation');
    }

    public function testListarDebitosUpcomingHasZeroDaysOverdueAndFlagsTruncation(): void
    {
        $query = new FakeCopilotFinanceQuery();
        $query->seed('openReceivables', [
            ['receivable_id' => 1, 'tutor_full_name' => 'Ana Lima', 'open_cents' => 5, 'due_date' => '2026-10-20'],
            ['receivable_id' => 2, 'tutor_full_name' => 'Bia Rocha', 'open_cents' => 100000000, 'due_date' => '2026-10-21'],
            ['receivable_id' => 3, 'tutor_full_name' => 'Caio Reis', 'open_cents' => 700, 'due_date' => '2026-10-22'],
        ]);

        $result = $this->run(new ListarDebitosTool($query, $this->clock()), ['situacao' => 'a_vencer', 'limite' => 2]);

        Assert::same(2, $result->rowCount);
        Assert::true($result->truncated, 'a third row exists beyond the limit');
        Assert::same('R$ 0,05', $result->data[0]['valor']);
        Assert::same(0, $result->data[0]['dias_em_atraso']);
        Assert::same('R$ 1.000.000,00', $result->data[1]['valor']);
        Assert::same(['a_vencer', 3], [$this->lastCall($query)['args'][0], $this->lastCall($query)['args'][2]]);
    }

    public function testIndicadoresMetadata(): void
    {
        $tool = new IndicadoresClinicaTool(new FakeCopilotFinanceQuery(), $this->clock());

        Assert::same('indicadores_clinica', $tool->name());
        Assert::same('FinancialOverview::onFilter', $tool->requiredPermission());
        Assert::same(ToolFamily::MANAGEMENT, $tool->family());
        Assert::true(ToolFamily::isAllowed(ToolFamily::MANAGEMENT, $tool->name()));
        Assert::same(['mes_atual', 'mes_anterior', 'ultimos_30d'], $tool->inputSchema()['properties']['periodo']['enum']);
        Assert::true(JsonSchemaValidator::validate($tool->inputSchema(), ['periodo' => 'ano']) !== []);
    }

    public function testIndicadoresWithZeroEncountersHasNullTicket(): void
    {
        $query = new FakeCopilotFinanceQuery();
        $query->seed('indicators', ['encounters' => 0, 'revenue_cents' => 5000, 'new_patients' => 0, 'returning_encounters' => 0]);

        $result = $this->run(new IndicadoresClinicaTool($query, $this->clock()), ['periodo' => 'mes_atual']);

        Assert::same(1, $result->rowCount);
        $row = $result->data[0];
        Assert::same(0, $row['atendimentos']);
        Assert::same('R$ 50,00', $row['receita']);
        Assert::same(null, $row['ticket_medio']);
        Assert::same(null, $row['retorno_percentual']);
        Assert::same(0, $row['novos_pacientes']);
    }

    public function testIndicadoresComputesTicketAndReturnRate(): void
    {
        $query = new FakeCopilotFinanceQuery();
        $query->seed('indicators', ['encounters' => 8, 'revenue_cents' => 100000, 'new_patients' => 3, 'returning_encounters' => 3]);

        $row = $this->run(new IndicadoresClinicaTool($query, $this->clock()), ['periodo' => 'mes_atual'])->data[0];

        Assert::same(['periodo', 'atendimentos', 'receita', 'ticket_medio', 'novos_pacientes', 'retorno_percentual'], array_keys($row));
        Assert::same(8, $row['atendimentos']);
        Assert::same('R$ 1.000,00', $row['receita']);
        Assert::same('R$ 125,00', $row['ticket_medio']);
        Assert::same(3, $row['novos_pacientes']);
        Assert::same(37.5, $row['retorno_percentual']);
    }

    public function testPeriodsAreHalfOpenRangesInTheDefaultTimezone(): void
    {
        $expected = [
            'mes_atual' => ['2026-10-01 00:00:00', '2026-11-01 00:00:00', '01/10/2026 a 31/10/2026'],
            'mes_anterior' => ['2026-09-01 00:00:00', '2026-10-01 00:00:00', '01/09/2026 a 30/09/2026'],
            'ultimos_30d' => ['2026-09-16 00:00:00', '2026-10-16 00:00:00', '16/09/2026 a 15/10/2026'],
        ];

        foreach ($expected as $periodo => [$from, $to, $label]) {
            $query = new FakeCopilotFinanceQuery();
            $row = $this->run(new IndicadoresClinicaTool($query, $this->clock()), ['periodo' => $periodo])->data[0];

            Assert::same(['indicators', [$from, $to]], [$this->lastCall($query)['method'], $this->lastCall($query)['args']], $periodo);
            Assert::same($label, $row['periodo'], $periodo);
        }
    }
}
