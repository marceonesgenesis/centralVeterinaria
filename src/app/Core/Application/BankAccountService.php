<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\BankAccount;
use CentralVet\Domain\Contract\BankAccountRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for the BankAccount aggregate (rodada 2, T-15): registering a
 * bank account of a unit with a hand-informed balance, editing it, and the
 * unit's total balance shown by FinancialOverview (T-20). No
 * reconciliation with financial_entry (decision in notes.md).
 *
 * The tenant is always resolved from the injected TenantContext and never
 * accepted from $data (ADR 0002). bank_account is per unit, so every use
 * case is also confined to the CURRENT unit (TenantContext::requireUnitId(),
 * ruling of T-15 rodada 1): create() writes the current unit and refuses
 * another; findById()/update() treat an account of another unit of the same
 * tenant as not found (same exception/message as an unknown id);
 * listByUnit() only accepts the current unit. Access to the screens is the
 * Adianti program permission of BankAccountList/BankAccountForm (T-01 DML),
 * which is not unit-aware — hence this check here.
 */
final class BankAccountService
{
    public function __construct(
        private readonly BankAccountRepositoryInterface $repository,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * system_unit_id is optional: absent or empty means the current unit;
     * any other unit is refused. balance_cents is required (integer).
     *
     * @param array{system_unit_id?: int|string, name: string, bank_name?: ?string, balance_cents: int|string} $data
     */
    public function create(array $data): BankAccount
    {
        $systemUnitId = $this->context->requireUnitId();
        $requestedUnit = $data['system_unit_id'] ?? null;

        if ($requestedUnit !== null && $requestedUnit !== '' && (int) $requestedUnit !== $systemUnitId) {
            throw new InvalidArgumentException("Bank account must belong to the current unit {$systemUnitId}");
        }

        $name = trim((string) ($data['name'] ?? ''));

        $account = BankAccount::create(
            tenantId: $this->context->tenantId(),
            systemUnitId: $systemUnitId,
            name: $name,
            bankName: isset($data['bank_name']) ? (string) $data['bank_name'] : null,
            balanceCents: self::cents($data['balance_cents'] ?? null),
            now: new DateTimeImmutable(),
        );

        $this->assertNameIsFree($systemUnitId, $account->name(), null);

        /** @var BankAccount $saved */
        $saved = $this->repository->save($account);

        return $saved;
    }

    /**
     * Edits an account of the current tenant. Every key is optional: only
     * the keys present in $data (name, bank_name, balance_cents, active) are
     * changed. balance_updated_at is stamped with now only when
     * balance_cents actually changes.
     *
     * @param array{name?: string, bank_name?: ?string, balance_cents?: int|string, active?: bool|int|string} $data
     */
    public function update(int $id, array $data): BankAccount
    {
        $account = $this->findById($id);

        if ($account === null) {
            throw new InvalidArgumentException("Bank account {$id} not found for this tenant");
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name !== $account->name()) {
                $this->assertNameIsFree($account->systemUnitId(), $name, $account->id());
            }

            $account->rename($name);
        }

        if (array_key_exists('bank_name', $data)) {
            $account->changeBankName($data['bank_name'] !== null ? (string) $data['bank_name'] : null);
        }

        if (array_key_exists('balance_cents', $data)) {
            $account->changeBalance(self::cents($data['balance_cents']), new DateTimeImmutable());
        }

        if (array_key_exists('active', $data)) {
            filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) ? $account->activate() : $account->deactivate();
        }

        /** @var BankAccount $saved */
        $saved = $this->repository->save($account);

        return $saved;
    }

    /**
     * Every account (active and inactive) of the unit, which must be the
     * current unit.
     *
     * @return list<BankAccount>
     */
    public function listByUnit(int $systemUnitId): array
    {
        $this->assertCurrentUnit($systemUnitId);

        return $this->repository->listBySystemUnit($systemUnitId);
    }

    public function findById(int $id): ?BankAccount
    {
        $account = $this->repository->findById($id);

        if (
            !$account instanceof BankAccount
            || $account->tenantId() !== $this->context->tenantId()
            || $account->systemUnitId() !== $this->context->requireUnitId()
        ) {
            return null;
        }

        return $account;
    }

    /**
     * Sum of balanceCents() of the ACTIVE accounts of the unit, or null when
     * the unit has no active account (so the screen can tell "no account"
     * from a real zero balance). Another unit than the current one has no
     * visible account, so it also gives null.
     */
    public function totalBalanceCents(int $systemUnitId): ?int
    {
        if ($systemUnitId !== $this->context->requireUnitId()) {
            return null;
        }

        $total = null;

        foreach ($this->repository->listBySystemUnit($systemUnitId) as $account) {
            if ($account instanceof BankAccount && $account->isActive()) {
                $total = ($total ?? 0) + $account->balanceCents();
            }
        }

        return $total;
    }

    private function assertCurrentUnit(int $systemUnitId): void
    {
        $current = $this->context->requireUnitId();

        if ($systemUnitId !== $current) {
            throw new InvalidArgumentException("Bank account must belong to the current unit {$current}");
        }
    }

    private static function cents(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        $parsed = is_string($value) ? filter_var(trim($value), FILTER_VALIDATE_INT) : false;

        if ($parsed === false) {
            throw new InvalidArgumentException('balance_cents must be an integer');
        }

        return $parsed;
    }

    private function assertNameIsFree(int $systemUnitId, string $name, ?int $exceptId): void
    {
        $existing = $this->repository->findByName($systemUnitId, $name);

        if ($existing instanceof BankAccount && $existing->id() !== $exceptId) {
            throw new InvalidArgumentException("A bank account named \"{$name}\" already exists for this unit");
        }
    }
}
