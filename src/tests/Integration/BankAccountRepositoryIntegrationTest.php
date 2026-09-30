<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\BankAccount;
use CentralVet\Persistence\BankAccountRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * BankAccountRepository against the real MySQL schema (rodada 2, T-15,
 * table `bank_account` from migration 0007). Every row belongs to
 * throwaway tenants created inside the test's transaction, rolled back in
 * tearDown() — nothing is committed.
 */
final class BankAccountRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantA;
    private int $tenantB;
    private int $unit1;
    private int $unit2;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        // system_unit.id has no AUTO_INCREMENT (Adianti table): reuse two existing units.
        $units = array_map('intval', $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        Assert::count(2, $units, 'Fixture requires two existing system_unit rows');
        [$this->unit1, $this->unit2] = $units;

        $this->tenantA = $this->createTenant('r2-bank-a');
        $this->tenantB = $this->createTenant('r2-bank-b');
    }

    public function testSaveAndFindByIdPreserveNegativeBalance(): void
    {
        $repository = $this->repositoryFor($this->tenantA);
        $now = new DateTimeImmutable('2031-05-10 09:30:00');

        $account = BankAccount::create($this->tenantA, $this->unit1, 'Conta corrente', 'Banco X', -12345, $now);
        $repository->save($account);
        Assert::true(($account->id() ?? 0) > 0, 'insert assigns the generated id');

        /** @var BankAccount $found */
        $found = $repository->findById((int) $account->id());
        Assert::instanceOf(BankAccount::class, $found);
        Assert::same(-12345, $found->balanceCents());
        Assert::same('Conta corrente', $found->name());
        Assert::same('Banco X', $found->bankName());
        Assert::same($this->unit1, $found->systemUnitId());
        Assert::same($this->tenantA, $found->tenantId());
        Assert::true($found->isActive());
        Assert::same('2031-05-10 09:30:00', $found->balanceUpdatedAt()?->format('Y-m-d H:i:s'));

        // UPDATE branch: new balance and inactive flag persist on the same row
        $found->changeBalance(-1, new DateTimeImmutable('2031-05-11 10:00:00'));
        $found->deactivate();
        $repository->save($found);

        /** @var BankAccount $again */
        $again = $repository->findById((int) $account->id());
        Assert::same(-1, $again->balanceCents());
        Assert::false($again->isActive());
        Assert::same('2031-05-11 10:00:00', $again->balanceUpdatedAt()?->format('Y-m-d H:i:s'));

        Assert::null($this->repositoryFor($this->tenantB)->findById((int) $account->id()), 'other tenant cannot find it');
    }

    public function testListBySystemUnitIsScopedToUnitAndTenant(): void
    {
        $now = new DateTimeImmutable();
        $repoA = $this->repositoryFor($this->tenantA);
        $repoB = $this->repositoryFor($this->tenantB);

        $a1 = $repoA->save(BankAccount::create($this->tenantA, $this->unit1, 'Caixa', null, 1000, $now));
        $a2 = $repoA->save(BankAccount::create($this->tenantA, $this->unit1, 'Banco', null, -250, $now));
        $repoA->save(BankAccount::create($this->tenantA, $this->unit2, 'Caixa U2', null, 70, $now));
        $b1 = $repoB->save(BankAccount::create($this->tenantB, $this->unit1, 'Caixa', null, 900, $now));

        $ids = array_map(static fn (BankAccount $a): int => (int) $a->id(), $repoA->listBySystemUnit($this->unit1));
        sort($ids);
        $expected = [(int) $a1->id(), (int) $a2->id()];
        sort($expected);
        Assert::same($expected, $ids, 'only tenant A / unit U1 accounts');
        Assert::false(in_array((int) $b1->id(), $ids, true), 'other tenant must not be listed');

        Assert::same((int) $a1->id(), (int) $repoA->findByName($this->unit1, 'Caixa')?->id());
        Assert::same((int) $b1->id(), (int) $repoB->findByName($this->unit1, 'Caixa')?->id());
        Assert::null($repoA->findByName($this->unit2, 'Banco'));
    }

    private function repositoryFor(int $tenantId): BankAccountRepository
    {
        return new BankAccountRepository(TenantContext::authenticated($tenantId, $this->userId), $this->pdo);
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
