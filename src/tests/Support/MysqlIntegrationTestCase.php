<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

/**
 * Shared setup for integration tests that need a real MySQL connection (the
 * `mysql` service from docker-compose.yml — the same schema/credentials the
 * application itself uses, per src/app/config/database.php). Every test
 * opens its own transaction in setUp() and ALWAYS rolls it back in
 * tearDown(), so nothing a test inserts is ever persisted: safe to run
 * against the real development database, never leaves residue. A test
 * that leaves the transaction (COMMIT, DDL) makes tearDown() throw. The
 * database name comes from TestDatabase::resolveName() (TEST_DB_DATABASE).
 *
 * When the foundation schema (T-03's migration) has not been applied yet,
 * setUp() throws SkippedTestException instead of failing, since these
 * tests exist to prove tenant isolation on tables that migration creates.
 */
abstract class MysqlIntegrationTestCase
{
    protected \PDO $pdo;

    private bool $transactionOpened = false;

    public function setUp(): void
    {
        try {
            $this->pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    getenv('DB_HOST') ?: '127.0.0.1',
                    getenv('DB_PORT') ?: '3306',
                    TestDatabase::resolveName(getenv()),
                ),
                (string) (getenv('DB_USERNAME') ?: 'centralvet'),
                (string) (getenv('DB_PASSWORD') ?: ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $e) {
            throw new SkippedTestException('Real MySQL service unreachable: ' . $e->getMessage());
        }

        $foundationApplied = (bool) $this->pdo
            ->query("SHOW TABLES LIKE 'tenant'")
            ->fetchColumn();

        if (!$foundationApplied) {
            throw new SkippedTestException(
                'Foundation migration (T-03) not applied yet: tenant isolation tables do not exist.',
            );
        }

        $this->pdo->beginTransaction();
        $this->transactionOpened = true;
    }

    /**
     * Rolls back the test transaction. If setUp() opened it and it is no
     * longer active, something committed it mid-test (explicit COMMIT or an
     * implicit one, such as DDL): the writes may already be persisted, so
     * the test fails instead of passing silently.
     */
    public function tearDown(): void
    {
        if (!$this->transactionOpened) {
            return;
        }

        $this->transactionOpened = false;

        if (!$this->pdo->inTransaction()) {
            throw new \RuntimeException(
                'Integration test left the test transaction; writes may have been committed to the development database',
            );
        }

        $this->pdo->rollBack();
    }
}
