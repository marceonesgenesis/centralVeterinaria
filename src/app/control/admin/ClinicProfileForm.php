<?php
/**
 * ClinicProfileForm — dados da clínica do primeiro acesso (Etapa 5, T-15).
 *
 * Edita razão social e nome fantasia do tenant da sessão e o nome da unidade
 * da sessão pelo CentralVet\Onboarding\ClinicProfileService (load/save); salvar
 * marca o item "Dados da clínica" dos primeiros passos e volta a
 * OnboardingChecklistView. As mensagens de validação do serviço
 * (DomainException) chegam ao usuário traduzidas.
 *
 * onSave é mutação (POST + X-CSRF-Token pela MutationRequestPolicy central);
 * onEdit só lê.
 *
 * @version    1.0
 * @package    control
 * @subpackage admin
 */
class ClinicProfileForm extends TPage
{
    protected $form;
    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->form = new BootstrapFormBuilder('form_ClinicProfile');
        $this->form->enableClientValidation();

        $legal_name = new TEntry('legal_name');
        $trade_name = new TEntry('trade_name');
        $unit_name  = new TEntry('unit_name');
        $legal_name->setMaxLength(255);
        $trade_name->setMaxLength(255);
        $unit_name->setMaxLength(120);

        CvForm::decorate($this->form, 1);

        $this->form->addFields( [new TLabel(_t('Legal name'))], [$legal_name] );
        $this->form->addFields( [new TLabel(_t('Trade name'))], [$trade_name] );
        $this->form->addFields( [new TLabel(_t('Unit name'))], [$unit_name] );

        $legal_name->addValidation( _t('Legal name'), new TRequiredValidator );
        $unit_name->addValidation( _t('Unit name'), new TRequiredValidator );

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary cv-touch-target';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Clinic data'), _t('First steps'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=OnboardingChecklistView', 'class' => 'btn btn-default cv-touch-target', 'title' => _t('Back')],
        ], false));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Salva pelo ClinicProfileService::save() e volta aos primeiros passos.
     */
    public function onSave($param = null)
    {
        $this->loaded = true;

        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            $trade_name = trim((string) ($data->trade_name ?? ''));

            // Tenant, unidade e marca do checklist mudam juntos ou nada muda.
            $pdo = TConnection::open('permission');
            $service = self::makeService($pdo);
            $pdo->beginTransaction();
            try
            {
                $service->save(
                    (string) $data->legal_name,
                    $trade_name === '' ? null : $trade_name,
                    (string) $data->unit_name,
                    new DateTimeImmutable(),
                );
                $pdo->commit();
            }
            catch (Throwable $e)
            {
                $pdo->rollBack();
                throw $e;
            }

            $this->form->setData($data);

            TToast::show('success', _t('Clinic data saved'));
            TScript::create("__adianti_goto_page('index.php?class=OnboardingChecklistView')");
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $this->form->setData($data ?? null);
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (DomainException $e)
        {
            $this->form->setData($data ?? null);
            new TMessage('error', _t($e->getMessage()));
        }
        catch (Exception $e)
        {
            $this->form->setData($data ?? null);
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Carrega os dados pelo ClinicProfileService::load() (só do tenant).
     */
    public function onEdit($param = null)
    {
        $this->loaded = true;

        try
        {
            $profile = self::makeService(TConnection::open('permission'))->load();

            $data = new stdClass;
            $data->legal_name = $profile['legal_name'];
            $data->trade_name = $profile['trade_name'] ?? '';
            $data->unit_name  = $profile['unit_name'];

            $this->form->setData($data);
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
        }
    }

    public function show()
    {
        if (!$this->loaded && ($_REQUEST['method'] ?? '') === '')
        {
            $this->onEdit($_REQUEST);
        }

        parent::show();
    }

    private static function makeService(PDO $pdo): CentralVet\Onboarding\ClinicProfileService
    {
        return new CentralVet\Onboarding\ClinicProfileService($pdo, OnboardingChecklistView::tenantContext($pdo));
    }
}
