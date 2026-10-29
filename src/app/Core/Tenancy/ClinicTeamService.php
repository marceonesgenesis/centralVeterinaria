<?php

declare(strict_types=1);

namespace CentralVet\Tenancy;

use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Billing\QuotaGuard;
use CentralVet\Billing\SubscriptionAccessGuard;
use CentralVet\Billing\TenantProvisioningService;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Equipe da clínica (Etapa 3, T-24): listar, convidar, editar, desativar e
 * reativar usuários do tenant da sessão. Toda leitura e escrita filtra por
 * $context->tenantId(): usuário pelo vínculo em tenant_user, unidade por
 * system_unit.tenant_id e grupo só o do plano da assinatura ligado ao
 * tenant em tenant_group (nunca o grupo 1 nem o de outro plano) ou um dos
 * perfis restritos globais de ClinicRoleProfiles (Etapa 5, T-12), achados
 * pelo nome exato. O usuário fica com um único grupo: o do plano
 * (administrador) ou o do perfil.
 *
 * As quotas de usuários são aplicadas aqui e só aqui (invite e reactivate).
 * O convite sai depois do commit do TransactionRunner: chame fora de uma
 * transação aberta (o controller usa uma conexão sem TTransaction).
 */
final class ClinicTeamService
{
    public const CONTROLLER = 'ClinicUserForm';

