<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\AppointmentService;
use CentralVet\Application\EncounterAccountService;
use CentralVet\Application\PatientService;
use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Application\StockService;
use CentralVet\Application\SurgeryCompletionService;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\EncounterAccountItem;
use CentralVet\Domain\Exception\InsufficientStockException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Patient;
use CentralVet\Domain\Product;
use CentralVet\Domain\Service;
use CentralVet\Domain\StockBatch;
use CentralVet\Domain\StockMovement;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryChecklist;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Domain\SurgeryMaterial;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAppointmentRepository;
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
use CentralVet\Tests\Support\FakeProductRepository;
use CentralVet\Tests\Support\FakeReceivableRepository;
use CentralVet\Tests\Support\FakeServiceRepository;
use CentralVet\Tests\Support\FakeStockBatchRepository;
use CentralVet\Tests\Support\FakeStockMovementRepository;
use CentralVet\Tests\Support\FakeSurgeryChecklistRepository;
use CentralVet\Tests\Support\FakeSurgeryEventRepository;
use CentralVet\Tests\Support\FakeSurgeryMaterialRepository;
use CentralVet\Tests\Support\FakeSurgeryRepository;
use CentralVet\Tests\Support\FakeTenantUserDirectory;
use CentralVet\Tests\Support\FakeTutorRepository;
use DateTimeImmutable;

/**
 * T-11: integrated surgery completion — stock consumption, procedure and
 * material account items, completion event — and the follow-up
 * appointment, with the real EncounterAccountService, StockService and
 * AppointmentService wired over fakes.
 */
final class SurgeryCompletionServiceTest
{
    private const ACTION = 'test::surgery_completion';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 9;
    private const SURGEON_ID = 10;
    private const PATIENT_ID = 7;
    private const NOW = '2026-10-05 12:00:00';

    /**
     * @return array<string, mixed>
     */
    private function build(int $stockQuantity = 10, bool $signOutConfirmed = true): array
    {
        $now = new DateTimeImmutable(self::NOW);
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: true);

        $encounters = new FakeEncounterRepository(self::TENANT_ID);
        $encounter = Encounter::start(
            tenantId: self::TENANT_ID,
            systemUnitId: self::UNIT_ID,
            patientId: self::PATIENT_ID,
            appointmentId: null,
            professionalSystemUserId: self::SURGEON_ID,
            now: $now->modify('-3 hours'),
        );
        $encounters->save($encounter);
        $encounterId = (int) $encounter->id();

