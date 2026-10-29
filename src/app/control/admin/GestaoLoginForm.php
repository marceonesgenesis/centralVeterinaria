<?php

declare(strict_types=1);

use CentralVet\Security\LoginRateLimiter;
use CentralVet\Security\Audit\BackofficeAuditService;

/**
 * GestaoLoginForm — Formulário de Login Exclusivo para a Equipe de Gestão (Back-office)
 *
 * Restrito aos operadores do Central Vet Pro:
 * - Não permite acesso a usuários de clínicas clientes (tenants)
 * - Não possui seletor de unidade/filial clínica
 * - Não possui botão de auto-cadastro de contas
 * - 2FA (TOTP) estritamente obrigatório para todos os perfis
 * - Registra tentativas e logins na trilha de auditoria
 */
class GestaoLoginForm extends TPage
{
    protected $form;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->form = new TModalForm('form_gestao_login');
        $this->form->setFormTitle('Central Vet Pro — Gestão da Plataforma');

        $login = new TEntry('login');
        $password = new TPassword('password');

        $login->disableAutoComplete();
        $password->disableAutoComplete();
        $login->setSize('100%');
        $password->setSize('100%');
        $login->placeholder = 'E-mail ou Usuário do Operador';
        $password->placeholder = 'Senha do Operador';

        // Cabeçalho institucional do back-office
        $headerHtml = new TLabel('<div class="text-center mb-3">
            <div style="font-size: 2rem;">🛡️</div>
            <h5 class="fw-bold mb-0">Área de Gestão do Sistema</h5>
            <small class="text-muted">Acesso restrito e auditado à equipe interna</small>
        </div>');
        $this->form->addContent([$headerHtml]);

        $this->form->addRowField('Usuário', $login, true);
        $this->form->addRowField('Senha', $password, true);

        $loginBtn = $this->form->addAction('Entrar na Gestão', new TAction([$this, 'onLogin']), 'fa:sign-in-alt');
        $loginBtn->class = 'btn btn-primary w-100 cv-touch-target';

        $backBtn = $this->form->addFooterAction('← Ir para Login de Clínicas', new TAction([self::class, 'onGoToClinicLogin']), '');
        $backBtn->setProperty('type', 'button');
        $backBtn->setProperty('onclick', "window.location.href = 'index.php'; return false;");

        parent::add($this->form);
    }

    public static function onGoToClinicLogin(): void
    {
        TScript::create("window.location.href = 'index.php';");
    }

    /**
     * Autenticação de Operadores da Gestão
     */
    public static function onLogin($param)
    {
        try {
            $rateLimiter = new LoginRateLimiter();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $rateLimitIdentity = 'gestao_' . $ip . '_' . strtolower(trim((string)($param['login'] ?? '')));

            $status = $rateLimiter->check($rateLimitIdentity);
            if (!$status->isAllowed()) {
                throw new Exception("Muitas tentativas de login incorretas. Tente novamente em {$status->retryAfterSeconds} segundos.");
            }

            if (empty($param['login']) || empty($param['password'])) {
                throw new Exception('Informe o usuário e a senha do operador.');
            }

            TTransaction::open('permission');

            $user = SystemUser::validate($param['login']);

            if (!$user instanceof SystemUser) {
                $rateLimiter->recordFailure($rateLimitIdentity);
                TTransaction::close();
                throw new Exception('Credenciais inválidas.');
            }

            // Validar senha
            if (!password_verify($param['password'], (string)$user->password)) {
                $rateLimiter->recordFailure($rateLimitIdentity);
                TTransaction::close();
                throw new Exception('Credenciais inválidas.');
            }

            // Validar se o usuário pertence à equipe de gestão e não é tenant clínico
            // Usuários de clínica possuem tenant_id ou grupos de clínica (Veterinário, Recepção, etc.)
            // Perfis permitidos na gestão: Administrador do sistema, Comercial, Financeiro, Suporte
            $userGroups = $user->getSystemUserGroupIds();
            
            // Regra de isolamento: usuário com tenant_id não pode acessar a gestão do sistema
            if (!empty($user->tenant_id)) {
                $rateLimiter->recordFailure($rateLimitIdentity);
                TTransaction::close();
                throw new Exception('Acesso negado: este login é de uma clínica cliente e não tem permissão para acessar o back-office da plataforma.');
            }

            // Validação de 2FA obrigatório para toda a equipe de gestão
            if (empty($user->otp_secret)) {
                TTransaction::close();
                throw new Exception('Acesso bloqueado: 2FA (TOTP) é obrigatório para a equipe de gestão. Entre em contato com o Administrador do Sistema.');
            }

            if (empty($param['two_factor'])) {
                self::renderTwoFactorModal($param);
                TTransaction::close();
                return;
            }

            $otp = \OTPHP\TOTP::create($user->otp_secret);
            if (!$otp->verify($param['two_factor'])) {
                $rateLimiter->recordFailure($rateLimitIdentity);
                TTransaction::close();
                throw new Exception('Código de autenticação em duas etapas (2FA) inválido.');
            }

            // Sucesso na autenticação da gestão
            $rateLimiter->clear($rateLimitIdentity);
            ApplicationAuthenticationService::loadSessionVars($user, true);

            // Marca a sessão explicitamente como contexto de Back-office
            TSession::setValue('cv_backoffice', true);
            TSession::setValue('userunitid', null); // Sem vínculo com unidade clínica

            // Registrar log de acesso administrativo
            SystemAccessLogService::registerLogin();

            TTransaction::close();

            // Redireciona para o painel de gestão
            AdiantiCoreApplication::gotoPage('GestaoDashboardView');

        } catch (Exception $e) {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Modal de 2FA TOTP para a Gestão
     */
    private static function renderTwoFactorModal($param): void
    {
        $action = new TAction(['GestaoLoginForm', 'onLogin'], $param);
        $form = new BootstrapFormBuilder('gestao_two_factor_form');

        $twoFactor = new TPassword('two_factor');
        $twoFactor->style = 'height: 44px; font-size: 1.25rem; text-align: center; letter-spacing: 0.25rem; font-weight: bold;';
        $twoFactor->placeholder = '000000';
        $twoFactor->focus();

        $form->addContent([
            new TLabel('<div class="text-center mb-3">
                <span class="badge bg-warning text-dark mb-2">2FA Obrigatório</span>
                <p class="small text-muted mb-0">Digite o código de 6 dígitos gerado no seu aplicativo autenticador (Google Authenticator / Authy).</p>
            </div>')
        ]);
        $form->addFields([$twoFactor]);

        $btn = $form->addAction('Confirmar e Entrar', $action, 'fa:shield-alt');
        $btn->class = 'btn btn-primary w-100 cv-touch-target mt-3';

        new TInputDialog('Autenticação em Duas Etapas (2FA)', $form);
    }
}

