<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Application\ProcedureExecutionService;
use CentralVet\Application\StockService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\Exception\InsufficientStockException;
use CentralVet\Domain\StockBatch;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogItemInputRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogRepository;
use CentralVet\Tests\Support\FakeProcedureExecutionRepository;
use CentralVet\Tests\Support\FakeStockBatchRepository;
use CentralVet\Tests\Support\FakeStockMovementRepository;
use DateTimeImmutable;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Tests\Support\FakeTenantUserDirectory;

/**
 * Unit tests for ProcedureExecutionService::execute() (T-05), against fake
 * repositories — no database involved, since `procedure_execution`/
 * `product`/`stock_batch`/`stock_movement` do not exist yet (migration T-01
 * not applied). Mirrors the FakeAuthorizationPolicy pattern already used by
 * VaccinationServiceTest/ExamServiceTest (Phase 3).
 *
 * Covers execute()'s three ordered business rules (per its own class
 * docblock):
 *   1. Unit-scope authorization against the origin ENCOUNTER's own
 *      system_unit_id (read back from the persisted Encounter), never the
 *      caller's active unit — a denial leaves stock untouched and no
 *      procedure_execution persisted.
 *   2. Every declared input is consumed via StockService::consume(); an
 *      InsufficientStockException on any of them propagates and
 *      procedure_execution is not written (inputs already consumed before
 *      the failing one are not rolled back — a documented limitation).
 *   3. procedure_execution is only persisted once every input's consumption
 *      succeeded.
 */
final class ProcedureExecutionServiceTest
{
    private const ACTION = 'test::action';
    private const TENANT_ID = 1;

    /**
     * Proves the unit-scope authorization check uses the origin encounter's
     * REAL unit (5), never the caller's active unit (1) supplied through
     * TenantContext — and that a denial leaves stock completely untouched
     * and no procedure_execution persisted.
     */
    public function testExecuteThrowsAuthorizationDeniedUsingEncounterRealUnitAndPersistsNothing(): void
    {
        $encounters = new FakeEncounterRepository(self::TENANT_ID);
        $encounter = Encounter::start(
            tenantId: self::TENANT_ID,
            systemUnitId: 5,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $encounters->save($encounter);
        $encounterId = $encounter->id();

        // Caller's active unit (1) deliberately differs from the
        // encounter's own unit (5): a check using the wrong source would
        // send a different resourceUnitId than the assertion below expects.
        $context = TenantContext::authenticated(self::TENANT_ID, 1, 1);

        $catalogService = new ProcedureCatalogService(
            new FakeProcedureCatalogRepository(self::TENANT_ID),
            new FakeProcedureCatalogItemInputRepository(self::TENANT_ID),
            $context,
        );
        $procedureItem = $catalogService->create('Banho', 5000, null, null);
        $procedureItemId = $procedureItem->id();
        $catalogService->addInput($procedureItemId, 1, 2);

        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $batch = StockBatch::receive(
            tenantId: self::TENANT_ID,
            systemUnitId: 5,
            productId: 1,
            lot: null,
            expiryDate: null,
            quantity: 10,
            receivedAt: new DateTimeImmutable(),
        );
        $batches->save($batch);
        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $stockService = new StockService($batches, $movements, new FakeAuthorizationPolicy(allowed: true), $context);

        $executions = new FakeProcedureExecutionRepository(self::TENANT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: false);

        $service = new ProcedureExecutionService(
            $executions,
            $encounters,
            $catalogService,
            $stockService,
            $policy,
            $context,
        );

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->execute($encounterId, $procedureItemId, 10, null, self::ACTION),
        );

        Assert::count(1, $policy->requests);
        Assert::same(5, $policy->requests[0]->resourceUnitId(), 'Must authorize against the encounter\'s REAL unit, not the caller\'s');

        Assert::count(0, $executions->listByEncounter($encounterId));

        $reloadedBatch = $batches->findById($batch->id());
        Assert::notNull($reloadedBatch);
        Assert::same(10, $reloadedBatch->quantity(), 'Stock must be untouched when authorization is denied');
        Assert::count(0, $movements->listByProduct(1));
    }

