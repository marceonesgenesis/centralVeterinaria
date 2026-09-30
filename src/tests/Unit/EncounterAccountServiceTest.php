<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\EncounterAccountService;
use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Authorization\AuthorizationDecision;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\EncounterAccountItem;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\DiscountExceedsSubtotalException;
use CentralVet\Domain\Patient;
use CentralVet\Domain\ProcedureExecution;
use CentralVet\Domain\Receivable;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeEncounterAccountItemRepository;
use CentralVet\Tests\Support\FakeEncounterAccountRepository;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakeExamCatalogRepository;
use CentralVet\Tests\Support\FakeExamRequestRepository;
use CentralVet\Tests\Support\FakePatientRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogItemInputRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogRepository;
use CentralVet\Tests\Support\FakeProcedureExecutionRepository;
use CentralVet\Tests\Support\FakeReceivableRepository;
use CentralVet\Tests\Support\FakeTenantUserDirectory;
use DateTimeImmutable;

/**
 * Unit tests for EncounterAccountService (T-03), against fakes of every
 * repository it consumes — no database involved, since `encounter_account`/
 * `encounter_account_item`/`receivable` do not exist yet (migration T-01
 * not applied). Mirrors the FakeAuthorizationPolicy pattern already used by
 * ProcedureExecutionServiceTest/SaleServiceTest (Phase 4).
 *
 * Covers T-03's own acceptance criteria (T-13's mandate):
 *   1. syncAutomaticItems() called twice in a row does not duplicate any
 *      item.
 *   2. applyDiscount() with a value greater than the subtotal throws
 *      DiscountExceedsSubtotalException without altering discount_cents/
 *      total_cents.
 *   3. close() produces a Receivable::totalCents() identical to
 *      EncounterAccount::totalCents().
 */
final class EncounterAccountServiceTest
{
    private const ACTION = 'test::encounter_account';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;

    /**
     * Builds a service instance wired to fresh fakes, plus an already
     * persisted encounter (unit 5) and patient (tutor 3), returning both
     * so tests can register procedure_execution/exam_request activity
     * against them.
     *
     * @return array{0: EncounterAccountService, 1: int, 2: FakeEncounterAccountItemRepository, 3: FakeProcedureExecutionRepository, 4: FakeProcedureCatalogRepository, 5: FakeProcedureCatalogItemInputRepository}
     */
    private function buildService(
        ?FakeTenantUserDirectory $tenantUsers = null,
        ?AuthorizationPolicyInterface $policy = null,
    ): array
    {
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::UNIT_ID);

        $encounters = new FakeEncounterRepository(self::TENANT_ID);
        $encounter = Encounter::start(
            tenantId: self::TENANT_ID,
            systemUnitId: self::UNIT_ID,
            patientId: 7,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $encounters->save($encounter);
        $encounterId = $encounter->id();

        $patients = new FakePatientRepository(self::TENANT_ID);
        $patients->save(new Patient(
            id: 7,
            tenantId: self::TENANT_ID,
            tutorId: 3,
            name: 'Rex',
            species: 'dog',
        ));

        $procedureCatalogItems = new FakeProcedureCatalogRepository(self::TENANT_ID);
        $procedureCatalogInputs = new FakeProcedureCatalogItemInputRepository(self::TENANT_ID);
        $procedureCatalog = new ProcedureCatalogService($procedureCatalogItems, $procedureCatalogInputs, $context);

        $procedureExecutions = new FakeProcedureExecutionRepository(self::TENANT_ID);
        $examRequests = new FakeExamRequestRepository(self::TENANT_ID);
        $examCatalog = new FakeExamCatalogRepository(self::TENANT_ID);

        $accounts = new FakeEncounterAccountRepository(self::TENANT_ID);
        $items = new FakeEncounterAccountItemRepository(self::TENANT_ID);
        $receivables = new FakeReceivableRepository(self::TENANT_ID);

        $service = new EncounterAccountService(
            $accounts,
            $items,
            $receivables,
            $encounters,
            $patients,
            $procedureExecutions,
            $examRequests,
            $procedureCatalog,
            $examCatalog,
            $policy ?? new FakeAuthorizationPolicy(allowed: true),
            $context,
            $tenantUsers ?? FakeTenantUserDirectory::allowingAll(),
        );

        return [$service, $encounterId, $items, $procedureExecutions, $procedureCatalogItems, $procedureCatalogInputs];
    }

