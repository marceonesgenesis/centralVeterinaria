<?php
/**
 * BedForm — cadastro e edição de leito da unidade ativa (Fase 6A, T-12).
 *
 * Campos: código, nome e diária (texto em reais, convertido por
 * MoneyInput::toCents). Sem regra própria: onSave() chama
 * CentralVet\Application\BedService::create() (sem id; a unidade é a da
 * sessão) ou ::update() (com id). Na edição (`&id=<bed_id>`) o código fica só
 * leitura — o service não troca código de leito existente.
 *
 * Rotas: index.php?class=BedForm (novo) e index.php?class=BedForm&id=<id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class BedForm extends TPage
{
    protected $form;
    private bool $loaded = false;
    private ?int $bedId = null;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->bedId = self::paramId($param);

        $this->form = new BootstrapFormBuilder('form_Bed');
        $this->form->enableClientValidation();

        $id         = new THidden('id');
        $code       = new TEntry('code');
        $name       = new TEntry('name');
        $daily_rate = new TEntry('daily_rate');

        $code->setMaxLength(30);
        $name->setMaxLength(120);
        // digitação livre ("100,00", "1.250,5"): MoneyInput::toCents() no
        // servidor converte ou recusa ("Invalid amount"), sem máscara no cliente.
        $daily_rate->setProperty('placeholder', '0,00');
        $daily_rate->setProperty('inputmode', 'decimal');
        $daily_rate->setMaxLength(20);

        if ($this->bedId !== null)
        {
            $code->setEditable(false);
        }

        CvForm::decorate($this->form, 2);

        $this->form->addFields( [new TLabel(_t('Code'))], [$code], [new TLabel(_t('Name'))], [$name] );
        $this->form->addFields( [new TLabel(_t('Daily rate'))], [$daily_rate] );

        $hidden_row = $this->form->addFields( [$id] );
        $hidden_row->style = 'display: none';

        $code->addValidation( _t('Code'), new TRequiredValidator );
        $name->addValidation( _t('Name'), new TRequiredValidator );
        $daily_rate->addValidation( _t('Daily rate'), new TRequiredValidator );

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary cv-touch-target';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($this->bedId !== null ? _t('Edit bed') : _t('New bed'), _t('Hospitalization'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=BedList'],
        ]));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Cria (sem id) ou edita nome/diária (com id) pela BedService.
     */
    public function onSave($param = null)
    {
        $this->loaded = true;

        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            // antes da transação: valor inválido lança Invalid amount e nada é gravado.
            $daily_rate_cents = \CentralVet\Presentation\MoneyInput::toCents(
                (string) $data->daily_rate,
                false,
                \CentralVet\Presentation\MoneyInput::MAX_UNSIGNED_INT_CENTS
            );

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeBedService($context);

            if (!empty($data->id))
            {
                $bed = $service->update((int) $data->id, (string) $data->name, $daily_rate_cents, 'BedForm::onSave');
            }
            else
            {
                $bed = $service->create((string) $data->code, (string) $data->name, $daily_rate_cents, 'BedForm::onSave');
            }

            TTransaction::close();

            $data->id = $bed->id();
            $this->form->setData($data);

            TToast::show('success', _t('Record saved'));
            TScript::create("__adianti_goto_page('index.php?class=BedList')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage beds'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Carrega o leito pela BedService::get() (escopada ao tenant e
     * autorizada contra a unidade do leito). Sem id, formulário vazio.
     */
    public function onEdit($param = null)
    {
        $this->loaded = true;

        $bed_id = self::paramId($param) ?? $this->bedId;

        if ($bed_id === null)
        {
            $this->form->clear(true);
            return;
        }

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $bed = self::makeBedService($context)->get($bed_id, 'BedForm::onEdit');

            TTransaction::close();

            $data = new stdClass;
            $data->id         = $bed->id();
            $data->code       = $bed->code();
            $data->name       = $bed->name();
            $data->daily_rate = number_format($bed->dailyRateCents() / 100, 2, ',', '.');

            $this->form->setData($data);
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage beds'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * `index.php?class=BedForm&id=<id>` (sem method) carrega o leito aqui.
     */
    public function show()
    {
        if (!$this->loaded && $this->bedId !== null)
        {
            $this->onEdit(['id' => $this->bedId]);
        }

        parent::show();
    }

    /**
     * id do leito a partir de `id` ou `key`; null quando ausente/inválido.
     */
    private static function paramId($param): ?int
    {
        if (!is_array($param))
        {
            return null;
        }

        $raw = $param['id'] ?? ($param['key'] ?? null);

        if ($raw === null || $raw === '' || !ctype_digit((string) $raw) || (int) $raw <= 0)
        {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function makeBedService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\BedService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\BedService(
            new \CentralVet\Persistence\BedRepository($context, $connection),
            new \CentralVet\Authorization\RbacAuthorizationService(
                new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
                new \CentralVet\Audit\PdoAuditLogWriter($connection),
            ),
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session, with the
     * tenant_user fallback used by the other clinic screens.
     */
    private static function resolveTenantContext(): \CentralVet\Tenancy\TenantContext
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
