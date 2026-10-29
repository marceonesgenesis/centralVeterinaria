<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Persistence\SaleFinanceReader;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Etapa 3, T-06: receiptSummary traz o nome do tutor e do paciente da venda,
 * lidos de tutor/patient do mesmo tenant, para o recibo não mostrar ids.
 * Tudo é criado na transação do teste e desfeito no tearDown().
 */
final class SaleFinanceReaderNamesIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $unitId;
    private int $tenantId;
    private int $tutorId;
    private int $patientId;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $slug = 'e3-sfrn-' . bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)')
            ->execute(['slug' => $slug, 'legal_name' => 'E3 test tenant (' . $slug . ')', 'status' => 'active']);
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :full_name, :phone)')
            ->execute(['tenant_id' => $this->tenantId, 'full_name' => 'ZZ <Tutor>', 'phone' => '11999990000']);
        $this->tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, :n, :s)')
            ->execute(['t' => $this->tenantId, 'tutor' => $this->tutorId, 'n' => 'ZZ Rex', 's' => 'Canina']);
        $this->patientId = (int) $this->pdo->lastInsertId();
    }

    public function testReceiptSummaryCarriesTutorAndPatientNames(): void
    {
        $saleId = $this->createSale($this->patientId);

        $summary = $this->reader()->receiptSummary($saleId);

        Assert::true(is_array($summary), 'sale must be read');
        Assert::same('ZZ <Tutor>', $summary['tutor_name'] ?? null, 'raw name; the receipt escapes it');
        Assert::same('ZZ Rex', $summary['patient_name'] ?? null);
    }

    public function testSaleWithoutPatientHasNullPatientName(): void
    {
        $saleId = $this->createSale(null);

        $summary = $this->reader()->receiptSummary($saleId);

        Assert::true(is_array($summary), 'sale must be read');
        Assert::same('ZZ <Tutor>', $summary['tutor_name'] ?? null);
        Assert::true(array_key_exists('patient_name', $summary), 'patient_name key is always present');
        Assert::null($summary['patient_name']);
    }

    private function reader(): SaleFinanceReader
    {
        return new SaleFinanceReader(TenantContext::authenticated($this->tenantId, $this->userId), $this->pdo);
    }

    private function createSale(?int $patientId): int
    {
        $this->pdo->prepare(
            'INSERT INTO sale (tenant_id, system_unit_id, tutor_id, patient_id, system_user_id, status, total_amount_cents, sold_at)
             VALUES (:tenant_id, :unit_id, :tutor_id, :patient_id, :user_id, :status, :total, :sold_at)',
        )->execute([
            'tenant_id' => $this->tenantId,
            'unit_id' => $this->unitId,
            'tutor_id' => $this->tutorId,
            'patient_id' => $patientId,
            'user_id' => $this->userId,
            'status' => 'completed',
            'total' => 1500,
            'sold_at' => '2031-08-01 10:00:00',
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
