<?php

declare(strict_types=1);

use CentralVet\Backoffice\BackofficeInviteService;

/**
 * GestaoInviteList — Gestão de Convites com Bônus de Degustação (15 Dias)
 */
class GestaoInviteList extends TPage
{
    private BackofficeInviteService $service;

    public function __construct()
    {
        parent::__construct();

        if (!TSession::getValue('cv_backoffice')) {
            throw new Exception('Acesso negado: área restrita à equipe de gestão.');
        }

        $this->service = new BackofficeInviteService();

        $container = new TVBox();
        $container->style = 'width: 100%';

        $header = new TLabel('<div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-0">Convites com Bônus (Trial de 15 Dias)</h3>
                <small class="text-muted">Envie acesso gratuito sem cartão de crédito antecipado para prospecção de clínicas</small>
            </div>
        </div>');

        // Formulário rápido de emissão de convite
        $formCard = new TLabel('
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="fw-bold mb-3">Emitir Novo Convite de Degustação</h5>
                <form method="post" action="gestao.php?class=GestaoInviteList&method=onCreateInvite">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="clinic_name">Nome da Clínica</label>
                            <input type="text" class="form-control" name="clinic_name" id="clinic_name" placeholder="Ex: Clínica Veterinária São Lázaro" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="email">E-mail do Administrador</label>
                            <input type="email" class="form-control" name="email" id="email" placeholder="admin@saolazaro.vet" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="trial_days">Dias de Bônus</label>
                            <input type="number" class="form-control" name="trial_days" id="trial_days" value="15" min="1" max="60" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="plan_id">Plano Liberado</label>
                            <select class="form-select" name="plan_id" id="plan_id">
                                <option value="pro" selected>Pro (Recomendado)</option>
                                <option value="starter">Starter</option>
                                <option value="business">Business</option>
                                <option value="enterprise">Enterprise</option>
                            </select>
                        </div>
                    </div>
                    <div class="text-end mt-3">
                        <button type="submit" class="btn btn-primary cv-touch-target">Gerar e Enviar Convite por E-mail</button>
                    </div>
                </form>
            </div>
        ');

        // Tabela de convites recentes
        $invites = $this->service->listInvites(50);
        $rows = '';

        if (empty($invites)) {
            $rows = '<tr><td colspan="6" class="text-center text-muted py-4">Nenhum convite emitido até o momento.</td></tr>';
        } else {
            foreach ($invites as $inv) {
                $statusBadge = match ($inv['status']) {
                    'pending' => '<span class="badge bg-secondary">Aguardando Aceite</span>',
                    'in_trial' => '<span class="badge bg-warning text-dark">Em Degustação (15d)</span>',
                    'converted' => '<span class="badge bg-success">Convertido em Assinante!</span>',
                    'expired' => '<span class="badge bg-danger">Expirado</span>',
                    'cancelled' => '<span class="badge bg-dark">Cancelado</span>',
                    default => '<span class="badge bg-light text-dark">' . htmlspecialchars((string)$inv['status']) . '</span>',
                };

                $expiresFormatted = date('d/m/Y H:i', strtotime((string)$inv['expires_at']));
                $actions = '';
                $invId = (int)$inv['id'];

                if ($inv['status'] === 'pending' || $inv['status'] === 'expired') {
                    $actions .= "<a href='gestao.php?class=GestaoInviteList&method=onResend&id={$invId}' class='btn btn-sm btn-outline-primary cv-touch-target me-1'>Reenviar</a>";
                    $actions .= "<a href='gestao.php?class=GestaoInviteList&method=onCancel&id={$invId}' class='btn btn-sm btn-outline-danger cv-touch-target'>Cancelar</a>";
                }

                $clinic = htmlspecialchars((string)$inv['clinic_name']);
                $email = htmlspecialchars((string)$inv['email']);
                $plan = strtoupper((string)$inv['plan_id']);
                $days = (int)$inv['trial_days'];

                $rows .= "
                    <tr>
                        <td><strong>{$clinic}</strong><br><small class='text-muted'>{$email}</small></td>
                        <td>{$plan}</td>
                        <td>{$days} dias</td>
                        <td>{$statusBadge}</td>
                        <td>{$expiresFormatted}</td>
                        <td class='text-end'>{$actions}</td>
                    </tr>
                ";
            }
        }

        $tableHtml = new TLabel("
            <div class='card border-0 shadow-sm p-4'>
                <h5 class='fw-bold mb-3'>Histórico de Convites Emitidos</h5>
                <div class='table-responsive'>
                    <table class='table align-middle'>
                        <thead class='table-light'>
                            <tr>
                                <th>Clínica / Destinatário</th>
                                <th>Plano</th>
                                <th>Período Grátis</th>
                                <th>Status</th>
                                <th>Validade do Link</th>
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
        $container->add($formCard);
        $container->add($tableHtml);

        parent::add($container);
    }

    public static function onCreateInvite(): void
    {
        try {
            $clinicName = (string)($_POST['clinic_name'] ?? '');
            $email = (string)($_POST['email'] ?? '');
            $trialDays = (int)($_POST['trial_days'] ?? 15);
            $planId = (string)($_POST['plan_id'] ?? 'pro');

            $service = new BackofficeInviteService();
            $service->createInvite($email, $clinicName, $planId, $trialDays);

            new TMessage('info', "Convite criado e enviado para {$email} com sucesso!");
            AdiantiCoreApplication::gotoPage('GestaoInviteList');
        } catch (Exception $e) {
            new TMessage('error', $e->getMessage());
        }
    }

    public static function onResend($param): void
    {
        try {
            $id = (int)($param['id'] ?? 0);
            $service = new BackofficeInviteService();
            $service->resendInvite($id);
            new TMessage('info', 'Convite reenviado com nova validade de 7 dias com sucesso.');
            AdiantiCoreApplication::gotoPage('GestaoInviteList');
        } catch (Exception $e) {
            new TMessage('error', $e->getMessage());
        }
    }

    public static function onCancel($param): void
    {
        try {
            $id = (int)($param['id'] ?? 0);
            $service = new BackofficeInviteService();
            $service->cancelInvite($id);
            new TMessage('info', 'Convite cancelado.');
            AdiantiCoreApplication::gotoPage('GestaoInviteList');
        } catch (Exception $e) {
            new TMessage('error', $e->getMessage());
        }
    }
}

