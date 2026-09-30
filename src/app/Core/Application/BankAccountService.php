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
 * accepted from $data (ADR 0002). Authorization is done by the calling
 * controller (program permission of BankAccountList/BankAccountForm),
 * following this task's Interface, which gives the service no policy.
 */
final class BankAccountService
{
    public function __construct(
        private readonly BankAccountRepositoryInterface $repository,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @param array{system_unit_id: int|string, name: string, bank_name?: ?string, balance_cents: int|string} $data
     */
    public function create(array $data): BankAccount
    {
        $systemUnitId = (int) ($data['system_unit_id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));

        $account = BankAccount::create(
            tenantId: $this->context->tenantId(),
            systemUnitId: $systemUnitId,
            name: $name,
            bankName: isset($data['bank_name']) ? (string) $data['bank_name'] : null,
            balanceCents: (int) ($data['balance_cents'] ?? 0),
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
            $account->changeBalance((int) $data['balance_cents'], new DateTimeImmutable());
        }

        if (array_key_exists('active', $data)) {
            filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) ? $account->activate() : $account->deactivate();
        }

        /** @var BankAccount $saved */
        $saved = $this->repository->save($account);

        return $saved;
    }

    /** @return list<BankAccount> every account (active and inactive) of the unit */
    public function listByUnit(int $systemUnitId): array
    {
        return $this->repository->listBySystemUnit($systemUnitId);
    }

    public function findById(int $id): ?BankAccount
    {
        $account = $this->repository->findById($id);

        if (!$account instanceof BankAccount || $account->tenantId() !== $this->context->tenantId()) {
            return null;
        }

        return $account;
    }

    /**
     * Sum of balanceCents() of the ACTIVE accounts of the unit, or null when
     * the unit has no active account (so the screen can tell "no account"
     * from a real zero balance).
     */
    public function totalBalanceCents(int $systemUnitId): ?int
    {
        $total = null;

        foreach ($this->repository->listBySystemUnit($systemUnitId) as $account) {
            if ($account instanceof BankAccount && $account->isActive()) {
                $total = ($total ?? 0) + $account->balanceCents();
            }
        }

        return $total;
    }

    private function assertNameIsFree(int $systemUnitId, string $name, ?int $exceptId): void
    {
        $existing = $this->repository->findByName($systemUnitId, $name);

        if ($existing instanceof BankAccount && $existing->id() !== $exceptId) {
            throw new InvalidArgumentException("A bank account named \"{$name}\" already exists for this unit");
        }
    }
}
