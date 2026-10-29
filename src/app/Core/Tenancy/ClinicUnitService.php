<?php

declare(strict_types=1);

namespace CentralVet\Tenancy;

use CentralVet\Billing\QuotaGuard;
use CentralVet\Billing\SubscriptionAccessGuard;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Unidades da clínica (Etapa 3, T-25): listar, criar e renomear unidades do
 * tenant da sessão. Toda leitura e escrita filtra por system_unit.tenant_id
 * = $context->tenantId(). Sem exclusão de unidade.
 *
 * A quota de unidades é aplicada aqui e só aqui (create); SystemUnitForm
 * fica sem quota, restrito ao grupo 1 (administração da plataforma).
 */
final class ClinicUnitService
{
    public const CONTROLLER = 'ClinicUnitForm';

    private const OUTSIDE_TENANT = 'Resource does not belong to the authenticated tenant';
    private const NAME_MAX = 120;

    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionRunner $tx,
        private readonly TenantContext $context,
        private readonly QuotaGuard $quota,
        private readonly SubscriptionAccessGuard $access,
    ) {
    }

    /**
     * Unidades do tenant por nome; `users` conta os usuários convidados ou
     * ativos do tenant ligados à unidade (system_user_unit).
     *
     * @return list<array{id: int, name: string, users: int}>
     */
    public function listUnits(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT su.id, su.name, '
            . '(SELECT COUNT(DISTINCT uu.system_user_id) FROM system_user_unit uu '
            . " JOIN tenant_user tu ON tu.system_user_id = uu.system_user_id AND tu.tenant_id = su.tenant_id AND tu.status IN ('invited', 'active') "
            . ' WHERE uu.system_unit_id = su.id) AS users '
            . 'FROM system_unit su WHERE su.tenant_id = ? ORDER BY su.name, su.id',
        );
        $statement->execute([$this->tenantId()]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'users' => (int) $row['users'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Uma unidade do tenant no formato de listUnits() (extra para o onEdit).
     *
     * @return array{id: int, name: string, users: int}
     */
    public function findUnit(int $unitId): array
    {
        foreach ($this->listUnits() as $unit) {
            if ($unit['id'] === $unitId) {
                return $unit;
            }
        }

        throw new TenantBoundaryViolation(self::OUTSIDE_TENANT);
    }

    public function create(string $name, DateTimeImmutable $now): int
    {
        $tenantId = $this->tenantId();

        /** @var int $id */
        $id = $this->tx->run(function () use ($tenantId, $name, $now): int {
            $decision = $this->access->decide($tenantId, self::CONTROLLER, $now);
            if (!$decision->allowed()) {
                throw new RuntimeException((string) $decision->messageKey());
            }

            // Serializa criações concorrentes do mesmo tenant antes de contar.
            $this->pdo->prepare('SELECT id FROM tenant WHERE id = ? FOR UPDATE')->execute([$tenantId]);
            $this->quota->assertCanAddUnit($tenantId);
            $name = self::validName($name);

            $statement = $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit FOR UPDATE');
            $id = (int) $statement->fetchColumn();
            $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (?, ?, ?)')->execute([$id, $tenantId, $name]);

            return $id;
        });

        return $id;
    }

    public function rename(int $unitId, string $name): void
    {
        $this->tx->run(function () use ($unitId, $name): void {
            $statement = $this->pdo->prepare('SELECT id FROM system_unit WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $statement->execute([$unitId, $this->tenantId()]);
            if ($statement->fetchColumn() === false) {
                throw new TenantBoundaryViolation(self::OUTSIDE_TENANT);
            }

            $this->pdo->prepare('UPDATE system_unit SET name = ? WHERE id = ? AND tenant_id = ?')
                ->execute([self::validName($name), $unitId, $this->tenantId()]);
        });
    }

    private static function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Unit name is required');
        }
        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw new InvalidArgumentException('Unit name must have at most 120 characters');
        }

        return $name;
    }

    private function tenantId(): int
    {
        return $this->context->tenantId();
    }
}
