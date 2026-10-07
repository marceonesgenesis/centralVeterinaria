<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Landing\LeadSubmission;
use CentralVet\Persistence\LeadRepository;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * LeadRepository contra o schema real (`landing_lead`, migration 0009).
 * Tabela de plataforma, sem tenant. Tudo roda na transação do
 * MysqlIntegrationTestCase e volta no rollback do tearDown().
 */
final class LeadRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private LeadRepository $repository;

    public function setUp(): void
    {
        parent::setUp();

        $this->repository = new LeadRepository($this->pdo);
    }

    public function testInsertSearchAndCountByPlanAndPeriod(): void
    {
        $before = $this->repository->count(null, null, null);
        $beforePro = $this->repository->count('pro', null, null);
        $now = new DateTimeImmutable('now');

        $proId = $this->repository->insert($this->lead('pro', 'LP teste Ana'), '203.0.113.7', $now);
        $starterId = $this->repository->insert($this->lead('starter', 'LP teste Bruno'), '198.51.100.9', $now);
        Assert::true($proId > 0, 'insert devolve o id gerado');
        Assert::true($starterId > $proId, 'ids crescentes');

        Assert::same($before + 2, $this->repository->count(null, null, null));
        Assert::same($beforePro + 1, $this->repository->count('pro', null, null));

        $rows = array_values(array_filter(
            $this->repository->search('pro', null, null, 50, 0),
            static fn (array $row): bool => $row['name'] === 'LP teste Ana',
        ));
        Assert::count(1, $rows, 'search por plano devolve o lead pro gravado');
        $row = $rows[0];
        Assert::same($proId, $row['id']);
        Assert::same(9700, $row['plan_price_cents']);
        Assert::same('Pro', $row['plan_name']);
        Assert::same('pro', $row['plan_id']);
        Assert::same('lgpd-contato-2026-10', $row['consent_version']);
        Assert::same('203.0.113.7', $row['consent_ip']);
        Assert::same('Clínica LP', $row['clinic_name']);
        Assert::same('ana@example.com', $row['email']);
        Assert::same('85999990000', $row['phone']);
        Assert::same('2-4', $row['vets_range']);
        Assert::same('Fortaleza', $row['city']);
        Assert::same('CE', $row['uf']);
        Assert::same($now->format('Y-m-d H:i:s'), substr((string) $row['consent_at'], 0, 19));
        Assert::true(is_string($row['created_at']) && $row['created_at'] !== '', 'created_at presente');

        foreach ($this->repository->search('pro', null, null, 50, 0) as $proRow) {
            Assert::same('pro', $proRow['plan_id'], 'filtro por plano exato');
        }

        $tomorrow = $now->modify('+1 day');
        Assert::same([], $this->repository->search(null, $tomorrow, $tomorrow, 50, 0));
        Assert::same(0, $this->repository->count(null, $tomorrow, $tomorrow));

        $today = $this->repository->search(null, $now, $now, 50, 0);
        $ids = array_column($today, 'id');
        Assert::true(in_array($proId, $ids, true) && in_array($starterId, $ids, true), 'período de hoje inclui os dois');
    }

    public function testSearchOrdersByIdDescAndPaginates(): void
    {
        $now = new DateTimeImmutable('now');
        $first = $this->repository->insert($this->lead('business', 'LP teste Caio'), '203.0.113.8', $now);
        $second = $this->repository->insert($this->lead('business', 'LP teste Dora'), '203.0.113.9', $now);

        $page1 = $this->repository->search(null, null, null, 1, 0);
        $page2 = $this->repository->search(null, null, null, 1, 1);
        Assert::count(1, $page1);
        Assert::same($second, $page1[0]['id'], 'ordem id DESC');
        Assert::same($first, $page2[0]['id'], 'offset pula a primeira linha');

        // limit fora de 1..500 é limitado, não quebra
        Assert::count(1, $this->repository->search(null, null, null, 0, 0));
        Assert::true(count($this->repository->search(null, null, null, 100000, 0)) <= 500);
    }

    private function lead(string $plan, string $name): LeadSubmission
    {
        return LeadSubmission::fromPayload([
            'name' => $name,
            'clinic' => 'Clínica LP',
            'email' => 'ana@example.com',
            'phone' => '(85) 99999-0000',
            'vets' => '2-4',
            'city' => 'Fortaleza',
            'uf' => 'CE',
            'plan' => $plan,
            'consent' => true,
        ]);
    }
}
