<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PayableService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Payable;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeFinancialEntryRepository;
use CentralVet\Tests\Support\FakePayableRepository;
use InvalidArgumentException;

/**
 * PayableService::update() (fase 10, T-18 Correção 2): editar uma conta a
 * pagar atualiza o registro existente do tenant em vez de criar outro.
 * PayableService::listByStatus() (T-28): filtro Em aberto/Pagas/Todas.
 */
final class PayableServiceTest
{
    private const ACTION = 'test::payable';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;

    /** @return array{0: PayableService, 1: FakePayableRepository} */
    private function buildService(bool $allowed = true, Payable ...$seed): array
    {
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::UNIT_ID);
        $payables = new FakePayableRepository(self::TENANT_ID, ...$seed);
        $policy = new FakeAuthorizationPolicy(allowed: $allowed);
        $entries = new FinancialEntryService(new FakeFinancialEntryRepository(self::TENANT_ID), $policy, $context);

        return [new PayableService($payables, $entries, $policy, $context), $payables];
    }

    private static function openPayable(int $tenantId = self::TENANT_ID): Payable
    {
        return Payable::create($tenantId, self::UNIT_ID, 'Luz', 'Utilidades', 1000, null, 1);
    }

    public function testUpdateChangesExistingPayableWithoutCreatingAnother(): void
    {
        [$service, $payables] = $this->buildService(true, self::openPayable());

        $updated = $service->update(1, 'Luz setembro', 'Energia', 1234, '2026-10-05', self::ACTION);

        Assert::same(1, $updated->id());
        Assert::same(1, $payables->count(), 'update() must not insert a second payable');
        Assert::same(0, $payables->inserts);
        Assert::same('Luz setembro', $updated->descriptionText());
        Assert::same('Energia', $updated->category());
        Assert::same(1234, $updated->amountCents());
        Assert::same('2026-10-05', $updated->dueDate()?->format('Y-m-d'));
        Assert::same(Payable::STATUS_OPEN, $updated->status());
    }

    public function testUpdateOfPayableFromAnotherTenantIsRefused(): void
    {
        // payable do tenant 2 guardado no mesmo fake: o findById do tenant 1 não o enxerga
        [$service, $payables] = $this->buildService(true, self::openPayable(2));
        Assert::same(1, $payables->count());
        Assert::null($payables->findById(1));

        Assert::throws(InvalidArgumentException::class, fn () => $service->update(1, 'X', 'Y', 100, null, self::ACTION));
    }

    public function testUpdateOfPaidPayableIsRefused(): void
    {
        $paid = self::openPayable();
        $paid->markPaid(new \DateTimeImmutable());
        [$service] = $this->buildService(true, $paid);

        Assert::throws(InvalidStatusTransitionException::class, fn () => $service->update(1, 'X', 'Y', 100, null, self::ACTION));
    }

    public function testUpdateValidatesFieldsAndAuthorization(): void
    {
        [$service] = $this->buildService(true, self::openPayable());
        Assert::throws(InvalidArgumentException::class, fn () => $service->update(1, '  ', 'Y', 100, null, self::ACTION));
        Assert::throws(InvalidArgumentException::class, fn () => $service->update(1, 'X', 'Y', 0, null, self::ACTION));

        [$denied, $payables] = $this->buildService(false, self::openPayable());
        Assert::throws(AuthorizationDenied::class, fn () => $denied->update(1, 'X', 'Y', 100, null, self::ACTION));
        Assert::same('Luz', $payables->findById(1)->descriptionText());
    }

    /** @return list<int> */
    private static function ids(array $payables): array
    {
        return array_map(static fn (Payable $p): int => (int) $p->id(), $payables);
    }

    public function testListByStatusFiltersOpenPaidAndAll(): void
    {
        $paid = self::openPayable();
        $paid->markPaid(new \DateTimeImmutable());
        [$service] = $this->buildService(true, self::openPayable(), $paid);

        Assert::same([2], self::ids($service->listByStatus(self::UNIT_ID, Payable::STATUS_PAID)), 'paid returns only the paid payable');
        Assert::same([1], self::ids($service->listByStatus(self::UNIT_ID, Payable::STATUS_OPEN)));
        Assert::same([1, 2], self::ids($service->listByStatus(self::UNIT_ID, null)), 'null returns every status');
        Assert::same([], $service->listByStatus(self::UNIT_ID, Payable::STATUS_CANCELLED));
        Assert::same([1], self::ids($service->listOpen(self::UNIT_ID)), 'listOpen() keeps returning only open payables');
    }

    public function testListByStatusRejectsUnknownStatus(): void
    {
        [$service] = $this->buildService(true, self::openPayable());

        Assert::throws(InvalidArgumentException::class, fn () => $service->listByStatus(self::UNIT_ID, 'xyz'));
    }
}
