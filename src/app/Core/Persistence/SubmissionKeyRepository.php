<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SubmissionKeyRepositoryInterface;
use CentralVet\Domain\SubmissionToken;
use CentralVet\Tenancy\TenantContext;
use LogicException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO-backed `submission_key` (migration 0016). Every query is scoped by
 * TenantQuery::forTenant() with the tenant of the context, never from input
 * (ADR 0002).
 *
 * `claim()` is a plain INSERT inside the caller transaction: the UNIQUE
 * `submission_key_uq` (tenant_id, scope, token) answers a repetition with
 * 1062 (→ false, the transaction stays usable), and a concurrent claim of a
 * token another transaction holds uncommitted waits on that key until it
 * commits (then 1062) or rolls back (then true), or fails with lock wait
 * 1205 for the caller. Never INSERT IGNORE (it turns CHECK violations into
 * warnings on MySQL 8).
 */
final class SubmissionKeyRepository implements SubmissionKeyRepositoryInterface
{
    private const DUPLICATE_KEY = 1062;

    private const UNIQUE_KEY = 'submission_key_uq';

    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function claim(string $scope, string $token, int $systemUserId): bool
    {
        if (!$this->connection->inTransaction()) {
            throw new LogicException('claim() requires an open transaction');
        }

        $statement = $this->connection->prepare(
            'INSERT INTO submission_key (tenant_id, scope, token, system_user_id) '
            . 'VALUES (:tenant_id, :scope, :token, :system_user_id)'
        );

        try {
            $statement->execute([
                'tenant_id' => $this->context->tenantId(),
                'scope' => $scope,
                'token' => SubmissionToken::assertValid($token),
                'system_user_id' => $systemUserId,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateClaim($exception)) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    public function attach(string $scope, string $token, int $referenceId): void
    {
        $query = $this->keyQuery($scope, $token);

        $statement = $this->connection->prepare(
            "UPDATE submission_key SET reference_id = :reference_id WHERE {$query->whereSql()}"
        );
        $statement->execute(['reference_id' => $referenceId, ...$query->parameters()]);

        if ($statement->rowCount() !== 1 && $this->findRow($query) === false) {
            throw new RuntimeException('Submission token was not claimed');
        }
    }

    public function findReference(string $scope, string $token): ?int
    {
        $row = $this->findRow($this->keyQuery($scope, $token));

        if ($row === false || $row['reference_id'] === null) {
            return null;
        }

        return (int) $row['reference_id'];
    }

    private function keyQuery(string $scope, string $token): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId())
            ->andEquals('scope', $scope)
            ->andEquals('token', SubmissionToken::assertValid($token));
    }

    /** @return array{reference_id: int|string|null}|false */
    private function findRow(TenantQuery $query): array|false
    {
        $statement = $this->connection->prepare(
            "SELECT reference_id FROM submission_key WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function isDuplicateClaim(PDOException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === self::DUPLICATE_KEY
            && str_contains((string) ($exception->errorInfo[2] ?? $exception->getMessage()), self::UNIQUE_KEY);
    }
}
