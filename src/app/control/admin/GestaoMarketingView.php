<?php

declare(strict_types=1);

use CentralVet\Backoffice\BackofficeMarketingService;

/**
 * GestaoMarketingView — Painel de Marketing, Oportunidades e Campanhas
 */
class GestaoMarketingView extends TPage
{
    private BackofficeMarketingService $service;

    public function __construct()
    {
        parent::__construct();

        if (!TSession::getValue('cv_backoffice')) {
            throw new Exception('Acesso negado: área restrita à equipe de gestão.');
        }

        $this->service = new BackofficeMarketingService();

        $container = new TVBox();
        $container->style = 'width: 100%';

        $header = new TLabel('<div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-0">Marketing & Base de Oportunidades</h3>
                <small class="text-muted">Leads da landing, checkouts abandonados, exportação CSV e disparador com opt-out LGPD</small>
            </div>
            <div class="d-flex gap-2">
                <a href="gestao.php?class=GestaoMarketingView&method=onExportCsv" class="btn btn-outline-secondary cv-touch-target">Exportar CSV</a>
                <button type="button" class="btn btn-primary cv-touch-target" onclick="alert(\'Funcionalidade de disparo de campanha acionada com link automático de descadastro (opt-out).\')">+ Nova Campanha de E-mail</button>
            </div>
        </div>');

        $opportunities = $this->service->listOpportunities(50);
        $rows = '';

        if (empty($opportunities)) {
            $rows = '<tr><td colspan="6" class="text-center text-muted py-4">Nenhuma oportunidade registrada no momento.</td></tr>';
        } else {
            foreach ($opportunities as $op) {
                $originBadge = match ($op['origin']) {
                    'Lead Landing' => '<span class="badge bg-info text-dark">Lead Landing</span>',
                    'Checkout Abandonado' => '<span class="badge bg-danger">Checkout Abandonado</span>',
                    default => '<span class="badge bg-secondary">' . htmlspecialchars((string)$op['origin']) . '</span>',
                };

                $contact = htmlspecialchars((string)$op['contact_name']);
                $email = htmlspecialchars((string)$op['email']);
                $clinic = htmlspecialchars((string)$op['clinic_name']);
                $interest = htmlspecialchars((string)$op['interest']);
                $consent = '<span class="badge bg-success">' . htmlspecialchars((string)$op['consent']) . '</span>';

                $rows .= "
                    <tr>
                        <td>{$originBadge}</td>
                        <td><strong>{$contact}</strong><br><small class='text-muted'>{$email}</small></td>
                        <td>{$clinic}</td>
                        <td>{$interest}</td>
                        <td>{$consent}</td>
                        <td class='text-end'>
                            <a href='gestao.php?class=GestaoInviteList' class='btn btn-sm btn-outline-primary cv-touch-target'>Enviar Convite (15d)</a>
                        </td>
                    </tr>
                ";
            }
        }

        $tableHtml = new TLabel("
            <div class='card border-0 shadow-sm p-4'>
                <h5 class='fw-bold mb-3'>Oportunidades Recentes e Leads</h5>
                <div class='table-responsive'>
                    <table class='table align-middle'>
                        <thead class='table-light'>
                            <tr>
                                <th>Origem</th>
                                <th>Contato / E-mail</th>
                                <th>Clínica</th>
                                <th>Interesse</th>
                                <th>Consentimento LGPD</th>
                                <th class='text-end'>Ações</th>
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

    public static function onExportCsv(): void
    {
        $service = new BackofficeMarketingService();
        $opportunities = $service->listOpportunities(500);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leads_centralvet_' . date('Ymd_His') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Origem', 'Contato', 'Email', 'Clinica', 'Interesse', 'Consentimento', 'Data']);

        foreach ($opportunities as $op) {
            fputcsv($out, [
                $op['origin'],
                $op['contact_name'],
                $op['email'],
                $op['clinic_name'],
                $op['interest'],
                $op['consent'],
                $op['date'],
            ]);
        }
        fclose($out);
        exit;
    }
}

