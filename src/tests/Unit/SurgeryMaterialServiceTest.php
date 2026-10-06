<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\SurgeryMaterialService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Product;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Domain\SurgeryMaterial;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeProductRepository;
use CentralVet\Tests\Support\FakeSurgeryEventRepository;
use CentralVet\Tests\Support\FakeSurgeryMaterialRepository;
use CentralVet\Tests\Support\FakeSurgeryRepository;
use DateTimeImmutable;

/**
 * T-10: SurgeryMaterialService — materials are recorded and removed only
 * while the surgery is `in_progress`, deciding on the status read under
 * lockStatus() (so a concurrent completion wins), with a `material` event
 * per change. Stock is not checked here (that happens at completion).
 */
final class SurgeryMaterialServiceTest
{
    private const TENANT_ID = 1;
    private const OTHER_TENANT_ID = 2;
    private const UNIT_ID = 3;
    private const USER_ID = 7;
    private const SURGERY_ID = 10;
    private const ACTIVE_PRODUCT_ID = 1;
    private const INACTIVE_PRODUCT_ID = 2;
    private const OTHER_TENANT_PRODUCT_ID = 3;
    private const ACTION = 'SurgeryMaterialForm::onSave';

    private FakeSurgeryRepository $surgeries;
    private FakeSurgeryMaterialRepository $materials;
    private FakeSurgeryEventRepository $events;
    private FakeProductRepository $products;
    private FakeAuthorizationPolicy $authorization;
    private DateTimeImmutable $now;

    public function setUp(): void
    {
        $inactive = Product::create(self::TENANT_ID, 'Fio catgut 2-0', 'Material cirúrgico', 'unidade', 900, 0);
        $inactive->deactivate();

        $this->surgeries = new FakeSurgeryRepository(self::TENANT_ID, $this->surgery(self::SURGERY_ID, Surgery::STATUS_IN_PROGRESS));
        $this->materials = new FakeSurgeryMaterialRepository(self::TENANT_ID);
        $this->events = new FakeSurgeryEventRepository(self::TENANT_ID);
        $this->products = new FakeProductRepository(
            self::TENANT_ID,
            Product::create(self::TENANT_ID, 'Fio nylon 3-0', 'Material cirúrgico', 'unidade', 1200, 0),
            $inactive,
            Product::create(self::OTHER_TENANT_ID, 'Outro tenant', 'Material cirúrgico', 'unidade', 100, 0),
        );
        $this->authorization = new FakeAuthorizationPolicy();
        $this->now = new DateTimeImmutable('2026-10-10 09:15:00');
    }

    private function service(): SurgeryMaterialService
    {
        return new SurgeryMaterialService(
            $this->surgeries,
            $this->materials,
            $this->events,
            $this->products,
            $this->authorization,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            fn (): DateTimeImmutable => $this->now,
        );
    }

    private function surgery(int $id, string $status): Surgery
    {
        return Surgery::reconstitute([
            'id' => $id,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::UNIT_ID,
            'patient_id' => 5,
            'encounter_id' => 9,
            'room_id' => 1,
            'procedure_catalog_item_id' => 4,
            'procedure_name' => 'Orquiectomia',
            'procedure_price_cents' => 80000,
            'surgeon_system_user_id' => self::USER_ID,
            'scheduled_by_system_user_id' => self::USER_ID,
            'scheduled_start_at' => '2026-10-10 08:00:00',
            'scheduled_end_at' => '2026-10-10 10:00:00',
            'status' => $status,
        ]);
    }

    /** @param class-string<\Throwable> $class */
    private static function throwsWithMessage(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Assert::instanceOf($class, $e, "Expected {$class}, got " . $e::class . ': ' . $e->getMessage());
            Assert::same($message, $e->getMessage());

            return;
        }

