<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Payable;
use CentralVet\Persistence\PayableRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * PayableRepository against the real MySQL schema (fase 10, T-28): the
 * UPDATE branch of save() and listBySystemUnitAndStatus(). Every row
 * belongs to throwaway tenants created inside the test's transaction,
 * rolled back in tearDown() — nothing is committed.
 *
 * Fixture: tenant A / unit U1 has one open and one paid payable; noise that
 * must never show up for A/U1 is a paid payable of tenant A / unit U2 and
 * a paid payable of tenant B / unit U1.
 */
final class PayableRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantA;
    private int $tenantB;
    private int $unit1;
    private int $unit2;
    private int $openA1;
    private int $paidA1;
    private int $paidA2;
    private int $paidB1;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        // system_unit.id has no AUTO_INCREMENT (Adianti table): reuse two existing units.
        $units = array_map('intval', $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        Assert::count(2, $units, 'Fixture requires two existing system_unit rows');
        [$this->unit1, $this->unit2] = $units;

        $this->tenantA = $this->createTenant('f10-pay-a');
        $this->tenantB = $this->createTenant('f10-pay-b');

        $this->openA1 = $this->payable($this->tenantA, $this->unit1, 'Luz', 'Utilidades', 1000, '2031-05-10', 'open');
        $this->paidA1 = $this->payable($this->tenantA, $this->unit1, 'Aluguel', 'Imóvel', 5000, '2031-05-05', 'paid');
        $this->paidA2 = $this->payable($this->tenantA, $this->unit2, 'Água U2', 'Utilidades', 700, '2031-05-01', 'paid');
        $this->paidB1 = $this->payable($this->tenantB, $this->unit1, 'Luz B', 'Utilidades', 900, '2031-05-01', 'paid');
    }

    public function testSaveOfExistingPayableUpdatesItsRowOnly(): void
    {
        $repository = $this->repositoryFor($this->tenantA);

        /** @var Payable $payable */
        $payable = $repository->findById($this->openA1);
        $payable->changeDetails('Luz setembro', 'Energia', 1234, new DateTimeImmutable('2031-06-15'));
        $repository->save($payable);

        Assert::same($this->openA1, $payable->id(), 'save() of an existing payable keeps its id');
        Assert::same(
            ['description_text' => 'Luz setembro', 'category' => 'Energia', 'amount_cents' => 1234, 'due_date' => '2031-06-15', 'status' => 'open'],
            $this->row($this->openA1),
            'UPDATE must persist the new description/category/amount/due date',
        );
        Assert::same(
            ['description_text' => 'Luz B', 'category' => 'Utilidades', 'amount_cents' => 900, 'due_date' => '2031-05-01', 'status' => 'paid'],
            $this->row($this->paidB1),
            'Payable of another tenant must be untouched',
        );
        Assert::same(4, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM payable WHERE tenant_id IN (' . $this->tenantA . ', ' . $this->tenantB . ')'
        )->fetchColumn(), 'UPDATE must not insert a new row');
    }

    public function testUpdateScopedByTenantDoesNotTouchOtherTenantRowWithSameId(): void
    {
        // a Payable of tenant A carrying the id of tenant B's row: the tenant
        // filter of the UPDATE must leave B's row as it was
        $forged = Payable::reconstitute(
            id: $this->paidB1,
            tenantId: $this->tenantA,
            systemUnitId: $this->unit1,
            descriptionText: 'Forjada',
            category: 'X',
            amountCents: 1,
            dueDate: null,
            status: 'open',
            paidAt: null,
            systemUserId: $this->userId,
            createdAt: null,
            updatedAt: null,
        );
        $this->repositoryFor($this->tenantA)->save($forged);

        Assert::same('Luz B', $this->row($this->paidB1)['description_text']);
        Assert::same(900, $this->row($this->paidB1)['amount_cents']);
    }

    public function testListBySystemUnitAndStatusIsScopedToUnitAndTenant(): void
    {
        $repository = $this->repositoryFor($this->tenantA);

        $paid = $repository->listBySystemUnitAndStatus($this->unit1, Payable::STATUS_PAID);
        Assert::same([$this->paidA1], self::ids($paid), 'paid: only tenant A / unit U1 paid payable');

        $open = $repository->listBySystemUnitAndStatus($this->unit1, Payable::STATUS_OPEN);
        Assert::same([$this->openA1], self::ids($open));

        // null: every status, ordered by due_date IS NULL, due_date ASC, id ASC
        $all = $repository->listBySystemUnitAndStatus($this->unit1, null);
        Assert::same([$this->paidA1, $this->openA1], self::ids($all));
        Assert::false(in_array($this->paidA2, self::ids($all), true), 'Other unit must not be listed');
        Assert::false(in_array($this->paidB1, self::ids($all), true), 'Other tenant must not be listed');

        Assert::same([$this->paidB1], self::ids($this->repositoryFor($this->tenantB)->listBySystemUnitAndStatus($this->unit1, null)));
    }

    private function repositoryFor(int $tenantId): PayableRepository
    {
        return new PayableRepository(TenantContext::authenticated($tenantId, $this->userId), $this->pdo);
    }

    /** @return list<int> */
    private static function ids(array $payables): array
    {
        return array_map(static fn (Payable $p): int => (int) $p->id(), $payables);
    }

    /** @return array{description_text: string, category: string, amount_cents: int, due_date: ?string, status: string} */
    private function row(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT description_text, category, amount_cents, due_date, status FROM payable WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        Assert::true($row !== false, "payable {$id} must exist");
        $row['amount_cents'] = (int) $row['amount_cents'];

        return $row;
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'F10 test tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }

    private function payable(int $tenantId, int $unitId, string $description, string $category, int $amountCents, ?string $dueDate, string $status): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO payable (tenant_id, system_unit_id, description_text, category, amount_cents, due_date, status, paid_at, system_user_id)
             VALUES (:tenant_id, :unit_id, :description, :category, :amount, :due_date, :status, :paid_at, :user_id)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'description' => $description,
            'category' => $category,
            'amount' => $amountCents,
            'due_date' => $dueDate,
            'status' => $status,
            'paid_at' => $status === 'paid' ? '2031-05-02 10:00:00' : null,
            'user_id' => $this->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
