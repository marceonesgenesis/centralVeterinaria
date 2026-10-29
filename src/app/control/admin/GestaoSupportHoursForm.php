<?php

declare(strict_types=1);

use CentralVet\Backoffice\BackofficeSupportService;

/**
 * GestaoSupportHoursForm — Gestão de Horários de Atendimento do Suporte
 */
class GestaoSupportHoursForm extends TPage
{
    private BackofficeSupportService $service;

    public function __construct()
    {
        parent::__construct();

        if (!TSession::getValue('cv_backoffice')) {
            throw new Exception('Acesso negado: área restrita à equipe de gestão.');
        }

        $this->service = new BackofficeSupportService();

        $container = new TVBox();
        $container->style = 'width: 100%';

        $header = new TLabel('<div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-0">Horários de Atendimento do Suporte</h3>
                <small class="text-muted">Grade semanal, plantões de sábado e canais de contato sincronizados com o app das clínicas</small>
            </div>
        </div>');

        $schedule = $this->service->getSchedule();
        $config = $this->service->getConfig();

        $rows = '';
        foreach ($schedule as $s) {
            $status = $s['is_open'] ? ($s['is_on_call'] ? '<span class="badge bg-info text-dark">Plantão</span>' : '<span class="badge bg-success">Aberto</span>') : '<span class="badge bg-secondary">Fechado</span>';
            $hours = $s['is_open'] ? substr((string)$s['open_time'], 0, 5) . ' às ' . substr((string)$s['close_time'], 0, 5) : 'Sem expediente';
            $day = htmlspecialchars((string)$s['day_name']);
            $notes = htmlspecialchars((string)($s['notes'] ?? ''));

            $rows .= "
                <tr>
                    <td><strong>{$day}</strong></td>
                    <td>{$hours}</td>
                    <td>{$status}</td>
                    <td><small class='text-muted'>{$notes}</small></td>
                </tr>
            ";
        }

        $supportEmail = htmlspecialchars((string)$config['support_email']);
        $supportWhatsapp = htmlspecialchars((string)$config['support_whatsapp']);
        $outOfHours = htmlspecialchars((string)$config['out_of_hours_message']);

        $content = new TLabel("
            <div class='row g-4'>
                <div class='col-lg-7'>
                    <div class='card border-0 shadow-sm p-4'>
                        <h5 class='fw-bold mb-3'>Grade Semanal de Expediente</h5>
                        <div class='table-responsive'>
                            <table class='table align-middle'>
                                <thead class='table-light'>
                                    <tr>
                                        <th>Dia da Semana</th>
                                        <th>Horário</th>
                                        <th>Status</th>
                                        <th>Observação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {$rows}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class='col-lg-5'>
                    <div class='card border-0 shadow-sm p-4'>
                        <h5 class='fw-bold mb-3'>Canais e Mensagem Automática</h5>
                        <form method='post' action='gestao.php?class=GestaoSupportHoursForm&method=onSaveConfig'>
                            <div class='mb-3'>
                                <label class='form-label' for='email'>E-mail de Suporte</label>
                                <input type='email' class='form-control' name='email' id='email' value='{$supportEmail}' required>
                            </div>
                            <div class='mb-3'>
                                <label class='form-label' for='whatsapp'>WhatsApp de Suporte</label>
                                <input type='text' class='form-control' name='whatsapp' id='whatsapp' value='{$supportWhatsapp}' required>
                            </div>
                            <div class='mb-3'>
                                <label class='form-label' for='out_of_hours'>Mensagem Fora do Expediente</label>
                                <textarea class='form-control' name='out_of_hours' id='out_of_hours' rows='4' required>{$outOfHours}</textarea>
                            </div>
                            <button type='submit' class='btn btn-primary cv-touch-target w-100'>Salvar Configurações</button>
                        </form>
                    </div>
                </div>
            </div>
        ");

        $container->add($header);
        $container->add($content);

        parent::add($container);
    }

    public static function onSaveConfig(): void
    {
        try {
            $email = (string)($_POST['email'] ?? 'suporte@centralvet.com.br');
            $whatsapp = (string)($_POST['whatsapp'] ?? '');
            $message = (string)($_POST['out_of_hours'] ?? '');

            $service = new BackofficeSupportService();
            $service->updateConfig($email, $whatsapp, $message);

            new TMessage('info', 'Horários e canais de suporte atualizados com sucesso.');
            AdiantiCoreApplication::gotoPage('GestaoSupportHoursForm');
        } catch (Exception $e) {
            new TMessage('error', $e->getMessage());
        }
    }
}

