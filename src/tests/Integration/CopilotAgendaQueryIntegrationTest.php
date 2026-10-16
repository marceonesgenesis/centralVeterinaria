<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Contract\CopilotAgendaQueryInterface;
use CentralVet\Persistence\CopilotAgendaQuery;
use CentralVet\Tenancy\Exception\MissingTenantContext;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Fase 8B, T-19: agenda reads of the management copilot. Every row lives in
 * throwaway tenants/users created inside the test transaction (rolled back
 * in tearDown()).
 *
 * Tenant A, day 2031-09-25, professional "pro" (active member of A):
 * - unit A: 09:00 agendado (45 min), 10:00 cancelado (30 min),
 *   14:00 atendido (30 min), 08:00 faltou (30 min);
 * - unit B: 11:00 confirmado (30 min) — not listed in unit A, but it still
 *   occupies the professional (same rule as AppointmentService::assertNoConflict);
 * - other day (2031-09-26) 09:00 in unit A.
 * Professional "other" (active member of A) has 15:00 em_atendimento in A.
 * "inactive" (active = 'N') and "outsider" (member of tenant B only) are
 * not professionals of A. Tenant B has a 09:00 appointment in unit A.
 */
final class CopilotAgendaQueryIntegrationTest extends MysqlIntegrationTestCase
{
    private const APPOINTMENT_KEYS = ['scheduled_at', 'patient_name', 'service_name', 'duration_minutes', 'status', 'professional_id', 'professional_name'];

    private int $userId;
    private int $unitA;
    private int $unitB;
    private int $tenantA;
    private int $tenantB;

    /** @var array<string, int> */
    private array $ids = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT id FROM system_users ORDER BY id LIMIT 1')->fetchColumn();
        $this->unitA = (int) $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 1')->fetchColumn();
        Assert::true($this->userId > 0 && $this->unitA > 0, 'Fixture requires a system_users and a system_unit row');

        $this->tenantA = $this->createTenant('f8b-agenda-a');
        $this->tenantB = $this->createTenant('f8b-agenda-b');
        $this->unitB = $this->otherUnitId();

