<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Sale;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Etapa 2 / T-09: Sale::cancel() is traceable — it requires a reason
 * (trimmed, at most 500 characters), records who cancelled and when, and
 * refuses a second cancellation.
 */
final class SaleCancelTest
{
    private static function sale(): Sale
    {
        return Sale::create(1, 1, 1, null, null, 10, 2500, new DateTimeImmutable('2026-10-21 09:00:00'));
    }

    /** @return array{0: ?string, 1: ?string} [exception class, message] */
    private static function failureOf(callable $callback): array
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            return [$e::class, $e->getMessage()];
        }

        return [null, null];
    }

    public function testBlankReasonIsRefused(): void
    {
        $sale = self::sale();

        Assert::same(
            [InvalidArgumentException::class, 'A cancellation reason is required'],
            self::failureOf(fn () => $sale->cancel('   ', 7, new DateTimeImmutable())),
        );
        Assert::false($sale->isCancelled(), 'A refused cancellation leaves the sale completed');
    }

    public function testReasonLongerThan500CharactersIsRefused(): void
    {
        $sale = self::sale();

        Assert::same(
            [InvalidArgumentException::class, 'The cancellation reason must have at most 500 characters'],
            self::failureOf(fn () => $sale->cancel(str_repeat('á', 501), 7, new DateTimeImmutable())),
        );

        $sale->cancel(str_repeat('á', 500), 7, new DateTimeImmutable());
        Assert::true($sale->isCancelled(), '500 multibyte characters are accepted');
    }

    public function testCancelRecordsTrimmedReasonUserAndDate(): void
    {
        $sale = self::sale();
        $now = new DateTimeImmutable('2026-10-21 11:45:00');

        Assert::false($sale->isCancelled());
        Assert::null($sale->cancelledAt());
        Assert::null($sale->cancelledBySystemUserId());
        Assert::null($sale->cancellationReason());

        $sale->cancel('  Cliente desistiu ', 7, $now);

        Assert::true($sale->isCancelled());
        Assert::same(Sale::STATUS_CANCELLED, $sale->status());
        Assert::same('Cliente desistiu', $sale->cancellationReason());
        Assert::same(7, $sale->cancelledBySystemUserId());
        Assert::same($now, $sale->cancelledAt());
    }

    public function testSecondCancellationIsRefused(): void
    {
        $sale = self::sale();
        $first = new DateTimeImmutable('2026-10-21 11:45:00');
        $sale->cancel('Cliente desistiu', 7, $first);

        Assert::same(
            [InvalidStatusTransitionException::class, 'Sale is already cancelled'],
            self::failureOf(fn () => $sale->cancel('Outro motivo', 8, new DateTimeImmutable())),
        );
        Assert::same('Cliente desistiu', $sale->cancellationReason(), 'The first cancellation is kept');
        Assert::same(7, $sale->cancelledBySystemUserId());
        Assert::same($first, $sale->cancelledAt());
    }

    public function testReconstituteCarriesCancellationData(): void
    {
        $cancelledAt = new DateTimeImmutable('2026-10-21 12:00:00');
        $sale = Sale::reconstitute(
            5, 1, 1, 1, null, null, 10, Sale::STATUS_CANCELLED, 2500,
            new DateTimeImmutable('2026-10-21 09:00:00'), null,
            $cancelledAt, 7, 'Cliente desistiu',
        );

        Assert::true($sale->isCancelled());
        Assert::same($cancelledAt, $sale->cancelledAt());
        Assert::same(7, $sale->cancelledBySystemUserId());
        Assert::same('Cliente desistiu', $sale->cancellationReason());
    }
}