    /**
     * Criterion 1: syncAutomaticItems() called twice in a row does not
     * duplicate any item — the second call finds nothing new to add
     * (matched by source_type+source_id against the account's existing
     * items) and returns an empty list.
     */
    public function testSyncAutomaticItemsCalledTwiceDoesNotDuplicateAnyItem(): void
    {
        [$service, $encounterId, $items, $procedureExecutions, $procedureCatalogItems] = $this->buildService();

        $procedureItem = $procedureCatalogItems->save(
            \CentralVet\Domain\ProcedureCatalogItem::create(self::TENANT_ID, 'Banho', 5000, null, null)
        );
        $execution = ProcedureExecution::record(
            tenantId: self::TENANT_ID,
            encounterId: $encounterId,
            patientId: 7,
            procedureCatalogItemId: $procedureItem->id(),
            professionalSystemUserId: 10,
            notesText: null,
            executedAt: new DateTimeImmutable(),
        );
        $procedureExecutions->save($execution);

        $account = $service->openOrGet($encounterId, self::ACTION);

        $firstSync = $service->syncAutomaticItems($account->id(), self::ACTION);
        Assert::count(1, $firstSync, 'First sync must add exactly the one billable execution');
        Assert::count(1, $items->listByAccount($account->id()));

        $secondSync = $service->syncAutomaticItems($account->id(), self::ACTION);
        Assert::count(0, $secondSync, 'Second sync must add nothing new — idempotent');
        Assert::count(1, $items->listByAccount($account->id()), 'No duplicate item must exist after a second sync');

        $reloadedAccount = $service->openOrGet($encounterId, self::ACTION);
        Assert::same(5000, $reloadedAccount->subtotalCents(), 'Subtotal must reflect exactly one item, not two');
    }

    /**
     * Criterion 2: applyDiscount() with a value greater than the subtotal
     * throws DiscountExceedsSubtotalException without altering
     * discount_cents/total_cents — the mutation happens only on the
     * in-memory EncounterAccount before EncounterAccountRepositoryInterface
     * ::save() is ever called.
     */
    public function testApplyDiscountExceedingSubtotalThrowsAndPersistsNothing(): void
    {
        [$service, $encounterId, , $procedureExecutions, $procedureCatalogItems] = $this->buildService();

        $procedureItem = $procedureCatalogItems->save(
            \CentralVet\Domain\ProcedureCatalogItem::create(self::TENANT_ID, 'Consulta', 10000, null, null)
        );
        $execution = ProcedureExecution::record(
            tenantId: self::TENANT_ID,
            encounterId: $encounterId,
            patientId: 7,
            procedureCatalogItemId: $procedureItem->id(),
            professionalSystemUserId: 10,
            notesText: null,
            executedAt: new DateTimeImmutable(),
        );
        $procedureExecutions->save($execution);

        $account = $service->openOrGet($encounterId, self::ACTION);
        $service->syncAutomaticItems($account->id(), self::ACTION);

        Assert::throws(
            DiscountExceedsSubtotalException::class,
            fn () => $service->applyDiscount($account->id(), 10001, 99, self::ACTION),
        );

        $reloaded = $service->openOrGet($encounterId, self::ACTION);
        Assert::same(0, $reloaded->discountCents(), 'discount_cents must remain untouched when the discount exceeds subtotal');
        Assert::same(10000, $reloaded->totalCents(), 'total_cents must remain untouched when the discount exceeds subtotal');
    }

