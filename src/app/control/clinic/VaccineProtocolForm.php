<?php
/**
 * VaccineProtocolForm
 *
 * Dose-schedule ("protocol") editor for one vaccine catalog item (T-08):
 * receives vaccine_catalog_item_id by parameter (?vaccine_catalog_item_id=...,
 * mirroring PatientList's tutor_id-by-parameter pattern from Fase 1) and
 * lets the user add/remove dose_number + interval_days_from_previous rows,
 * consuming CentralVet\Application\VaccineProtocolService entirely.
 *
 * Extends TPage (not TStandardForm): a protocol row has no independent
 * "edit" semantics — VaccineProtocol is immutable once created (its own
 * docblock says so) and VaccineProtocolService only exposes
 * create()/listByVaccineCatalogItem()/remove(), so this screen combines a
 * small entry form with an embedded read-only listing + delete action,
 * the same shape PatientList uses for a listing scoped by a related id.
 *
 * No business rule of its own: dose_number/interval validation (>= 1,
 * non-negative) lives in the Domain entity VaccineProtocol::create(),
 * reached only through VaccineProtocolService — this controller never
 * touches Persistence/Domain directly.
 *
 * PENDING: this screen depends on the `vaccine_protocol` table created by
 * the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01). Validated only with `php -l` / `new VaccineProtocolForm()` (no
 * fatal error) until that migration is applied.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class VaccineProtocolForm extends TPage
{
    protected $vaccine_catalog_item_id;
    protected $form;
    protected $datagrid;
    protected $panel;

    /**
     * Class constructor
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->vaccine_catalog_item_id = (isset($param['vaccine_catalog_item_id']) && (int) $param['vaccine_catalog_item_id'] > 0)
            ? (int) $param['vaccine_catalog_item_id']
            : null;

        // combo de item de catálogo escopado ao tenant da sessão (precedente:
        // AppointmentForm); sem tenant resolvido, tenant_id impossível -1
        // (combo vazio em vez de erro fatal)
        try
        {
            $tenant_id = self::resolveTenantContext()->tenantId();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $tenant_id = -1;
        }

        $tenant_criteria = self::catalogCriteria((int) $tenant_id, $this->vaccine_catalog_item_id);

        // creates the entry form
        $this->form = new BootstrapFormBuilder('form_VaccineProtocol');
        $this->form->enableClientValidation();
        CvForm::decorate($this->form, 2);

        $vaccine_catalog_item_id = new TDBCombo('vaccine_catalog_item_id', 'permission', 'VaccineCatalogItem', 'id', 'name', 'name', $tenant_criteria);
        $dose_number = new TEntry('dose_number');
        $interval_days_from_previous = new TEntry('interval_days_from_previous');

        $vaccine_catalog_item_id->setValue($this->vaccine_catalog_item_id);
        $vaccine_catalog_item_id->setChangeAction(new TAction([__CLASS__, 'onChangeVaccine']));
        $dose_number->setNumericMask(0, '', '');
        $dose_number->setProperty('pattern', '[0-9]*'); // PATTERN0: máscara numérica sem decimais gera regex inválida (d{1,0})
        $interval_days_from_previous->setNumericMask(0, '', '');
        $interval_days_from_previous->setProperty('pattern', '[0-9]*'); // PATTERN0

        // pares rótulo/campo em 2 colunas, rótulo acima (CvForm)
        $this->form->addFields( [new TLabel(_t('Vaccine'))], [$vaccine_catalog_item_id] );
        $this->form->addFields( [new TLabel(_t('Dose number'))], [$dose_number], [new TLabel(_t('Interval from previous dose (days)'))], [$interval_days_from_previous] );

        $vaccine_catalog_item_id->addValidation( _t('Vaccine'), new TRequiredValidator );
        $dose_number->addValidation( _t('Dose number'), new TRequiredValidator );

        $btn = $this->form->addAction(_t('Add dose'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-primary';

        // creates the schedule listing
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid, false);
        $this->datagrid->setActionSide('right');

        $column_dose     = new TDataGridColumn('dose_number', _t('Dose'), 'left');
        $column_interval = new TDataGridColumn('interval_label', _t('Interval from previous dose'), 'left');

        $this->datagrid->addColumn($column_dose);
        $this->datagrid->addColumn($column_interval);

        // a exclusão repassa o item de catálogo para a recarga manter o contexto
        $action_delete = new TDataGridAction(array($this, 'onDelete'), array('id' => '{id}', 'vaccine_catalog_item_id' => (string) $this->vaccine_catalog_item_id, 'register_state' => 'false'));
        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Delete'), 'action' => $action_delete, 'icon' => 'fa:trash-alt red'],
        ]));

        $this->datagrid->createModel();

        $this->panel = CvCard::create(_t('Dose schedule'), $this->datagrid);

        // página cheia: cabeçalho do kit com voltar para a lista de vacinas
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Vaccine protocol'), null, [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=VaccineCatalogList'],
        ]));
        $container->add($this->form);
        $container->add($this->panel);

        parent::add($container);

        $this->onReload($param);
    }

    /**
     * Troca da vacina no combo: recarrega a página com o item escolhido.
     */
    public static function onChangeVaccine($param)
    {
        $id = isset($param['vaccine_catalog_item_id']) ? (int) $param['vaccine_catalog_item_id'] : 0;

        AdiantiCoreApplication::loadPage(__CLASS__, 'onEdit', $id > 0 ? ['vaccine_catalog_item_id' => $id] : []);
    }

    /**
     * method onEdit()
     * The constructor already builds the full page (entry form + schedule
     * listing) straight from $param; this method only needs to exist so
     * the "Protocol" row action wired in VaccineCatalogList
     * (`new TAction(['VaccineProtocolForm', 'onEdit'], ...)`) resolves to a
     * valid callback for Adianti's router, mirroring the role
     * AppointmentForm::onEdit() plays for a plain TPage (Fase 1).
     */
    public function onEdit($param)
    {
    }

    /**
     * method onReload()
     * Reloads the schedule listing from
     * VaccineProtocolService::listByVaccineCatalogItem(). Never lets a
     * domain/tenancy exception escape as a fatal error — every failure is
     * translated into a treated TMessage.
     */
    public function onReload($param = null)
    {
        try
        {
            if (empty($this->vaccine_catalog_item_id))
            {
                return;
            }

            $this->datagrid->clear();

            TTransaction::open('permission');

            $service = self::buildVaccineProtocolService();
            $schedule = $service->listByVaccineCatalogItem($this->vaccine_catalog_item_id);

            TTransaction::close();

            foreach ($schedule as $entry)
            {
                $row = new stdClass;
                $row->id             = $entry->id();
                $row->dose_number    = $entry->doseNumber();
                $row->interval_label = $entry->intervalDaysFromPrevious() !== null
                    ? _t('%s days', $entry->intervalDaysFromPrevious())
                    : _t('Not scheduled');

                $this->datagrid->addItem($row);
            }
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (Exception $e) // never let it escape as a fatal error
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * method onSave()
     * Adds one dose to the schedule through VaccineProtocolService::create().
     * No validation/decision is made here: dose_number/interval rules are
     * enforced inside VaccineProtocol::create(), reached only through the
     * Application service.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            if (empty($data->vaccine_catalog_item_id))
            {
                throw new Exception(_t('A vaccine catalog item is required'));
            }

            TTransaction::open('permission');

            $service = self::buildVaccineProtocolService();

            $service->create([
                'vaccine_catalog_item_id'      => $data->vaccine_catalog_item_id,
                'dose_number'                   => $data->dose_number,
                'interval_days_from_previous'  => $data->interval_days_from_previous,
            ]);

            TTransaction::close();

            new TMessage('info', _t('Dose added to the schedule'));

            $this->vaccine_catalog_item_id = (int) $data->vaccine_catalog_item_id;

            $this->form->clear();
            $this->form->setData((object) ['vaccine_catalog_item_id' => $this->vaccine_catalog_item_id]);

            $this->onReload($param);
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (Exception $e) // in case of exception, never let it escape as a fatal error
        {
            $this->form->setData($data ?? null);
            new TMessage('error', $e->getMessage());
            TTransaction::rollback();
        }
    }

    /**
     * method onDelete()
     * Removes one dose-schedule row through VaccineProtocolService::remove().
     */
    public function onDelete($param = null)
    {
        try
        {
            $id = isset($param['id']) ? (int) $param['id'] : null;

            if (empty($id))
            {
                return;
            }

            TTransaction::open('permission');

            $service = self::buildVaccineProtocolService();
            $service->remove($id);

            TTransaction::close();

            new TMessage('info', _t('Dose removed'), new TAction([$this, 'onReload'], $param));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (Exception $e) // never let it escape as a fatal error
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Builds the Application service with its dependencies, following the
     * same tenant-scoping convention as ServiceForm::buildServiceCatalogService().
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildVaccineProtocolService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\VaccineProtocolRepository($tenant_context, $connection);

        return new \CentralVet\Application\VaccineProtocolService($repository, $tenant_context);
    }

    /**
     * Critério do combo de catálogo: itens ativos do tenant e, quando há item
     * atual, também ele (mesmo inativo), para o combo não perder o vínculo.
     */
    private static function catalogCriteria(int $tenantId, ?int $currentId): TCriteria
    {
        $criteria = new TCriteria;
        $criteria->add(new TFilter('tenant_id', '=', $tenantId));

        if ($currentId === null)
        {
            $criteria->add(new TFilter('active', '=', 1));
            return $criteria;
        }

        $visible = new TCriteria;
        $visible->add(new TFilter('active', '=', 1));
        $visible->add(new TFilter('id', '=', $currentId), TExpression::OR_OPERATOR);
        $criteria->add($visible);

        return $criteria;
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
