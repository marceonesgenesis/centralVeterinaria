<?php

declare(strict_types=1);

namespace CentralVet\Backoffice;

use PDO;
use CentralVet\Persistence\PdoConnectionFactory;
use TSession;

/**
 * Trilha de auditoria indelével da Gestão da Plataforma.
 * Grava toda ação mutável com autor, IP, antes e depois.
 */
class BackofficeAuditService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? PdoConnectionFactory::create();
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $extra
     */
    public function log(
        string $action,
        string $targetType,
        string $targetId,
        array $before = [],
        array $after = [],
        array $extra = []
    ): void {
        $userId = TSession::getValue('userid') ? (int)TSession::getValue('userid') : null;
        $userEmail = (string)(TSession::getValue('usermail') ?: TSession::getValue('login') ?: 'sistema');
        $userRole = (string)(TSession::getValue('cv_backoffice_role') ?: 'admin');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $details = [
            'before' => $before,
            'after' => $after,
            'extra' => $extra,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO backoffice_audit_log 
             (user_id, user_email, user_role, ip_address, action, target_type, target_id, details_json, created_at)
             VALUES (:user_id, :user_email, :user_role, :ip_address, :action, :target_type, :target_id, :details_json, NOW(6))'
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':user_email' => $userEmail,
            ':user_role' => $userRole,
            ':ip_address' => $ip,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, user_email, user_role, ip_address, action, target_type, target_id, details_json, created_at 
             FROM backoffice_audit_log 
             ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

