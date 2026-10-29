<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\SubmissionToken;
use CentralVet\Persistence\SubmissionKeyRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\TestDatabase;

/**
 * SubmissionKeyRepository (T-07) against submission_key (migration 0016):
 * claim once per tenant/scope/token (1062 → false), another tenant is
 * another key, a concurrent claim of the same token waits for the first
 * transaction (1205 with innodb_lock_wait_timeout = 1), attach/findReference
 * round trip and the open-transaction guard. Nothing is committed: the main
 * connection rolls back in tearDown() and the second one in a finally.
 */
final class SubmissionKeyRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'submission_key'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0016 must be applied to the test database');

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->tenantA = $this->createTenant('e2-subkey-a');
        $this->tenantB = $this->createTenant('e2-subkey-b');
    }

    public function testClaimInsertsOnceAndRepeatedClaimReturnsFalse(): void
    {
        $repository = $this->repositoryFor($this->tenantA, $this->pdo);
        $token = SubmissionToken::generate();

        Assert::true($repository->claim(SubmissionToken::SCOPE_SALE, $token, $this->userId));
        Assert::false(
            $repository->claim(SubmissionToken::SCOPE_SALE, $token, $this->userId),
            'The duplicate key 1062 must answer false',
        );
        Assert::true($this->pdo->inTransaction(), 'The 1062 must not end the caller transaction');

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM submission_key WHERE tenant_id = :t AND token = :k');
        $count->execute(['t' => $this->tenantA, 'k' => $token]);
        Assert::same(1, (int) $count->fetchColumn());
    }

    public function testSameTokenInAnotherScopeIsAnotherKey(): void
    {
        $repository = $this->repositoryFor($this->tenantA, $this->pdo);
        $token = SubmissionToken::generate();

        Assert::true($repository->claim(SubmissionToken::SCOPE_SALE, $token, $this->userId));
        Assert::true($repository->claim(SubmissionToken::SCOPE_PAYMENT, $token, $this->userId));
    }

    public function testSameTokenInAnotherTenantIsAnotherKey(): void
    {
        $token = SubmissionToken::generate();

        Assert::true($this->repositoryFor($this->tenantA, $this->pdo)->claim(SubmissionToken::SCOPE_SALE, $token, $this->userId));
        Assert::true($this->repositoryFor($this->tenantB, $this->pdo)->claim(SubmissionToken::SCOPE_SALE, $token, $this->userId));
        Assert::null($this->repositoryFor($this->tenantB, $this->pdo)->findReference(SubmissionToken::SCOPE_SALE, $token));
    }

    public function testConcurrentClaimOfTheSameTokenWaitsForTheFirstTransaction(): void
    {
        $token = SubmissionToken::generate();

        // A committed tenant, so B's FK check on tenant_id does not wait on
        // A's uncommitted fixture row: the only lock B can meet is the
        // unique-key record A inserted and has not committed.
        $committedTenant = (int) $this->pdo->query('SELECT MIN(id) FROM tenant')->fetchColumn();
        Assert::true($committedTenant > 0, 'Fixture requires at least one committed tenant row');

        Assert::true($this->repositoryFor($committedTenant, $this->pdo)->claim(SubmissionToken::SCOPE_SALE, $token, $this->userId));

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        try {
            $lockWait = false;
            $answer = 'no answer';

            try {
                $answer = var_export(
                    $this->repositoryFor($committedTenant, $other)->claim(SubmissionToken::SCOPE_SALE, $token, $this->userId),
                    true,
                );
            } catch (\PDOException $e) {
                $lockWait = (int) ($e->errorInfo[1] ?? 0) === 1205;
                $answer = $e->getMessage();
            }

            Assert::true($lockWait, "The second claim must wait for the pending first one (answered: {$answer})");
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
    }

    public function testAttachThenFindReferenceReturnsTheStoredId(): void
    {
        $repository = $this->repositoryFor($this->tenantA, $this->pdo);
        $token = SubmissionToken::generate();

        Assert::true($repository->claim(SubmissionToken::SCOPE_PAYMENT, $token, $this->userId));
        Assert::null($repository->findReference(SubmissionToken::SCOPE_PAYMENT, $token));

        $repository->attach(SubmissionToken::SCOPE_PAYMENT, $token, 98765);

        Assert::same(98765, $repository->findReference(SubmissionToken::SCOPE_PAYMENT, $token));
        Assert::null($repository->findReference(SubmissionToken::SCOPE_SALE, $token));
        Assert::null($this->repositoryFor($this->tenantB, $this->pdo)->findReference(SubmissionToken::SCOPE_PAYMENT, $token));
    }

    public function testClaimOutsideATransactionIsALogicError(): void
    {
        $outside = $this->secondConnection();

        Assert::throws(\LogicException::class, function () use ($outside): void {
            $this->repositoryFor($this->tenantA, $outside)->claim(SubmissionToken::SCOPE_SALE, SubmissionToken::generate(), $this->userId);
        });
    }

    private function repositoryFor(int $tenantId, \PDO $connection): SubmissionKeyRepository
    {
        return new SubmissionKeyRepository(TenantContext::authenticated($tenantId, $this->userId), $connection);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'E2 teste tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }

    private function secondConnection(): \PDO
    {
        return new \PDO(
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
    }
}
