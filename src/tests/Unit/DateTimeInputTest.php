<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\DateTimeInput;
use CentralVet\Tests\Support\Assert;
use InvalidArgumentException;

/**
 * Rodada 2, T-53: parser estrito de data e hora digitada. "01/10/2026 11:00"
 * é 1º de outubro (d/m/Y), nunca 10 de janeiro (m/d/Y do DateTimeImmutable).
 */
final class DateTimeInputTest
{
    public function testBrazilianDateIsDayMonthYear(): void
    {
        Assert::same('2026-10-01 11:00:00', DateTimeInput::parse('01/10/2026 11:00')->format('Y-m-d H:i:s'));
        Assert::same('2026-01-10 11:00:00', DateTimeInput::parse('10/01/2026 11:00')->format('Y-m-d H:i:s'));
        Assert::same('2026-10-01 11:00:30', DateTimeInput::parse('01/10/2026 11:00:30')->format('Y-m-d H:i:s'));
    }

    public function testDatabaseFormatIsAccepted(): void
    {
        Assert::same('2026-10-01 11:00:00', DateTimeInput::parse('2026-10-01 11:00')->format('Y-m-d H:i:s'));
        Assert::same('2026-10-01 11:00:45', DateTimeInput::parse('2026-10-01 11:00:45')->format('Y-m-d H:i:s'));
        Assert::same('2026-10-01 11:00:00', DateTimeInput::parse('  2026-10-01 11:00  ')->format('Y-m-d H:i:s'), 'input is trimmed');
    }

    public function testInvalidInputsThrowInvalidDateAndTime(): void
    {
        $cases = ['13/13/2026 11:00', '01/10/2026', 'abc', '', '01/10/2300 11:00', '01/10/1899 11:00', '31/02/2026 11:00', '2026-10-01T11:00', '01/10/2026 25:00', '2026-10-01 11:00 extra'];

        foreach ($cases as $raw) {
            Assert::throws(InvalidArgumentException::class, static fn () => DateTimeInput::parse($raw), "parse('{$raw}') must throw");

            try {
                DateTimeInput::parse($raw);
            } catch (InvalidArgumentException $e) {
                Assert::same('Invalid date and time', $e->getMessage(), "message for '{$raw}'");
            }
        }
    }
}
