<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed per-tenant feature flags (`feature_flag`, migration 0014). A
 * missing row means off. set() is a single `INSERT ... ON DUPLICATE KEY
 * UPDATE` on `feature_flag_tenant_key_uq`, so the flag keeps one row per
 * tenant. The tenant always comes from the context.
 */
final class FeatureFlagRepository implements FeatureFlagRepositoryInterface
{
    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function isEnabled(string $flagKey): bool
    {
        $query = TenantQuery::forTenant($this->context->tenantId())->andEquals('flag_key', $flagKey);

        $statement = $this->connection->prepare("SELECT enabled FROM feature_flag WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        return (int) $statement->fetchColumn() === 1;
    }

    public function set(string $flagKey, bool $enabled, int $systemUserId): void
    {
        if (trim($flagKey) === '' || strlen($flagKey) > 64) {
            throw new InvalidArgumentException('Invalid feature flag key');
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO feature_flag (tenant_id, flag_key, enabled, updated_by_system_user_id)
            VALUES (:tenant_id, :flag_key, :enabled, :updated_by)
            ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                updated_by_system_user_id = VALUES(updated_by_system_user_id)
            SQL
        );
        $statement->execute([
            ':tenant_id' => $this->context->tenantId(),
            ':flag_key' => $flagKey,
            ':enabled' => $enabled ? 1 : 0,
            ':updated_by' => $systemUserId,
        ]);
    }
}
