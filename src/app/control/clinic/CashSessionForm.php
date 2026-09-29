<?php
/**
 * CashSessionForm
 *
 * Cash till session screen for the active system unit (T-08). Unlike
 * ServiceForm/StockBatchForm (TStandardForm bound to a single active
 * record), this is a plain TPage that toggles between two states depending
 * on whether the active unit currently has an open `cash_session` row:
 *
 * - no open session: shows an opening-balance field and an "Abrir caixa"
 *   button, calling CentralVet\Application\CashSessionService::open() (T-04);
 * - an open session: shows the running totals per payment method (from
 *   CashSessionService::totalsByPaymentMethod()) and a closing-balance field
 *   with a "Fechar caixa" button, calling CashSessionService::close().
 *
 * Which state is current is read directly through the `CashSession` TRecord
 * (app/model/clinic/CashSession.php) via TRepository/TCriteria — the same
 * precedent SystemPostCommentList::onReload() and this task's own
 * CashSessionList follow for read-only state, since
 * CashSessionRepositoryInterface (T-02, immutable) exposes no "current open
 * session" query beyond what CashSessionService itself already covers for
 * writes. Every state change (open/close) still goes exclusively through
 * CashSessionService — this controller never calls
 * CentralVet\Persistence\CashSessionRepository / CentralVet\Domain\CashSession
 * directly.
 *
 * Mirrors AgendaView's shape: the constructor renders the current state via
 * onReload(), and onOpen()/onReload() rebuild the whole page body again
 * after every action so the screen always reflects the real DB state
 * (crucially: after a successful close, the page redraws in the "no open
 * session" state, ready to open a new one).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class CashSessionForm extends TPage
{
    protected $form; // the form currently on screen (open-panel or close-panel)

    /**
     * Class constructor. Renders the current state on first load.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->onReload($param);
    }

    /**
     * Rebuilds the whole page body from the current DB state. Target of the
     * "Abrir caixa"/"Fechar caixa" buttons' post-action redraw.
     */
    public function onReload($param = null)
    {
        $container = new TVBox;
        $container->style = 'width: 100%';

        try
        {
            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $open_session = $this->resolveOpenSession($tenant_context);

            if ($open_session === null)
            {
                $container->add($this->buildPageHeader(_t('Open cash session')));
                $container->add($this->buildOpenPanel());
            }
            else
            {
                $service = self::buildCashSessionService($tenant_context);
                $totals = $service->totalsByPaymentMethod((int) $open_session->id);

                $container->add($this->buildPageHeader(_t('Close cash session')));
                $container->add($this->buildClosePanel($open_session, $totals));
            }

            TTransaction::close();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $container->add(new TAlert('danger', _t('An authenticated session with a tenant is required')));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            $container->add(new TAlert('danger', $e->getMessage()));
        }

        parent::add($container);
    }

    /**
     * Builds the page header block (design system: .cv-page-header /
     * .cv-page-title, mirrors src/design-system.html). The title text is
     * whichever of the two existing state labels (_t('Open cash session') /
     * _t('Close cash session')) matches what onReload() is about to render,
     * so the header always reflects the current screen state.
     */
    private function buildPageHeader($title)
    {
        // cabeçalho do kit Cv* (voltar para o histórico) + abas financeiras
        $box = new TElement('div');
        $box->add(CvPage::header((string) $title, _t('Financial'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=CashSessionList'],
        ]));
        $box->add(CvNav::tabs('finance', 'cashflow'));

        return $box;
    }

    /**
     * Builds the "no open session" panel: opening balance field + "Abrir
     * caixa" button, wired to onOpen(). Stores the form in $this->form so
     * onOpen() can read it back with getData().
     */
    private function buildOpenPanel()
    {
        $form = new BootstrapFormBuilder('form_CashSessionOpen');
        $form->enableClientValidation();
        CvForm::decorate($form, 2);

        $opening_balance = new TEntry('opening_balance');
        $opening_balance->setNumericMask(2, ',', '.');
        $opening_balance->addValidation(_t('Opening balance'), new TRequiredValidator);

        $form->addFields( [new TLabel(_t('Opening balance'))], [$opening_balance] );

        $btn = $form->addAction(_t('Open cash session'), new TAction(array($this, 'onOpen')), 'fa:cash-register');
        $btn->class = 'btn btn-primary';

        $this->form = $form;

        return $form;
    }

    /**
     * Builds the "session open" panel: totals-by-payment-method readout +
     * closing balance field + "Fechar caixa" button, wired to onClose().
     * Stores the form in $this->form so onClose() can read it back with
     * getData().
     *
     * @param object            $open_session the open CashSession TRecord row
     * @param array<string,int> $totals       payment_method => amount_cents
     */
    private function buildClosePanel($open_session, array $totals)
    {
        $wrapper = new TVBox;
        $wrapper->style = 'width: 100%';

        $summary = new TElement('div');
        $summary->class = 'card cv-card';
        $summary->style = 'margin-bottom: 16px; padding: 16px';

        $title = new TElement('h4');
        $title->add(CvFormat::e(_t('Cash session open since') . ' ' . $open_session->opened_at) . ' ');
        $title->add(CvBadge::create(_t('Open (status)'), 'success'));
        $summary->add($title);

        $opening_line = new TElement('p');
        $opening_line->add(CvFormat::e(_t('Opening balance') . ': ' . CvFormat::money((int) $open_session->opening_balance_cents)));
        $summary->add($opening_line);

        $list = new TElement('ul');

        if (empty($totals))
        {
            $item = new TElement('li');
            $item->add(_t('No payments recorded in this session yet'));
            $list->add($item);
        }
        else
        {
            foreach ($totals as $payment_method => $amount_cents)
            {
                $item = new TElement('li');
                $item->add(CvFormat::e($payment_method . ': ' . CvFormat::money((int) $amount_cents)));
                $list->add($item);
            }
        }

        $summary->add($list);
        $wrapper->add($summary);

        $form = new BootstrapFormBuilder('form_CashSessionClose');
        $form->enableClientValidation();
        CvForm::decorate($form, 2);

        $closing_balance = new TEntry('closing_balance');
        $closing_balance->setNumericMask(2, ',', '.');
        $closing_balance->addValidation(_t('Closing balance'), new TRequiredValidator);

        $form->addFields( [new TLabel(_t('Closing balance'))], [$closing_balance] );

        $btn = $form->addAction(_t('Close cash session'), new TAction(array($this, 'onClose')), 'fa:cash-register');
        $btn->class = 'btn btn-primary';

        $wrapper->add($form);

        $this->form = $form;

        return $wrapper;
    }

    /**
     * method onOpen()
     * Opens a new cash session through CashSessionService::open(). If the
     * active unit already has an open session, CashSessionService itself
     * throws CashSessionAlreadyOpenException before writing anything: this
     * method only translates that into a TMessage, it never retries or
     * works around it, so a second session is never created.
     */
    public function onOpen($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildCashSessionService($tenant_context);

            $service->open(
                $tenant_context->requireUnitId(),
                self::toCents($data->opening_balance),
                $tenant_context->userId(),
                'CashSessionForm::onOpen'
            );

            TTransaction::close();

            TToast::show('info', _t('Cash session opened'));
        }
        catch (\CentralVet\Domain\Exception\CashSessionAlreadyOpenException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (Exception $e) // in case of exception (validation, domain, etc.)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }

        // always redraw from the real DB state: still one open session (the
        // pre-existing one) when CashSessionAlreadyOpenException was thrown,
        // the freshly-opened one otherwise.
        $this->onReload($param);
    }

    /**
     * method onClose()
     * Closes the active unit's open cash session through
     * CashSessionService::close(). On success the page redraws in the
     * "no open session" state (via onReload()), ready to open a new one.
     */
    public function onClose($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $open_session = $this->resolveOpenSession($tenant_context);

            if ($open_session === null)
            {
                throw new Exception(_t('There is no open cash session for this unit'));
            }

            $service = self::buildCashSessionService($tenant_context);

            $service->close(
                (int) $open_session->id,
                self::toCents($data->closing_balance),
                $tenant_context->userId(),
                'CashSessionForm::onClose'
            );

            TTransaction::close();

            TToast::show('info', _t('Cash session closed'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (Exception $e) // in case of exception (validation, domain, etc.)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }

        // always redraw from the real DB state: back to the "no open
        // session" panel after a successful close.
        $this->onReload($param);
    }

    /**
     * Finds the active unit's open cash session, if any, reading the
     * `CashSession` TRecord directly (TRepository/TCriteria) — see class
     * docblock for why this bypasses CashSessionService for this one
     * read-only lookup. Requires an already-open TTransaction('permission')
     * connection.
     */
    private function resolveOpenSession(\CentralVet\Tenancy\TenantContext $tenant_context)
    {
        $repository = new TRepository('CashSession');

        $criteria = new TCriteria;
        $criteria->add(new TFilter('tenant_id', '=', $tenant_context->tenantId()));
        $criteria->add(new TFilter('system_unit_id', '=', $tenant_context->requireUnitId()));
        $criteria->add(new TFilter('status', '=', 'open'));
        $criteria->setProperty('order', 'id');
        $criteria->setProperty('direction', 'desc');
        $criteria->setProperty('limit', 1);

        $objects = $repository->load($criteria, FALSE);

        return $objects[0] ?? null;
    }

    /**
     * Converts a "1.234,56"-style amount typed by the user into integer
     * cents, matching CashSessionService::open()/close()'s *_cents input
     * (same conversion as ServiceForm::toCents()).
     */
    private static function toCents($amount)
    {
        $normalized = str_replace('.', '', (string) $amount);
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Builds CentralVet\Application\CashSessionService with its
     * dependencies, mirroring StockBatchForm::buildStockService()/
     * AgendaView::buildAppointmentService(): the real RbacAuthorizationService
     * (Fase 0), backed by AdiantiProgramPermissionProvider and
     * PdoAuditLogWriter against this same 'permission' connection, plus the
     * raw PDO connection CashSessionService::totalsByPaymentMethod() needs
     * (see that method's own docblock). Requires an already-open
     * TTransaction('permission') connection.
     */
    private static function buildCashSessionService(\CentralVet\Tenancy\TenantContext $tenant_context)
    {
        $connection = TTransaction::get();

        $sessions = new \CentralVet\Persistence\CashSessionRepository($tenant_context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\CashSessionService($sessions, $authorization, $tenant_context, $connection);
    }

    /**
     * Resolves the tenant context of the authenticated session (T-03),
     * mirroring SystemUnitForm::resolveTenantContext() (same fallback for
     * legacy sessions where TSession does not carry 'tenantid' yet).
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