        throw new AssertionFailedException("Expected exception {$class} was not thrown");
    }

    public function testAddMaterialRecordsMaterialAndMaterialEvent(): void
    {
        $material = $this->service()->addMaterial(self::SURGERY_ID, self::ACTIVE_PRODUCT_ID, 2, self::ACTION);

        Assert::notNull($material->id());
        Assert::same(self::SURGERY_ID, $material->surgeryId());
        Assert::same(self::ACTIVE_PRODUCT_ID, $material->productId());
        Assert::same(2, $material->quantity());
        Assert::same(self::USER_ID, $material->recordedBySystemUserId());
        Assert::same($this->now, $material->recordedAt());
        Assert::count(1, $this->materials->listBySurgery(self::SURGERY_ID));

        $events = $this->events->listBySurgery(self::SURGERY_ID);
        Assert::count(1, $events);
        Assert::same(SurgeryEvent::TYPE_MATERIAL, $events[0]->eventType());
        Assert::same('Fio nylon 3-0 × 2', $events[0]->notesText());
        Assert::same(self::USER_ID, $events[0]->recordedBySystemUserId());

        $request = $this->authorization->requests[0];
        Assert::same(self::UNIT_ID, $request->resourceUnitId());
        Assert::same('surgery', $request->entityType());
        Assert::same(self::SURGERY_ID, $request->entityId());
    }

    public function testMaterialAfterConcurrentCompletionIsRefused(): void
    {
        $this->service()->addMaterial(self::SURGERY_ID, self::ACTIVE_PRODUCT_ID, 2, self::ACTION);
        $this->surgeries->forceStatus(self::SURGERY_ID, Surgery::STATUS_COMPLETED);
        $saves = $this->materials->saveCount;

        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            'Surgery ' . self::SURGERY_ID . ' is not in progress',
            fn () => $this->service()->addMaterial(self::SURGERY_ID, self::ACTIVE_PRODUCT_ID, 1, self::ACTION),
        );

        Assert::same($saves, $this->materials->saveCount);
        Assert::count(1, $this->materials->listBySurgery(self::SURGERY_ID));
        Assert::count(1, $this->events->listBySurgery(self::SURGERY_ID));
    }

    public function testRemoveMaterialOnCompletedSurgeryIsRefused(): void
    {
        $material = $this->service()->addMaterial(self::SURGERY_ID, self::ACTIVE_PRODUCT_ID, 2, self::ACTION);
        $this->surgeries->forceStatus(self::SURGERY_ID, Surgery::STATUS_COMPLETED);

        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            'Surgery ' . self::SURGERY_ID . ' is not in progress',
            fn () => $this->service()->removeMaterial((int) $material->id(), self::ACTION),
        );

        Assert::count(1, $this->materials->listBySurgery(self::SURGERY_ID));
        Assert::count(1, $this->events->listBySurgery(self::SURGERY_ID));
    }

    public function testRemoveMaterialDeletesItAndRecordsRemovalEvent(): void
    {
        $material = $this->service()->addMaterial(self::SURGERY_ID, self::ACTIVE_PRODUCT_ID, 3, self::ACTION);
        $this->now = new DateTimeImmutable('2026-10-10 09:30:00');

        $this->service()->removeMaterial((int) $material->id(), self::ACTION);

        Assert::count(0, $this->materials->listBySurgery(self::SURGERY_ID));
        $events = $this->events->listBySurgery(self::SURGERY_ID);
        Assert::count(2, $events);
        Assert::same(SurgeryEvent::TYPE_MATERIAL, $events[0]->eventType());
        Assert::same('removido: Fio nylon 3-0 × 3', $events[0]->notesText());
    }

    public function testInactiveOrOtherTenantProductIsNotFound(): void
    {
        self::throwsWithMessage(
            CrossTenantReferenceException::class,
            'product_id ' . self::INACTIVE_PRODUCT_ID . ' was not found for the authenticated tenant',
            fn () => $this->service()->addMaterial(self::SURGERY_ID, self::INACTIVE_PRODUCT_ID, 1, self::ACTION),
        );
        self::throwsWithMessage(
            CrossTenantReferenceException::class,
            'product_id ' . self::OTHER_TENANT_PRODUCT_ID . ' was not found for the authenticated tenant',
            fn () => $this->service()->addMaterial(self::SURGERY_ID, self::OTHER_TENANT_PRODUCT_ID, 1, self::ACTION),
        );

        Assert::same(0, $this->materials->saveCount);
        Assert::count(0, $this->events->listBySurgery(self::SURGERY_ID));
    }

    public function testUnknownSurgeryIsNotFound(): void
    {
        self::throwsWithMessage(
            CrossTenantReferenceException::class,
            'surgery_id 999 was not found for the authenticated tenant',
            fn () => $this->service()->addMaterial(999, self::ACTIVE_PRODUCT_ID, 1, self::ACTION),
        );
    }

    public function testMaterialOfAnotherTenantIsNotFound(): void
    {
        $this->materials = new FakeSurgeryMaterialRepository(
            self::TENANT_ID,
            SurgeryMaterial::record(self::OTHER_TENANT_ID, self::SURGERY_ID, self::ACTIVE_PRODUCT_ID, 1, 99, $this->now),
        );

        self::throwsWithMessage(
            CrossTenantReferenceException::class,
            'material_id 1 was not found for the authenticated tenant',
            fn () => $this->service()->removeMaterial(1, self::ACTION),
        );
    }

    public function testDeniedAuthorizationRecordsNothing(): void
    {
        $this->authorization->setAllowed(false);

        Assert::throws(
            AuthorizationDenied::class,
            fn () => $this->service()->addMaterial(self::SURGERY_ID, self::ACTIVE_PRODUCT_ID, 1, self::ACTION),
        );

        Assert::same(0, $this->materials->saveCount);
        Assert::count(0, $this->events->listBySurgery(self::SURGERY_ID));
    }

    public function testListMaterialsCarriesProductNameAndActiveProductsAreListed(): void
    {
        $this->service()->addMaterial(self::SURGERY_ID, self::ACTIVE_PRODUCT_ID, 2, self::ACTION);

        $rows = $this->service()->listMaterials(self::SURGERY_ID, self::ACTION);
        Assert::count(1, $rows);
        Assert::instanceOf(SurgeryMaterial::class, $rows[0]['material']);
        Assert::same('Fio nylon 3-0', $rows[0]['product_name']);

        $products = $this->service()->listActiveProducts(self::ACTION);
        Assert::count(1, $products);
        Assert::same('Fio nylon 3-0', $products[0]->name());
    }
}