        $patients = new FakePatientRepository(self::TENANT_ID);
        $patients->save(new Patient(id: self::PATIENT_ID, tenantId: self::TENANT_ID, tutorId: 3, name: 'Rex', species: 'dog'));

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
            Product::create(self::TENANT_ID, 'Fio de sutura', null, 'un', 300, 0, 1200),
        );
        $productId = 1;

        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $batches->save(StockBatch::receive(self::TENANT_ID, self::UNIT_ID, $productId, 'L1', null, $stockQuantity, $now->modify('-10 days')));
        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $stock = new StockService($batches, $movements, $policy, $context);

        $appointments = new FakeAppointmentRepository(self::TENANT_ID);
        $appointmentService = new AppointmentService(
            $appointments,
            new FakeServiceRepository(self::TENANT_ID, Service::create(self::TENANT_ID, 'Retorno', null, 30, 0)),
            new PatientService($patients, new FakeTutorRepository(self::TENANT_ID), $context),
            $context,
            $policy,
        );

        $surgeries = new FakeSurgeryRepository(self::TENANT_ID, Surgery::reconstitute([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::UNIT_ID,
            'patient_id' => self::PATIENT_ID,
            'encounter_id' => $encounterId,
            'room_id' => 2,
            'procedure_catalog_item_id' => 3,
            'procedure_name' => 'Orquiectomia',
            'procedure_price_cents' => 50000,
            'surgeon_system_user_id' => self::SURGEON_ID,
            'scheduled_by_system_user_id' => self::USER_ID,
            'scheduled_start_at' => '2026-10-05 09:00:00',
            'scheduled_end_at' => '2026-10-05 11:00:00',
            'status' => Surgery::STATUS_IN_PROGRESS,
            'consent_signer_name' => 'Tutor',
            'consent_text' => 'Autorizo.',
            'consent_recorded_at' => '2026-10-05 08:30:00',
            'consent_recorded_by_system_user_id' => self::USER_ID,
            'started_at' => '2026-10-05 09:05:00',
        ]));
        $surgeryId = 1;

        $checklist = new FakeSurgeryChecklistRepository(self::TENANT_ID);

        if ($signOutConfirmed) {
            foreach (SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_OUT) as $code) {
                $checklist->save(SurgeryChecklistItem::check(
                    self::TENANT_ID,
                    $surgeryId,
                    SurgeryChecklist::PHASE_SIGN_OUT,
                    $code,
                    self::USER_ID,
                    $now->modify('-30 minutes'),
                ));
            }
        }

        $materials = new FakeSurgeryMaterialRepository(
            self::TENANT_ID,
            SurgeryMaterial::record(self::TENANT_ID, $surgeryId, $productId, 1, self::USER_ID, $now->modify('-2 hours')),
            SurgeryMaterial::record(self::TENANT_ID, $surgeryId, $productId, 2, self::USER_ID, $now->modify('-1 hour')),
        );

        $events = new FakeSurgeryEventRepository(self::TENANT_ID);

        $service = new SurgeryCompletionService(
            $surgeries,
            $checklist,
            $materials,
            $events,
            $products,
            $accountService,
            $stock,
            $appointmentService,
            $policy,
            $context,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );

        return [
            'service' => $service,
            'accountService' => $accountService,
            'encounterId' => $encounterId,
            'surgeryId' => $surgeryId,
            'surgeries' => $surgeries,
            'events' => $events,
            'items' => $items,
            'batches' => $batches,
            'movements' => $movements,
            'appointments' => $appointments,
        ];
    }

    public function testCompletionBillsProcedureAndMaterialsAndConsumesStock(): void
    {
        $env = $this->build();

        $result = $env['service']->complete($env['surgeryId'], self::ACTION);

        Assert::same(3, $result['items_added']);
        Assert::same(1, $result['consumed_products']);

        $accountItems = $env['items']->listByAccount($result['account_id']);
        Assert::count(3, $accountItems);

        $procedure = array_values(array_filter(
            $accountItems,
            static fn (EncounterAccountItem $item): bool => $item->sourceType() === EncounterAccountItem::TYPE_SURGERY_PROCEDURE,
        ));
        Assert::count(1, $procedure);
        Assert::same(50000, $procedure[0]->amountCents());
        Assert::same($env['surgeryId'], $procedure[0]->sourceId());
        Assert::same('Cirurgia — Orquiectomia', $procedure[0]->descriptionText());

        $materialItems = array_values(array_filter(
            $accountItems,
            static fn (EncounterAccountItem $item): bool => $item->sourceType() === EncounterAccountItem::TYPE_SURGERY_MATERIAL,
        ));
        Assert::count(2, $materialItems);
        Assert::same(1200, $materialItems[0]->amountCents());
        Assert::same(2400, $materialItems[1]->amountCents());
        Assert::same(1, $materialItems[0]->sourceId());
        Assert::same(2, $materialItems[1]->sourceId());
        Assert::same('Fio de sutura × 2', $materialItems[1]->descriptionText());

        $account = $env['accountService']->openOrGet($env['encounterId'], self::ACTION);
        Assert::same(53600, $account->totalCents(), 'account totals must be refreshed');

        $movements = $env['movements']->listByProduct(1);
        Assert::count(1, $movements);
        Assert::same(3, $movements[0]->quantity());
        Assert::same(StockMovement::REASON_SURGERY_CONSUMPTION, $movements[0]->reason());
        Assert::same('surgery', $movements[0]->referenceType());
        Assert::same($env['surgeryId'], $movements[0]->referenceId());
        Assert::same(7, $env['batches']->findById(1)->quantity());

        $surgery = $env['surgeries']->findById($env['surgeryId']);
        Assert::same(Surgery::STATUS_COMPLETED, $surgery->status());
        Assert::same(self::USER_ID, $surgery->completedBySystemUserId());

        $events = $env['events']->listBySurgery($env['surgeryId']);
        Assert::count(1, $events);
        Assert::same(SurgeryEvent::TYPE_COMPLETION, $events[0]->eventType());
    }

    public function testSecondCompletionIsRefused(): void
    {
        $env = $this->build();
        $env['service']->complete($env['surgeryId'], self::ACTION);

        $message = $this->captureMessage(
            InvalidStatusTransitionException::class,
            fn () => $env['service']->complete($env['surgeryId'], self::ACTION),
        );

        Assert::same("Surgery {$env['surgeryId']} is not in progress", $message);
        Assert::count(1, $env['movements']->listByProduct(1), 'second completion must not consume stock again');
    }

    public function testCompletionUsesLockedStatusNotStaleRead(): void
    {
        $env = $this->build();
        $env['surgeries']->forceStatus($env['surgeryId'], Surgery::STATUS_COMPLETED);

        $message = $this->captureMessage(
            InvalidStatusTransitionException::class,
            fn () => $env['service']->complete($env['surgeryId'], self::ACTION),
        );

        Assert::same("Surgery {$env['surgeryId']} is not in progress", $message);
        Assert::count(0, $env['movements']->listByProduct(1));
    }

    public function testCompletionRequiresSignOut(): void
    {
        $env = $this->build(signOutConfirmed: false);

        $message = $this->captureMessage(
            InvalidStatusTransitionException::class,
            fn () => $env['service']->complete($env['surgeryId'], self::ACTION),
        );

        Assert::same("Checklist phase \"sign_out\" is not confirmed for surgery {$env['surgeryId']}", $message);
        Assert::count(0, $env['movements']->listByProduct(1));
    }

    public function testCompletionRefusedWithoutStockTouchesNothing(): void
    {
        $env = $this->build(stockQuantity: 2);

        Assert::throws(
            InsufficientStockException::class,
            fn () => $env['service']->complete($env['surgeryId'], self::ACTION),
        );

        $account = $env['accountService']->openOrGet($env['encounterId'], self::ACTION);
        Assert::count(0, $env['items']->listByAccount((int) $account->id()), 'no account item without stock');
        Assert::count(0, $env['movements']->listByProduct(1));
        Assert::same(2, $env['batches']->findById(1)->quantity());
        Assert::same(
            Surgery::STATUS_IN_PROGRESS,
            $env['surgeries']->findById($env['surgeryId'])->status(),
        );
        Assert::count(0, $env['events']->listBySurgery($env['surgeryId']));
    }

    public function testScheduleFollowUpBooksSurgeonAndRefusesSecond(): void
    {
        $env = $this->build();
        $env['service']->complete($env['surgeryId'], self::ACTION);

        $appointment = $env['service']->scheduleFollowUp(
            $env['surgeryId'],
            1,
            new DateTimeImmutable('2026-10-15 10:00:00'),
            self::ACTION,
        );

        Assert::notNull($appointment->id);
        Assert::same(self::SURGEON_ID, $appointment->professionalSystemUserId);
        Assert::same(self::PATIENT_ID, $appointment->patientId);
        Assert::same(self::UNIT_ID, $appointment->systemUnitId);

        $surgery = $env['surgeries']->findById($env['surgeryId']);
        Assert::same($appointment->id, $surgery->followupAppointmentId());

        $types = array_map(
            static fn (SurgeryEvent $event): string => $event->eventType(),
            $env['events']->listBySurgery($env['surgeryId']),
        );
        Assert::true(in_array(SurgeryEvent::TYPE_FOLLOWUP, $types, true), 'followup event must be recorded');

        $message = $this->captureMessage(
            InvalidStatusTransitionException::class,
            fn () => $env['service']->scheduleFollowUp(
                $env['surgeryId'],
                1,
                new DateTimeImmutable('2026-10-16 10:00:00'),
                self::ACTION,
            ),
        );

        Assert::same("Surgery {$env['surgeryId']} already has a follow-up appointment", $message);
    }

    public function testScheduleFollowUpRequiresCompletedSurgery(): void
    {
        $env = $this->build();

        $message = $this->captureMessage(
            InvalidStatusTransitionException::class,
            fn () => $env['service']->scheduleFollowUp(
                $env['surgeryId'],
                1,
                new DateTimeImmutable('2026-10-15 10:00:00'),
                self::ACTION,
            ),
        );

        Assert::same("Surgery {$env['surgeryId']} is not completed", $message);
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
