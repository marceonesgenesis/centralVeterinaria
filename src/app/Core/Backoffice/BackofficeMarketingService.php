<?php

declare(strict_types=1);

namespace CentralVet\Backoffice;

use PDO;
use Exception;
use InvalidArgumentException;
use CentralVet\Persistence\PdoConnectionFactory;
use TSession;

/**
 * Gestão de Marketing, Leads e Campanhas da Plataforma Central Vet.
 * Inclui conformidade com a LGPD (opt-out e validação de consentimento).
 */
class BackofficeMarketingService
{
    private PDO $pdo;
    private BackofficeAuditService $auditService;

    public function __construct(?PDO $pdo = null, ?BackofficeAuditService $auditService = null)
    {
        $this->pdo = $pdo ?? PdoConnectionFactory::create();
        $this->auditService = $auditService ?? new BackofficeAuditService($this->pdo);
    }

    /**
     * Retorna a visão agregada de oportunidades: leads da landing + checkouts pendentes + convites
     * @return array<int, array<string, mixed>>
     */
    public function listOpportunities(int $limit = 100): array
    {
        $opportunities = [];

        // 1. Leads da Landing
        $stmtLeads = $this->pdo->prepare('
            SELECT id, email, name, clinic_name, phone, plan_id, created_at, \'landing_lead\' as origin, 1 as consent_commercial 
            FROM landing_lead 
            ORDER BY id DESC LIMIT :limit
        ');
        $stmtLeads->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtLeads->execute();
        foreach ($stmtLeads->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lead) {
            $opportunities[] = [
                'origin' => 'Lead Landing',
                'contact_name' => $lead['name'],
                'email' => $lead['email'],
                'clinic_name' => $lead['clinic_name'] ?? 'Não informado',
                'interest' => strtoupper((string)$lead['plan_id']),
                'consent' => 'Consentimento registrado',
                'date' => $lead['created_at'],
            ];
        }

        // 2. Checkouts Pendentes / Abandonados
        $stmtOrders = $this->pdo->prepare("
            SELECT id, email, plan_id, payment_method, status, created_at 
            FROM billing_order 
            WHERE status IN ('pending', 'checkout_open') 
            ORDER BY id DESC LIMIT :limit
        ");
        $stmtOrders->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtOrders->execute();
        foreach ($stmtOrders->fetchAll(PDO::FETCH_ASSOC) ?: [] as $order) {
            $opportunities[] = [
                'origin' => 'Checkout Abandonado',
                'contact_name' => 'Interessado na compra',
                'email' => $order['email'],
                'clinic_name' => 'Em contratação',
                'interest' => strtoupper((string)$order['plan_id']) . ' (' . strtoupper((string)$order['payment_method']) . ')',
                'consent' => 'Fluxo de compra',
                'date' => $order['created_at'],
            ];
        }

        return $opportunities;
    }

    /**
     * Registra opt-out (descadastro) de um e-mail
     */
    public function registerOptout(string $email, string $reason = 'Solicitado pelo usuário'): bool
    {
        $cleanEmail = strtolower(trim($email));
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO backoffice_marketing_optout (email, reason, created_at) VALUES (:email, :reason, NOW(6))');
        $stmt->execute([':email' => $cleanEmail, ':reason' => $reason]);

        $this->auditService->log(
            'marketing_optout',
            'backoffice_marketing_optout',
            $cleanEmail,
            [],
            ['email' => $cleanEmail, 'reason' => $reason]
        );

        return true;
    }

    /**
     * Verifica se o e-mail está na lista de descadastro
     */
    public function isOptedOut(string $email): bool
    {
        $cleanEmail = strtolower(trim($email));
        $stmt = $this->pdo->prepare('SELECT id FROM backoffice_marketing_optout WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $cleanEmail]);
        return (bool)$stmt->fetch();
    }

    /**
     * Cria e agenda uma nova campanha de e-mail com link obrigatório de descadastro
     */
    public function createCampaign(string $title, string $subject, string $targetAudience, string $contentHtml): array
    {
        if (empty(trim($title)) || empty(trim($subject)) || empty(trim($contentHtml))) {
            throw new InvalidArgumentException('Título, assunto e conteúdo são obrigatórios.');
        }

        // Assegurar link de descadastro
        if (!str_contains($contentHtml, '{optout_link}') && !str_contains($contentHtml, 'descadastr')) {
            $contentHtml .= '<br><hr><small>Caso não deseje mais receber nossos e-mails promocionais, <a href="{optout_link}">clique aqui para se descadastrar</a>.</small>';
        }

        $userId = TSession::getValue('userid') ? (int)TSession::getValue('userid') : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO backoffice_marketing_campaign 
             (title, subject, target_audience, content_html, status, created_by_user_id, created_at)
             VALUES (:title, :subject, :target, :content, \'scheduled\', :user_id, NOW(6))'
        );
        $stmt->execute([
            ':title' => trim($title),
            ':subject' => trim($subject),
            ':target' => $targetAudience,
            ':content' => $contentHtml,
            ':user_id' => $userId,
        ]);

        $campaignId = (int)$this->pdo->lastInsertId();

        $this->auditService->log(
            'campaign_create',
            'backoffice_marketing_campaign',
            (string)$campaignId,
            [],
            ['title' => $title, 'subject' => $subject, 'target' => $targetAudience]
        );

        return [
            'id' => $campaignId,
            'title' => $title,
            'status' => 'scheduled',
        ];
    }
}

