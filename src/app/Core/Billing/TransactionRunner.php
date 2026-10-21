<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use PDO;
use Throwable;

/**
 * Runs a unit of work in a transaction: beginTransaction → work → commit,
 * rollBack and rethrow on any exception. When a transaction is already open
 * (for instance the one MysqlIntegrationTestCase opens), the work runs in a
 * SAVEPOINT instead, released on success and rolled back to on failure, so
 * the outer transaction stays usable and nothing is committed.
 */
final class TransactionRunner
{
    private int $depth = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(\Closure $work): mixed
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();

            try {
                $result = $work();
                $this->pdo->commit();

                return $result;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }

        $savepoint = 'cv_tx_' . (++$this->depth);
        $this->pdo->exec('SAVEPOINT ' . $savepoint);

        try {
            $result = $work();
            $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }

            throw $e;
        } finally {
            $this->depth--;
        }
    }
}
