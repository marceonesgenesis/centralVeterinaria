<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\CashSessionService;
use CentralVet\Domain\Exception\CashSessionAlreadyOpenException;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCashSessionRepository;
use CentralVet\Tests\Support\NullPdo;

/**
 * Unit tests for CashSessionService (T-04), against a fake of
 * CashSessionRepositoryInterface — no database involved, since
 * `cash_session` does not exist yet (migration T-01 not applied). Mirrors
 * the FakeAuthorizationPolicy pattern already used by
 * ProcedureExecutionServiceTest/SaleServiceTest (Phase 4).
 *
 * Covers T-04's own acceptance criterion (T-13's mandate): open() for a
 * unit that already has an open session throws
 * CashSessionAlreadyOpenException without writing a second row.
 *
 * The real CashSessionService constructor also takes a raw PDO connection
 * (for totalsByPaymentMethod() alone — see that class's own docblock); this
 * suite never exercises that method, so a NullPdo stand-in (same one
 * EncounterServiceTest already uses for the same reason) satisfies the type
 * requirement without opening any real connection.
 */
final class CashSessionServiceTest
{
    private const ACTION = 'test::cash_session';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;

    /** @return array{0: CashSessionService, 1: FakeCashSessionRepository, 2: TenantContext} */
    private function buildService(bool $allowed = true): array
    {
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::UNIT_ID);
        $sessions = new FakeCashSessionRepository(self::TENANT_ID);

        $service = new CashSessionService(
            $sessions,
            new FakeAuthorizationPolicy(allowed: $allowed),
            $context,
            new NullPdo(),
        );

        return [$service, $sessions, $context];
    }

    /**
     * Criterion 4: open() for a unit that already has status='open' throws
     * CashSessionAlreadyOpenException and does not write a second row.
     */
    public function testOpenForUnitWithAlreadyOpenSessionThrowsAndDoesNotWriteSecondRow(): void
    {
        [$service, $sessions] = $this->buildService();

        $first = $service->open(self::UNIT_ID, 10000, 1, self::ACTION);
        Assert::notNull($first->id());
        Assert::same(1, $sessions->count(), 'Exactly one session must exist after the first open()');

        Assert::throws(
            CashSessionAlreadyOpenException::class,
            fn () => $service->open(self::UNIT_ID, 20000, 1, self::ACTION),
        );

        Assert::same(1, $sessions->count(), 'open() must not write a second session row when one is already open for the unit');

        $stillOpen = $sessions->findOpenBySystemUnit(self::UNIT_ID);
        Assert::notNull($stillOpen);
        Assert::same($first->id(), $stillOpen->id(), 'The originally opened session must remain the only open one');
        Assert::same(10000, $stillOpen->openingBalanceCents(), 'The original opening_balance_cents must be untouched');
    }

    /**
     * Sanity check that a different system unit is unaffected by another
     * unit's already-open session — open() must key the "already open"
     * check by system_unit_id, not globally.
     */
    public function testOpenForDifferentUnitIsUnaffectedByAnotherUnitsOpenSession(): void
    {
        [$service, $sessions] = $this->buildService();

        $service->open(self::UNIT_ID, 10000, 1, self::ACTION);

        $otherUnitSession = $service->open(7, 5000, 1, self::ACTION);
        Assert::notNull($otherUnitSession->id());
        Assert::same(2, $sessions->count());
    }
}
