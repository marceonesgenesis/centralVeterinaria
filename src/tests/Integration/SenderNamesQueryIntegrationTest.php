<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Fase 7A, correção do gate T-23: os nomes `{{unit_name}}` e `{{clinic_name}}`
 * da mensagem manual vêm da unidade ativa (`system_unit.name`) e do tenant da
 * sessão (`trade_name`, ou `legal_name` sem nome fantasia), a mesma regra do
 * ReminderSourceQuery. Tenants descartáveis, desfeitos no tearDown().
 */
final class SenderNamesQueryIntegrationTest extends MysqlIntegrationTestCase
{
    private const QUERY = 'CentralVet\\Persistence\\SenderNamesQuery';

    private int $unitId;
    private string $unitName;

    public function setUp(): void
    {
        parent::setUp();

        $row = $this->pdo->query('SELECT id, name FROM system_unit ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        Assert::true(is_array($row), 'Fixture requires at least one system_unit row');
        $this->unitId = (int) $row['id'];
        $this->unitName = (string) $row['name'];
    }

    public function testNamesComeFromTheActiveUnitAndTheTenantTradeName(): void
    {
        $tenantId = $this->createTenant('F7A teste Clinica Fantasia');

        Assert::same(
            ['unit_name' => $this->unitName, 'clinic_name' => 'F7A teste Clinica Fantasia'],
            $this->query($tenantId)->namesForUnit($this->unitId),
        );
    }

    public function testClinicNameFallsBackToTheLegalName(): void
    {
        $tenantId = $this->createTenant(null);

        $names = $this->query($tenantId)->namesForUnit($this->unitId);

        Assert::stringContains('F7A teste tenant', (string) $names['clinic_name']);
    }

    public function testUnknownUnitGivesNullUnitName(): void
    {
        $tenantId = $this->createTenant('F7A teste Clinica');
        $missing = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM system_unit')->fetchColumn();

        Assert::same(
            ['unit_name' => null, 'clinic_name' => 'F7A teste Clinica'],
            $this->query($tenantId)->namesForUnit($missing),
        );
    }

    private function query(int $tenantId): object
    {
        Assert::true(class_exists(self::QUERY), 'SenderNamesQuery must exist');
        $class = self::QUERY;
        $userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();

        return new $class(TenantContext::authenticated($tenantId, max(1, $userId), $this->unitId), $this->pdo);
    }

    private function createTenant(?string $tradeName): int
    {
        $slug = 'f7a-sender-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, trade_name, status) VALUES (UUID(), :slug, :legal, :trade, \'active\')'
        );
        $statement->execute(['slug' => $slug, 'legal' => 'F7A teste tenant (' . $slug . ')', 'trade' => $tradeName]);

        return (int) $this->pdo->lastInsertId();
    }
}
