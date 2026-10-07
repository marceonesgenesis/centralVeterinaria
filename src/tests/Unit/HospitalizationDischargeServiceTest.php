<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\EncounterAccountService;
use CentralVet\Application\HospitalizationDischargeService;
use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Application\StockService;
use CentralVet\Domain\Bed;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\EncounterAccount;
use CentralVet\Domain\EncounterAccountItem;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Hospitalization;
use CentralVet\Domain\HospitalizationAdministration;
use CentralVet\Domain\HospitalizationEvent;
use CentralVet\Domain\HospitalizationOrder;
use CentralVet\Domain\Patient;
use CentralVet\Domain\Product;
use CentralVet\Domain\StockBatch;
use CentralVet\Domain\StockMovement;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeBedRepository;
use CentralVet\Tests\Support\FakeEncounterAccountItemRepository;
use CentralVet\Tests\Support\FakeEncounterAccountRepository;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakeExamCatalogRepository;
use CentralVet\Tests\Support\FakeExamRequestRepository;
use CentralVet\Tests\Support\FakeHospitalizationAdministrationRepository;
use CentralVet\Tests\Support\FakeHospitalizationEventRepository;
use CentralVet\Tests\Support\FakeHospitalizationOrderRepository;
use CentralVet\Tests\Support\FakeHospitalizationRepository;
use CentralVet\Tests\Support\FakePatientRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogItemInputRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogRepository;
use CentralVet\Tests\Support\FakeProcedureExecutionRepository;
use CentralVet\Tests\Support\FakeProductRepository;
use CentralVet\Tests\Support\FakeReceivableRepository;
use CentralVet\Tests\Support\FakeStockBatchRepository;
use CentralVet\Tests\Support\FakeStockMovementRepository;
use CentralVet\Tests\Support\FakeTenantUserDirectory;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * T-11: integrated discharge — stock consumption, account items, pending
 * administrations cancelled, bed released, discharge event — with the real
 * EncounterAccountService and StockService wired over fakes.
 */
final class HospitalizationDischargeServiceTest
{
    private const ACTION = 'test::hospitalization_discharge';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 9;
    private const NOW = '2026-10-05 12:00:00';

    /**
     * @return array{
     *     service: HospitalizationDischargeService,
     *     accountService: EncounterAccountService,
     *     encounterId: int,
     *     hospitalizationId: int,
     *     hospitalizations: FakeHospitalizationRepository,
     *     beds: FakeBedRepository,
     *     administrations: FakeHospitalizationAdministrationRepository,
     *     events: FakeHospitalizationEventRepository,
     *     items: FakeEncounterAccountItemRepository,
     *     batches: FakeStockBatchRepository,
     *     movements: FakeStockMovementRepository,
     * }
     */
    private function build(): array
    {
        $now = new DateTimeImmutable(self::NOW);
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: true);

        $encounters = new FakeEncounterRepository(self::TENANT_ID);
        $encounter = Encounter::start(
            tenantId: self::TENANT_ID,
            systemUnitId: self::UNIT_ID,
            patientId: 7,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: $now->modify('-51 hours'),
        );
        $encounters->save($encounter);
        $encounterId = (int) $encounter->id();

        $patients = new FakePatientRepository(self::TENANT_ID);
        $patients->save(new Patient(id: 7, tenantId: self::TENANT_ID, tutorId: 3, name: 'Rex', species: 'dog'));

        $accounts = new FakeEncounterAccountRepository(self::TENANT_ID);
        $items = new FakeEncounterAccountItemRepository(self::TENANT_ID);
        $accountService = new EncounterAccountService(
            $accounts,
            $items,
            new FakeReceivableRepository(self::TENANT_ID),
            $encounters,
            $patients,
            new FakeProcedureExecutionRepository(self::TENANT_ID),
            new FakeExamRequestRepository(self::TENANT_ID),
            new ProcedureCatalogService(
                new FakeProcedureCatalogRepository(self::TENANT_ID),
                new FakeProcedureCatalogItemInputRepository(self::TENANT_ID),
                $context,
            ),
            new FakeExamCatalogRepository(self::TENANT_ID),
            $policy,
            $context,
            FakeTenantUserDirectory::allowingAll(),
        );

        $products = new FakeProductRepository(
            self::TENANT_ID,
            Product::create(self::TENANT_ID, 'Dipirona', null, 'un', 300, 0, 1200),
        );
        $productId = 1;

        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $batches->save(StockBatch::receive(self::TENANT_ID, self::UNIT_ID, $productId, 'L1', null, 10, $now->modify('-10 days')));
        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $stock = new StockService($batches, $movements, $policy, $context);

