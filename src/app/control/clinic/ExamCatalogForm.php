<?php
/**
 * ExamCatalogForm
 *
 * Registration screen for the exam catalog (T-07). Follows the same
 * TStandardForm pattern used by ServiceForm (Fase 1), but contains no
 * business rule of its own: creation is delegated entirely to
 * CentralVet\Application\ExamCatalogService (T-04) — name/price validation
 * and the "active by default" rule all live there.
 *
 * PENDING: this screen depends on the `exam_catalog_item` table created by
 * the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01). Validated only with `php -l` / `new ExamCatalogForm()` (no fatal
 * error) until that migration is applied — any database failure while
 * actually saving is caught and shown as a TMessage, never a fatal error.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ExamCatalogForm extends TStandardForm
{
    protected $form; // form

    /**
     * Class constructor
     * Creates the page and the registration form
     */
    function __construct()
    {
        parent::__construct();

        $this->setDatabase('permission');           // defines the database
        $this->setActiveRecord('ExamCatalogItem');    // defines the active record
        $this->setAfterSaveAction( new TAction(['ExamCatalogList', 'onReload']) );
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_ExamCatalogItem');
        $this->form->enableClientValidation();
        CvForm::decorate($this->form, 2);

        // create the form fields
        $id = new TEntry('id');
        $name = new TEntry('name');
        $partner_name = new TEntry('partner_name');
        $price = new TEntry('price');
        $active = new TCombo('active');
        $active->addItems([1 => _t('Active'), 0 => _t('Inactive')]);

        // add the fields
        // pares rótulo/campo em 2 colunas, rótulo acima (CvForm)
        $this->form->addFields( [new TLabel(_t('Name'))], [$name], [new TLabel(_t('Partner'))], [$partner_name] );
        $this->form->addFields( [new TLabel(_t('Price'))], [$price], [new TLabel(_t('Status'))], [$active] );
        $this->form->addFields( [new TLabel('Id')], [$id] );

        $id->setEditable(FALSE);
        $price->setNumericMask(2, ',', '.');
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
        $container->add(CvPage::header(_t('Exam'), null, [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=ExamCatalogList'],
        ]));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * method onSave()
     * Persists the catalog entry through ExamCatalogService::create().
     * No validation/decision is made here: everything (required fields,
     * defaults) is enforced inside the Application service.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            // open a transaction with database
            TTransaction::open('permission');

            $catalog = self::buildExamCatalogService();

            $item = $catalog->create([
                'name'         => $data->name,
                'partner_name' => $data->partner_name,
                'price_cents'  => self::toCents($data->price),
            ]);

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
            new TMessage('error', $e->getMessage());

            // undo all pending operations
            TTransaction::rollback();
        }
    }

    /**
     * Converts a "1.234,56"-style amount typed by the user into integer
     * cents, matching ExamCatalogService::create()'s price_cents input.
     */
    private static function toCents($amount)
    {
        $normalized = str_replace('.', '', (string) $amount);
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Builds the Application service with its dependencies, following the
     * same tenant-scoping convention as ServiceForm::buildServiceCatalogService().
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildExamCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\ExamCatalogRepository($tenant_context, $connection);

        return new \CentralVet\Application\ExamCatalogService($repository, $tenant_context);
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
