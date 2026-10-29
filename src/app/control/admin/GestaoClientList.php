<?php

declare(strict_types=1);

use CentralVet\Backoffice\BackofficeClientService;
use CentralVet\Backoffice\BackofficeProfile;

/**
 * GestaoClientList — Listagem e Gestão de Clientes e Licenças
 * Acesso exclusivo para a equipe de gestão.
 */
class GestaoClientList extends TPage
{
    private BackofficeClientService $service;

    public function __construct()
    {
        parent::__construct();

        if (!TSession::getValue('cv_backoffice')) {
            throw new Exception('Acesso negado: área restrita à equipe de gestão.');
        }

        $this->service = new BackofficeClientService();

        $container = new TVBox();
        $container->style = 'width: 100%';

        $header = new TLabel('<div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-0">Clientes & Licenças</h3>
                <small class="text-muted">Controle de clínicas ativas, planos, suspensões e prorrogações</small>
            </div>
            <div class="d-flex gap-2">
                <a href="gestao.php?class=GestaoInviteList" class="btn btn-outline-primary cv-touch-target">+ Novo Convite (15d)</a>
            </div>
        </div>');

        $clients = $this->service->listClients(100);

        $tableRows = '';
        if (empty($clients)) {
            $tableRows = '<tr><td colspan="7" class="text-center text-muted py-4">Nenhuma clínica encontrada.</td></tr>';
        } else {
            foreach ($clients as $client) {
                $tenantId = (int)$client['tenant_id'];
                $statusBadge = match ($client['tenant_status']) {
                    'active' => '<span class="badge bg-success">Ativa</span>',
                    'suspended' => '<span class="badge bg-danger">Suspensa</span>',
                    'cancelled' => '<span class="badge bg-secondary">Cancelada</span>',
                    default => '<span class="badge bg-warning text-dark">' . htmlspecialchars((string)$client['tenant_status']) . '</span>',
                };

                $planBadge = match ($client['plan_id'] ?? 'starter') {
                    'pro' => '<span class="badge bg-primary">Pro</span>',
                    'business' => '<span class="badge bg-info text-dark">Business</span>',
                    'enterprise' => '<span class="badge bg-dark">Enterprise</span>',
                    default => '<span class="badge bg-secondary">Starter</span>',
                };

                $periodEnd = $client['current_period_end'] ? date('d/m/Y', strtotime((string)$client['current_period_end'])) : 'Degustação / Sem fatura';

                $actions = '';
                if ($client['tenant_status'] === 'suspended') {
                    $actions .= "<a href='gestao.php?class=GestaoClientList&method=onReactivate&id={$tenantId}' class='btn btn-sm btn-success cv-touch-target me-1'>Reativar</a>";
                } else {
                    $actions .= "<a href='gestao.php?class=GestaoClientList&method=onSuspendPrompt&id={$tenantId}' class='btn btn-sm btn-outline-danger cv-touch-target me-1'>Suspender</a>";
                }
                $actions .= "<a href='gestao.php?class=GestaoClientList&method=onExtendPrompt&id={$tenantId}' class='btn btn-sm btn-outline-warning cv-touch-target me-1'>+15d Bônus</a>";
                $actions .= "<a href='gestao.php?class=GestaoClientList&method=onChangePlanPrompt&id={$tenantId}' class='btn btn-sm btn-outline-primary cv-touch-target'>Trocar Plano</a>";

                $legalName = htmlspecialchars((string)$client['legal_name']);
                $unitsCount = (int)$client['units_count'];
                $usersCount = (int)$client['users_count'];

                $tableRows .= "
                    <tr>
                        <td><strong>{$legalName}</strong><br><small class='text-muted'>ID: {$tenantId}</small></td>
                        <td>{$planBadge}</td>
                        <td>{$statusBadge}</td>
                        <td>{$unitsCount} un / {$usersCount} usu</td>
                        <td>{$periodEnd}</td>
                        <td class='text-end'>{$actions}</td>
                    </tr>
                ";
            }
        }

        $gridHtml = new TLabel("
            <div class='card border-0 shadow-sm p-4'>
                <div class='table-responsive'>
                    <table class='table align-middle'>
                        <thead class='table-light'>
                            <tr>
                                <th>Clínica Cliente</th>
                                <th>Plano</th>
                                <th>Status</th>
                                <th>Quotas Usadas</th>
                                <th>Vencimento / Renovação</th>
                                <th class='text-end'>Ações de Gestão</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$tableRows}
                        </tbody>
                    </table>
                </div>
            </div>
        ");

        $container->add($header);
        $container->add($gridHtml);

        parent::add($container);
    }

    public static function onSuspendPrompt($param): void
    {
        $id = (int)($param['id'] ?? 0);
        $action = new TAction([self::class, 'onConfirmSuspend'], ['id' => $id]);
        new TQuestion("Deseja realmente suspender o acesso desta clínica ao sistema?", $action);
    }

    public static function onConfirmSuspend($param): void
    {
        try {
            $id = (int)($param['id'] ?? 0);
            $service = new BackofficeClientService();
            $service->suspendClient($id, 'Suspensão manual realizada pelo operador de gestão');
            new TMessage('info', 'Acesso da clínica suspenso com sucesso.');
            AdiantiCoreApplication::gotoPage('GestaoClientList');
        } catch (Exception $e) {
            new TMessage('error', $e->getMessage());
        }
    }

    public static function onReactivate($param): void
    {
        try {
            $id = (int)($param['id'] ?? 0);
            $service = new BackofficeClientService();
            $service->reactivateClient($id);
            new TMessage('info', 'Clínica reativada com sucesso.');
            AdiantiCoreApplication::gotoPage('GestaoClientList');
        } catch (Exception $e) {
            new TMessage('error', $e->getMessage());
        }
    }

    public static function onExtendPrompt($param): void
    {
        try {
            $id = (int)($param['id'] ?? 0);
            $service = new BackofficeClientService();
            $service->extendTrial($id, 15, 'Prorrogação de degustação de 15 dias');
            new TMessage('info', 'Período de bônus prorrogado por mais 15 dias com sucesso.');
            AdiantiCoreApplication::gotoPage('GestaoClientList');
        } catch (Exception $e) {
            new TMessage('error', $e->getMessage());
        }
    }

    public static function onChangePlanPrompt($param): void
    {
        $id = (int)($param['id'] ?? 0);
        $form = new BootstrapFormBuilder('form_change_plan');
        $plan = new TCombo('new_plan');
        $plan->addItems([
            'starter' => 'Starter (R$ 149/mês)',
            'pro' => 'Pro (R$ 299/mês)',
            'business' => 'Business (R$ 499/mês)',
            'enterprise' => 'Enterprise (R$ 899/mês)',
        ]);
        $plan->setValue('pro');
        $form->addFields([new TLabel('Selecione o novo plano:')], [$plan]);
        
        $action = new TAction([self::class, 'onSaveChangePlan'], ['id' => $id]);
        $form->addAction('Salvar Alteração', $action, 'fa:check');
        new TInputDialog('Alteração de Plano', $form);
    }

    public static function onSaveChangePlan($param): void
    {
        try {
            $id = (int)($param['id'] ?? 0);
            $newPlan = (string)($param['new_plan'] ?? 'pro');
            $service = new BackofficeClientService();
            $service->changePlan($id, $newPlan);
            new TMessage('info', "Plano alterado para {$newPlan} com sucesso.");
            AdiantiCoreApplication::gotoPage('GestaoClientList');
        } catch (Exception $e) {
            new TMessage('error', $e->getMessage());
        }
    }
}

