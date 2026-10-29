<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\BillingOrderRepositoryInterface;
use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Turns a paid billing order into a clinic: tenant, unit, administrator
 * user, memberships in the plan group and the subscription linked to the
 * tenant, then the first access invite.
 *
 * Idempotent and resumable: everything runs in one TransactionRunner
 * transaction with the order locked (findByIdForUpdate), every step looks
 * up its deterministic key first (tenant.public_id, system_unit.custom_code,
 * system_users.login, membership pairs) and never duplicates. Any exception
 * rolls the whole step chain back and marks the order provisioning_failed
 * in a separate transaction, so the next call starts again from scratch.
 * The invite runs after the commit: if it fails the order stays
 * provisioned with invite_sent_at null and the next call only resends it;
 * each failed invite adds one provisioning_attempts (the resend cap).
 *
 * Never talks to the payment provider. $faultInjector(string $step) runs
 * before each step (tenant, unit, user, memberships, subscription, invite)
 * and exists only for tests.
 */
final class TenantProvisioningService
{
    public const GROUP_NAME_PREFIX = 'Central Vet - Plano ';

    private const SLUG_SUFFIX_ATTEMPTS = 8;

    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionRunner $tx,
        private readonly BillingOrderRepositoryInterface $orders,
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly AccountInviteService $invites,
        private readonly ?\Closure $faultInjector = null,
    ) {
    }

    public function fulfill(int $billingOrderId, DateTimeImmutable $now): ProvisioningResult
    {
        try {
            /** @var array{status: string, tenantId: ?int, order: ?BillingOrder} $outcome */
            $outcome = $this->tx->run(fn (): array => $this->provision($billingOrderId));
        } catch (Throwable $e) {
            $code = $e instanceof ProvisioningConflict ? $e->errorCode() : self::shortName($e);
            error_log(sprintf(
                '[TenantProvisioning] billing order %d failed: %s %s (code %s)',
                $billingOrderId,
                $e::class,
                $code,
                (string) $e->getCode(),
            ));
            $this->tx->run(function () use ($billingOrderId, $code): void {
                $order = $this->orders->findByIdForUpdate($billingOrderId);
                if ($order !== null && in_array($order->status(), [BillingOrder::STATUS_PAID, BillingOrder::STATUS_PROVISIONING_FAILED], true)) {
                    $order->markProvisioningFailed($code);
                    $this->orders->save($order);
                }
            });

            return new ProvisioningResult(ProvisioningResult::FAILED, null, $code);
        }

        $order = $outcome['order'];
        if ($order !== null && $outcome['tenantId'] !== null && $order->inviteSentAt() === null) {
            $this->sendInvite($order, $outcome['tenantId'], $now);
        }

        return new ProvisioningResult($outcome['status'], $outcome['tenantId']);
    }

    /** @return array{status: string, tenantId: ?int, order: ?BillingOrder} */
    private function provision(int $billingOrderId): array
    {
        $order = $this->orders->findByIdForUpdate($billingOrderId);
        if ($order === null) {
            return ['status' => ProvisioningResult::NOT_PAID, 'tenantId' => null, 'order' => null];
        }

        if ($order->status() === BillingOrder::STATUS_PROVISIONED) {
            return ['status' => ProvisioningResult::ALREADY_PROVISIONED, 'tenantId' => $order->tenantId(), 'order' => $order];
        }

        if (!in_array($order->status(), [BillingOrder::STATUS_PAID, BillingOrder::STATUS_PROVISIONING_FAILED], true)) {
            return ['status' => ProvisioningResult::NOT_PAID, 'tenantId' => null, 'order' => null];
        }

        $groupName = self::GROUP_NAME_PREFIX . PlanEntitlements::forPlan($order->planId())->name();
        $groupId = $this->scalar('SELECT id FROM system_group WHERE name = ? ORDER BY id LIMIT 1', [$groupName]);
        if ($groupId === null) {
            throw ProvisioningConflict::groupMissing($groupName);
        }

        $this->fault('tenant');
        $tenantId = $this->ensureTenant($order);

        $this->fault('unit');
        $unitId = $this->ensureUnit($tenantId, $order);

        $this->fault('user');
        $userId = $this->ensureUser($tenantId, $unitId, $order);

        $this->fault('memberships');
        $this->ensureSystemRow('system_user_unit', ['system_user_id' => $userId, 'system_unit_id' => $unitId]);
        $this->ensureSystemRow('system_user_group', ['system_user_id' => $userId, 'system_group_id' => $groupId]);
        if ($this->scalar('SELECT id FROM tenant_user WHERE tenant_id = ? AND system_user_id = ?', [$tenantId, $userId]) === null) {
            $this->execute("INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (?, ?, 'invited')", [$tenantId, $userId]);
        }
        if ($this->scalar('SELECT id FROM tenant_group WHERE tenant_id = ? AND system_group_id = ?', [$tenantId, $groupId]) === null) {
            $this->execute('INSERT INTO tenant_group (tenant_id, system_group_id) VALUES (?, ?)', [$tenantId, $groupId]);
        }

        $this->fault('subscription');
        $orderId = (int) $order->id();
        $subscription = $this->subscriptions->findByBillingOrderId($orderId);
        if ($subscription === null) {
            throw ProvisioningConflict::subscriptionMissing($orderId);
        }
        if ($subscription->tenantId() !== $tenantId) {
            $subscription->assignTenant($tenantId);
            $this->subscriptions->save($subscription);
        }

        $order->markProvisioned($tenantId);
        $this->orders->save($order);

        return ['status' => ProvisioningResult::PROVISIONED, 'tenantId' => $tenantId, 'order' => $order];
    }

    private function ensureTenant(BillingOrder $order): int
    {
        $existing = $this->scalar('SELECT id FROM tenant WHERE public_id = ?', [$order->publicId()]);
        if ($existing !== null) {
            return $existing;
        }

        $this->execute(
            "INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (?, ?, ?, 'active')",
            [$order->publicId(), $this->freeSlug($order->publicId()), $order->clinicName()],
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 'clinica-' + 8 first characters of public_id; if another tenant holds
     * it (tenant_slug_uq), tries -2 .. -9 and then the whole public_id, so a
     * slug never blocks a paid order. FOR UPDATE locks the index entry, so a
     * concurrent provisioning waits instead of taking the same slug.
     */
    private function freeSlug(string $publicId): string
    {
        $base = 'clinica-' . substr($publicId, 0, 8);
        $candidates = [$base];
        for ($n = 2; $n <= self::SLUG_SUFFIX_ATTEMPTS + 1; $n++) {
            $candidates[] = $base . '-' . $n;
        }
        $candidates[] = 'clinica-' . $publicId;

        foreach ($candidates as $slug) {
            if ($this->scalar('SELECT id FROM tenant WHERE slug = ? FOR UPDATE', [$slug]) === null) {
                return $slug;
            }
        }

        throw new \RuntimeException(sprintf('No free tenant slug for public id %s', $publicId));
    }

    private function ensureUnit(int $tenantId, BillingOrder $order): int
    {
        $code = 'order:' . $order->publicId();
        $existing = $this->scalar('SELECT id FROM system_unit WHERE tenant_id = ? AND custom_code = ?', [$tenantId, $code]);
        if ($existing !== null) {
            return $existing;
        }

        $id = $this->nextSystemId('system_unit');
        $this->execute(
            'INSERT INTO system_unit (id, tenant_id, name, custom_code) VALUES (?, ?, ?, ?)',
            [$id, $tenantId, $order->clinicName(), $code],
        );

        return $id;
    }

    private function ensureUser(int $tenantId, int $unitId, BillingOrder $order): int
    {
        $login = $order->responsibleEmail();
        $existing = $this->scalar('SELECT id FROM system_users WHERE login = ? ORDER BY id LIMIT 1 FOR UPDATE', [$login]);
        if ($existing !== null) {
            $member = $this->scalar('SELECT id FROM tenant_user WHERE tenant_id = ? AND system_user_id = ?', [$tenantId, $existing]);
            if ($member === null) {
                throw ProvisioningConflict::loginTaken();
            }

            return $existing;
        }

        $id = $this->nextSystemId('system_users');
        $this->execute(
            "INSERT INTO system_users (id, name, login, password, email, active, system_unit_id) VALUES (?, ?, ?, ?, ?, 'Y', ?)",
            [$id, $order->responsibleName(), $login, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $login, $unitId],
        );

        return $id;
    }

    /** @param array{system_user_id: int, system_unit_id?: int, system_group_id?: int} $pair */
    private function ensureSystemRow(string $table, array $pair): void
    {
        $columns = array_keys($pair);
        $where = implode(' AND ', array_map(static fn (string $c): string => $c . ' = ?', $columns));
        if ($this->scalar(sprintf('SELECT id FROM %s WHERE %s LIMIT 1', $table, $where), array_values($pair)) !== null) {
            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, %s) VALUES (?, ?, ?)', $table, implode(', ', $columns)),
            [$this->nextSystemId($table), ...array_values($pair)],
        );
    }

    private function sendInvite(BillingOrder $order, int $tenantId, DateTimeImmutable $now): void
    {
        try {
            $this->fault('invite');

            $statement = $this->pdo->prepare(
                'SELECT u.login, u.email, u.password FROM system_users u '
                . 'JOIN tenant_user tu ON tu.system_user_id = u.id '
                . 'WHERE tu.tenant_id = ? AND u.login = ? ORDER BY u.id LIMIT 1',
            );
            $statement->execute([$tenantId, $order->responsibleEmail()]);
            $user = $statement->fetch(PDO::FETCH_ASSOC);
            if ($user === false) {
                throw new \RuntimeException('Provisioned administrator not found for the invite');
            }

            $this->invites->send(
                (string) $user['login'],
                (string) ($user['email'] ?: $user['login']),
                (string) $user['password'],
                $order->clinicName(),
                $now->getTimestamp(),
            );

            $this->tx->run(function () use ($order, $now): void {
                $locked = $this->orders->findByIdForUpdate((int) $order->id());
                if ($locked !== null && $locked->inviteSentAt() === null) {
                    $locked->markInviteSent($now);
                    $this->orders->save($locked);
                }
            });
        } catch (Throwable $e) {
            // The order stays provisioned with invite_sent_at null; the next
            // fulfill() (reconciler) only resends the invite, up to the
            // attempt cap of listPendingFulfillment.
            error_log(sprintf('[TenantProvisioning] invite for billing order %d failed: %s', (int) $order->id(), self::shortName($e)));
            $this->countInviteFailure((int) $order->id());
        }
    }

    /**
     * A failed invite counts one provisioning attempt, so an invite that
     * always fails leaves listPendingFulfillment after the cap and sorts
     * after new orders meanwhile.
     */
    private function countInviteFailure(int $billingOrderId): void
    {
        try {
            $this->tx->run(function () use ($billingOrderId): void {
                $locked = $this->orders->findByIdForUpdate($billingOrderId);
                if ($locked === null || $locked->status() !== BillingOrder::STATUS_PROVISIONED || $locked->inviteSentAt() !== null) {
                    return;
                }

                $row = $locked->toRow();
                $row['provisioning_attempts'] = $locked->provisioningAttempts() + 1;
                $this->orders->save(BillingOrder::fromRow($row));
            });
        } catch (Throwable $e) {
            error_log(sprintf('[TenantProvisioning] invite attempt count for billing order %d failed: %s', $billingOrderId, self::shortName($e)));
        }
    }

    private function nextSystemId(string $table): int
    {
        return (int) $this->scalar(sprintf('SELECT COALESCE(MAX(id), 0) + 1 FROM %s FOR UPDATE', $table));
    }

    private function fault(string $step): void
    {
        if ($this->faultInjector !== null) {
            ($this->faultInjector)($step);
        }
    }

    /** @param list<int|string> $params */
    private function scalar(string $sql, array $params = []): ?int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    /** @param list<int|string|null> $params */
    private function execute(string $sql, array $params): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    private static function shortName(Throwable $e): string
    {
        $class = $e::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
