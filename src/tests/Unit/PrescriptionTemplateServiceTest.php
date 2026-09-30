<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\PrescriptionTemplateService;
use CentralVet\Domain\PrescriptionTemplate;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\FakePrescriptionTemplateRepository;
use InvalidArgumentException;

/**
 * Unit tests for PrescriptionTemplateService and the PrescriptionTemplate
 * entity (rodada 2, T-13), against FakePrescriptionTemplateRepository.
 */
final class PrescriptionTemplateServiceTest
{
    public function testSaveFromItemsKeepsItemsInOrder(): void
    {
        $service = $this->service(new FakePrescriptionTemplateRepository(1));

        $template = $service->saveFromItems('Otite', null, self::twoItems(), 1);

        Assert::notNull($template->id());
        Assert::same(1, $template->tenantId());
        Assert::same('Otite', $template->name());
        Assert::null($template->orientationText());
        Assert::same(1, $template->createdBySystemUserId());
        Assert::same(self::twoItems(), $template->items());

        $found = $service->findById((int) $template->id());
        Assert::notNull($found);
        Assert::same(self::twoItems(), $found->items());
    }

    public function testSaveFromItemsRejectsDuplicateNameWithExactMessage(): void
    {
        $service = $this->service(new FakePrescriptionTemplateRepository(1));
        $service->saveFromItems('Otite', 'Limpar antes', self::twoItems(), 1);

        self::assertThrowsMessage(
            'A template named "Otite" already exists for this tenant',
            static fn () => $service->saveFromItems('Otite', null, self::twoItems(), 1),
        );
        Assert::count(1, $service->listAll());
    }

    public function testSameNameInAnotherTenantIsAllowed(): void
    {
        $other = PrescriptionTemplate::create(2, 'Otite', null, self::twoItems(), 1);
        $service = $this->service(new FakePrescriptionTemplateRepository(1, $other));

        $template = $service->saveFromItems('Otite', null, self::twoItems(), 1);

        Assert::same(1, $template->tenantId());
        Assert::count(1, $service->listAll());
    }

    public function testListAllIsOrderedByNameAndScopedToTenant(): void
    {
        $other = PrescriptionTemplate::create(2, 'Alergia', null, self::twoItems(), 1);
        $service = $this->service(new FakePrescriptionTemplateRepository(1, $other));
        $service->saveFromItems('Otite', null, self::twoItems(), 1);
        $service->saveFromItems('Dermatite', null, self::twoItems(), 1);

        $names = array_map(static fn (PrescriptionTemplate $t): string => $t->name(), $service->listAll());

        Assert::same(['Dermatite', 'Otite'], $names);
    }

    public function testFindByIdReturnsNullForOtherTenantOrUnknownId(): void
    {
        $other = PrescriptionTemplate::create(2, 'Otite', null, self::twoItems(), 1);
        $service = $this->service(new FakePrescriptionTemplateRepository(1, $other));

        Assert::null($service->findById(1));
        Assert::null($service->findById(999));
    }

    public function testCreateValidatesNameItemsAndItemFields(): void
    {
        self::assertThrowsMessage('name is required', static fn () => PrescriptionTemplate::create(1, '  ', null, self::twoItems(), 1));
        self::assertThrowsMessage('items must be a non-empty list', static fn () => PrescriptionTemplate::create(1, 'Otite', null, [], 1));

        foreach (['medication_name', 'dose', 'dose_unit', 'route', 'frequency', 'duration'] as $field) {
            $item = self::twoItems()[0];
            unset($item[$field]);

            self::assertThrowsMessage(
                "items[].{$field} is required",
                static fn () => PrescriptionTemplate::create(1, 'Otite', null, [$item], 1),
            );
        }
    }

    private function service(FakePrescriptionTemplateRepository $repository): PrescriptionTemplateService
    {
        return new PrescriptionTemplateService($repository, TenantContext::authenticated(1, 1, 1));
    }

    /** @return list<array{medication_name: string, dose: string, dose_unit: string, route: string, frequency: string, duration: string}> */
    private static function twoItems(): array
    {
        return [
            [
                'medication_name' => 'Otomax',
                'dose' => '4',
                'dose_unit' => 'gotas',
                'route' => 'otológica',
                'frequency' => '12/12h',
                'duration' => '10 dias',
            ],
            [
                'medication_name' => 'Meloxicam',
                'dose' => '0,1',
                'dose_unit' => 'mg/kg',
                'route' => 'oral',
                'frequency' => '24/24h',
                'duration' => '3 dias',
            ],
        ];
    }

    private static function assertThrowsMessage(string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            Assert::same($message, $e->getMessage());

            return;
        }

        throw new AssertionFailedException("Expected InvalidArgumentException \"{$message}\" was not thrown");
    }
}
