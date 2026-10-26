<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Legal\LegalAcceptanceService;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Etapa 5, T-10: aceite de Termos de Uso e Política de Privacidade registrado por
 * usuário, documento e versão em legal_acceptance. Cada teste cria os próprios
 * tenants e usuários; tudo é desfeito no rollback do tearDown().
 */
final class LegalAcceptanceIntegrationTest extends MysqlIntegrationTestCase
{
    public function testNewTenantUserHasBothDocumentsPendingInOrder(): void
    {
        $user = $this->createUser();
        $this->linkUser($this->createTenant(), $user);

        Assert::same(['terms', 'privacy'], $this->service()->pendingDocuments($user));
    }

    public function testAcceptRecordsCurrentVersionsTenantAndIpAndClearsPending(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser();
        $this->linkUser($tenant, $user);
        $service = $this->service();

        Assert::same(2, $service->accept($user, '203.0.113.7', 'Teste/1.0', $this->now()));

        $rows = $this->acceptances($user);
        Assert::count(2, $rows);
        Assert::same(
            ['terms' => 'termos-uso-2026-10', 'privacy' => 'privacidade-2026-10'],
            array_column($rows, 'version', 'document'),
        );
        foreach ($rows as $row) {
            Assert::same($tenant, (int) $row['tenant_id']);
            Assert::same('203.0.113.7', $row['ip']);
            Assert::same('Teste/1.0', $row['user_agent']);
            Assert::same('2026-10-26 15:00:00.000000', $row['accepted_at']);
        }

        Assert::same([], $service->pendingDocuments($user));
    }

    public function testSecondAcceptIsIdempotent(): void
    {
        $user = $this->createUser();
        $this->linkUser($this->createTenant(), $user);
        $service = $this->service();

        Assert::same(2, $service->accept($user, '203.0.113.7', 'Teste/1.0', $this->now()));
        Assert::same(0, $service->accept($user, '203.0.113.7', 'Teste/1.0', $this->now()));
        Assert::count(2, $this->acceptances($user), 'nenhuma linha nova');
    }

    public function testOlderVersionDoesNotCountAsAcceptance(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser();
        $this->linkUser($tenant, $user);
        $this->pdo->prepare(
            "INSERT INTO legal_acceptance (tenant_id, system_user_id, document, version, accepted_at, ip, user_agent)
             VALUES (:tenant_id, :user_id, 'terms', 'termos-uso-2020-01', '2020-01-10 10:00:00', '198.51.100.1', NULL)",
        )->execute(['tenant_id' => $tenant, 'user_id' => $user]);
        $service = $this->service();

        Assert::same(['terms', 'privacy'], $service->pendingDocuments($user));
        Assert::same(2, $service->accept($user, '203.0.113.7', null, $this->now()));
        Assert::count(3, $this->acceptances($user), 'a versão antiga continua registrada');
        Assert::same([], $service->pendingDocuments($user));
    }

    public function testUserWithoutTenantHasNoPendingAndCannotAccept(): void
    {
        $user = $this->createUser();
        $service = $this->service();

        Assert::same([], $service->pendingDocuments($user));
        Assert::throws(
            \InvalidArgumentException::class,
            fn () => $service->accept($user, '203.0.113.7', 'Teste/1.0', $this->now()),
        );
        Assert::count(0, $this->acceptances($user));
    }

    public function testAcceptanceOfTenantAUserDoesNotAffectTenantBUser(): void
    {
        $userA = $this->createUser();
        $this->linkUser($this->createTenant(), $userA);
        $userB = $this->createUser();
        $this->linkUser($this->createTenant(), $userB);
        $service = $this->service();

        Assert::same(2, $service->accept($userA, '203.0.113.7', 'Teste/1.0', $this->now()));

        Assert::same([], $service->pendingDocuments($userA));
        Assert::same(['terms', 'privacy'], $service->pendingDocuments($userB));
        Assert::count(0, $this->acceptances($userB));
    }

    public function testAcceptUsesLowestTenantAndTruncatesIpAndUserAgent(): void
    {
        $first = $this->createTenant();
        $second = $this->createTenant();
        $user = $this->createUser();
        $this->linkUser($second, $user);
        $this->linkUser($first, $user);

        Assert::same(2, $this->service()->accept($user, str_repeat('a', 60), str_repeat('b', 300), $this->now()));

        foreach ($this->acceptances($user) as $row) {
            Assert::same(min($first, $second), (int) $row['tenant_id']);
            Assert::same(45, strlen((string) $row['ip']));
            Assert::same(255, strlen((string) $row['user_agent']));
        }
    }

    private function service(): LegalAcceptanceService
    {
        return new LegalAcceptanceService($this->pdo);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-26 15:00:00');
    }

    /** @return list<array<string, mixed>> */
    private function acceptances(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tenant_id, document, version, accepted_at, ip, user_agent
             FROM legal_acceptance WHERE system_user_id = ? ORDER BY id',
        );
        $statement->execute([$userId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function createTenant(): int
    {
        $slug = 'e5-t10-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            "INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, 'active')",
        );
        $statement->execute(['slug' => $slug, 'name' => 'T-10 legal tenant (' . $slug . ')']);

        return (int) $this->pdo->lastInsertId();
    }

    /** system_users.id não é auto_increment (Adianti); salto aleatório evita colisão com suítes paralelas. */
    private function createUser(): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM system_users')->fetchColumn()
            + random_int(1000, 900000);
        $statement = $this->pdo->prepare(
            "INSERT INTO system_users (id, name, login, active) VALUES (:id, :name, :login, 'Y')",
        );
        $statement->execute(['id' => $id, 'name' => 'T-10 user ' . $id, 'login' => 't10-legal-' . $id]);

        return $id;
    }

    private function linkUser(int $tenantId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (:tenant_id, :user_id, 'active')",
        );
        $statement->execute(['tenant_id' => $tenantId, 'user_id' => $userId]);
    }
}
