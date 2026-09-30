<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\MoneyInput;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;

/**
 * Rodada 2, T-48: parser único de moeda digitada (pt-BR ou ponto decimal
 * das máscaras) → centavos inteiros, sem float; o resto é Invalid amount.
 */
final class MoneyInputTest
{
    public function testValidInputsConvertToCents(): void
    {
        $cases = [
            ['', false, 0],
            ['   ', false, 0],
            ['1.234,56', false, 123456],
            ['1234,5', false, 123450],
            ['1234.56', false, 123456],
            ['1.234', false, 123400],
            ['0,01', false, 1],
            ['12', false, 1200],
            ['1.234.567,89', false, 123456789],
            ['-50,00', true, -5000],
            ['9999999999999,99', false, 999999999999999],
        ];

        foreach ($cases as [$raw, $allowNegative, $expected]) {
            Assert::same($expected, MoneyInput::toCents($raw, $allowNegative), "toCents('{$raw}')");
        }
    }

    public function testInvalidInputsThrowInvalidAmount(): void
    {
        $cases = [
            ['-50,00', false],
            ['abc', true],
            ['12,345', true],
            ['99999999999999,00', true],
            ['99999999999999999999', true],
            ['1,2,3', true],
            ['12.5.0', true],
            ['1.23,45', true],
        ];

        foreach ($cases as [$raw, $allowNegative]) {
            self::assertInvalidAmount($raw, $allowNegative);
        }
    }

    public function testMaxCentsAcceptsValuesUpToTheCeiling(): void
    {
        Assert::same(4294967295, MoneyInput::MAX_UNSIGNED_INT_CENTS);
        Assert::same(4294967295, MoneyInput::toCents('42.949.672,95', false, MoneyInput::MAX_UNSIGNED_INT_CENTS));
        Assert::same(4294967295, MoneyInput::toCents('42949672.95', false, MoneyInput::MAX_UNSIGNED_INT_CENTS));
        Assert::same(0, MoneyInput::toCents('', false, MoneyInput::MAX_UNSIGNED_INT_CENTS));
        Assert::same(-10000, MoneyInput::toCents('-100,00', true, 10000));
        // sem teto: continua valendo só o limite de 13 dígitos.
        Assert::same(5000000000, MoneyInput::toCents('50.000.000,00'));
    }

    public function testMaxCentsRejectsValuesAboveTheCeiling(): void
    {
        self::assertInvalidAmount('42.949.672,96', false, MoneyInput::MAX_UNSIGNED_INT_CENTS);
        self::assertInvalidAmount('50.000.000,00', false, MoneyInput::MAX_UNSIGNED_INT_CENTS);
        self::assertInvalidAmount('42949673', false, MoneyInput::MAX_UNSIGNED_INT_CENTS);
        self::assertInvalidAmount('-100,01', true, 10000);
    }

    public function testLeadingZerosDoNotCountTowardsTheDigitLimit(): void
    {
        Assert::same(50, MoneyInput::toCents('00000000000000,50'));
    }

    private static function assertInvalidAmount(string $raw, bool $allowNegative, ?int $maxCents = null): void
    {
        try {
            $result = MoneyInput::toCents($raw, $allowNegative, $maxCents);
        } catch (\InvalidArgumentException $e) {
            Assert::same('Invalid amount', $e->getMessage(), "toCents('{$raw}') message");
            return;
        }

        throw new AssertionFailedException("toCents('{$raw}') should throw Invalid amount, got {$result}");
    }
}
