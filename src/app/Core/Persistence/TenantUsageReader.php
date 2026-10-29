<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Billing\Contract\TenantUsageReaderInterface;
use PDO;

/**
 * Contagens por tenant para o QuotaGuard. Sem TenantContext: o tenant vem
 * do chamador (serviço de equipe/unidades já escopado) e cada consulta
 * filtra pela chave exata.
 */
final class TenantUsageReader implements TenantUsageReaderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function countUsers(int $tenantId): int
    {
        return $this->count(
            "SELECT COUNT(*) FROM tenant_user WHERE tenant_id = :tenant_id AND status IN ('invited', 'active')",
            $tenantId,
        );
    }

    public function countUnits(int $tenantId): int
    {
        return $this->count('SELECT COUNT(*) FROM system_unit WHERE tenant_id = :tenant_id', $tenantId);
    }

    private function count(string $sql, int $tenantId): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId]);

        return (int) $statement->fetchColumn();
    }
}