    /**
     * Criterion 3: close() produces Receivable::totalCents() identical to
     * EncounterAccount::totalCents() — the value is copied, never
     * recomputed differently.
     */
    public function testCloseProducesReceivableTotalCentsIdenticalToAccountTotalCents(): void
    {
        [$service, $encounterId, , $procedureExecutions, $procedureCatalogItems] = $this->buildService();

        $procedureItem = $procedureCatalogItems->save(
            \CentralVet\Domain\ProcedureCatalogItem::create(self::TENANT_ID, 'Cirurgia', 30000, null, null)
        );
        $execution = ProcedureExecution::record(
            tenantId: self::TENANT_ID,
            encounterId: $encounterId,
            patientId: 7,
            procedureCatalogItemId: $procedureItem->id(),
            professionalSystemUserId: 10,
            notesText: null,
            executedAt: new DateTimeImmutable(),
        );
        $procedureExecutions->save($execution);

        $account = $service->openOrGet($encounterId, self::ACTION);
        $service->syncAutomaticItems($account->id(), self::ACTION);
        $service->applyDiscount($account->id(), 5000, 99, self::ACTION);

        $receivable = $service->close($account->id(), self::ACTION);

        Assert::instanceOf(Receivable::class, $receivable);
        Assert::same(25000, $receivable->totalCents(), 'Receivable total must equal 30000 - 5000 discount');

        $closedAccount = $service->openOrGet($encounterId, self::ACTION);
        Assert::same($closedAccount->totalCents(), $receivable->totalCents(), 'Receivable::totalCents must equal EncounterAccount::totalCents exactly');
    }

    /**
     * T-25: the discount authorizer comes from caller input (a combo that
     * a tampered POST can bypass), so an id outside the authenticated
     * tenant is refused before anything is persisted.
     */
    public function testApplyDiscountWithAuthorizerOutsideTenantThrowsAndPersistsNothing(): void
    {
        $this->assertDiscountRefusedFor(new FakeTenantUserDirectory([10]), 999);
    }

    /**
     * T-25 (Review Focus): a user of the tenant that is inactive
     * (active='N', i.e. not an active member) is refused the same way.
     */
    public function testApplyDiscountWithInactiveAuthorizerThrowsAndPersistsNothing(): void
    {
        $this->assertDiscountRefusedFor(new FakeTenantUserDirectory([]), 10);
    }

    /**
     * T-36: RBAC runs before the authorizer lookup, so a user without the
     * discount permission cannot probe which authorizer ids are active: the
     * answer is AuthorizationDenied even for a nonexistent authorizer.
     */
    public function testApplyDiscountDeniedByPolicyThrowsAuthorizationDeniedEvenForUnknownAuthorizer(): void
    {
        $policy = new class implements AuthorizationPolicyInterface {
            public bool $allowed = true;

            public function decide(AuthorizationRequest $request): AuthorizationDecision
            {
                return new AuthorizationDecision($this->allowed, $this->allowed ? 'granted' : 'denied', 'test-correlation-id');
            }
        };
        [$service, $encounterId] = $this->buildService(new FakeTenantUserDirectory([]), $policy);

        $account = $service->openOrGet($encounterId, self::ACTION);
        $policy->allowed = false;

        Assert::throws(
            AuthorizationDenied::class,
            fn () => $service->applyDiscount($account->id(), 0, 999, self::ACTION),
        );
    }

    private function assertDiscountRefusedFor(FakeTenantUserDirectory $tenantUsers, int $authorizerId): void
    {
        [$service, $encounterId, , $procedureExecutions, $procedureCatalogItems] = $this->buildService($tenantUsers);

        $procedureItem = $procedureCatalogItems->save(
            \CentralVet\Domain\ProcedureCatalogItem::create(self::TENANT_ID, 'Consulta', 10000, null, null)
        );
        $procedureExecutions->save(ProcedureExecution::record(
            tenantId: self::TENANT_ID,
            encounterId: $encounterId,
            patientId: 7,
            procedureCatalogItemId: $procedureItem->id(),
            professionalSystemUserId: 10,
            notesText: null,
            executedAt: new DateTimeImmutable(),
        ));

        $account = $service->openOrGet($encounterId, self::ACTION);
        $service->syncAutomaticItems($account->id(), self::ACTION);

        Assert::throws(
            CrossTenantReferenceException::class,
            fn () => $service->applyDiscount($account->id(), 500, $authorizerId, self::ACTION),
        );

        $reloaded = $service->openOrGet($encounterId, self::ACTION);
        Assert::same(0, $reloaded->discountCents(), 'discount_cents must remain untouched when the authorizer is not an active tenant member');
    }
}
