<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Persistence\ReminderSourceQuery;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Fase 7A, T-24 (revisão final): o `{{unit_name}}` dos lembretes automáticos
 * vem de `system_unit` filtrada pelo tenant da origem (`system_unit` tem
 * `tenant_id` desde a 0001). Uma linha cujo `system_unit_id` aponte para a
 * unidade de outro tenant nunca leva o nome dessa unidade para a mensagem:
 * o candidato segue, com `unit_name` vazio (mesma regra do SenderNamesQuery).
 * Tenants e unidades descartáveis, desfeitos no tearDown().
 */
final class ReminderSourceQueryUnitTenantIntegrationTest extends MysqlIntegrationTestCase
{
    private const FOREIGN_UNIT_NAME = 'F7A teste Unidade Alheia T24';

    private int $userId;
    private int $tenantId;
    private int $ownUnit;
    private int $foreignUnit;

    /** @var array<string, int> */
    private array $ids = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->tenantId = $this->createTenant();
        $this->ownUnit = $this->createUnit($this->tenantId, 'F7A teste Unidade Propria T24');
        $otherTenant = $this->createTenant();
        $this->foreignUnit = $this->createUnit($otherTenant, self::FOREIGN_UNIT_NAME);

        $tutor = $this->insert('tutor', [
            'tenant_id' => $this->tenantId,
            'public_id' => $this->uuid(),
            'full_name' => 'F7A teste Tutor T24',
            'phone' => '85999990024',
            'email' => 'f7a.teste@example.invalid',
        ]);
        $patient = $this->insert('patient', [
            'tenant_id' => $this->tenantId,
            'tutor_id' => $tutor,
            'name' => 'F7A teste Rex T24',
            'species' => 'canino',
        ]);
        $service = $this->insert('service', [
            'tenant_id' => $this->tenantId,
            'name' => 'F7A teste Consulta T24',
            'duration_minutes' => 30,
            'price_cents' => 10000,
        ]);
        $this->ids['appointment'] = $this->insert('appointment', [
            'tenant_id' => $this->tenantId,
            'system_unit_id' => $this->foreignUnit,
            'patient_id' => $patient,
            'service_id' => $service,
            'professional_system_user_id' => $this->userId,
            'scheduled_at' => '2031-10-11 15:00:00',
            'status' => 'agendado',
        ]);
        $encounter = $this->insert('encounter', [
            'tenant_id' => $this->tenantId,
            'system_unit_id' => $this->foreignUnit,
            'patient_id' => $patient,
            'professional_system_user_id' => $this->userId,
            'started_at' => '2031-10-01 07:00:00',
        ]);
        $vaccine = $this->insert('vaccine_catalog_item', ['tenant_id' => $this->tenantId, 'name' => 'F7A teste V10 T24']);
        $this->ids['vaccination'] = $this->insert('vaccination', [
            'tenant_id' => $this->tenantId,
            'encounter_id' => $encounter,
            'patient_id' => $patient,
            'vaccine_catalog_item_id' => $vaccine,
            'dose_number' => 1,
            'professional_system_user_id' => $this->userId,
            'applied_at' => '2031-09-15 10:00:00',
            'next_dose_at' => '2031-10-15',
        ]);
        $account = $this->insert('encounter_account', [
            'tenant_id' => $this->tenantId,
            'encounter_id' => $encounter,
            'patient_id' => $patient,
            'tutor_id' => $tutor,
            'system_unit_id' => $this->foreignUnit,
            'subtotal_cents' => 150000,
            'total_cents' => 150000,
        ]);
        $this->ids['receivable'] = $this->insert('receivable', [
            'tenant_id' => $this->tenantId,
            'encounter_account_id' => $account,
            'tutor_id' => $tutor,
            'total_cents' => 150000,
            'paid_cents' => 0,
            'status' => 'open',
            'created_at' => '2031-10-01 08:00:00',
        ]);
    }

    public function testAppointmentReminderNeverCarriesTheUnitNameOfAnotherTenant(): void
    {
        $candidates = $this->query()->appointmentsBetween(
            new DateTimeImmutable('2031-10-11 00:00:00'),
            new DateTimeImmutable('2031-10-12 00:00:00'),
        );

        $this->assertNoForeignUnitName($candidates, $this->ids['appointment'], 'appointment');
    }

    public function testVaccineReminderNeverCarriesTheUnitNameOfAnotherTenant(): void
    {
        $candidates = $this->query()->vaccinesDueBetween(
            new DateTimeImmutable('2031-10-10'),
            new DateTimeImmutable('2031-10-17'),
        );

        $this->assertNoForeignUnitName($candidates, $this->ids['vaccination'], 'vaccination');
    }

    public function testReceivableReminderNeverCarriesTheUnitNameOfAnotherTenant(): void
    {
        $candidates = $this->query()->openReceivablesCreatedBefore(new DateTimeImmutable('2031-10-03 12:00:00'));

        $this->assertNoForeignUnitName($candidates, $this->ids['receivable'], 'receivable');
    }

    /**
     * @param list<\CentralVet\Domain\ReminderCandidate> $candidates
     */
    private function assertNoForeignUnitName(array $candidates, int $sourceId, string $label): void
    {
        $ours = array_values(array_filter($candidates, static fn ($c): bool => $c->sourceId() === $sourceId));
        Assert::count(1, $ours, "{$label} of the tenant stays a candidate");

        foreach ($candidates as $candidate) {
            Assert::false(
                ($candidate->variables()['unit_name'] ?? null) === self::FOREIGN_UNIT_NAME,
                "{$label}: unit_name of another tenant must not reach the reminder",
            );
        }
        Assert::same('', $ours[0]->variables()['unit_name'], "{$label}: unit of another tenant reads as empty");
    }

    private function query(): ReminderSourceQuery
    {
        return new ReminderSourceQuery(TenantContext::authenticated($this->tenantId, $this->userId), $this->pdo);
    }

    private function createTenant(): int
    {
        $slug = 'f7a-t24-' . bin2hex(random_bytes(4));

        return $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'F7A teste tenant (' . $slug . ')',
            'trade_name' => 'F7A teste Clinica T24',
            'status' => 'active',
        ]);
    }

    /** system_unit.id não é AUTO_INCREMENT (Adianti): próximo id dentro da transação do teste. */
    private function createUnit(int $tenantId, string $name): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit')->fetchColumn();
        $statement = $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (:id, :tenant_id, :name)');
        $statement->execute(['id' => $id, 'tenant_id' => $tenantId, 'name' => $name]);

        return $id;
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));
        $statement->execute($row);

        return (int) $this->pdo->lastInsertId();
    }

    private function uuid(): string
    {
        return (string) $this->pdo->query('SELECT UUID()')->fetchColumn();
    }
}
