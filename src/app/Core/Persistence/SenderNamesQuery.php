<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SenderNamesQueryInterface;
use CentralVet\Tenancy\TenantContext;
use PDO;

/**
 * Names of the active unit and of the current tenant for the manual message
 * placeholders `{{unit_name}}` and `{{clinic_name}}`. Only SELECTs.
 * `system_unit` is filtered by the context's tenant (`tenant_id`, as every
 * new query): a unit of another tenant reads as not found (null). The
 * tenant is always the context's, never caller input.
 */
final class SenderNamesQuery implements SenderNamesQueryInterface
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    public function namesForUnit(int $unitId): array
    {
        $query = TenantQuery::forTenant($this->context->tenantId(), 'su')->andEquals('id', $unitId, 'su');
        $unit = $this->connection->prepare('SELECT su.name FROM system_unit su WHERE ' . $query->whereSql());
        $unit->execute($query->parameters());
        $unitName = $unit->fetchColumn();

        $tenant = $this->connection->prepare(
            "SELECT COALESCE(NULLIF(trade_name, ''), legal_name) FROM tenant WHERE id = :tenant_id"
        );
        $tenant->execute(['tenant_id' => $this->context->tenantId()]);
        $clinicName = $tenant->fetchColumn();

        return [
            'unit_name' => self::nameOrNull($unitName),
            'clinic_name' => self::nameOrNull($clinicName),
        ];
    }

    private static function nameOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
