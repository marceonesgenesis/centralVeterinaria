<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

/**
 * Read-only lookup of the sender names a manual message shows to the tutor
 * (Fase 7A, correção do gate T-23): `unit_name` is the unit's name and
 * `clinic_name` the tenant's trade name (or legal name without one), the
 * same rule as ReminderSourceQuery. The tenant is never taken from caller
 * input: implementations are bound to the TenantContext they were built with.
 */
interface SenderNamesQueryInterface
{
    /**
     * @return array{unit_name: ?string, clinic_name: ?string} null when the
     *         unit (or the tenant) is not found or its name is empty.
     */
    public function namesForUnit(int $unitId): array;
}
