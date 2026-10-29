<?php

declare(strict_types=1);

namespace CentralVet\Backoffice;

use PDO;
use Exception;
use InvalidArgumentException;
use CentralVet\Persistence\PdoConnectionFactory;
use TSession;

/**
 * Gestão de convites com bônus de degustação (trial de 15 dias).
 * Permite que um cliente teste o sistema sem fornecer cartão de crédito antecipadamente.
 */
class BackofficeInviteService
{
    public const DEFAULT_TRIAL_DAYS = 15;
    public const DEFAULT_INVITE_VALIDITY_DAYS = 7;

    private PDO $pdo;
    private BackofficeAuditService $auditService;

    public function __construct(?PDO $pdo = null, ?BackofficeAuditService $auditService = null)
    {
        $this->pdo = $pdo ?? PdoConnectionFactory::create();
        $this->auditService = $auditService ?? new BackofficeAuditService($this->pdo);
    }

    /**
     * Emite um novo convite com período de bônus
     */
    public function createInvite(
        string $email,
        string $clinicName,
        string $planId = 'pro',
        int $trialDays = self::DEFAULT_TRIAL_DAYS,
        int $validityDays = self::DEFAULT_INVITE_VALIDITY_DAYS
    ): array {
        $cleanEmail = strtolower(trim($email));
        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-mail informado é inválido.');
        }

        if (empty(trim($clinicName))) {
            throw new InvalidArgumentException('O nome da clínica é obrigatório.');
        }

        // Verificar se já existe um convite pendente para este e-mail
        $stmtCheck = $this->pdo->prepare('SELECT id, status FROM backoffice_invite WHERE email = :email AND status IN (\'pending\', \'in_trial\') LIMIT 1');
        $stmtCheck->execute([':email' => $cleanEmail]);
        if ($stmtCheck->fetch()) {
            throw new Exception('Já existe um convite ativo ou em período de bônus para este e-mail.');
        }

        $token = bin2hex(random_bytes(16)); // 32 chars hex / UUID
        $expiresAt = date('Y-m-d H:i:s', time() + ($validityDays * 86400));
        $userId = TSession::getValue('userid') ? (int)TSession::getValue('userid') : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO backoffice_invite 
             (public_token, email, clinic_name, plan_id, trial_days, expires_at, status, created_by_user_id, created_at)
             VALUES (:token, :email, :clinic_name, :plan_id, :trial_days, :expires_at, \'pending\', :user_id, NOW(6))'
        );

        $stmt->execute([
            ':token' => $token,
            ':email' => $cleanEmail,
            ':clinic_name' => trim($clinicName),
            ':plan_id' => $planId,
            ':trial_days' => $trialDays,
            ':expires_at' => $expiresAt,
            ':user_id' => $userId,
        ]);

        $inviteId = (int)$this->pdo->lastInsertId();

        $inviteData = [
            'id' => $inviteId,
            'token' => $token,
            'email' => $cleanEmail,
            'clinic_name' => $clinicName,
            'plan_id' => $planId,
            'trial_days' => $trialDays,
            'expires_at' => $expiresAt,
            'status' => 'pending',
        ];

        $this->auditService->log(
            'invite_create',
            'backoffice_invite',
            (string)$inviteId,
            [],
            $inviteData,
            ['reason' => "Emissão de convite com {$trialDays} dias de degustação"]
        );

        return $inviteData;
    }

    /**
     * Reenvia um convite expirado ou pendente, renovando a validade
     */
    public function resendInvite(int $inviteId, int $validityDays = self::DEFAULT_INVITE_VALIDITY_DAYS): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backoffice_invite WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $inviteId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            throw new Exception('Convite não encontrado.');
        }

        if ($existing['status'] === 'converted' || $existing['status'] === 'in_trial') {
            throw new Exception('Não é possível reenviar um convite já utilizado.');
        }

        $newExpiresAt = date('Y-m-d H:i:s', time() + ($validityDays * 86400));
        $update = $this->pdo->prepare(
            'UPDATE backoffice_invite SET expires_at = :expires_at, status = \'pending\', updated_at = NOW(6) WHERE id = :id'
        );
        $update->execute([':expires_at' => $newExpiresAt, ':id' => $inviteId]);

        $this->auditService->log(
            'invite_resend',
            'backoffice_invite',
            (string)$inviteId,
            ['expires_at' => $existing['expires_at']],
            ['expires_at' => $newExpiresAt],
            ['email' => $existing['email']]
        );

        return true;
    }

    /**
     * Cancela um convite
     */
    public function cancelInvite(int $inviteId): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backoffice_invite WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $inviteId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            throw new Exception('Convite não encontrado.');
        }

        $update = $this->pdo->prepare('UPDATE backoffice_invite SET status = \'cancelled\', updated_at = NOW(6) WHERE id = :id');
        $update->execute([':id' => $inviteId]);

        $this->auditService->log(
            'invite_cancel',
            'backoffice_invite',
            (string)$inviteId,
            ['status' => $existing['status']],
            ['status' => 'cancelled']
        );

        return true;
    }

    /**
     * Lista todos os convites emitidos
     * @return array<int, array<string, mixed>>
     */
    public function listInvites(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, u.name as created_by_name 
             FROM backoffice_invite i 
             LEFT JOIN system_user u ON i.created_by_user_id = u.id 
             ORDER BY i.id DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

