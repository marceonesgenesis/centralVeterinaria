<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

/**
 * Shared setup for integration tests that need a real MySQL connection (the
 * `mysql` service from docker-compose.yml — the same schema/credentials the
 * application itself uses, per src/app/config/database.php). Every test
 * opens its own transaction in setUp() and ALWAYS rolls it back in
 * tearDown(), so nothing a test inserts is ever persisted: safe to run
 * against the real development database, never leaves residue.
 *
 * When the foundation schema (T-03's migration) has not been applied yet,
 * setUp() throws SkippedTestException instead of failing, since these
 * tests exist to prove tenant isolation on tables that migration creates.
 */
abstract class MysqlIntegrationTestCase
{
    protected \PDO $pdo;

    public function setUp(): void
    {
        try {
            $this->pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    getenv('DB_HOST') ?: '127.0.0.1',
                    getenv('DB_PORT') ?: '3306',
                    getenv('DB_DATABASE') ?: 'centralvet',
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
    }

    public function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
