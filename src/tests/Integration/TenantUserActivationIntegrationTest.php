<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tenancy\TenantUserActivation;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Etapa 3, T-19: o primeiro login do usuário convidado muda o vínculo
 * tenant_user de invited para active, uma única vez, sem tocar vínculos
 * inactive nem de outros usuários. Tudo é desfeito no rollback do tearDown().
 */
final class TenantUserActivationIntegrationTest extends MysqlIntegrationTestCase
{
    public function testActivateInvitedChangesOneRowAndIsIdempotent(): void
    {
        $tenantId = $this->createTenant();
        $invited = $this->createUser();
        $other = $this->createUser();
        $this->linkUser($tenantId, $invited, 'invited');
        $this->linkUser($tenantId, $other, 'invited');

        Assert::same(1, TenantUserActivation::activateInvited($this->pdo, $invited));
        Assert::same('active', $this->status($invited));
        Assert::same('invited', $this->status($other), 'outro usuário convidado não muda');

        Assert::same(0, TenantUserActivation::activateInvited($this->pdo, $invited), 'a segunda chamada não muda nada');
        Assert::same('active', $this->status($invited));
    }

    public function testInactiveLinkIsNotReactivatedByLogin(): void
    {
        $tenantId = $this->createTenant();
        $user = $this->createUser();
        $this->linkUser($tenantId, $user, 'inactive');

        Assert::same(0, TenantUserActivation::activateInvited($this->pdo, $user));
        Assert::same('inactive', $this->status($user));
        Assert::same(0, TenantUserActivation::activateInvited($this->pdo, $this->createUser()), 'usuário sem vínculo devolve 0');
    }

    private function status(int $userId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM tenant_user WHERE system_user_id = ?');
        $statement->execute([$userId]);

        return (string) $statement->fetchColumn();
    }

    private function createTenant(): int
    {
        $slug = 't19-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            "INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, 'active')",
        );
        $statement->execute(['slug' => $slug, 'name' => 'T-19 activation tenant (' . $slug . ')']);

        return (int) $this->pdo->lastInsertId();
    }

    /** system_users.id não é auto_increment (Adianti). */
    private function createUser(): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_users')->fetchColumn();
        $statement = $this->pdo->prepare(
            "INSERT INTO system_users (id, name, login, active) VALUES (:id, :name, :login, 'Y')",
        );
        $statement->execute(['id' => $id, 'name' => 'T-19 user ' . $id, 'login' => 't19-activation-' . $id]);

        return $id;
    }

    private function linkUser(int $tenantId, int $userId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (:tenant_id, :user_id, :status)',
        );
        $statement->execute(['tenant_id' => $tenantId, 'user_id' => $userId, 'status' => $status]);
    }
}