    /**
     * Happy path: with authorization approved, every declared input is
     * consumed exactly once (in listInputs() order) and the
     * ProcedureExecution is persisted only after both consume() calls
     * succeed.
     */
    public function testExecuteConsumesEveryDeclaredInputAndPersistsExecutionWhenAuthorizationApproved(): void
    {
        $encounters = new FakeEncounterRepository(self::TENANT_ID);
        $encounter = Encounter::start(
            tenantId: self::TENANT_ID,
            systemUnitId: 1,
            patientId: 7,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $encounters->save($encounter);
        $encounterId = $encounter->id();

        $context = TenantContext::authenticated(self::TENANT_ID, 1, 1);

        $catalogService = new ProcedureCatalogService(
            new FakeProcedureCatalogRepository(self::TENANT_ID),
            new FakeProcedureCatalogItemInputRepository(self::TENANT_ID),
            $context,
        );
        $procedureItem = $catalogService->create('Tosquia', 8000, null, null);
        $procedureItemId = $procedureItem->id();
        $catalogService->addInput($procedureItemId, 1, 2);
        $catalogService->addInput($procedureItemId, 2, 3);

        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $batchProductA = StockBatch::receive(self::TENANT_ID, 1, 1, null, null, 10, new DateTimeImmutable());
        $batches->save($batchProductA);
        $batchProductB = StockBatch::receive(self::TENANT_ID, 1, 2, null, null, 10, new DateTimeImmutable());
        $batches->save($batchProductB);

        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $stockService = new StockService($batches, $movements, new FakeAuthorizationPolicy(allowed: true), $context);

        $executions = new FakeProcedureExecutionRepository(self::TENANT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: true);

        $service = new ProcedureExecutionService(
            $executions,
            $encounters,
            $catalogService,
            $stockService,
            $policy,
            $context,
        );

        $execution = $service->execute($encounterId, $procedureItemId, 10, 'observação', self::ACTION);

        Assert::notNull($execution->id());
        Assert::same($encounterId, $execution->encounterId());
        Assert::same(7, $execution->patientId());
        Assert::same($procedureItemId, $execution->procedureCatalogItemId());

        $reloadedA = $batches->findById($batchProductA->id());
        Assert::notNull($reloadedA);
        Assert::same(8, $reloadedA->quantity());

        $reloadedB = $batches->findById($batchProductB->id());
        Assert::notNull($reloadedB);
        Assert::same(7, $reloadedB->quantity());

        Assert::count(1, $executions->listByEncounter($encounterId));
        Assert::count(1, $movements->listByProduct(1));
        Assert::count(1, $movements->listByProduct(2));
    }

    /**
     * When a later input lacks enough stock, InsufficientStockException
     * propagates and procedure_execution is NEVER persisted — the
     * acceptance criterion this test exists for. Also documents (per the
     * service's own class docblock) that the earlier input already consumed
     * before the failing one is NOT rolled back: this is an accepted
     * limitation, not a bug, so the test asserts it explicitly rather than
     * leaving it unverified.
     */
    public function testExecutePropagatesInsufficientStockExceptionAndNeverPersistsExecution(): void
    {
        $encounters = new FakeEncounterRepository(self::TENANT_ID);
        $encounter = Encounter::start(
            tenantId: self::TENANT_ID,
            systemUnitId: 1,
            patientId: 7,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $encounters->save($encounter);
        $encounterId = $encounter->id();

        $context = TenantContext::authenticated(self::TENANT_ID, 1, 1);

        $catalogService = new ProcedureCatalogService(
            new FakeProcedureCatalogRepository(self::TENANT_ID),
            new FakeProcedureCatalogItemInputRepository(self::TENANT_ID),
            $context,
        );
        $procedureItem = $catalogService->create('Cirurgia', 20000, null, null);
        $procedureItemId = $procedureItem->id();
        // First input: plenty of stock (10 available, needs 2).
        $catalogService->addInput($procedureItemId, 1, 2);
        // Second input: short (only 5 available, needs 100).
        $catalogService->addInput($procedureItemId, 2, 100);

        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $batchProductA = StockBatch::receive(self::TENANT_ID, 1, 1, null, null, 10, new DateTimeImmutable());
        $batches->save($batchProductA);
        $batchProductB = StockBatch::receive(self::TENANT_ID, 1, 2, null, null, 5, new DateTimeImmutable());
        $batches->save($batchProductB);

        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $stockService = new StockService($batches, $movements, new FakeAuthorizationPolicy(allowed: true), $context);

        $executions = new FakeProcedureExecutionRepository(self::TENANT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: true);

        $service = new ProcedureExecutionService(
            $executions,
            $encounters,
            $catalogService,
            $stockService,
            $policy,
            $context,
        );

        Assert::throws(
            InsufficientStockException::class,
            static fn () => $service->execute($encounterId, $procedureItemId, 10, null, self::ACTION),
        );

        Assert::count(0, $executions->listByEncounter($encounterId), 'procedure_execution must never be written when a declared input is short');

        // Documented limitation: the first input (product 1) was already
        // consumed before the second one failed, and is not rolled back.
        $reloadedA = $batches->findById($batchProductA->id());
        Assert::notNull($reloadedA);
        Assert::same(8, $reloadedA->quantity());

        // The failing input's own consume() call is itself all-or-nothing:
        // nothing was written for product 2.
        $reloadedB = $batches->findById($batchProductB->id());
        Assert::notNull($reloadedB);
        Assert::same(5, $reloadedB->quantity());
        Assert::count(0, $movements->listByProduct(2));
    }

    /**
     * final-fix: a professional_system_user_id that is not an active member
     * of the authenticated tenant (another tenant's user, or nonexistent) is
     * rejected with CrossTenantReferenceException and nothing is persisted.
     */
    public function testExecuteRejectsProfessionalOutsideTenantAndPersistsNothingNorConsumesStock(): void
    {
        $encounters = new FakeEncounterRepository(1);
        $encounter = Encounter::start(
            tenantId: 1,
            systemUnitId: 1,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $encounters->save($encounter);
        $encounterId = $encounter->id();

        $context = TenantContext::authenticated(self::TENANT_ID, 1, 1);

        $catalogService = new ProcedureCatalogService(
            new FakeProcedureCatalogRepository(self::TENANT_ID),
            new FakeProcedureCatalogItemInputRepository(self::TENANT_ID),
            $context,
        );
        $procedureItemId = $catalogService->create('Tosquia', 8000, null, null)->id();
        $catalogService->addInput($procedureItemId, 1, 2);

        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $batch = StockBatch::receive(self::TENANT_ID, 1, 1, null, null, 10, new DateTimeImmutable());
        $batches->save($batch);

        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $stockService = new StockService($batches, $movements, new FakeAuthorizationPolicy(allowed: true), $context);

        $executions = new FakeProcedureExecutionRepository(self::TENANT_ID);
        $service = new ProcedureExecutionService(
            $executions,
            $encounters,
            $catalogService,
            $stockService,
            new FakeAuthorizationPolicy(allowed: true),
            $context,
            new FakeTenantUserDirectory([10]),
        );

        Assert::throws(
            CrossTenantReferenceException::class,
            static fn () => $service->execute($encounterId, $procedureItemId, 999, null, self::ACTION),
        );

        Assert::count(0, $executions->listByEncounter($encounterId));
        Assert::same(10, $batches->findById($batch->id())->quantity());
        Assert::count(0, $movements->listByProduct(1));
    }
}
