<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\PaymentRepositoryInterface;
use CentralVet\Domain\Payment;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Payment aggregate (T-06). Every query
 * starts from TenantQuery::forTenant() (via AbstractTenantRepository::
 * tenantQuery(), ADR 0002); tenant scoping is never accepted from caller
 * input.
 *
 * save() only ever inserts: a Payment is written once by
 * CentralVet\Application\PaymentService::register() and never updated
 * afterwards, same append-only shape as FinancialEntryRepository.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `payment` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. It is
 * prepared and syntax-checked (php -l) only; it must not be constructed
 * with a real PDO connection nor executed against the live database until
 * that migration has explicit SQL execution approval and has actually been
 * applied.
 *
 * @implements PaymentRepositoryInterface<Payment>
 */
final class PaymentRepository extends AbstractTenantRepository implements PaymentRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM payment WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function listByReceivable(int $receivableId): array
    {
        $query = $this->tenantQuery()->andEquals('receivable_id', $receivableId);

        $statement = $this->connection->prepare(
            "SELECT * FROM payment WHERE {$query->whereSql()} ORDER BY paid_at ASC, id ASC"
        );
        $statement->execute($query->parameters());

        $payments = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payments[] = self::hydrate($row);
        }

        return $payments;
    }

    public function listByCashSession(int $cashSessionId): array
    {
        $query = $this->tenantQuery()->andEquals('cash_session_id', $cashSessionId);

        $statement = $this->connection->prepare(
            "SELECT * FROM payment WHERE {$query->whereSql()} ORDER BY paid_at ASC, id ASC"
        );
        $statement->execute($query->parameters());

        $payments = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payments[] = self::hydrate($row);
        }

        return $payments;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Payment) {
            throw new InvalidArgumentException('Expected a Payment entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() !== null) {
            throw new InvalidArgumentException('Payment is append-only and cannot be updated');
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO payment (
                tenant_id, receivable_id, payment_method, amount_cents,
                cash_session_id, system_user_id, paid_at
            ) VALUES (
                :tenant_id, :receivable_id, :payment_method, :amount_cents,
                :cash_session_id, :system_user_id, :paid_at
            )
            SQL
        );
        $statement->execute([
            ':tenant_id' => $entity->tenantId(),
            ':receivable_id' => $entity->receivableId(),
            ':payment_method' => $entity->paymentMethod(),
            ':amount_cents' => $entity->amountCents(),
            ':cash_session_id' => $entity->cashSessionId(),
            ':system_user_id' => $entity->systemUserId(),
            ':paid_at' => $entity->paidAt()->format('Y-m-d H:i:s.u'),
        ]);

        $entity->assignId((int) $this->connection->lastInsertId());

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Payment) {
            throw new InvalidArgumentException('Expected a Payment entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM payment WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Payment
    {
        return Payment::reconstitute($row);
    }
}
