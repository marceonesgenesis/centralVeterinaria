<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Proves the MysqlIntegrationTestCase guard: a test that leaves the test
 * transaction (explicit COMMIT or an implicit one, such as DDL) fails in
 * tearDown() instead of silently persisting rows in the development
 * database. It does not extend the base itself, so the runner's own
 * setUp()/tearDown() never wrap these methods.
 */
final class MysqlIsolationGuardIntegrationTest
{
    public function testTearDownThrowsWhenTheTestTransactionWasCommitted(): void
    {
        $case = $this->newCase();
        $case->setUp();
        $case->connection()->commit();

        Assert::throws(\RuntimeException::class, static fn () => $case->tearDown());
    }

    public function testNormalPathRollsBackWithoutThrowing(): void
    {
        $case = $this->newCase();
        $case->setUp();
        $pdo = $case->connection();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM tutor')->fetchColumn();

        $tenantId = $this->createTenant($pdo);
        $statement = $pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :full_name, :phone)');
        $statement->execute(['tenant_id' => $tenantId, 'full_name' => 'R3 guard', 'phone' => '11900000000']);

        $case->tearDown();

        $after = (int) $pdo->query('SELECT COUNT(*) FROM tutor')->fetchColumn();
        Assert::same($before, $after, 'The rolled-back INSERT must not change COUNT(*) of tutor');
    }

    private function newCase(): MysqlIntegrationTestCase
    {
        return new class extends MysqlIntegrationTestCase {
            public function connection(): \PDO
            {
                return $this->pdo;
            }
        };
    }

    private function createTenant(\PDO $pdo): int
    {
        $slug = 'r3-guard-' . bin2hex(random_bytes(4));
        $statement = $pdo->prepare("INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, 'active')");
        $statement->execute(['slug' => $slug, 'legal_name' => 'R3 guard tenant (' . $slug . ')']);

        return (int) $pdo->lastInsertId();
    }
}
