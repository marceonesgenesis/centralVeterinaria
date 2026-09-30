<?php

use CentralVet\Presentation\MoneyInput;

/**
 * ProcedureCatalogForm
 *
 * Registration screen for the procedure catalog (T-08). Follows the same
 * TStandardForm pattern used by ServiceForm (Fase 1) / VaccineCatalogForm
 * (T-08 da Fase 3), but contains no business rule of its own: creation is
 * delegated entirely to CentralVet\Application\ProcedureCatalogService
 * (T-04) — name/price/duration validation and the "active by default" rule
 * all live there.
 *
 * The `procedure_catalog_item` table was created by migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql,
 * already applied (Fase 4) — any database failure while saving is still
 * caught and shown as a TMessage, never a fatal error.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ProcedureCatalogForm extends TStandardForm
{
    protected $form; // form

    /**
     * Class constructor
     * Creates the page and the registration form
     */
    function __construct()
    {
        parent::__construct();

        $this->setDatabase('permission');                // defines the database
        $this->setActiveRecord('ProcedureCatalogItem');   // defines the active record
        $this->setAfterSaveAction( new TAction(['ProcedureCatalogList', 'onReload']) );
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_ProcedureCatalogItem');
        $this->form->enableClientValidation();
        CvForm::decorate($this->form, 2);

        // create the form fields
        $id = new TEntry('id');
        $name = new TEntry('name');
        $price = new TEntry('price');
        $duration_minutes = new TEntry('duration_minutes');
        $preparation_text = new TText('preparation_text');
        $active = new TCombo('active');
        $active->addItems([1 => _t('Active'), 0 => _t('Inactive')]);

        // add the fields
        // pares rótulo/campo em 2 colunas, rótulo acima (CvForm)
        $this->form->addFields( [new TLabel(_t('Name'))], [$name], [new TLabel(_t('Status'))], [$active] );
        $this->form->addFields( [new TLabel(_t('Price'))], [$price], [new TLabel(_t('Duration (minutes)'))], [$duration_minutes] );
        $this->form->addFields( [new TLabel(_t('Preparation notes'))], [$preparation_text] );
        $this->form->addFields( [new TLabel('Id')], [$id] );

        $id->setEditable(FALSE);
        // digitação livre, sem máscara nem filtro (padrão BankAccountForm):
        // MoneyInput::toCents() converte ou recusa ("Valor inválido").
        $price->setProperty('placeholder', _t('e.g. 12,34'));
        $price->setProperty('inputmode', 'decimal');
        $price->setMaxLength(16);
        $duration_minutes->setNumericMask(0, '', '');
        $duration_minutes->setProperty('pattern', '[0-9]*'); // PATTERN0: máscara numérica sem decimais gera regex inválida (d{1,0})
        $preparation_text->setSize('100%', 80);
        $active->setValue(1);

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $price->addValidation( _t('Price'), new TRequiredValidator );

        // create the form actions
        $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-primary';
        $this->form->addActionLink(_t('Clear'),  new TAction(array($this, 'onEdit')), 'fa:eraser');

        // página cheia: cabeçalho do kit com voltar para a lista
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Procedure'), null, [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=ProcedureCatalogList'],
        ]));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * method onSave()
     * Persists the catalog entry through ProcedureCatalogService::create().
     * No validation/decision is made here: everything (required fields,
     * defaults, price/duration rules) is enforced inside the Application
     * service / Domain entity.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            // open a transaction with database
            TTransaction::open('permission');

            $catalog = self::buildProcedureCatalogService();

            $duration_minutes = ($data->duration_minutes !== null && $data->duration_minutes !== '')
                ? (int) $data->duration_minutes
                : null;

            $preparation_text = ($data->preparation_text !== null && trim((string) $data->preparation_text) !== '')
                ? $data->preparation_text
                : null;

            $item = $catalog->create(
                $data->name,
                MoneyInput::toCents((string) $data->price, false, MoneyInput::MAX_UNSIGNED_INT_CENTS),
                $duration_minutes,
                $preparation_text
            );

            $data->id = $item->id();

            // fill the form with the active record data
            $this->form->setData($data);

            // close the transaction
            TTransaction::close();

            // shows the success message
            if (!empty($this->useToast))
            {
                TToast::show('info', _t('Record saved'));
                AdiantiCoreApplication::loadPageURL( $this->afterSaveAction->serialize() );
            }
            else
            {
                new TMessage('info', _t('Record saved'), $this->afterSaveAction);
            }

            return $data;
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            $this->form->setData($data ?? null);
            new TMessage('error', _t('You are not allowed to perform this action'));
            TTransaction::rollback();
        }
        catch (Exception $e) // in case of exception
        {
            // fill the form with the active record data
            $this->form->setData($data ?? null);

            // shows the exception error message
            new TMessage('error', CvFormat::userError($e));

            // undo all pending operations
            TTransaction::rollback();
        }
    }

    /**
     * Builds the Application service with its dependencies, following the
     * same tenant-scoping convention as ServiceForm::buildServiceCatalogService().
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildProcedureCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $catalog_repository = new \CentralVet\Persistence\ProcedureCatalogRepository($tenant_context, $connection);
        $inputs_repository  = new \CentralVet\Persistence\ProcedureCatalogItemInputRepository($tenant_context, $connection);

        return new \CentralVet\Application\ProcedureCatalogService($catalog_repository, $inputs_repository, $tenant_context);
    }

    /**
     * Resolves the tenant context of the authenticated session (T-03).
     * Falls back to the tenant_user membership table for legacy sessions
     * created before this task, since TSession does not carry 'tenantid'
     * yet (LoginForm.php / ApplicationAuthenticationService::loadSessionVars()
     * are out of scope for this task).
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
