<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\Management\ConsultarEstoqueTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\AiPurpose;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCopilotStockQuery;
use DateTimeImmutable;

/** T-20 — consultar_estoque. */
final class StockToolTest
{
    private function context(): ToolContext
    {
        return ToolContext::create(
            TenantContext::authenticated(7, 3, 11),
            'corr-t20',
            null,
            AiPurpose::COPILOT_MANAGEMENT,
            null,
        );
    }

    private function tool(FakeCopilotStockQuery $query): ConsultarEstoqueTool
    {
        return new ConsultarEstoqueTool($query, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-15 09:30:00'));
    }

    private function run(ConsultarEstoqueTool $tool, array $input): ToolResult
    {
        Assert::same([], JsonSchemaValidator::validate($tool->inputSchema(), $input));
        $tool->assertInScope($this->context(), $input);

        return $tool->execute($this->context(), $input);
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $balance = '3', ?string $minimum = '5', ?string $expiry = null, ?string $lot = null): array
    {
        return [
            'product_id' => $id,
            'name' => 'Produto ' . $id,
            'balance' => $balance,
            'minimum' => $minimum,
            'next_expiry' => $expiry,
            'lot' => $lot,
        ];
    }

    public function testMetadataAndSchema(): void
    {
        $tool = $this->tool(new FakeCopilotStockQuery());

        Assert::same('consultar_estoque', $tool->name());
        Assert::same('ProductList::onReload', $tool->requiredPermission());
        Assert::same('management', $tool->family());
        Assert::true($tool->label('pt') !== '' && $tool->label('en') !== '' && $tool->description() !== '');

        Assert::true(JsonSchemaValidator::validate($tool->inputSchema(), []) !== [], 'filtro is required');
        Assert::true(JsonSchemaValidator::validate($tool->inputSchema(), ['filtro' => 'tudo']) !== [], 'filtro is an enum');
        Assert::true(JsonSchemaValidator::validate($tool->inputSchema(), ['filtro' => 'termo', 'termo' => 'a']) !== [], 'termo min 2');
        Assert::true(JsonSchemaValidator::validate($tool->inputSchema(), ['filtro' => 'termo', 'termo' => str_repeat('a', 61)]) !== [], 'termo max 60');
        foreach (['abaixo_minimo', 'vencendo_30d', 'termo'] as $filtro) {
            Assert::same([], JsonSchemaValidator::validate($tool->inputSchema(), ['filtro' => $filtro]));
        }
    }

    public function testTermFilterWithoutTermThrowsToolInputException(): void
    {
        $query = new FakeCopilotStockQuery();
        $tool = $this->tool($query);

        Assert::throws(ToolInputException::class, fn () => $this->run($tool, ['filtro' => 'termo']));
        Assert::throws(ToolInputException::class, fn () => $this->run($tool, ['filtro' => 'termo', 'termo' => '   ']));
        Assert::same([], $query->calls(), 'no query without a term');
    }

    public function testBelowMinimumCutsAtFiftyAndFlagsTruncated(): void
    {
        $query = new FakeCopilotStockQuery();
        $query->seed('belowMinimum', array_map(fn (int $i): array => $this->row($i), range(1, 60)));

        $result = $this->run($this->tool($query), ['filtro' => 'abaixo_minimo']);

        Assert::same(50, $result->rowCount);
        Assert::true($result->truncated, '60 items must be truncated');
        Assert::same(['method' => 'belowMinimum', 'args' => [51]], $query->calls()[0]);
        Assert::same(
            ['item' => 'Produto 1', 'saldo' => '3', 'minimo' => '5', 'validade' => null, 'lote' => null],
            $result->data[0],
        );
    }

    public function testBelowMinimumWithFewRowsIsNotTruncated(): void
    {
        $query = new FakeCopilotStockQuery();
        $query->seed('belowMinimum', [$this->row(1), $this->row(2, '0')]);

        $result = $this->run($this->tool($query), ['filtro' => 'abaixo_minimo', 'termo' => 'ignorado']);

        Assert::same(2, $result->rowCount);
        Assert::false($result->truncated);
        Assert::same('0', $result->data[1]['saldo']);
    }

    public function testExpiringUsesTheClockAndThirtyDays(): void
    {
        $query = new FakeCopilotStockQuery();
        $query->seed('expiring', [$this->row(4, '10', null, '2026-11-04', 'L-77')]);

        $result = $this->run($this->tool($query), ['filtro' => 'vencendo_30d']);

        $call = $query->calls()[0];
        Assert::same('expiring', $call['method']);
        Assert::same('2026-10-15', $call['args'][0]->format('Y-m-d'));
        Assert::same([30, 51], [$call['args'][1], $call['args'][2]]);
        Assert::same(
            [['item' => 'Produto 4', 'saldo' => '10', 'minimo' => null, 'validade' => '2026-11-04', 'lote' => 'L-77']],
            $result->data,
        );
    }

    public function testTermSearchTrimsTheTerm(): void
    {
        $query = new FakeCopilotStockQuery();
        $query->seed('search', [$this->row(9, '12', '2', '2027-01-31', 'A1')]);

        $result = $this->run($this->tool($query), ['filtro' => 'termo', 'termo' => '  Racao ']);

        Assert::same(['method' => 'search', 'args' => ['Racao', 51]], $query->calls()[0]);
        Assert::same('Produto 9', $result->data[0]['item']);
        Assert::same([], $result->freeTextFields);
    }
}
