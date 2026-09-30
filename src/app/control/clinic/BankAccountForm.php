<?php
/**
 * BankAccountForm
 *
 * Cadastro e edição de conta bancária da unidade da sessão (rodada 2,
 * T-22): nome, banco, saldo (moeda, aceita negativo) e situação. Sem regra
 * própria: onSave() chama CentralVet\Application\BankAccountService::create()
 * (sem id, com 'system_unit_id' da sessão) ou ::update() (com id), e o
 * serviço (T-15) valida nome, saldo e a unidade corrente. onEdit() de id
 * inexistente, de outro tenant ou de outra unidade mostra _t('Record not
 * found').
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class BankAccountForm extends TPage
{
    protected $form;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->form = new BootstrapFormBuilder('form_BankAccount');
        $this->form->enableClientValidation();

        $id        = new THidden('id');
        $name      = new TEntry('name');
        $bank_name = new TEntry('bank_name');
        $balance   = new TEntry('balance');
        $active    = new TCombo('active');

        $name->setMaxLength(120);
        $bank_name->setMaxLength(120);
        $balance->setNumericMask(2, ',', '.', false, false, true);
        $active->addItems([1 => _t('Active'), 0 => _t('Inactive')]);
        $active->setDefaultOption(false);
        $active->setValue(1);

        CvForm::decorate($this->form, 2);

        $this->form->addFields( [new TLabel(_t('Name'))], [$name], [new TLabel(_t('Bank'))], [$bank_name] );
        $this->form->addFields( [new TLabel(_t('Balance'))], [$balance], [new TLabel(_t('Status'))], [$active] );

        $hidden_row = $this->form->addFields( [$id] );
        $hidden_row->style = 'display: none';

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $balance->addValidation( _t('Balance'), new TRequiredValidator );

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Bank account'), _t('Financial'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=BankAccountList'],
        ]));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Cria (sem id) ou edita (com id) pela BankAccountService.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildService($tenant_context);

            $input = [
                'name'          => (string) $data->name,
                'bank_name'     => trim((string) $data->bank_name) !== '' ? trim((string) $data->bank_name) : null,
                'balance_cents' => self::toCents($data->balance),
            ];
            $is_active = ((string) $data->active) !== '0';

            if (!empty($data->id))
            {
                $account = $service->update((int) $data->id, $input + ['active' => $is_active]);
            }
            else
            {
                $account = $service->create($input + ['system_unit_id' => $tenant_context->requireUnitId()]);

                if (!$is_active)
                {
                    $account = $service->update((int) $account->id(), ['active' => false]);
                }
            }

            $data->id = $account->id();
            $this->form->setData($data);

            TTransaction::close();

            TToast::show('info', _t('Record saved'));
            AdiantiCoreApplication::loadPage('BankAccountList');

            return $data;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (Exception $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Carrega a conta pela BankAccountService::findById() (escopada ao
     * tenant e à unidade corrente). Sem key, formulário vazio (Novo).
     */
    public function onEdit($param)
    {
        try
        {
            $key = $param['key'] ?? ($param['id'] ?? null);

            if ($key === null || $key === '')
            {
                $this->form->clear(true);
                $this->form->setData((object) ['active' => 1]);
                return;
            }

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $account = self::buildService($tenant_context)->findById((int) $key);

            TTransaction::close();

            if (!$account instanceof \CentralVet\Domain\BankAccount)
            {
                $this->form->clear(true);
                new TMessage('error', _t('Record not found'));
                return;
            }

            $data = new stdClass;
            $data->id        = $account->id();
            $data->name      = $account->name();
            $data->bank_name = $account->bankName();
            $data->balance   = number_format($account->balanceCents() / 100, 2, ',', '.');
            $data->active    = $account->isActive() ? 1 : 0;

            $this->form->setData($data);

            return $data;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * "1.234,56" / "-50,00" → centavos inteiros (sinal preservado).
     */
    private static function toCents($amount): int
    {
        $normalized = str_replace('.', '', trim((string) $amount));
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\BankAccountService
    {
        return new \CentralVet\Application\BankAccountService(
            new \CentralVet\Persistence\BankAccountRepository($context, TTransaction::get()),
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session, with the
     * tenant_user fallback used by the other clinic screens.
     */
    private static function resolveTenantContext()
    {
        $source = new \CentralVet\Tenancy\AdiantiSessionContextSource();

        try
        {
            return \CentralVet\Tenancy\TenantContext::fromAuthenticatedSession($source);
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $userid = TSession::getValue('userid');

            if (TSession::getValue('logged') !== true || empty($userid))
            {
                throw $e;
            }

            TTransaction::open('permission');
            $stmt = TTransaction::get()->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
            $stmt->execute(['userid' => (int) $userid]);
            $tenant_id = $stmt->fetchColumn();
            TTransaction::close();

            if (empty($tenant_id))
            {
                throw $e;
            }

            $unit_id = TSession::getValue('userunitid');

            return \CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}