        $hospitalizations = new FakeHospitalizationRepository(
            self::TENANT_ID,
            Hospitalization::admit(
                self::TENANT_ID,
                self::UNIT_ID,
                7,
                $encounterId,
                4,
                self::USER_ID,
                self::USER_ID,
                'Observação',
                null,
                10000,
                $now->modify('-50 hours'),
            ),
        );
        $hospitalizationId = 1;

        $beds = new FakeBedRepository(self::TENANT_ID, Bed::reconstitute([
            'id' => 4,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::UNIT_ID,
            'code' => 'B4',
            'name' => 'Baia 4',
            'daily_rate_cents' => 10000,
            'status' => Bed::STATUS_OCCUPIED,
            'current_hospitalization_id' => $hospitalizationId,
        ]));

        $orders = new FakeHospitalizationOrderRepository(
            self::TENANT_ID,
            HospitalizationOrder::prescribe(
                self::TENANT_ID,
                $hospitalizationId,
                HospitalizationOrder::TYPE_MEDICATION,
                'Dipirona',
                $productId,
                1,
                '25 mg/kg',
                'iv',
                12,
                $now->modify('-48 hours'),
                $now->modify('+24 hours'),
                self::USER_ID,
            ),
        );

        $seedAdministrations = [];

        for ($i = 0; $i < 3; $i++) {
            $administration = HospitalizationAdministration::schedule(
                self::TENANT_ID,
                $hospitalizationId,
                1,
                $now->modify('-' . (48 - 12 * $i) . ' hours'),
            );
            $administration->markDone($now->modify('-' . (48 - 12 * $i) . ' hours'), self::USER_ID, '');
            $seedAdministrations[] = $administration;
        }

        $seedAdministrations[] = HospitalizationAdministration::schedule(self::TENANT_ID, $hospitalizationId, 1, $now->modify('+12 hours'));
        $administrations = new FakeHospitalizationAdministrationRepository(self::TENANT_ID, ...$seedAdministrations);

        $events = new FakeHospitalizationEventRepository(self::TENANT_ID);

        $service = new HospitalizationDischargeService(
            $hospitalizations,
            $beds,
            $orders,
            $administrations,
            $events,
            $products,
            $accountService,
            $stock,
            $policy,
            $context,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );

