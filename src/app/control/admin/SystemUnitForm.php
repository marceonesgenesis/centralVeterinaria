<?php
/**
 * SystemUnitForm
 *
 * @version    8.6
 * @package    control
 * @subpackage admin
 * @author     Pablo Dall'Oglio
 * @copyright  Copyright (c) 2006 Adianti Solutions Ltd. (http://www.adianti.com.br)
 * @license    https://adiantiframework.com.br/license-template
 */
class SystemUnitForm extends TStandardForm
{
    protected $form; // form
    
    /**
     * Class constructor
     * Creates the page and the registration form
     */
    function __construct()
    {
        parent::__construct();
        
        parent::setTargetContainer('adianti_right_panel');
        
        $ini  = AdiantiApplicationConfig::get();
        
        $this->setDatabase('permission');              // defines the database
        $this->setActiveRecord('SystemUnit');     // defines the active record
        $this->setAfterSaveAction( new TAction(['SystemUnitList', 'onReload']) );
        $this->setUseToast(true);
        
        // creates the form
        $this->form = new BootstrapFormBuilder('form_SystemUnit');
        $this->form->setFormTitle(_t('Unit'));
        $this->form->enableClientValidation();
        
        // create the form fields
        $id = new TEntry('id');
        $name = new TEntry('name');
        $custom_code = new TEntry('custom_code');
        
        // add the fields
        $this->form->addFields( [new TLabel('Id')] );
        $this->form->addFields( [$id] );
        $this->form->addFields( [new TLabel(_t('Name'))] );
        $this->form->addFields( [$name] );
        
        if (!empty($ini['general']['multi_database']) and $ini['general']['multi_database'] == '1')
        {
            $database = new TCombo('connection_name');
            $database->addItems( SystemDatabaseInformationService::getConnections() );
            $this->form->addFields( [new TLabel(_t('Database'))] );
            $this->form->addFields( [$database] );
            $database->setSize('100%');
        }
        
        $this->form->addFields( [new TLabel(_t('Custom code'))] );
        $this->form->addFields( [$custom_code] );
        
        $id->setEditable(FALSE);
        $id->setSize('30%');
        $name->setSize('100%');
        $name->addValidation( _t('Name'), new TRequiredValidator );
        
        // create the form actions
        $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';
        $this->form->addActionLink(_t('Clear'),  new TAction(array($this, 'onEdit')), 'fa:eraser red');
        //$this->form->addActionLink(_t('Back'),new TAction(array('SystemUnitList','onReload')),'far:arrow-alt-circle-left blue');
        
        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');
        
        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        // $container->add(new TXMLBreadCrumb('menu.xml', 'SystemUnitList'));
        $container->add($this->form);
        
        parent::add($container);
    }
    
    /**
     * on close
     */
    public static function onClose($param)
    {
        TScript::create("Template.closeRightPanel()");
    }

    /**
     * method onEdit()
     * Executed whenever the user clicks at the edit button of the datagrid.
     * Tenant-scoping (T-03): recusa a abertura de uma unidade que não
     * pertence ao tenant do usuário autenticado, sem alterar o restante do
     * fluxo herdado de AdiantiStandardFormTrait::onEdit().
     */
    public function onEdit($param)
    {
        try
        {
            if (isset($param['key']))
            {
                // get the parameter $key
                $key = $param['key'];

                // open a transaction with database 'permission'
                TTransaction::open('permission');

                $tenant_context = self::resolveTenantContext();

                // tenant_id is not declared via addAttribute() on SystemUnit,
                // so it must be read directly instead of via the active record
                $stmt = TTransaction::get()->prepare('SELECT tenant_id FROM system_unit WHERE id = :id');
                $stmt->execute(['id' => (int) $key]);
                $tenant_id = $stmt->fetchColumn();

                if ($tenant_id === false)
                {
                    throw new Exception(_t('Record not found'));
                }

                $tenant_context->assertTenant((int) $tenant_id);

                // instantiates object SystemUnit
                $object = new SystemUnit($key);

                // fill the form with the active record data
                $this->form->setData($object);

                // close the transaction
                TTransaction::close();

                return $object;
            }
            else
            {
                $this->form->clear();
            }
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Record not found'));
        }
        catch (Exception $e) // in case of exception
        {
            new TMessage('error', $e->getMessage());
            TTransaction::rollback();
        }
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
