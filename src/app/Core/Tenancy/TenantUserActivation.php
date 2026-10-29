<?php

declare(strict_types=1);

namespace CentralVet\Tenancy;

use PDO;

/**
 * Etapa 3, T-19: o primeiro login do usuário convidado (T-12/T-13/T-24)
 * ativa o vínculo tenant_user. Idempotente: só muda invited para active, sem
 * reativar vínculo inactive.
 */
final class TenantUserActivation
{
    private function __construct()
    {
    }

    /** @return int linhas afetadas (0 quando não havia convite pendente) */
    public static function activateInvited(PDO $pdo, int $systemUserId): int
    {
        $statement = $pdo->prepare("UPDATE tenant_user SET status = 'active' WHERE system_user_id = ? AND status = 'invited'");
        $statement->execute([$systemUserId]);

        return $statement->rowCount();
    }
}