        return [
            'service' => $service,
            'accountService' => $accountService,
            'encounterId' => $encounterId,
            'hospitalizationId' => $hospitalizationId,
            'hospitalizations' => $hospitalizations,
            'beds' => $beds,
            'administrations' => $administrations,
            'events' => $events,
            'items' => $items,
            'batches' => $batches,
            'movements' => $movements,
        ];
    }

    public function testDischargeBillsStayAndAdministrationsConsumesStockAndReleasesBed(): void
    {
        $env = $this->build();

        $result = $env['service']->discharge($env['hospitalizationId'], 'Alta com melhora', self::ACTION);

        Assert::same(3, $result['billable_days'], '50 h must bill 3 daily rates');
        Assert::same(4, $result['items_added']);
        Assert::same(1, $result['consumed_products']);

        $accountItems = $env['items']->listByAccount($result['account_id']);
        Assert::count(4, $accountItems);

        $stay = array_values(array_filter(
            $accountItems,
            static fn (EncounterAccountItem $item): bool => $item->sourceType() === EncounterAccountItem::TYPE_HOSPITALIZATION_STAY,
        ));
        Assert::count(1, $stay);
        Assert::same(30000, $stay[0]->amountCents());
        Assert::same($env['hospitalizationId'], $stay[0]->sourceId());
        Assert::same('Internação — 3 diária(s) (leito B4)', $stay[0]->descriptionText());

        $administrationItems = array_values(array_filter(
            $accountItems,
            static fn (EncounterAccountItem $item): bool => $item->sourceType() === EncounterAccountItem::TYPE_HOSPITALIZATION_ADMINISTRATION,
        ));
        Assert::count(3, $administrationItems);

        foreach ($administrationItems as $item) {
            Assert::same(1200, $item->amountCents());
        }

        Assert::same('Dipirona — 03/10/2026 12:00', $administrationItems[0]->descriptionText());

        $account = $env['accountService']->openOrGet($env['encounterId'], self::ACTION);
        Assert::same(33600, $account->totalCents(), 'account totals must be refreshed');

        $movements = $env['movements']->listByProduct(1);
        Assert::count(1, $movements);
        Assert::same(3, $movements[0]->quantity());
        Assert::same(StockMovement::REASON_HOSPITALIZATION_CONSUMPTION, $movements[0]->reason());
        Assert::same('hospitalization', $movements[0]->referenceType());
        Assert::same($env['hospitalizationId'], $movements[0]->referenceId());
        Assert::same(7, $env['batches']->findById(1)->quantity());

        Assert::same(Bed::STATUS_AVAILABLE, $env['beds']->findById(4)->status());

        $hospitalization = $env['hospitalizations']->findById($env['hospitalizationId']);
        Assert::same(Hospitalization::STATUS_DISCHARGED, $hospitalization->status());
        Assert::same('Alta com melhora', $hospitalization->dischargeSummaryText());

        Assert::count(0, $env['administrations']->listPendingByHospitalization($env['hospitalizationId']));
        Assert::same(HospitalizationAdministration::STATUS_CANCELLED, $env['administrations']->findById(4)->status());

        $events = $env['events']->listByHospitalization($env['hospitalizationId']);
        Assert::count(1, $events);
        Assert::same(HospitalizationEvent::TYPE_DISCHARGE, $events[0]->eventType());
        Assert::same('Alta com melhora', $events[0]->notesText());
    }

    public function testSecondDischargeIsRefused(): void
    {
        $env = $this->build();
        $env['service']->discharge($env['hospitalizationId'], 'Alta', self::ACTION);

        $message = $this->captureMessage(
            InvalidStatusTransitionException::class,
            fn () => $env['service']->discharge($env['hospitalizationId'], 'Alta', self::ACTION),
        );

        Assert::same("Hospitalization {$env['hospitalizationId']} is not admitted", $message);
        Assert::count(1, $env['movements']->listByProduct(1), 'second discharge must not consume stock again');
    }

    public function testDischargeRefusedWhenAccountClosedTouchesNoStock(): void
    {
        $env = $this->build();
        $account = $env['accountService']->openOrGet($env['encounterId'], self::ACTION);
        $env['accountService']->close((int) $account->id(), self::ACTION);

        $message = $this->captureMessage(
            InvalidStatusTransitionException::class,
            fn () => $env['service']->discharge($env['hospitalizationId'], 'Alta', self::ACTION),
        );

        Assert::same(
            sprintf('Encounter account %d cannot be modified: status is "closed", not "open"', (int) $account->id()),
            $message,
        );
        Assert::count(0, $env['movements']->listByProduct(1), 'no stock movement when the account is closed');
        Assert::same(10, $env['batches']->findById(1)->quantity());
        Assert::count(0, $env['items']->listByAccount((int) $account->id()));
        Assert::same(
            Hospitalization::STATUS_ADMITTED,
            $env['hospitalizations']->findById($env['hospitalizationId'])->status(),
        );
        Assert::same(Bed::STATUS_OCCUPIED, $env['beds']->findById(4)->status());
    }

    public function testAddSourcedItemIsIdempotentAndRejectsOtherTypes(): void
    {
        $env = $this->build();
        $account = $env['accountService']->openOrGet($env['encounterId'], self::ACTION);
        $accountId = (int) $account->id();

        $first = $env['accountService']->addSourcedItem(
            $accountId,
            EncounterAccountItem::TYPE_HOSPITALIZATION_STAY,
            1,
            'Internação',
            5000,
            self::ACTION,
        );
        Assert::notNull($first);

        $second = $env['accountService']->addSourcedItem(
            $accountId,
            EncounterAccountItem::TYPE_HOSPITALIZATION_STAY,
            1,
            'Internação',
            5000,
            self::ACTION,
        );
        Assert::null($second, 'same source pair must not be added twice');
        Assert::count(1, $env['items']->listByAccount($accountId));
        Assert::same(5000, $env['accountService']->openOrGet($env['encounterId'], self::ACTION)->totalCents());

        Assert::throws(
            InvalidArgumentException::class,
            fn () => $env['accountService']->addSourcedItem($accountId, EncounterAccountItem::TYPE_MANUAL, 1, 'X', 100, self::ACTION),
        );

        Assert::same(EncounterAccount::STATUS_OPEN, $account->status());
    }

    private function captureMessage(string $exceptionClass, callable $callback): string
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Assert::instanceOf($exceptionClass, $e, 'unexpected ' . $e::class . ': ' . $e->getMessage());

            return $e->getMessage();
        }

        throw new \CentralVet\Tests\Support\AssertionFailedException("Expected {$exceptionClass} to be thrown");
    }
}
