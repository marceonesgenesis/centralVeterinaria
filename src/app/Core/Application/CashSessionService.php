<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\CashSession;
use CentralVet\Domain\Contract\CashSessionRepositoryInterface;
use CentralVet\Domain\Exception\CashSessionAlreadyOpenException;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Use cases for the CashSession aggregate (T-04): opening/closing a system
 * unit's till session and reading its payment totals for the closing
 * screen.
 *
 * Depends only on Domain contracts, TenantContext and (see the PDO note
 * below) a raw PDO connection — no TPage or any other Adianti class (ADR
 * 0001), so it can run from REST, workers or MCP exactly like from the
 * current Adianti presentation layer.
 *
 * Authorization discipline: open() authorizes against the unit-scoped
 * $systemUnitId BEFORE any read/write, same pattern documented on
 * StockService::receiveBatch() and required here explicitly by this task's
 * own Caminhos relevantes note — a "simple" write like opening a till still
 * goes through AuthorizationPolicyInterface first. close() re-derives its
 * unit scope from the session it loads (a session's system_unit_id never
 * changes after open()), so the caller cannot close a session in a unit it
 * has no access to just by knowing its id.
 *
 * PDO dependency note: the constructor's first three parameters
 * (CashSessionRepositoryInterface, AuthorizationPolicyInterface,
 * TenantContext) are CashSessionRepositoryInterface's own T-04 dependency.
 * A fourth parameter, a raw PDO connection, is added solely for
 * totalsByPaymentMethod(): that method sums `payment.amount_cents` grouped
 * by `payment_method` for a cash session, but CashSessionRepositoryInterface
 * (T-02, immutable — "não redefinir contratos") exposes no such read, and
 * Domain\Payment / PaymentRepository do not exist yet (T-06, a later wave,
 * builds them). Rather than invent a dependency on a not-yet-existing
 * Payment entity or smuggle a payment-table query through
 * CashSessionRepositoryInterface, totalsByPaymentMethod() queries `payment`
 * directly through this PDO connection — exactly the precedent set by
 * EncounterService::timeline() querying `audit_log` directly for the same
 * reason (see that class's own PDO dependency note). It still never accepts
 * tenant scoping from caller input: the tenant predicate always comes from
 * TenantContext.
 */
final class CashSessionService
{
    public function __construct(
        private readonly CashSessionRepositoryInterface $sessions,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    /**
     * Opens a new cash session for $systemUnitId, with $openingBalanceCents
     * as its starting balance.
     *
     * @throws CashSessionAlreadyOpenException when
     *         CashSessionRepositoryInterface::findOpenBySystemUnit()
     *         already returns an open session for $systemUnitId. Nothing is
     *         persisted when this is thrown.
     */
    public function open(
        int $systemUnitId,
        int $openingBalanceCents,
        int $openedBySystemUserId,
        string $action,
    ): CashSession {
        // Unit-scope authorization against the caller-supplied
        // $systemUnitId, before any read/write — same pattern as
        // StockService::receiveBatch()/ProcedureExecutionService::execute().
        // A denial throws AuthorizationDenied with nothing persisted.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $systemUnitId,
            entityType: 'cash_session',
            entityId: null,
        ))->assertAllowed();

        $existing = $this->sessions->findOpenBySystemUnit($systemUnitId);

        if ($existing !== null) {
            throw CashSessionAlreadyOpenException::forSystemUnit($systemUnitId);
        }

        $session = CashSession::open(
            tenantId: $this->context->tenantId(),
            systemUnitId: $systemUnitId,
            openingBalanceCents: $openingBalanceCents,
            openedBySystemUserId: $openedBySystemUserId,
            openedAt: new DateTimeImmutable(),
        );

        /** @var CashSession $saved */
        $saved = $this->sessions->save($session);

        return $saved;
    }

    /**
     * Closes $cashSessionId with the counted $closingBalanceCents.
     *
     * @throws \CentralVet\Domain\Exception\InvalidStatusTransitionException
     *         when the session is not currently `status='open'` (thrown by
     *         CashSession::close() itself) — a session already closed keeps
     *         its original closing_balance_cents unchanged, nothing is
     *         written.
     */
    public function close(
        int $cashSessionId,
        int $closingBalanceCents,
        int $closedBySystemUserId,
        string $action,
    ): CashSession {
        /** @var CashSession|null $session */
        $session = $this->sessions->findById($cashSessionId);

        if ($session === null) {
            throw new InvalidArgumentException("Cash session {$cashSessionId} not found");
        }

        // Unit-scope authorization against the session's own
        // system_unit_id, resolved from the loaded aggregate rather than
        // caller input (mirrors VaccinationService::apply()'s "resolve the
        // real unit, then authorize" ordering) — before any mutation.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $session->systemUnitId(),
            entityType: 'cash_session',
            entityId: $session->id(),
        ))->assertAllowed();

        $session->close($closingBalanceCents, $closedBySystemUserId, new DateTimeImmutable());

        /** @var CashSession $saved */
        $saved = $this->sessions->save($session);

        return $saved;
    }

    /**
     * Sums `payment.amount_cents` recorded in $cashSessionId, grouped by
     * `payment_method`, for the closing screen. See the class docblock's
     * PDO dependency note for why this reads `payment` directly instead of
     * through a Repository interface.
     *
     * @return array<string, int> payment_method => total amount_cents
     */
    public function totalsByPaymentMethod(int $cashSessionId): array
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
            SELECT payment_method, SUM(amount_cents) AS total_cents
            FROM payment
            WHERE tenant_id = :tenant_id AND cash_session_id = :cash_session_id
            GROUP BY payment_method
            SQL
        );
        $statement->execute([
            ':tenant_id' => $this->context->tenantId(),
            ':cash_session_id' => $cashSessionId,
        ]);

        $totals = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(string) $row['payment_method']] = (int) $row['total_cents'];
        }

        return $totals;
    }
}
