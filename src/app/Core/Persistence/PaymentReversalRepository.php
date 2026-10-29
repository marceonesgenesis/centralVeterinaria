<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\PaymentReversalRepositoryInterface;
use CentralVet\Domain\PaymentReversal;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * PDO-backed persistence for PaymentReversal (Etapa 2, T-08; table
 * `payment_reversal`, migration 0016). Every query starts from
 * TenantQuery::forTenant() (ADR 0002). Append-only: save() only inserts,
 * remove() is refused — a reversal is the audit trail of money leaving the
 * till.
 *
 * @implements PaymentReversalRepositoryInterface<PaymentReversal>
 */
final class PaymentReversalRepository extends AbstractTenantRepository implements PaymentReversalRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM payment_reversal WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : PaymentReversal::reconstitute($row);
    }

    public function existsForPayment(int $paymentId): bool
    {
        $query = $this->tenantQuery()->andEquals('payment_id', $paymentId);

        $statement = $this->connection->prepare(
            "SELECT 1 FROM payment_reversal WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        return $statement->fetchColumn() !== false;
    }

    /** @return list<PaymentReversal> */
    public function listByReceivable(int $receivableId): array
    {
        $query = $this->tenantQuery('pr')->andEquals('receivable_id', $receivableId, 'p');

        $statement = $this->connection->prepare(
            'SELECT pr.* FROM payment_reversal pr '
            . 'INNER JOIN payment p ON p.id = pr.payment_id AND p.tenant_id = pr.tenant_id '
            . "WHERE {$query->whereSql()} ORDER BY pr.reversed_at ASC, pr.id ASC"
        );
        $statement->execute($query->parameters());

        $reversals = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reversals[] = PaymentReversal::reconstitute($row);
        }

        return $reversals;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof PaymentReversal) {
            throw new InvalidArgumentException('Expected a PaymentReversal entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() !== null) {
            throw new InvalidArgumentException('PaymentReversal is append-only and cannot be updated');
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO payment_reversal (
                tenant_id, payment_id, cash_session_id, payment_method, amount_cents,
                reason, system_user_id, reversed_at
            ) VALUES (
                :tenant_id, :payment_id, :cash_session_id, :payment_method, :amount_cents,
                :reason, :system_user_id, :reversed_at
            )
            SQL
        );
        $statement->execute([
            ':tenant_id' => $entity->tenantId(),
            ':payment_id' => $entity->paymentId(),
            ':cash_session_id' => $entity->cashSessionId(),
            ':payment_method' => $entity->paymentMethod(),
            ':amount_cents' => $entity->amountCents(),
            ':reason' => $entity->reason(),
            ':system_user_id' => $entity->systemUserId(),
            ':reversed_at' => $entity->reversedAt()->format('Y-m-d H:i:s.u'),
        ]);

        $entity->assignId((int) $this->connection->lastInsertId());

        return $entity;
    }

    public function remove(object $entity): void
    {
        throw new LogicException('PaymentReversal is append-only and cannot be removed');
    }
}
