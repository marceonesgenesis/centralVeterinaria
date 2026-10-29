<?php

declare(strict_types=1);

namespace CentralVet\Onboarding;

use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use PDO;

/**
 * Dados da clínica do primeiro acesso (Etapa 5, T-09): razão social e nome
 * fantasia do tenant da sessão e o nome da unidade da sessão. A unidade só é
 * lida e alterada com id = requireUnitId() AND tenant_id = tenantId().
 * Salvar grava tenant_onboarding.clinic_confirmed_at.
 */
final class ClinicProfileService
{
    private const OUTSIDE_TENANT = 'Resource does not belong to the authenticated tenant';

    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantContext $context,
    ) {
    }

    /** @return array{legal_name: string, trade_name: ?string, unit_name: string} */
    public function load(): array
    {
        $statement = $this->pdo->prepare('SELECT legal_name, trade_name FROM tenant WHERE id = ?');
        $statement->execute([$this->context->tenantId()]);
        $tenant = $statement->fetch(PDO::FETCH_ASSOC);
        if ($tenant === false) {
            throw new TenantBoundaryViolation(self::OUTSIDE_TENANT);
        }

        return [
            'legal_name' => (string) $tenant['legal_name'],
            'trade_name' => $tenant['trade_name'] === null ? null : (string) $tenant['trade_name'],
            'unit_name' => $this->unitName(),
        ];
    }

    public function save(string $legalName, ?string $tradeName, string $unitName, DateTimeImmutable $now): void
    {
        $legalName = trim($legalName);
        $tradeName = $tradeName === null ? null : trim($tradeName);
        $unitName = trim($unitName);

        if ($legalName === '') {
            throw new \DomainException('Enter the clinic legal name');
        }
        if ($unitName === '') {
            throw new \DomainException('Enter the unit name');
        }

        $tenantId = $this->context->tenantId();
        $this->unitName();

        $this->pdo->prepare('UPDATE tenant SET legal_name = ?, trade_name = ? WHERE id = ?')
            ->execute([$legalName, $tradeName === '' ? null : $tradeName, $tenantId]);
        $this->pdo->prepare('UPDATE system_unit SET name = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$unitName, $this->context->requireUnitId(), $tenantId]);

        OnboardingChecklistService::mark($this->pdo, $tenantId, 'clinic_confirmed_at', $now);
    }

    /** Nome da unidade da sessão; recusa unidade fora do tenant antes de qualquer escrita. */
    private function unitName(): string
    {
        $statement = $this->pdo->prepare('SELECT name FROM system_unit WHERE id = ? AND tenant_id = ?');
        $statement->execute([$this->context->requireUnitId(), $this->context->tenantId()]);
        $name = $statement->fetchColumn();
        if ($name === false) {
            throw new TenantBoundaryViolation(self::OUTSIDE_TENANT);
        }

        return (string) $name;
    }
}
