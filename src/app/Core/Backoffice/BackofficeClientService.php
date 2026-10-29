<?php

declare(strict_types=1);

namespace CentralVet\Backoffice;

use PDO;
use Exception;
use InvalidArgumentException;
use CentralVet\Persistence\PdoConnectionFactory;

/**
 * Gestão de clientes, licenças e assinaturas no Back-office Central Vet.
 * Não acessa nem expõe prontuários, tutores ou dados clínicos das clínicas.
 */
class BackofficeClientService
{
    private PDO $pdo;
    private BackofficeAuditService $auditService;

    public function __construct(?PDO $pdo = null, ?BackofficeAuditService $auditService = null)
    {
        $this->pdo = $pdo ?? PdoConnectionFactory::create();
        $this->auditService = $auditService ?? new BackofficeAuditService($this->pdo);
    }

    /**
     * Lista clientes e o status consolidado de licença
     * @return array<int, array<string, mixed>>
     */
    public function listClients(int $limit = 100): array
    {
        $sql = "
            SELECT 
                t.id as tenant_id,
                t.public_id,
                t.slug,
                t.legal_name,
                t.trade_name,
                t.status as tenant_status,
                t.created_at,
                s.id as subscription_id,
                s.plan_id,
                s.status as subscription_status,
                s.payment_method,
                s.stripe_customer_id,
                s.stripe_subscription_id,
                s.current_period_end,
                s.grace_until,
                (SELECT COUNT(*) FROM system_unit u WHERE u.tenant_id = t.id) as units_count,
                (SELECT COUNT(*) FROM system_user su WHERE su.tenant_id = t.id) as users_count
            FROM tenant t
            LEFT JOIN subscription s ON s.tenant_id = t.id
            ORDER BY t.id DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Suspende o acesso de uma clínica cliente
     */
    public function suspendClient(int $tenantId, string $reason): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, status, legal_name FROM tenant WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tenant) {
            throw new Exception('Clínica não encontrada.');
        }

        $oldStatus = $tenant['status'];
        $update = $this->pdo->prepare("UPDATE tenant SET status = 'suspended', updated_at = NOW(6) WHERE id = :id");
        $update->execute([':id' => $tenantId]);

        // Se houver subscription associada, marca como suspensa
        $updateSub = $this->pdo->prepare("UPDATE subscription SET status = 'suspended', updated_at = NOW(6) WHERE tenant_id = :id");
        $updateSub->execute([':id' => $tenantId]);

        $this->auditService->log(
            'client_suspend',
            'tenant',
            (string)$tenantId,
            ['status' => $oldStatus],
            ['status' => 'suspended'],
            ['reason' => $reason, 'clinic_name' => $tenant['legal_name']]
        );

        return true;
    }

    /**
     * Reativa o acesso de uma clínica cliente
     */
    public function reactivateClient(int $tenantId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, status, legal_name FROM tenant WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tenant) {
            throw new Exception('Clínica não encontrada.');
        }

        $oldStatus = $tenant['status'];
        $update = $this->pdo->prepare("UPDATE tenant SET status = 'active', updated_at = NOW(6) WHERE id = :id");
        $update->execute([':id' => $tenantId]);

        $updateSub = $this->pdo->prepare("UPDATE subscription SET status = 'active', updated_at = NOW(6) WHERE tenant_id = :id");
        $updateSub->execute([':id' => $tenantId]);

        $this->auditService->log(
            'client_reactivate',
            'tenant',
            (string)$tenantId,
            ['status' => $oldStatus],
            ['status' => 'active'],
            ['clinic_name' => $tenant['legal_name']]
        );

        return true;
    }

    /**
     * Troca o plano de uma assinatura ativa
     */
    public function changePlan(int $tenantId, string $newPlanId): bool
    {
        $validPlans = ['starter', 'pro', 'business', 'enterprise'];
        if (!in_array($newPlanId, $validPlans, true)) {
            throw new InvalidArgumentException("Plano inválido: {$newPlanId}");
        }

        $stmt = $this->pdo->prepare('SELECT id, plan_id, status FROM subscription WHERE tenant_id = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            throw new Exception('Assinatura da clínica não encontrada.');
        }

        $oldPlan = $sub['plan_id'];
        $update = $this->pdo->prepare('UPDATE subscription SET plan_id = :plan, updated_at = NOW(6) WHERE id = :id');
        $update->execute([':plan' => $newPlanId, ':id' => $sub['id']]);

        $this->auditService->log(
            'client_change_plan',
            'subscription',
            (string)$sub['id'],
            ['plan_id' => $oldPlan],
            ['plan_id' => $newPlanId],
            ['tenant_id' => $tenantId]
        );

        return true;
    }

    /**
     * Prorroga o período de bônus ou tolerância por N dias adicionais
     */
    public function extendTrial(int $tenantId, int $additionalDays = 15, string $reason = ''): bool
    {
        if ($additionalDays <= 0 || $additionalDays > 60) {
            throw new InvalidArgumentException('O período de prorrogação deve ser entre 1 e 60 dias.');
        }

        $stmt = $this->pdo->prepare('SELECT id, current_period_end, grace_until FROM subscription WHERE tenant_id = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            throw new Exception('Assinatura não encontrada para esta clínica.');
        }

        $baseDate = $sub['current_period_end'] ? strtotime($sub['current_period_end']) : time();
        if ($baseDate < time()) {
            $baseDate = time();
        }
        $newEnd = date('Y-m-d H:i:s', $baseDate + ($additionalDays * 86400));

        $update = $this->pdo->prepare('UPDATE subscription SET current_period_end = :end_date, updated_at = NOW(6) WHERE id = :id');
        $update->execute([':end_date' => $newEnd, ':id' => $sub['id']]);

        $this->auditService->log(
            'client_extend_trial',
            'subscription',
            (string)$sub['id'],
            ['current_period_end' => $sub['current_period_end']],
            ['current_period_end' => $newEnd],
            ['tenant_id' => $tenantId, 'additional_days' => $additionalDays, 'reason' => $reason]
        );

        return true;
    }
}

