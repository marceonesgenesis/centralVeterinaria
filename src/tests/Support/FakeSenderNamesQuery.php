<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SenderNamesQueryInterface;

/**
 * Double for SenderNamesQueryInterface (T-05): returns the names given per
 * unit id in the constructor; an unknown unit (or a missing key) is null.
 */
final class FakeSenderNamesQuery implements SenderNamesQueryInterface
{
    /** @param array<int, array{unit_name?: ?string, clinic_name?: ?string}> $namesByUnit */
    public function __construct(private readonly array $namesByUnit)
    {
    }

    public function namesForUnit(int $unitId): array
    {
        $names = $this->namesByUnit[$unitId] ?? [];

        return [
            'unit_name' => $names['unit_name'] ?? null,
            'clinic_name' => $names['clinic_name'] ?? null,
        ];
    }
}
