<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Contract\CopilotStockQueryInterface;
use CentralVet\Persistence\CopilotStockQuery;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * T-20 — CopilotStockQuery against the real schema. Everything lives in
 * throwaway tenants inside the test transaction (rolled back).
 *
 * Today = 2031-05-10. Tenant A, unit A = context unit, unit B = other unit:
 *   - Racao   (min 5, code RAC-1): unit A batch 3 (lot RA, expiry 2031-08-01) + unit B batch 10 -> A balance 3, below
 *   - Coleira (min 2): no batch -> balance 0, below
 *   - Vacina  (min 0): unit A batches 4 (L20, +20 d), 6 (L40, +40 d), 0 (L10, +10 d) -> 10, never below
 *   - Shampoo (min 2): unit A batch 8 -> above minimum
 *   - Inativo (min 5, inactive): no batch -> never listed
 *   - Antipulga (min 0): unit B batch 5 expiring +5 d -> not expiring for A
 * Tenant B: Racao (min 5, no batch) -> never listed for A.
 */
final class CopilotStockQueryIntegrationTest extends MysqlIntegrationTestCase
{
    private const TODAY = '2031-05-10';

    private int $unitA;
    private int $unitB;
    private int $tenantA;
    private int $tenantB;
    private int $userId;

    /** @var array<string, int> */
    private array $ids = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT id FROM system_users ORDER BY id LIMIT 1')->fetchColumn();
        $this->unitA = (int) $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 1')->fetchColumn();
        Assert::true($this->userId > 0 && $this->unitA > 0, 'Fixture requires a system_users and a system_unit row');

        $this->tenantA = $this->createTenant('f8b-stock-a');
        $this->tenantB = $this->createTenant('f8b-stock-b');
        $this->unitB = $this->otherUnitId();

        $a = $this->tenantA;
        $this->ids['racao'] = $this->product($a, 'F8B Racao', 5, 'RAC-1');
        $this->ids['coleira'] = $this->product($a, 'F8B Coleira', 2);
        $this->ids['vacina'] = $this->product($a, 'F8B Vacina', 0);
        $this->ids['shampoo'] = $this->product($a, 'F8B Shampoo', 2);
        $this->ids['inativo'] = $this->product($a, 'F8B Inativo', 5, null, false);
        $this->ids['antipulga'] = $this->product($a, 'F8B Antipulga', 0);
        $this->ids['racaoB'] = $this->product($this->tenantB, 'F8B Racao', 5);

