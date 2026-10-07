<?php
/**
 * FinancialEntryList
 *
 * Listing screen for FinancialEntry rows within a period (T-09). Mirrors
 * ServiceList (Fase 1) / ProcedureCatalogList (T-08 da Fase 4): this
 * listing has no data-access logic of its own — onReload() is overridden to
 * source every row from
 * CentralVet\Application\FinancialEntryService::listByPeriod() (T-09
 * passthrough over CentralVet\Domain\Contract\
 * FinancialEntryRepositoryInterface::listBySystemUnitAndPeriod(), per this
 * task's own Interface spec) — the Persistence layer
 * (CentralVet\Persistence\FinancialEntryRepository) is never touched from
 * here.
 *
 * There is no Edit/Delete row action: financial_entry is append-only
 * (CentralVet\Domain\FinancialEntry's own docblock), so offering edit here
 * would force this controller to bypass the Application service and hit
 * Persistence/Domain directly, which is out of scope for this task.
 *
 * The period filter defaults to the current calendar month when no filter
 * has been searched yet this session.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class FinancialEntryList extends TStandardList
{
    protected $form;     // registration form
    protected $datagrid; // listing
    protected $pageNavigation;
    protected $footerBox;

    /** @var string|null 'income'|'expense' vindo da aba (outro valor = todos) */
    private $entryType = null;

    /**
     * Page constructor
     */
    public function __construct()
    {
        parent::__construct();

        // No setActiveRecord() call: AdiantiStandardControlTrait::
        // setActiveRecord() requires class_exists($activeRecord) and throws
        // otherwise — there is no CentralVet\Domain\FinancialEntry-backed
        // TRecord model (onReload() below talks to
        // FinancialEntryService directly), so calling it here was a fatal
        // dead end on every load, never exercised by the fake-repository
        // unit tests.
        parent::setDefaultOrder('id', 'desc');
        parent::addFilterField('period_from', '>=', 'period_from'); // filterField, operator, formField
        parent::addFilterField('period_to', '<=', 'period_to');
        parent::setLimit(TSession::getValue(__CLASS__ . '_limit') ?? 10);

        // aba Receitas/Despesas: entry_type do schema (FinancialEntry::TYPE_*)
        $this->entryType = self::entryTypeParam($_REQUEST['entry_type'] ?? null);
        $type_param = $this->entryType !== null ? ['entry_type' => $this->entryType] : [];

        // barra de filtros em linha (período), no lugar da cortina
        $this->form = new TForm('form_search_FinancialEntry');

        // create the form fields
        $period_from = new TDate('period_from');
        $period_to = new TDate('period_to');

        foreach ([$period_from, $period_to] as $field)
        {
            $field->setMask('dd/mm/yyyy');
            $field->setDatabaseMask('yyyy-mm-dd');
            $field->setSize('100%');
        }

        $find = new TButton('find');
        $find->setAction(new TAction([$this, 'onSearch'], $type_param), _t('Find'));
        $find->setImage('fa:search');
        $find->{'class'} = 'btn btn-primary';

        $this->form->add(CvPage::filterBar([
            self::labeled(_t('Start date'), $period_from),
            self::labeled(_t('End date'), $period_to),
            $find,
        ]));
        $this->form->setFields([$period_from, $period_to, $find]);

        // keep the form filled during navigation with session data, or
        // default to the current calendar month on first load
        $this->form->setData(self::filterData());

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();

        // creates the datagrid columns
        $column_occurred_at   = new TDataGridColumn('occurred_at_label', _t('Date'), 'left', 130);
        $column_reference     = new TDataGridColumn('reference_label', _t('Reference'), 'left');
        $column_category      = new TDataGridColumn('category', _t('Category'), 'left');
        $column_entry_type    = new TDataGridColumn('entry_type', _t('Type'), 'left', 110);
        $column_amount        = new TDataGridColumn('amount_cents', _t('Amount'), 'right', 130);

        $column_entry_type->setTransformer(function ($value) {
            return $value === \CentralVet\Domain\FinancialEntry::TYPE_EXPENSE
                ? CvBadge::create(_t('Expense'), 'danger')
                : CvBadge::create(_t('Income'), 'success');
        });
        $column_amount->setTransformer(function ($value, $object) {
            $is_expense = $object->entry_type === \CentralVet\Domain\FinancialEntry::TYPE_EXPENSE;
            $amount = CvFormat::money((int) $value);
            return '<span class="' . ($is_expense ? 'text-danger' : 'text-success') . '">'
                 . CvFormat::e($is_expense ? '-' . $amount : $amount) . '</span>';
        });

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_occurred_at);
        $this->datagrid->addColumn($column_reference);
        $this->datagrid->addColumn($column_category);
        $this->datagrid->addColumn($column_entry_type);
        $this->datagrid->addColumn($column_amount);

        // create the datagrid model
        $this->datagrid->createModel();

        // create the page navigation
        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction(array($this, 'onReload'), $type_param));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        $active_tab = $this->entryType === \CentralVet\Domain\FinancialEntry::TYPE_INCOME
            ? 'revenues'
            : ($this->entryType === \CentralVet\Domain\FinancialEntry::TYPE_EXPENSE ? 'expenses' : '');

        $title = $active_tab === 'revenues' ? _t('Revenues') : ($active_tab === 'expenses' ? _t('Expenses') : _t('Financial entries'));

        $new_href = 'index.php?class=FinancialEntryForm&method=onEdit&register_state=false'
                  . ($this->entryType !== null ? '&entry_type=' . $this->entryType : '');

        // No TXMLBreadCrumb here on purpose: TXMLBreadCrumb throws when the
        // class is not listed in menu.xml, which would make
        // `new FinancialEntryList()` fatal whenever the menu changes.
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($title, _t('Financial'), [
            ['label' => _t('New'), 'href' => $new_href, 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]));
        $container->add(CvNav::tabs('finance', $active_tab));
        $container->add($this->form);
        $container->add($this->datagrid);
        $container->add($this->footerBox);

        parent::add($container);
    }

    /**
     * method onReload()
     * Loads the datagrid exclusively from
     * CentralVet\Application\FinancialEntryService::listByPeriod() — the
     * tenant scoping happens inside that service/repository, never here.
     * With entry_type=income|expense only rows of that type are listed.
     */
    public function onReload($param = NULL)
    {
        if (!isset($this->datagrid))
        {
            return;
        }

        try
        {
            // open a transaction with database
            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildFinancialEntryService($tenant_context);

            $filter_data = self::filterData();

            $period_from = !empty($filter_data->period_from ?? null) ? $filter_data->period_from : date('Y-m-01');
            $period_to   = !empty($filter_data->period_to ?? null) ? $filter_data->period_to : date('Y-m-t');

            // every row this listing can ever show comes from this call
            $entries = $service->listByPeriod(
                $tenant_context->requireUnitId(),
                $period_from . ' 00:00:00',
                $period_to . ' 23:59:59',
            );

            $rows = [];
            foreach ($entries as $entry)
            {
                if ($this->entryType !== null && $entry->entryType() !== $this->entryType)
                {
                    continue;
                }

                $row = new stdClass;
                $row->id                 = $entry->id();
                $row->entry_type         = $entry->entryType();
                $row->category           = CvFormat::paymentMethod((string) $entry->category());
                $row->amount_cents       = $entry->amountCents();
                $row->occurred_at_label  = $entry->occurredAt()->format('d/m/Y H:i');
                $row->reference_label    = $entry->referenceType() !== null
                    ? $entry->referenceType() . ' #' . $entry->referenceId()
                    : '—';

                $rows[] = $row;
            }

            $count = count($rows);

            $offset = isset($param['offset']) ? max(0, (int) $param['offset']) : 0;
            $limit  = isset($this->limit) ? ( $this->limit > 0 ? $this->limit : NULL) : 10;

            $page_rows = $limit ? array_slice($rows, $offset, $limit) : $rows;

            $this->datagrid->clear();
            foreach ($page_rows as $row)
            {
                $this->datagrid->addItem($row);
            }

            $this->pageNavigation->setCount($count); // count of records
            $this->pageNavigation->setProperties($param); // order, page
            $this->pageNavigation->setLimit($limit); // limit

            $this->footerBox->clearChildren();
            $this->footerBox->add(CvDatagrid::footer(
                $this->pageNavigation,
                $offset + 1,
                $offset + count($page_rows),
                $count,
                _t('entries')
            ));

            // close the transaction
            TTransaction::close();
            $this->loaded = true;

            return $rows;
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            new TMessage('error', _t('You are not allowed to perform this action'));
            TTransaction::rollback();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', _t('An authenticated session with a tenant is required'));
            TTransaction::rollback();
        }
        catch (Exception $e) // in case of exception
        {
            // shows the exception error message
            new TMessage('error', $e->getMessage());
            // undo all pending operations
            TTransaction::rollback();
        }
    }

    /**
     * entry_type válido (income|expense) ou null (lista todos).
     */
    private static function entryTypeParam($value): ?string
    {
        return in_array($value, [\CentralVet\Domain\FinancialEntry::TYPE_INCOME, \CentralVet\Domain\FinancialEntry::TYPE_EXPENSE], true)
            ? $value
            : null;
    }

    /**
     * Filtro de período da sessão (gravado por onSearch() sob o nome da
     * classe) ou o mês corrente.
     */
    private static function filterData(): stdClass
    {
        $data = TSession::getValue(__CLASS__ . '_filter_data');

        if (!is_object($data) || (empty($data->period_from) && empty($data->period_to)))
        {
            $data = new stdClass;
            $data->period_from = date('Y-m-01');
            $data->period_to = date('Y-m-t');
        }

        return $data;
    }

    private static function labeled(string $label, $field): TElement
    {
        $box = new TElement('div');
        $box->add(new TLabel($label));
        $box->add($field);

        return $box;
    }

    /**
     *
     */
    public static function onChangeLimit($param)
    {
        TSession::setValue(__CLASS__ . '_limit', $param['limit'] );
        AdiantiCoreApplication::loadPage(__CLASS__, 'onReload');
    }

    /**
     * Wires an Application-layer FinancialEntryService instance, consistent
     * with FinancialEntryForm::buildFinancialEntryService() (T-09).
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildFinancialEntryService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\FinancialEntryService
    {
        $connection = TTransaction::get();

        $entries = new \CentralVet\Persistence\FinancialEntryRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\FinancialEntryService($entries, $authorization, $context);
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
