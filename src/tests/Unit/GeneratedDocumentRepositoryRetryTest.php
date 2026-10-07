<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Persistence\GeneratedDocumentRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * GeneratedDocumentRepository::insertNextVersion() retries (Fase 7B, T-24):
 * a duplicate key 1062 on the version UNIQUE and a deadlock 1213 of the
 * `INSERT ... SELECT` are retried a bounded number of times; any other error
 * surfaces at once. A deadlock inside the caller's transaction is never
 * retried (InnoDB rolled the whole transaction back). The PDO is a stub: no
 * database is touched.
 */
final class GeneratedDocumentRepositoryRetryTest
{
    private const TENANT_ID = 4;

    private StubInsertPdo $pdo;

    public function testVersionCollisionIsRetriedThenSucceeds(): void
    {
        $repo = $this->repository([self::duplicate('generated_document_version_uq')]);

        $document = $repo->insertNextVersion(self::card());

        Assert::same(42, $document->id());
        Assert::same(3, $document->version());
        Assert::count(2, $this->pdo->inserts);
    }

    public function testPersistentVersionCollisionStopsAfterFourAttempts(): void
    {
        $collision = self::duplicate('generated_document_version_uq');
        $repo = $this->repository([$collision, $collision, $collision, $collision, $collision]);

        Assert::throws(RuntimeException::class, static fn () => $repo->insertNextVersion(self::card()));
        Assert::count(4, $this->pdo->inserts);
    }

    public function testDuplicateOnAnotherKeyIsNotRetried(): void
    {
        $repo = $this->repository([self::duplicate('generated_document_stored_object_uq')]);

        Assert::throws(PDOException::class, static fn () => $repo->insertNextVersion(self::card()));
        Assert::count(1, $this->pdo->inserts);
    }

    public function testDeadlockOutsideATransactionIsRetried(): void
    {
        $repo = $this->repository([self::deadlock()]);

        $document = $repo->insertNextVersion(self::card());

        Assert::same(42, $document->id());
        Assert::count(2, $this->pdo->inserts);
    }

    public function testPersistentDeadlockSurfacesAfterBoundedRetries(): void
    {
        $deadlock = self::deadlock();
        $repo = $this->repository([$deadlock, $deadlock, $deadlock, $deadlock, $deadlock]);

        $caught = null;
        try {
            $repo->insertNextVersion(self::card());
        } catch (PDOException $exception) {
            $caught = $exception;
        }

        Assert::same($deadlock, $caught, 'the last deadlock surfaces as is');
        Assert::count(4, $this->pdo->inserts);
    }

    public function testDeadlockInsideTheCallerTransactionIsNotRetried(): void
    {
        $repo = $this->repository([self::deadlock()], inTransaction: true);

        Assert::throws(PDOException::class, static fn () => $repo->insertNextVersion(self::card()));
        Assert::count(1, $this->pdo->inserts);
    }

    /** @param list<PDOException> $failures thrown by the first INSERT executions, in order */
    private function repository(array $failures, bool $inTransaction = false): GeneratedDocumentRepository
    {
        $this->pdo = new StubInsertPdo($failures, $inTransaction);

        return new GeneratedDocumentRepository(TenantContext::authenticated(self::TENANT_ID, 1), $this->pdo);
    }

    private static function card(): GeneratedDocument
    {
        return GeneratedDocument::request(self::TENANT_ID, 5, 21, 7, DocumentKind::VACCINATION_CARD, 21, null, null, false, 1);
    }

    private static function duplicate(string $key): PDOException
    {
        return self::pdoException('23000', 1062, "Duplicate entry 'x' for key 'generated_document.{$key}'");
    }

    private static function deadlock(): PDOException
    {
        return self::pdoException('40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction');
    }

    private static function pdoException(string $state, int $code, string $message): PDOException
    {
        $exception = new PDOException("SQLSTATE[{$state}]: {$message}");
        $exception->errorInfo = [$state, $code, $message];

        return $exception;
    }
}

/**
 * PDO stub for the insert path: INSERT executions throw the queued failures
 * (then succeed), the version SELECT answers 3 and lastInsertId() is 42.
 */
final class StubInsertPdo extends PDO
{
    /** @var list<string> INSERT statements executed, in order */
    public array $inserts = [];

    /** @param list<PDOException> $failures */
    public function __construct(private array $failures, private readonly bool $inTransaction)
    {
        // Intentionally does not call parent::__construct(): no connection.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $pdo = $this;
        $isInsert = str_starts_with(ltrim($query), 'INSERT');

        return new class ($pdo, $isInsert, $query) extends PDOStatement {
            public function __construct(private readonly StubInsertPdo $pdo, private readonly bool $isInsert, private readonly string $sql)
            {
            }

            public function execute(?array $params = null): bool
            {
                if ($this->isInsert) {
                    $this->pdo->onInsert($this->sql);
                }

                return true;
            }

            public function fetchColumn(int $column = 0): mixed
            {
                return 3;
            }
        };
    }

    public function onInsert(string $sql): void
    {
        $this->inserts[] = $sql;
        $failure = array_shift($this->failures);

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '42';
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }
}