        $this->seed();
    }

    public function testImplementsTheContract(): void
    {
        Assert::instanceOf(CopilotAgendaQueryInterface::class, $this->query($this->tenantA));
    }

    public function testAppointmentsComeOnlyFromTheActiveUnitAndTenant(): void
    {
        $rows = $this->query($this->tenantA)->appointments($this->day(), $this->day()->modify('+1 day'), null, 50);

        Assert::same(
            ['2031-09-25 08:00:00', '2031-09-25 09:00:00', '2031-09-25 10:00:00', '2031-09-25 14:00:00', '2031-09-25 15:00:00'],
            array_column($rows, 'scheduled_at'),
            'unit A of tenant A only, ordered by time; unit B, other day and tenant B left out',
        );
        Assert::same(self::APPOINTMENT_KEYS, array_keys($rows[1]));
        Assert::same([
            'scheduled_at' => '2031-09-25 09:00:00',
            'patient_name' => 'F8B Rex Agenda',
            'service_name' => 'F8B Consulta longa',
            'duration_minutes' => 45,
            'status' => 'agendado',
            'professional_id' => $this->ids['pro'],
            'professional_name' => 'F8B Ana Profissional',
        ], $rows[1]);
        Assert::same('cancelado', $rows[2]['status'], 'cancelled stays on the listing with its status');

        $unitB = $this->query($this->tenantA, $this->unitB)->appointments($this->day(), $this->day()->modify('+1 day'), null, 50);
        Assert::same(['2031-09-25 11:00:00'], array_column($unitB, 'scheduled_at'));
    }

    public function testAppointmentsFilterByProfessionalRangeAndLimit(): void
    {
        $query = $this->query($this->tenantA);

        $other = $query->appointments($this->day(), $this->day()->modify('+1 day'), $this->ids['other'], 50);
        Assert::same(['2031-09-25 15:00:00'], array_column($other, 'scheduled_at'));

        $twoDays = $query->appointments($this->day(), $this->day()->modify('+2 days'), $this->ids['pro'], 50);
        Assert::same('2031-09-26 09:00:00', $twoDays[count($twoDays) - 1]['scheduled_at']);

        Assert::count(2, $query->appointments($this->day(), $this->day()->modify('+1 day'), null, 2));
        Assert::same([], $this->query($this->tenantB)->appointments($this->day(), $this->day()->modify('+1 day'), $this->ids['pro'], 50));
    }

    public function testCancelledAndNoShowDoNotOccupyTheProfessional(): void
    {
        $busy = $this->query($this->tenantA)->busy($this->day(), $this->ids['pro']);

        Assert::same([
            ['professional_id' => $this->ids['pro'], 'start' => '2031-09-25 09:00:00', 'end' => '2031-09-25 09:45:00'],
            ['professional_id' => $this->ids['pro'], 'start' => '2031-09-25 11:00:00', 'end' => '2031-09-25 11:30:00'],
            ['professional_id' => $this->ids['pro'], 'start' => '2031-09-25 14:00:00', 'end' => '2031-09-25 14:30:00'],
        ], $busy, 'agendado/confirmado/atendido occupy (unit B included, like the booking rule); cancelado and faltou do not');

        $all = $this->query($this->tenantA)->busy($this->day(), null);
        Assert::same([$this->ids['pro'], $this->ids['pro'], $this->ids['pro'], $this->ids['other']], array_column($all, 'professional_id'));
        Assert::same('2031-09-25 15:30:00', $all[3]['end'], 'em_atendimento occupies');

        Assert::same([], $this->query($this->tenantB)->busy($this->day(), $this->ids['pro']), 'other tenant sees nothing');
    }

    public function testProfessionalsAreActiveMembersOfTheTenant(): void
    {
        $query = $this->query($this->tenantA);
        $ids = array_column($query->professionals(), 'id');

        Assert::true(in_array($this->ids['pro'], $ids, true));
        Assert::true(in_array($this->ids['other'], $ids, true));
        Assert::false(in_array($this->ids['inactive'], $ids, true), 'inactive user is not a professional');
        Assert::false(in_array($this->ids['outsider'], $ids, true), 'user of another tenant is not a professional');
        Assert::same(['id', 'name'], array_keys($query->professionals()[0]));

        Assert::true($query->professionalExists($this->ids['pro']));
        Assert::false($query->professionalExists($this->ids['inactive']));
        Assert::false($query->professionalExists($this->ids['outsider']));
        Assert::true($this->query($this->tenantB)->professionalExists($this->ids['outsider']));
    }

    public function testRequiresAnActiveUnit(): void
    {
        $query = new CopilotAgendaQuery(TenantContext::authenticated($this->tenantA, $this->userId, null), $this->pdo);

        Assert::throws(MissingTenantContext::class, fn () => $query->appointments($this->day(), $this->day()->modify('+1 day'), null, 10));
        Assert::throws(MissingTenantContext::class, fn () => $query->busy($this->day(), null));
    }

    private function seed(): void
    {
        $this->ids['pro'] = $this->insertUser('F8B Ana Profissional', 'Y', $this->tenantA);
        $this->ids['other'] = $this->insertUser('F8B Bruno Outro', 'Y', $this->tenantA);
        $this->ids['inactive'] = $this->insertUser('F8B Carla Inativa', 'N', $this->tenantA);
        $this->ids['outsider'] = $this->insertUser('F8B Davi Externo', 'Y', $this->tenantB);

        $tutor = $this->insert('tutor', [
            'tenant_id' => $this->tenantA,
            'public_id' => $this->uuid(),
            'full_name' => 'F8B Maria Tutora',
            'document' => 'F8B-DOC-AG',
            'phone' => '85999990019',
            'email' => 'f8b.agenda@example.invalid',
            'address' => 'F8B Rua Agenda',
        ]);
        $patient = $this->insertPatient($this->tenantA, $tutor, 'F8B Rex Agenda');
        $long = $this->insertService($this->tenantA, 'F8B Consulta longa', 45);
        $short = $this->insertService($this->tenantA, 'F8B Retorno', 30);

        $pro = $this->ids['pro'];
        $this->insertAppointment($this->tenantA, $this->unitA, $patient, $long, $pro, '2031-09-25 09:00:00', 'agendado');
        $this->insertAppointment($this->tenantA, $this->unitA, $patient, $short, $pro, '2031-09-25 10:00:00', 'cancelado');
        $this->insertAppointment($this->tenantA, $this->unitA, $patient, $short, $pro, '2031-09-25 14:00:00', 'atendido');
        $this->insertAppointment($this->tenantA, $this->unitA, $patient, $short, $pro, '2031-09-25 08:00:00', 'faltou');
        $this->insertAppointment($this->tenantA, $this->unitB, $patient, $short, $pro, '2031-09-25 11:00:00', 'confirmado');
        $this->insertAppointment($this->tenantA, $this->unitA, $patient, $short, $pro, '2031-09-26 09:00:00', 'agendado');
        $this->insertAppointment($this->tenantA, $this->unitA, $patient, $short, $this->ids['other'], '2031-09-25 15:00:00', 'em_atendimento');

        $tutorB = $this->insert('tutor', [
            'tenant_id' => $this->tenantB,
            'public_id' => $this->uuid(),
            'full_name' => 'F8B Tutor B',
            'document' => 'F8B-DOC-AGB',
            'phone' => '85999990020',
            'email' => 'f8b.agenda.b@example.invalid',
            'address' => 'F8B Rua B',
        ]);
        $patientB = $this->insertPatient($this->tenantB, $tutorB, 'F8B Rex TenantB');
        $serviceB = $this->insertService($this->tenantB, 'F8B Consulta B', 30);
        $this->insertAppointment($this->tenantB, $this->unitA, $patientB, $serviceB, $this->ids['outsider'], '2031-09-25 09:00:00', 'agendado');
    }

    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable('2031-09-25 00:00:00');
    }

    private function query(int $tenantId, ?int $unitId = null): CopilotAgendaQuery
    {
        return new CopilotAgendaQuery(
            TenantContext::authenticated($tenantId, $this->userId, $unitId ?? $this->unitA),
            $this->pdo,
        );
    }

    private function insertUser(string $name, string $active, int $tenantId): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_users')->fetchColumn();
        $this->pdo->prepare('INSERT INTO system_users (id, name, login, email, active) VALUES (:id, :name, :login, :email, :active)')
            ->execute([
                'id' => $id,
                'name' => $name,
                'login' => 'f8b-agenda-' . bin2hex(random_bytes(4)),
                'email' => 'f8b.user@example.invalid',
                'active' => $active,
            ]);
        $this->insert('tenant_user', ['tenant_id' => $tenantId, 'system_user_id' => $id, 'status' => 'active']);

        return $id;
    }

    private function insertPatient(int $tenantId, int $tutorId, string $name): int
    {
        return $this->insert('patient', [
            'tenant_id' => $tenantId,
            'tutor_id' => $tutorId,
            'name' => $name,
            'species' => 'canino',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2024-03-15',
        ]);
    }

    private function insertService(int $tenantId, string $name, int $minutes): int
    {
        return $this->insert('service', [
            'tenant_id' => $tenantId,
            'name' => $name,
            'duration_minutes' => $minutes,
            'price_cents' => 10000,
        ]);
    }

    private function insertAppointment(int $tenantId, int $unitId, int $patientId, int $serviceId, int $professionalId, string $at, string $status): void
    {
        $this->insert('appointment', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $unitId,
            'patient_id' => $patientId,
            'service_id' => $serviceId,
            'professional_system_user_id' => $professionalId,
            'scheduled_at' => $at,
            'status' => $status,
        ]);
    }

    private function otherUnitId(): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM system_unit WHERE id <> ? ORDER BY id LIMIT 1');
        $statement->execute([$this->unitA]);
        $id = (int) $statement->fetchColumn();

        if ($id > 0) {
            return $id;
        }

        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit')->fetchColumn();
        $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (:id, :tenant_id, :name)')
            ->execute(['id' => $id, 'tenant_id' => $this->tenantA, 'name' => 'F8B teste outra unidade']);

        return $id;
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));

        return $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'F8B teste tenant (' . $slug . ')',
            'status' => 'active',
        ]);
    }

    /** @param array<string, int|string|null> $row */
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
