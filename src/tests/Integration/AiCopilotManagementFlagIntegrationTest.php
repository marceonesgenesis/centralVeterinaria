<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\AiSettingsService;
use CentralVet\Persistence\AiInteractionRepository;
use CentralVet\Persistence\AiTenantSettingRepository;
use CentralVet\Persistence\FeatureFlagRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\SpyAuditLogWriter;

/**
 * Fase 8B pendências, T-18: AiSettingsService::setCopilotManagement grava a
 * flag `ai_copilot_management` pelo FeatureFlagRepository real (enabled 1/0
 * em feature_flag) e overview() lê o valor gravado. Tudo dentro da transação
 * do teste, desfeita no tearDown().
 */
final class AiCopilotManagementFlagIntegrationTest extends MysqlIntegrationTestCase
{
    private const FLAG = 'ai_copilot_management';

    private int $userId;
    private int $unitId;
    private int $tenantId;
    private int $otherTenantId;

    public function setUp(): void
    {
        parent::setUp();

        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantId = $this->createTenant('f8b-t18-a');
        $this->otherTenantId = $this->createTenant('f8b-t18-b');
    }

    public function testSetCopilotManagementWritesEnabledOneThenZeroAndOverviewFollows(): void
    {
        $service = $this->service($this->tenantId);

        Assert::null($this->enabled($this->tenantId), 'no flag row before the switch is used');
        Assert::false($service->overview()['copilot_management'], 'missing row means off');

        $service->setCopilotManagement(true);
        Assert::same('1', $this->enabled($this->tenantId), 'turning on writes enabled = 1');
        Assert::true($service->overview()['copilot_management'], 'overview reads the stored flag (on)');

        $service->setCopilotManagement(false);
        Assert::same('0', $this->enabled($this->tenantId), 'turning off writes enabled = 0');
        Assert::false($service->overview()['copilot_management'], 'overview reads the stored flag (off)');

        Assert::same('1', $this->scalar('SELECT COUNT(*) FROM feature_flag WHERE tenant_id = ? AND flag_key = ?', [$this->tenantId, self::FLAG]), 'the upsert keeps one row');
        Assert::same((string) $this->userId, $this->scalar('SELECT updated_by_system_user_id FROM feature_flag WHERE tenant_id = ? AND flag_key = ?', [$this->tenantId, self::FLAG]));
        Assert::null($this->enabled($this->otherTenantId), 'another tenant is not touched');
    }

    private function service(int $tenantId): AiSettingsService
    {
        $context = TenantContext::authenticated($tenantId, $this->userId, $this->unitId);

        return new AiSettingsService(
            new AiTenantSettingRepository($context, $this->pdo),
            new FeatureFlagRepository($context, $this->pdo),
            new AiInteractionRepository($context, $this->pdo),
            new FakeAuthorizationPolicy(),
            new SpyAuditLogWriter(),
            $context,
        );
    }

    private function enabled(int $tenantId): ?string
    {
        return $this->scalar('SELECT enabled FROM feature_flag WHERE tenant_id = ? AND flag_key = ?', [$tenantId, self::FLAG]);
    }

    /** @param list<int|string> $parameters */
    private function scalar(string $sql, array $parameters): ?string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'F8B teste tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
