<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Fase 7A, correção do gate T-23: os nomes `{{unit_name}}` e `{{clinic_name}}`
 * da mensagem manual vêm da unidade ativa (`system_unit.name`, só do tenant
 * da sessão) e do tenant da
 * sessão (`trade_name`, ou `legal_name` sem nome fantasia), a mesma regra do
 * ReminderSourceQuery. Tenants descartáveis, desfeitos no tearDown().
 */
final class SenderNamesQueryIntegrationTest extends MysqlIntegrationTestCase
{
    private const QUERY = 'CentralVet\\Persistence\\SenderNamesQuery';

    public function testNamesComeFromTheActiveUnitAndTheTenantTradeName(): void
    {
        $tenantId = $this->createTenant('F7A teste Clinica Fantasia');
        $unitId = $this->createUnit($tenantId, 'F7A teste Unidade');

        Assert::same(
            ['unit_name' => 'F7A teste Unidade', 'clinic_name' => 'F7A teste Clinica Fantasia'],
            $this->query($tenantId, $unitId)->namesForUnit($unitId),
        );
    }

    public function testClinicNameFallsBackToTheLegalName(): void
    {
        $tenantId = $this->createTenant(null);
        $unitId = $this->createUnit($tenantId, 'F7A teste Unidade');

        $names = $this->query($tenantId, $unitId)->namesForUnit($unitId);

        Assert::stringContains('F7A teste tenant', (string) $names['clinic_name']);
    }

    public function testUnknownUnitGivesNullUnitName(): void
    {
        $tenantId = $this->createTenant('F7A teste Clinica');
        $unitId = $this->createUnit($tenantId, 'F7A teste Unidade');
        $missing = $this->nextUnitId() + 1000;

        Assert::same(
            ['unit_name' => null, 'clinic_name' => 'F7A teste Clinica'],
            $this->query($tenantId, $unitId)->namesForUnit($missing),
        );
    }

    public function testUnitOfAnotherTenantGivesNullUnitName(): void
    {
        // Revisão da rodada 1: system_unit tem tenant_id NOT NULL; o nome de uma
        // unidade de outro tenant nunca pode vazar para a mensagem.
        $tenantId = $this->createTenant('F7A teste Clinica');
        $ownUnit = $this->createUnit($tenantId, 'F7A teste Unidade');
        $otherTenant = $this->createTenant('F7A teste Outra Clinica');
        $otherUnit = $this->createUnit($otherTenant, 'F7A teste Unidade Alheia');

        Assert::same(
            ['unit_name' => null, 'clinic_name' => 'F7A teste Clinica'],
            $this->query($tenantId, $ownUnit)->namesForUnit($otherUnit),
        );
    }

    private function query(int $tenantId, int $unitId): object
    {
        Assert::true(class_exists(self::QUERY), 'SenderNamesQuery must exist');
        $class = self::QUERY;
        $userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();

        return new $class(TenantContext::authenticated($tenantId, max(1, $userId), $unitId), $this->pdo);
    }

    /** system_unit.id não é AUTO_INCREMENT (Adianti): próximo id dentro da transação do teste. */
    private function nextUnitId(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit')->fetchColumn();
    }

    private function createUnit(int $tenantId, string $name): int
    {
        $id = $this->nextUnitId();
        $statement = $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (:id, :tenant_id, :name)');
        $statement->execute(['id' => $id, 'tenant_id' => $tenantId, 'name' => $name]);

        return $id;
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
