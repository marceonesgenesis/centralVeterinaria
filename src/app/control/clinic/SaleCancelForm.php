<?php

/**
 * SaleCancelForm
 *
 * Cortina lateral de cancelamento rastreável de venda (Etapa 2, T-16).
 * onEdit só lê: mostra o resumo gravado da venda (SaleFinanceReader::
 * receiptSummary) e o campo de motivo. onCancel grava: chega por POST de
 * formulário (o cv-shell.js põe o X-CSRF-Token; MutationRequestPolicy nega
 * GET) e delega tudo ao SaleCancellationService (T-14) — devolução de
 * estoque, estorno dos recebimentos no caixa aberto da unidade da venda e
 * cancelamento do recebível e da venda.
 *
 * A tela é dona da transação. Sem caixa aberto e com recebimento a
 * estornar, o serviço lança CashSessionRequiredException depois de já ter
 * gravado a devolução do estoque: o rollback aqui desfaz essa gravação.
 *
 * RBAC: programa próprio (seed, grupo 1). onCancel é estático, então um
 * erro não redesenha a cortina e o motivo digitado continua no campo.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SaleCancelForm extends TPage
{
    private const ACTION = 'SaleCancelForm::onCancel';
    private const REASON_MAX_LENGTH = 500;

    protected $form;
    private TElement $summaryBox;

    public function __construct($param = null)
    {
        parent::__construct();
        parent::setTargetContainer('adianti_right_panel');

        $this->form = new BootstrapFormBuilder('form_SaleCancel');
        $this->form->setFormTitle(_t('Cancel sale'));
        $this->form->enableClientValidation();
        CvForm::decorate($this->form, 1);

        $this->summaryBox = new TElement('div');
        $this->form->addContent([$this->summaryBox]);

        $sale_id = new THidden('sale_id');
        $this->form->addFields([$sale_id]);

        $reason = new TText('cancellation_reason');
        $reason->setSize('100%', 120);
        $reason->setProperty('maxlength', (string) self::REASON_MAX_LENGTH);
        $reason->addValidation(_t('Cancellation reason'), new TRequiredValidator);
        $reason->addValidation(_t('Cancellation reason'), new TMaxLengthValidator, [self::REASON_MAX_LENGTH]);

        $this->form->addFields([new TLabel(_t('Cancellation reason'))]);
        $this->form->addFields([$reason]);

        $this->form->addHeaderActionLink(_t('Close'), new TAction([__CLASS__, 'onClose']), 'fa:times');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Leitura: carrega o resumo gravado da venda e o sale_id no formulário.
     */
    public function onEdit($param)
    {
        $saleId = is_array($param) && isset($param['sale_id']) ? (int) $param['sale_id'] : 0;

        try
        {
            if ($saleId <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid sale'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $summary = (new \CentralVet\Persistence\SaleFinanceReader($context, TTransaction::get()))->receiptSummary($saleId);
            TTransaction::close();

            if ($summary === null)
            {
                throw new InvalidArgumentException(_t('Invalid sale'));
            }

            $this->summaryBox->add(self::summaryPanel($summary));

            if ($summary['status'] === \CentralVet\Domain\Sale::STATUS_CANCELLED)
            {
                // venda já cancelada: só o selo e o aviso, sem a ação de confirmar
                $this->summaryBox->add(CvBadge::create(_t('Cancelled'), 'danger'));
                $this->summaryBox->add(new TAlert('info', _t('This sale was already cancelled')));
            }
            else
            {
                $btn = $this->form->addAction(_t('Cancel sale'), new TAction([__CLASS__, 'onCancel']), 'fa:ban');
                $btn->class = 'btn btn-danger cv-touch-target';
            }

            $data = new stdClass;
            $data->sale_id = $saleId;
            $this->form->setData($data);
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
     * Gravação (POST + X-CSRF-Token): cancela a venda pelo
     * SaleCancellationService na transação desta tela; qualquer exceção
     * desfaz tudo, inclusive a devolução de estoque já gravada.
     */
    public static function onCancel($param)
    {
        try
        {
            $saleId = isset($param['sale_id']) ? (int) $param['sale_id'] : 0;
            $reason = trim((string) ($param['cancellation_reason'] ?? ''));

            if ($saleId <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid sale'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::buildCancellationService($context, TTransaction::get());
            $result = $service->cancel($saleId, $reason, $context->userId(), self::ACTION);

            TTransaction::close();

            new TMessage('info', $result->alreadyCancelled
                ? _t('This sale was already cancelled')
                : _t('Sale cancelled successfully'));

            TScript::create('Template.closeRightPanel()');
            AdiantiCoreApplication::loadPage('SaleList', 'onReload');
        }
        catch (\CentralVet\Domain\Exception\CashSessionRequiredException $e)
        {
            // o serviço já gravou a devolução do estoque (T-14): o rollback a desfaz
            TTransaction::rollback();
            new TMessage('error', _t('Open a cash session for this unit before reversing payments'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to cancel sales for this unit'));
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e) // catch-all: motivo inválido, venda inexistente, banco
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    public static function onClose($param = null)
    {
        TScript::create('Template.closeRightPanel()');
    }

    /**
     * @param array{sale_id: int, status: string, total_cents: int, receivable_status: ?string, received_cents: int} $summary
     */
    private static function summaryPanel(array $summary): TElement
    {
        $box = new TElement('div');
        $box->style = 'text-align: left; margin-bottom: var(--cv-space-3);';

        $lines = [
            CvFormat::e(_t('Sale') . ' #' . $summary['sale_id']),
            CvFormat::e(_t('Total') . ': ' . CvFormat::money($summary['total_cents'])),
            CvFormat::e(_t('Amount received') . ': ' . CvFormat::money($summary['received_cents'])),
        ];

        $box->add('<p>' . implode('<br>', $lines) . '</p>');

        $status = new TElement('p');
        $status->add(CvFormat::e(_t('Status')) . ': ');
        $status->add(SaleList::saleStatusBadge($summary['status']));
        $status->add(' ' . CvFormat::e(_t('Financial status')) . ': ');
        $status->add(SaleList::financialStatusBadge($summary['receivable_status']));
        $box->add($status);

        return $box;
    }

    private static function buildCancellationService(
        \CentralVet\Tenancy\TenantContext $context,
        $connection
    ): \CentralVet\Application\SaleCancellationService {
        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        $receivables = new \CentralVet\Persistence\ReceivableRepository($context, $connection);

        $stock = new \CentralVet\Application\StockService(
            new \CentralVet\Persistence\StockBatchRepository($context, $connection),
            new \CentralVet\Persistence\StockMovementRepository($context, $connection),
            $authorization,
            $context,
        );

        $reversals = new \CentralVet\Application\PaymentReversalService(
            new \CentralVet\Persistence\PaymentRepository($context, $connection),
            new \CentralVet\Persistence\PaymentReversalRepository($context, $connection),
            $receivables,
            new \CentralVet\Persistence\CashSessionRepository($context, $connection),
            new \CentralVet\Application\FinancialEntryService(
                new \CentralVet\Persistence\FinancialEntryRepository($context, $connection),
                $authorization,
                $context,
            ),
            $authorization,
            $context,
        );

        return new \CentralVet\Application\SaleCancellationService(
            new \CentralVet\Persistence\SaleRepository($context, $connection),
            $receivables,
            $stock,
            $reversals,
            $authorization,
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session (same
     * fallback as PaymentForm::resolveTenantContext() for legacy sessions).
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
