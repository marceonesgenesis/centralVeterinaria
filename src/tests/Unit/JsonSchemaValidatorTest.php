<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\ToolFormat;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;

final class JsonSchemaValidatorTest
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'patient_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 10],
                'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
                'day' => ['type' => 'string', 'format' => 'date'],
                'only_active' => ['type' => 'boolean'],
            ],
            'required' => ['patient_id'],
        ];
    }

    private function validate(array $input): array
    {
        return (new JsonSchemaValidator())->validate($this->schema(), $input);
    }

    public function testValidInputHasNoErrors(): void
    {
        Assert::same([], $this->validate([
            'patient_id' => 5,
            'query' => 'Rex',
            'status' => 'open',
            'day' => '2026-02-28',
            'only_active' => true,
        ]));
    }

    public function testWrongTypeIsError(): void
    {
        Assert::count(1, $this->validate(['patient_id' => '5']));
        Assert::count(1, $this->validate(['patient_id' => 5, 'only_active' => 'yes']));
        Assert::count(1, $this->validate(['patient_id' => 5.5]));
    }

    public function testMissingRequiredIsError(): void
    {
        Assert::count(1, $this->validate(['query' => 'Rex']));
    }

    public function testEnumOutsideIsError(): void
    {
        Assert::count(1, $this->validate(['patient_id' => 5, 'status' => 'deleted']));
    }

    public function testMaximumAndMinimumAreEnforced(): void
    {
        Assert::count(1, $this->validate(['patient_id' => 1001]));
        Assert::count(1, $this->validate(['patient_id' => 0]));
    }

    public function testStringLengthIsEnforced(): void
    {
        Assert::count(1, $this->validate(['patient_id' => 5, 'query' => 'R']));
        Assert::count(1, $this->validate(['patient_id' => 5, 'query' => 'Rexxxxxxxxx']));
    }

    public function testInvalidDateIsError(): void
    {
        Assert::count(1, $this->validate(['patient_id' => 5, 'day' => '2026-02-30']));
        Assert::count(1, $this->validate(['patient_id' => 5, 'day' => '15/10/2026']));
    }

    public function testExtraKeyIsError(): void
    {
        Assert::count(1, $this->validate(['patient_id' => 5, 'tenant_id' => 2]));
    }

    public function testUnsupportedKeywordThrows(): void
    {
        Assert::throws(\LogicException::class, static fn () => (new JsonSchemaValidator())->validate(
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'pattern' => '^x']]],
            ['a' => 'x'],
        ));
    }

    public function testToolResultCountsRowsAndCapsConstant(): void
    {
        $result = new ToolResult([['id' => 1], ['id' => 2]], true, ['notes'], ['Rex']);

        Assert::same(2, $result->rowCount);
        Assert::true($result->truncated);
        Assert::same(['notes'], $result->freeTextFields);
        Assert::same(['Rex'], $result->knownNames);
        Assert::same(50, ToolResult::MAX_ROWS);
    }

    public function testToolFormatHelpers(): void
    {
        $today = new DateTimeImmutable('2026-10-15');

        Assert::same('Maria', ToolFormat::firstName('  Maria da Silva '));
        Assert::null(ToolFormat::firstName(null));
        Assert::same('3 anos', ToolFormat::age('2023-01-10', $today));
        Assert::same('7 meses', ToolFormat::age('2026-03-01', $today));
        Assert::null(ToolFormat::age(null, $today));
        Assert::same('abc…', ToolFormat::excerpt('abcdef', 4));
        Assert::same('abc', ToolFormat::excerpt('abc', 4));
        Assert::null(ToolFormat::excerpt('  ', 4));
    }
}
