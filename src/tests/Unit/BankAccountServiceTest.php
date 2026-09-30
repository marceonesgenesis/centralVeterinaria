<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\BankAccountService;
use CentralVet\Domain\BankAccount;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeBankAccountRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * BankAccountService (rodada 2, T-15): conta bancária com saldo informado à
 * mão, sem conciliação. totalBalanceCents() soma só as contas ativas da
 * unidade e devolve null quando a unidade não tem conta ativa.
 */
final class BankAccountServiceTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 1;
    private const OTHER_UNIT_ID = 2;

    /** @return array{0: BankAccountService, 1: FakeBankAccountRepository} */
    private function buildService(BankAccount ...$seed): array
    {
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::UNIT_ID);
        $repository = new FakeBankAccountRepository(self::TENANT_ID, ...$seed);

        return [new BankAccountService($repository, $context), $repository];
    }

    /** Service of the same tenant working on another current unit, sharing the repository. */
    private static function serviceForUnit(FakeBankAccountRepository $repository, int $unitId): BankAccountService
    {
        return new BankAccountService($repository, TenantContext::authenticated(self::TENANT_ID, 1, $unitId));
    }

    private static function accountOfOtherUnit(int $id): BankAccount
    {
        return BankAccount::reconstitute([
            'id' => $id,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::OTHER_UNIT_ID,
            'name' => 'Conta U2',
            'bank_name' => null,
            'balance_cents' => 300,
            'balance_updated_at' => null,
            'active' => 1,
        ]);
    }

    public function testTotalBalanceSumsOnlyActiveAccountsOfTheUnit(): void
    {
        [$service] = $this->buildService();

        $service->create(['system_unit_id' => self::UNIT_ID, 'name' => 'Caixa', 'bank_name' => 'Banco A', 'balance_cents' => 1000]);
        $service->create(['system_unit_id' => self::UNIT_ID, 'name' => 'Conta corrente', 'balance_cents' => -250]);
        $inactive = $service->create(['system_unit_id' => self::UNIT_ID, 'name' => 'Poupança', 'balance_cents' => 500]);
        $service->update((int) $inactive->id(), ['active' => false]);

        Assert::same(750, $service->totalBalanceCents(self::UNIT_ID));
        Assert::count(3, $service->listByUnit(self::UNIT_ID), 'listByUnit lists active and inactive accounts');
    }

    public function testTotalBalanceIsNullForUnitWithoutActiveAccount(): void
    {
        [, $repository] = $this->buildService();
        $service = self::serviceForUnit($repository, self::OTHER_UNIT_ID);

        Assert::null($service->totalBalanceCents(self::OTHER_UNIT_ID), 'unit without accounts');

        $only = $service->create(['system_unit_id' => self::OTHER_UNIT_ID, 'name' => 'Única', 'balance_cents' => 0]);
        Assert::same(0, $service->totalBalanceCents(self::OTHER_UNIT_ID), 'an active account with zero balance is 0, not null');

        $service->update((int) $only->id(), ['active' => false]);
        Assert::null($service->totalBalanceCents(self::OTHER_UNIT_ID), 'unit with only inactive accounts');
    }

    public function testCreateWithRepeatedNameInTheUnitThrowsExactMessage(): void
    {
        [$service, $repository] = $this->buildService();
        $service->create(['system_unit_id' => self::UNIT_ID, 'name' => 'Caixa', 'balance_cents' => 100]);

        self::assertThrowsMessage(
            InvalidArgumentException::class,
            'A bank account named "Caixa" already exists for this unit',
            fn () => $service->create(['system_unit_id' => self::UNIT_ID, 'name' => 'Caixa', 'balance_cents' => 5]),
        );
        Assert::same(1, $repository->count(), 'the repeated account must not be saved');

        // the same name in another unit is allowed
        self::serviceForUnit($repository, self::OTHER_UNIT_ID)->create(['system_unit_id' => self::OTHER_UNIT_ID, 'name' => 'Caixa', 'balance_cents' => 5]);
        Assert::same(2, $repository->count());
    }

    public function testCreateRequiresName(): void
    {
        [$service] = $this->buildService();

        self::assertThrowsMessage(
            InvalidArgumentException::class,
            'name is required',
            fn () => $service->create(['system_unit_id' => self::UNIT_ID, 'name' => '  ', 'balance_cents' => 5]),
        );
    }

    public function testCreateUsesTenantOfContextAndStampsBalance(): void
    {
        [$service] = $this->buildService();

        $account = $service->create(['system_unit_id' => self::UNIT_ID, 'name' => ' Caixa ', 'bank_name' => '', 'balance_cents' => -99]);

        Assert::same(self::TENANT_ID, $account->tenantId());
        Assert::same(self::UNIT_ID, $account->systemUnitId());
        Assert::same('Caixa', $account->name());
        Assert::null($account->bankName(), 'empty bank_name is stored as null');
        Assert::same(-99, $account->balanceCents());
        Assert::true($account->isActive());
        Assert::true($account->balanceUpdatedAt() instanceof DateTimeImmutable);
    }

    public function testUpdateOfBalanceFillsBalanceUpdatedAt(): void
    {
        $seed = BankAccount::reconstitute([
            'id' => 7,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::UNIT_ID,
            'name' => 'Caixa',
            'bank_name' => null,
            'balance_cents' => 100,
            'balance_updated_at' => null,
            'active' => 1,
        ]);
        [$service, $repository] = $this->buildService($seed);
        Assert::null($seed->balanceUpdatedAt());

        $before = new DateTimeImmutable();
        $updated = $service->update(7, ['name' => 'Caixa', 'bank_name' => 'Banco B', 'balance_cents' => 4321]);

        Assert::same(7, $updated->id());
        Assert::same(0, $repository->inserts, 'update() must not insert');
        Assert::same(4321, $updated->balanceCents());
        Assert::same('Banco B', $updated->bankName());
        Assert::true($updated->balanceUpdatedAt() instanceof DateTimeImmutable, 'balance change must stamp balance_updated_at');
        Assert::true($updated->balanceUpdatedAt() >= $before);
    }

    public function testUpdateWithoutBalanceChangeKeepsBalanceUpdatedAt(): void
    {
        $stamp = new DateTimeImmutable('2026-01-02 03:04:05');
        $seed = BankAccount::reconstitute([
            'id' => 3,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::UNIT_ID,
            'name' => 'Caixa',
            'bank_name' => null,
            'balance_cents' => 100,
            'balance_updated_at' => $stamp->format('Y-m-d H:i:s'),
            'active' => 1,
        ]);
        [$service] = $this->buildService($seed);

        $updated = $service->update(3, ['name' => 'Caixa principal', 'balance_cents' => 100]);

        Assert::same('Caixa principal', $updated->name());
        Assert::same($stamp->format('Y-m-d H:i:s'), $updated->balanceUpdatedAt()?->format('Y-m-d H:i:s'));
    }

    public function testUpdateOfUnknownOrOtherTenantAccountThrowsExactMessage(): void
    {
        $foreign = BankAccount::create(2, self::UNIT_ID, 'Outra', null, 10, new DateTimeImmutable());
        [$service] = $this->buildService($foreign);

        self::assertThrowsMessage(
            InvalidArgumentException::class,
            'Bank account 1 not found for this tenant',
            fn () => $service->update(1, ['balance_cents' => 5]),
        );
        self::assertThrowsMessage(
            InvalidArgumentException::class,
            'Bank account 99 not found for this tenant',
            fn () => $service->update(99, ['balance_cents' => 5]),
        );
        Assert::null($service->findById(1), 'findById of another tenant account is null');
    }

    public function testUpdateToNameOfAnotherAccountOfTheUnitIsRefused(): void
    {
        [$service] = $this->buildService();
        $service->create(['system_unit_id' => self::UNIT_ID, 'name' => 'Caixa', 'balance_cents' => 1]);
        $second = $service->create(['system_unit_id' => self::UNIT_ID, 'name' => 'Banco', 'balance_cents' => 1]);

        self::assertThrowsMessage(
            InvalidArgumentException::class,
            'A bank account named "Caixa" already exists for this unit',
            fn () => $service->update((int) $second->id(), ['name' => 'Caixa']),
        );
        Assert::same('Banco', $service->findById((int) $second->id())?->name());
    }

    public function testFindByIdOfAccountOfAnotherUnitOfTheSameTenantIsNull(): void
    {
        [$service, $repository] = $this->buildService(self::accountOfOtherUnit(4));
        Assert::notNull($repository->findById(4), 'same tenant: the repository sees it');

        Assert::null($service->findById(4), 'account of another unit must be treated as not found');
    }

    public function testUpdateOfAccountOfAnotherUnitOfTheSameTenantThrowsNotFound(): void
    {
        $other = self::accountOfOtherUnit(4);
        [$service] = $this->buildService($other);

        self::assertThrowsMessage(
            InvalidArgumentException::class,
            'Bank account 4 not found for this tenant',
            fn () => $service->update(4, ['balance_cents' => 1, 'name' => 'Invadida']),
        );
        Assert::same(300, $other->balanceCents(), 'nothing changed');
        Assert::same('Conta U2', $other->name());
    }

    public function testCreateRefusesAnotherUnitAndUsesTheCurrentOne(): void
    {
        [$service, $repository] = $this->buildService();

        Assert::throws(
            InvalidArgumentException::class,
            fn () => $service->create(['system_unit_id' => self::OTHER_UNIT_ID, 'name' => 'Caixa', 'balance_cents' => 1]),
        );
        Assert::same(0, $repository->count(), 'account of another unit must not be created');

        $account = $service->create(['name' => 'Caixa', 'balance_cents' => 1]);
        Assert::same(self::UNIT_ID, $account->systemUnitId(), 'without system_unit_id the current unit is used');
    }

    public function testListByUnitRefusesAnotherUnit(): void
    {
        [$service] = $this->buildService(self::accountOfOtherUnit(4));

        Assert::throws(InvalidArgumentException::class, fn () => $service->listByUnit(self::OTHER_UNIT_ID));
        Assert::same([], $service->listByUnit(self::UNIT_ID));
    }

    public function testBalanceCentsMustBeAnInteger(): void
    {
        [$service] = $this->buildService();

        Assert::throws(InvalidArgumentException::class, fn () => $service->create(['name' => 'Caixa']));
        Assert::throws(InvalidArgumentException::class, fn () => $service->create(['name' => 'Caixa', 'balance_cents' => 'abc']));
        $account = $service->create(['name' => 'Caixa', 'balance_cents' => '-15']);
        Assert::same(-15, $account->balanceCents());
        Assert::throws(InvalidArgumentException::class, fn () => $service->update((int) $account->id(), ['balance_cents' => '1,5']));
        Assert::same(-15, $account->balanceCents());
    }

    private static function assertThrowsMessage(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Assert::instanceOf($class, $e);
            Assert::same($message, $e->getMessage());

            return;
        }

        throw new \CentralVet\Tests\Support\AssertionFailedException("Expected {$class}: {$message}");
    }
}