    private const OUTSIDE_TENANT = 'Resource does not belong to the authenticated tenant';

    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionRunner $tx,
        private readonly TenantContext $context,
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly QuotaGuard $quota,
        private readonly SubscriptionAccessGuard $access,
        private readonly AccountInviteService $invites,
    ) {
    }

    /**
     * @return list<array{id: int, name: string, email: string, status: string, group: ?string, unit_id: ?int}>
     */
    public function listUsers(): array
    {
        return array_map(static function (array $user): array {
            unset($user['group_id']);

            return $user;
        }, $this->selectUsers(null));
    }

    /**
     * Um usuário do tenant no formato de listUsers() (extra para o onEdit).
     *
     * @return array{id: int, name: string, email: string, status: string, group: ?string, unit_id: ?int, group_id: ?int}
     */
    public function findUser(int $userId): array
    {
        $rows = $this->selectUsers($userId);
        if ($rows === []) {
            throw new TenantBoundaryViolation(self::OUTSIDE_TENANT);
        }

        return $rows[0];
    }

    /**
     * @return array<int, string> id → nome, por id: o grupo do plano ligado
     * ao tenant e os grupos de perfil restrito; [] sem assinatura
     */
    public function allowedGroups(): array
    {
        $subscription = $this->subscriptions->findByTenantId($this->tenantId());
        if ($subscription === null) {
            return [];
        }

        $name = TenantProvisioningService::GROUP_NAME_PREFIX . PlanEntitlements::forPlan($subscription->planId())->name();
        $profiles = ClinicRoleProfiles::groupNames();
        $in = implode(', ', array_fill(0, count($profiles), '?'));
        $statement = $this->pdo->prepare(
            'SELECT g.id, g.name FROM system_group g '
            . 'LEFT JOIN tenant_group tg ON tg.system_group_id = g.id AND tg.tenant_id = ? '
            . "WHERE g.id <> 1 AND ((g.name = ? AND tg.tenant_id IS NOT NULL) OR g.name IN ({$in})) ORDER BY g.id",
        );
        $statement->execute([$this->tenantId(), $name, ...$profiles]);

        $groups = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[(int) $row['id']] = (string) $row['name'];
        }

        return $groups;
    }

    /** @return array<int, string> id → nome das unidades do tenant, por nome (extra para o combo) */
    public function listUnits(): array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM system_unit WHERE tenant_id = ? ORDER BY name, id');
        $statement->execute([$this->tenantId()]);

        $units = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $units[(int) $row['id']] = (string) $row['name'];
        }

        return $units;
    }

    public function invite(string $name, string $email, int $groupId, int $unitId, DateTimeImmutable $now): int
    {
        $tenantId = $this->tenantId();
        $email = trim($email);
        $name = trim($name);

        /** @var array{id: int, hash: string} $created */
        $created = $this->tx->run(function () use ($tenantId, $name, $email, $groupId, $unitId, $now): array {
            $decision = $this->access->decide($tenantId, self::CONTROLLER, $now);
            if (!$decision->allowed()) {
                throw new RuntimeException((string) $decision->messageKey());
            }

            $this->quota->assertCanAddUser($tenantId);
            $this->assertAllowedGroup($groupId);
            $this->assertTenantUnit($unitId);

            if ($this->scalar('SELECT id FROM system_users WHERE login = ? ORDER BY id LIMIT 1 FOR UPDATE', [$email]) !== null) {
                throw new InvalidArgumentException('A user with this e-mail already exists');
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Invalid e-mail');
            }

            $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $userId = $this->nextSystemId('system_users');
            $this->execute(
                "INSERT INTO system_users (id, name, login, password, email, active, system_unit_id) VALUES (?, ?, ?, ?, ?, 'Y', ?)",
                [$userId, $name, $email, $hash, $email, $unitId],
            );
            $this->execute(
                'INSERT INTO system_user_unit (id, system_user_id, system_unit_id) VALUES (?, ?, ?)',
                [$this->nextSystemId('system_user_unit'), $userId, $unitId],
            );
            $this->execute(
                'INSERT INTO system_user_group (id, system_user_id, system_group_id) VALUES (?, ?, ?)',
                [$this->nextSystemId('system_user_group'), $userId, $groupId],
            );
            $this->execute("INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (?, ?, 'invited')", [$tenantId, $userId]);

            return ['id' => $userId, 'hash' => $hash];
        });

        try {
            $this->invites->send($email, $email, $created['hash'], $this->clinicName(), $now->getTimestamp());
        } catch (Throwable $e) {
            // O usuário fica convidado; o acesso pode ser recuperado por
            // "Esqueci minha senha" (o login é o e-mail).
            error_log(sprintf('[ClinicTeam] invite for user %d failed: %s', $created['id'], $e::class));
        }

        return $created['id'];
    }

    public function update(int $userId, string $name, int $groupId, int $unitId): void
    {
        $this->tx->run(function () use ($userId, $name, $groupId, $unitId): void {
            $this->assertTenantUser($userId);
            $allowed = $this->assertAllowedGroup($groupId);
            $this->assertTenantUnit($unitId);
            $planGroupId = $this->planGroupId($allowed);
            if ($planGroupId !== null && $groupId !== $planGroupId) {
                $this->assertNotTheLastAdministrator($userId, $planGroupId);
            }

            $this->execute('UPDATE system_users SET name = ?, system_unit_id = ? WHERE id = ?', [trim($name), $unitId, $userId]);

            $tenantUnits = array_keys($this->listUnits());
            $this->replaceLinks('system_user_unit', 'system_unit_id', $userId, $tenantUnits, $unitId);
            $this->replaceLinks('system_user_group', 'system_group_id', $userId, array_keys($allowed), $groupId);
        });
    }

    public function deactivate(int $userId): void
    {
        $this->tx->run(function () use ($userId): void {
            $this->assertTenantUser($userId);
            if ($userId === $this->context->userId()) {
                throw new InvalidArgumentException('You cannot deactivate your own user');
            }
            $planGroupId = $this->planGroupId($this->allowedGroups());
            if ($planGroupId !== null) {
                $this->assertNotTheLastAdministrator($userId, $planGroupId);
            }

            $this->execute("UPDATE tenant_user SET status = 'inactive' WHERE tenant_id = ? AND system_user_id = ?", [$this->tenantId(), $userId]);
            $this->execute("UPDATE system_users SET active = 'N' WHERE id = ?", [$userId]);
        });
    }

    public function reactivate(int $userId, DateTimeImmutable $now): void
    {
        $this->tx->run(function () use ($userId): void {
            $status = $this->assertTenantUser($userId);
            if ($status !== 'inactive') {
                return;
            }

            $this->quota->assertCanAddUser($this->tenantId());

            $this->execute("UPDATE tenant_user SET status = 'active' WHERE tenant_id = ? AND system_user_id = ?", [$this->tenantId(), $userId]);
            $this->execute("UPDATE system_users SET active = 'Y' WHERE id = ?", [$userId]);
        });
    }

    /**
     * @return list<array{id: int, name: string, email: string, status: string, group: ?string, unit_id: ?int, group_id: ?int}>
     */
    private function selectUsers(?int $userId): array
    {
        $tenantId = $this->tenantId();
        $profiles = ClinicRoleProfiles::groupNames();
        $in = implode(', ', array_fill(0, count($profiles), '?'));
        $sql = 'SELECT u.id, u.name, u.login, u.email, tu.status, su.id AS unit_id, '
            . '(SELECT g.id FROM system_user_group ug JOIN system_group g ON g.id = ug.system_group_id '
            . ' LEFT JOIN tenant_group tg ON tg.system_group_id = g.id AND tg.tenant_id = ? '
            . " WHERE ug.system_user_id = u.id AND g.id <> 1 AND (tg.tenant_id IS NOT NULL OR g.name IN ({$in})) "
            . ' ORDER BY g.id LIMIT 1) AS group_id '
            . 'FROM tenant_user tu JOIN system_users u ON u.id = tu.system_user_id '
            . 'LEFT JOIN system_unit su ON su.id = u.system_unit_id AND su.tenant_id = ? '
            . 'WHERE tu.tenant_id = ?';
        $params = [$tenantId, ...$profiles, $tenantId, $tenantId];
        if ($userId !== null) {
            $sql .= ' AND u.id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY u.name, u.id';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $groupNames = [];
        $groupIds = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['group_id'], $rows))));
        if ($groupIds !== []) {
            $in = implode(', ', array_fill(0, count($groupIds), '?'));
            $names = $this->pdo->prepare("SELECT id, name FROM system_group WHERE id IN ({$in})");
            $names->execute($groupIds);
            foreach ($names->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $groupNames[(int) $row['id']] = (string) $row['name'];
            }
        }

        return array_map(static function (array $row) use ($groupNames): array {
            $groupId = $row['group_id'] === null ? null : (int) $row['group_id'];
            $email = (string) ($row['email'] ?? '');

            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'email' => $email !== '' ? $email : (string) $row['login'],
                'status' => (string) $row['status'],
                'group' => $groupId === null ? null : ($groupNames[$groupId] ?? null),
                'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'],
                'group_id' => $groupId,
            ];
        }, $rows);
    }

    /** @return string status do vínculo (invited/active/inactive) */
    private function assertTenantUser(int $userId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM tenant_user WHERE tenant_id = ? AND system_user_id = ? FOR UPDATE');
        $statement->execute([$this->tenantId(), $userId]);
        $status = $statement->fetchColumn();
        if ($status === false) {
            throw new TenantBoundaryViolation(self::OUTSIDE_TENANT);
        }

        return (string) $status;
    }

    /** @return array<int, string> */
    private function assertAllowedGroup(int $groupId): array
    {
        $allowed = $this->allowedGroups();
        if (!isset($allowed[$groupId])) {
            throw new TenantBoundaryViolation('Group not allowed for this clinic');
        }

        return $allowed;
    }

    /** @param array<int, string> $allowed saída de allowedGroups() */
    private function planGroupId(array $allowed): ?int
    {
        foreach ($allowed as $id => $name) {
            if (!in_array($name, ClinicRoleProfiles::groupNames(), true)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Recusa tirar do grupo do plano (ou desativar) o último administrador
     * ativo do tenant: sem ele ninguém mais gere equipe, assinatura e
     * exportação. Vale também para o próprio usuário da sessão. Convidado
     * ainda não conta. FOR UPDATE serializa duas trocas simultâneas.
     */
    private function assertNotTheLastAdministrator(int $userId, int $planGroupId): void
    {
        $isAdmin = $this->scalar(
            'SELECT id FROM system_user_group WHERE system_user_id = ? AND system_group_id = ? LIMIT 1',
            [$userId, $planGroupId],
        ) !== null;
        if (!$isAdmin) {
            return;
        }

        $others = $this->pdo->prepare(
            'SELECT u.id FROM tenant_user tu JOIN system_users u ON u.id = tu.system_user_id '
            . 'JOIN system_user_group ug ON ug.system_user_id = u.id AND ug.system_group_id = ? '
            . "WHERE tu.tenant_id = ? AND tu.status = 'active' AND u.active = 'Y' AND u.id <> ? FOR UPDATE",
        );
        $others->execute([$planGroupId, $this->tenantId(), $userId]);
        if ($others->fetchAll(PDO::FETCH_COLUMN) === []) {
            throw new DomainException('The clinic must keep at least one active administrator');
        }
    }

    private function assertTenantUnit(int $unitId): void
    {
        if ($this->scalar('SELECT id FROM system_unit WHERE id = ? AND tenant_id = ?', [$unitId, $this->tenantId()]) === null) {
            throw new TenantBoundaryViolation(self::OUTSIDE_TENANT);
        }
    }

    /**
     * Troca o vínculo do usuário só dentro de $scope (unidades do tenant ou
     * grupos permitidos): remove os outros vínculos desse conjunto e garante
     * $target. Vínculos fora de $scope não são tocados.
     *
     * @param list<int> $scope
     */
    private function replaceLinks(string $table, string $column, int $userId, array $scope, int $target): void
    {
        $others = array_values(array_filter($scope, static fn (int $id): bool => $id !== $target));
        if ($others !== []) {
            $in = implode(', ', array_fill(0, count($others), '?'));
            $this->execute(sprintf('DELETE FROM %s WHERE system_user_id = ? AND %s IN (%s)', $table, $column, $in), [$userId, ...$others]);
        }

        if ($this->scalar(sprintf('SELECT id FROM %s WHERE system_user_id = ? AND %s = ? LIMIT 1', $table, $column), [$userId, $target]) === null) {
            $this->execute(
                sprintf('INSERT INTO %s (id, system_user_id, %s) VALUES (?, ?, ?)', $table, $column),
                [$this->nextSystemId($table), $userId, $target],
            );
        }
    }

    private function clinicName(): string
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(NULLIF(trade_name, \'\'), legal_name) FROM tenant WHERE id = ?');
        $statement->execute([$this->tenantId()]);

        return (string) $statement->fetchColumn();
    }

    private function tenantId(): int
    {
        return $this->context->tenantId();
    }

    private function nextSystemId(string $table): int
    {
        return (int) $this->scalar(sprintf('SELECT COALESCE(MAX(id), 0) + 1 FROM %s FOR UPDATE', $table));
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
}
