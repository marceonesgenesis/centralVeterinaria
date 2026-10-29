<?php

declare(strict_types=1);

use CentralVet\Backoffice\BackofficeAuditService;

/**
 * GestaoAuditLogList — Trilha de Auditoria Indelével da Gestão da Plataforma
 */
class GestaoAuditLogList extends TPage
{
    private BackofficeAuditService $service;

    public function __construct()
    {
        parent::__construct();

        if (!TSession::getValue('cv_backoffice')) {
            throw new Exception('Acesso negado: área restrita à equipe de gestão.');
        }

        $this->service = new BackofficeAuditService();

        $container = new TVBox();
        $container->style = 'width: 100%';

        $header = new TLabel('<div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-0">Trilha de Auditoria da Gestão</h3>
                <small class="text-muted">Registro auditável de todas as mutações no back-office com operador, IP e diff de dados</small>
            </div>
        </div>');

        $logs = $this->service->listRecent(50);
        $rows = '';

        if (empty($logs)) {
            $rows = '<tr><td colspan="6" class="text-center text-muted py-4">Nenhum registro de auditoria encontrado.</td></tr>';
        } else {
            foreach ($logs as $l) {
                $dateFormatted = date('d/m/Y H:i:s', strtotime((string)$l['created_at']));
                $actionBadge = '<span class="badge bg-secondary">' . htmlspecialchars((string)$l['action']) . '</span>';
                $user = htmlspecialchars((string)$l['user_email']);
                $ip = htmlspecialchars((string)$l['ip_address']);
                $target = htmlspecialchars((string)($l['target_type'] . ' #' . $l['target_id']));
                $details = htmlspecialchars((string)$l['details_json']);

                $rows .= "
                    <tr>
                        <td>{$dateFormatted}</td>
                        <td><strong>{$user}</strong></td>
                        <td><code>{$ip}</code></td>
                        <td>{$actionBadge}</td>
                        <td>{$target}</td>
                        <td><pre class='mb-0 small' style='max-height: 80px; overflow-y: auto;'>{$details}</pre></td>
                    </tr>
                ";
            }
        }

        $tableHtml = new TLabel("
            <div class='card border-0 shadow-sm p-4'>
                <h5 class='fw-bold mb-3'>Últimas Mutações Realizadas</h5>
                <div class='table-responsive'>
                    <table class='table align-middle'>
                        <thead class='table-light'>
                            <tr>
                                <th>Data / Hora</th>
                                <th>Operador</th>
                                <th>IP</th>
                                <th>Ação</th>
                                <th>Alvo</th>
                                <th>Detalhes (Diff)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$rows}
                        </tbody>
                    </table>
                </div>
            </div>
        ");

        $container->add($header);
        $container->add($tableHtml);

        parent::add($container);
    }
}

