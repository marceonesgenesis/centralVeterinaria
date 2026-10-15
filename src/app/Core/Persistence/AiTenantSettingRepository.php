<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\Contract\AiTenantSettingRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use PDO;

/**
 * PDO-backed persistence of `ai_tenant_setting` (migration 0014), one row
 * per tenant (PK `tenant_id`). save() is a single `INSERT ... ON DUPLICATE
 * KEY UPDATE`, so a second save changes the same row. The tenant always
 * comes from the context; a setting of another tenant throws
 * TenantBoundaryViolation.
 */
final class AiTenantSettingRepository implements AiTenantSettingRepositoryInterface
{
    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function find(): ?AiTenantSetting
    {
        $query = TenantQuery::forTenant($this->context->tenantId());

        $statement = $this->connection->prepare("SELECT * FROM ai_tenant_setting WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : AiTenantSetting::reconstitute($row);
    }

    public function save(AiTenantSetting $setting, int $systemUserId): void
    {
        $this->context->assertTenant($setting->tenantId());

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO ai_tenant_setting (
                tenant_id, terms_version, terms_accepted_at, terms_accepted_by_system_user_id,
                monthly_token_quota, updated_by_system_user_id
            ) VALUES (
                :tenant_id, :terms_version, :terms_accepted_at, :terms_accepted_by,
                :monthly_token_quota, :updated_by
            )
            ON DUPLICATE KEY UPDATE
                terms_version = VALUES(terms_version),
                terms_accepted_at = VALUES(terms_accepted_at),
                terms_accepted_by_system_user_id = VALUES(terms_accepted_by_system_user_id),
                monthly_token_quota = VALUES(monthly_token_quota),
                updated_by_system_user_id = VALUES(updated_by_system_user_id)
            SQL
        );
        $statement->execute([
            ':tenant_id' => $this->context->tenantId(),
            ':terms_version' => $setting->termsVersion(),
            ':terms_accepted_at' => $setting->termsAcceptedAt()?->format('Y-m-d H:i:s.u'),
            ':terms_accepted_by' => $setting->termsAcceptedBySystemUserId(),
            ':monthly_token_quota' => $setting->monthlyTokenQuota(),
            ':updated_by' => $systemUserId,
        ]);
    }
}