        $this->batch($a, $this->unitA, $this->ids['racao'], 3, 'RA', '2031-08-01');
        $this->batch($a, $this->unitB, $this->ids['racao'], 10, 'RB', '2031-05-15');
        $this->batch($a, $this->unitA, $this->ids['vacina'], 4, 'L20', '2031-05-30');
        $this->batch($a, $this->unitA, $this->ids['vacina'], 6, 'L40', '2031-06-19');
        $this->batch($a, $this->unitA, $this->ids['vacina'], 0, 'L10', '2031-05-20');
        $this->batch($a, $this->unitA, $this->ids['shampoo'], 8, null, null);
        $this->batch($a, $this->unitB, $this->ids['antipulga'], 5, 'AP', '2031-05-15');
    }

    public function testImplementsTheContract(): void
    {
        Assert::instanceOf(CopilotStockQueryInterface::class, $this->query());
    }

    public function testBalanceIgnoresBatchesOfAnotherUnit(): void
    {
        $rows = $this->query()->search('Racao', 10);

        Assert::same([[
            'product_id' => $this->ids['racao'],
            'name' => 'F8B Racao',
            'balance' => '3',
            'minimum' => '5',
            'next_expiry' => '2031-08-01',
            'lot' => 'RA',
        ]], $rows, 'unit B batch of 10 must not count; tenant B product must not appear');

        $unitB = $this->query($this->unitB)->search('Racao', 10);
        Assert::same('10', $unitB[0]['balance'], 'unit B sees only its own batch');
    }

    public function testBelowMinimumListsLowAndOutOfStockOfTheUnit(): void
    {
        $rows = $this->query()->belowMinimum(50);

        Assert::same(['F8B Coleira', 'F8B Racao'], array_column($rows, 'name'));
        Assert::same(['0', '3'], array_column($rows, 'balance'));
        Assert::same([$this->ids['coleira'], $this->ids['racao']], array_column($rows, 'product_id'));
        Assert::null($rows[0]['next_expiry']);
        Assert::same(1, count($this->query()->belowMinimum(1)), 'limit applies');
    }

    public function testExpiringIncludesTwentyDaysButNotFortyNorEmptyBatches(): void
    {
        $rows = $this->query()->expiring(new DateTimeImmutable(self::TODAY), 30, 50);

        Assert::same([[
            'product_id' => $this->ids['vacina'],
            'name' => 'F8B Vacina',
            'balance' => '10',
            'minimum' => null,
            'next_expiry' => '2031-05-30',
            'lot' => 'L20',
        ]], $rows, 'lot +20 d only: +40 d out, empty batch out, unit B out');
    }

    public function testSearchMatchesCodeAndSkipsInactive(): void
    {
        Assert::same([$this->ids['racao']], array_column($this->query()->search('rac-1', 10), 'product_id'));
        Assert::same([], $this->query()->search('Inativo', 10));
        Assert::same([], $this->query()->search('   ', 10));
        Assert::same([], $this->query()->search('100%', 10), 'LIKE wildcards are escaped');
    }

    /**
     * Pendências 8B, T-09: a batch already expired (MySQL CURDATE(), the
     * date of the writes) is not shown as the next expiry/lot; with only
     * expired batches, both are null. The balance still counts them.
     */
    public function testBelowMinimumSkipsExpiredBatchForNextExpiry(): void
    {
        $a = $this->tenantA;
        $mixed = $this->product($a, 'F8B Seringa', 10);
        $expiredOnly = $this->product($a, 'F8B Agulha', 10);
        $this->batch($a, $this->unitA, $mixed, 2, 'VENC', $this->sqlDate('CURDATE() - INTERVAL 1 DAY'));
        $this->batch($a, $this->unitA, $mixed, 3, 'OK60', $this->sqlDate('CURDATE() + INTERVAL 60 DAY'));
        $this->batch($a, $this->unitA, $expiredOnly, 1, 'VENC2', $this->sqlDate('CURDATE() - INTERVAL 1 DAY'));

        $rows = [];
        foreach ($this->query()->belowMinimum(50) as $row) {
            $rows[$row['product_id']] = $row;
        }

        Assert::same('5', $rows[$mixed]['balance']);
        Assert::same($this->sqlDate('CURDATE() + INTERVAL 60 DAY'), $rows[$mixed]['next_expiry'], 'next valid batch, not the one expired yesterday');
        Assert::same('OK60', $rows[$mixed]['lot']);
        Assert::null($rows[$expiredOnly]['next_expiry'], 'only an expired batch: no expiry shown');
        Assert::null($rows[$expiredOnly]['lot']);

        $search = $this->query()->search('Seringa', 10);
        Assert::same('OK60', $search[0]['lot'], 'search follows the same rule');
    }

    /** Pendências 8B, T-09: `_` is a literal in the search, not a one-character wildcard. */
    public function testSearchEscapesUnderscore(): void
    {
        $exact = $this->product($this->tenantA, 'F8B Gaze_A', 0);
        $this->product($this->tenantA, 'F8B GazeXA', 0);

        Assert::same([$exact], array_column($this->query()->search('Gaze_A', 10), 'product_id'));
    }

    /** Pendências 8B, T-09: expiring is [today, today + N] inclusive; expired and inactive products are left out. */
    public function testExpiringBoundaries(): void
    {
        $a = $this->tenantA;
        $today = new DateTimeImmutable($this->sqlDate('CURDATE()'));
        $edgeToday = $this->product($a, 'F8B Borda Hoje', 0);
        $edgeThirty = $this->product($a, 'F8B Borda Trinta', 0);
        $expired = $this->product($a, 'F8B Borda Ontem', 0);
        $inactive = $this->product($a, 'F8B Borda Inativo', 0, null, false);
        $this->batch($a, $this->unitA, $edgeToday, 1, 'H0', $today->format('Y-m-d'));
        $this->batch($a, $this->unitA, $edgeThirty, 1, 'H30', $today->modify('+30 days')->format('Y-m-d'));
        $this->batch($a, $this->unitA, $expired, 1, 'H-1', $today->modify('-1 day')->format('Y-m-d'));
        $this->batch($a, $this->unitA, $inactive, 1, 'HI', $today->modify('+5 days')->format('Y-m-d'));

        $ids = array_column($this->query()->expiring($today, 30, 50), 'product_id');

        Assert::true(in_array($edgeToday, $ids, true), 'expiring today');
        Assert::true(in_array($edgeThirty, $ids, true), 'expiring at today + 30');
        Assert::false(in_array($expired, $ids, true), 'expired yesterday');
        Assert::false(in_array($inactive, $ids, true), 'inactive product');
    }

    private function sqlDate(string $expression): string
    {
        return (string) $this->pdo->query('SELECT DATE_FORMAT(' . $expression . ", '%Y-%m-%d')")->fetchColumn();
    }

    private function query(?int $unitId = null): CopilotStockQuery
    {
        return new CopilotStockQuery(
            TenantContext::authenticated($this->tenantA, $this->userId, $unitId ?? $this->unitA),
            $this->pdo,
        );
    }

    private function product(int $tenantId, string $name, int $minimum, ?string $code = null, bool $active = true): int
    {
        return $this->insert('product', [
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => $code,
            'category' => 'F8B',
            'unit_of_measure' => 'un',
            'unit_cost_cents' => 100,
            'minimum_stock_quantity' => $minimum,
            'active' => $active ? 1 : 0,
        ]);
    }

    private function batch(int $tenantId, int $unitId, int $productId, int $quantity, ?string $lot, ?string $expiry): void
    {
        $this->insert('stock_batch', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $unitId,
            'product_id' => $productId,
            'lot' => $lot,
            'expiry_date' => $expiry,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Second system_unit (any other than unit A); when the test database has
     * only one, creates one inside the test transaction (rolled back).
     * system_unit.id is not AUTO_INCREMENT (Adianti).
     */
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
            'public_id' => (string) $this->pdo->query('SELECT UUID()')->fetchColumn(),
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
}
