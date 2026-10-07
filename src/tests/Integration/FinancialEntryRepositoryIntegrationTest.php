<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\FinancialEntry;
use CentralVet\Persistence\FinancialEntryRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * FinancialEntryRepository against the real MySQL schema (rodada 2, T-37:
 * the `payment_method` column of migration 0007, delivered by T-14). Every
 * row belongs to a throwaway tenant created inside the test's transaction,
 * rolled back in tearDown() — nothing is committed.
 */
final class FinancialEntryRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantA;
    private int $tenantB;
    private int $unitId;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        // system_unit.id has no AUTO_INCREMENT (Adianti table): reuse an existing unit.
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('r2-fin-entry-a');
        $this->tenantB = $this->createTenant('r2-fin-entry-b');
    }

    public function testSaveAndFindByIdPreservePaymentMethod(): void
    {
        $repository = $this->repositoryFor($this->tenantA);

        $entry = FinancialEntry::record(
            $this->tenantA,
            $this->unitId,
            'expense',
            'Fornecedores',
            4321,
            null,
            null,
            new DateTimeImmutable('2031-06-10 14:30:00'),
            $this->userId,
            'bank_transfer',
        );
        $repository->save($entry);
        Assert::true(($entry->id() ?? 0) > 0, 'insert assigns the generated id');

        /** @var FinancialEntry $found */
        $found = $repository->findById((int) $entry->id());
        Assert::instanceOf(FinancialEntry::class, $found);
        Assert::same('bank_transfer', $found->paymentMethod());
        Assert::same('expense', $found->entryType());
        Assert::same(4321, $found->amountCents());
        Assert::same('2031-06-10 14:30:00', $found->occurredAt()->format('Y-m-d H:i:s'));

        $listed = $repository->listBySystemUnitAndPeriod($this->unitId, '2031-06-10 00:00:00', '2031-06-10 23:59:59');
        Assert::count(1, $listed);
        Assert::same('bank_transfer', $listed[0]->paymentMethod(), 'listBySystemUnitAndPeriod hydrates payment_method too');

        Assert::null($this->repositoryFor($this->tenantB)->findById((int) $entry->id()), 'other tenant cannot find it');
    }

    public function testNullPaymentMethodRoundTripsAsNull(): void
    {
        $repository = $this->repositoryFor($this->tenantA);

        $entry = FinancialEntry::record(
            $this->tenantA,
            $this->unitId,
            'income',
            'Consultas',
            1000,
            null,
            null,
            new DateTimeImmutable('2031-06-11 09:00:00'),
            $this->userId,
        );
        $repository->save($entry);

        /** @var FinancialEntry $found */
        $found = $repository->findById((int) $entry->id());
        Assert::null($found->paymentMethod());
    }

    private function repositoryFor(int $tenantId): FinancialEntryRepository
    {
        return new FinancialEntryRepository(TenantContext::authenticated($tenantId, $this->userId, $this->unitId), $this->pdo);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'R2 test tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
