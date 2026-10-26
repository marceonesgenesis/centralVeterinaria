<?php
/**
 * SubscriptionCancelForm
 *
 * Cancelamento da assinatura pelo app (Etapa 5, T-14). Aberto pelo botão
 * "Cancelar assinatura" da SubscriptionStatusView. O construtor só lê: mostra
 * a política (a assinatura segue ativa até o fim do período pago; depois os
 * dados ficam disponíveis para exportação por PilotSettings::
 * retentionDaysAfterCancel() dias e são excluídos), com os links para
 * exportar os dados e para os Termos de Uso, e o botão "Confirmar
 * cancelamento".
 *
 * onConfirm grava: chega por POST do formulário (o cv-shell.js põe o
 * X-CSRF-Token; MutationRequestPolicy nega GET) e delega ao
 * SubscriptionCancellationService (T-08), que agenda o cancelamento no fim
 * do período (Stripe no cartão, marca local no Pix). Em `scheduled` ou
 * `already_scheduled` volta à SubscriptionStatusView com "Cancelamento da
 * assinatura solicitado"; erro de domínio sai em pt-BR por
 * CvFormat::userError (UserMessage). Sem reativação pelo app.
 *
 * @version    1.0
 * @package    control
 * @subpackage admin
 */
class SubscriptionCancelForm extends TPage
{
    private const EXPORT_URL = 'index.php?class=ClinicDataExportView';
    private const TERMS_URL = 'legal.php?doc=termos';

    protected $form;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->form = new BootstrapFormBuilder('form_SubscriptionCancel');
        $this->form->setFormTitle(_t('Cancellation policy'));
        CvForm::decorate($this->form, 1);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Cancel subscription'), _t('Subscription'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=SubscriptionStatusView', 'class' => 'btn btn-default cv-touch-target', 'title' => _t('Back')],
        ], false));

        try
        {
            [$tenantId] = self::resolveSession();

            TTransaction::open('permission');
            $subscription = (new CentralVet\Persistence\SubscriptionRepository(TTransaction::get()))->findByTenantId($tenantId);
            TTransaction::close();

            $this->form->addContent([self::policy($subscription)]);

            if (CentralVet\Presentation\SubscriptionStatusPresenter::canCancel($subscription))
            {
                $btn = $this->form->addAction(_t('Confirm cancellation'), new TAction([$this, 'onConfirm']), 'fa:ban');
                $btn->class = 'btn btn-danger cv-touch-target';
            }
            else
            {
                $scheduled = CentralVet\Presentation\SubscriptionStatusPresenter::cancelScheduledFor($subscription);
                $this->form->addContent([new TAlert('info', $scheduled !== null
                    ? CvFormat::e(_t('Cancellation scheduled for ^1', $scheduled))
                    : CvFormat::userMessage(CentralVet\Billing\SubscriptionCancellationService::NO_ACTIVE_SUBSCRIPTION))]);
            }
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
        }

        $container->add($this->form);
        parent::add($container);
    }

    /**
     * Gravação (POST + X-CSRF-Token): agenda o cancelamento no fim do
     * período pelo SubscriptionCancellationService do tenant da sessão.
     */
    public function onConfirm($param)
    {
        try
        {
            [$tenantId, $userId] = self::resolveSession();

            $pdo = TConnection::open('permission');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $service = new CentralVet\Billing\SubscriptionCancellationService(
                new CentralVet\Persistence\SubscriptionRepository($pdo),
                new CentralVet\Billing\Stripe\CurlStripeClient(CentralVet\Billing\Stripe\StripeConfig::fromEnvironment()),
                new CentralVet\Billing\TransactionRunner($pdo),
            );

            $result = $service->request($tenantId, $userId, new DateTimeImmutable());

            if (in_array($result, [CentralVet\Billing\SubscriptionCancellationService::RESULT_SCHEDULED, CentralVet\Billing\SubscriptionCancellationService::RESULT_ALREADY_SCHEDULED], true))
            {
                new TMessage('info', _t('Subscription cancellation requested'));
                AdiantiCoreApplication::loadPage('SubscriptionStatusView');
            }
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e) // SubscriptionCancellationException (pt-BR por UserMessage), banco
        {
            error_log(__METHOD__ . ': ' . get_class($e) . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /** Texto da política com o fim do período, a retenção e os links. */
    private static function policy(?CentralVet\Billing\Subscription $subscription): TElement
    {
        $box = new TElement('div');
        $box->{'class'} = 'cv-subscription-cancel-policy';
        $box->style = 'text-align:left';

        $periodEnd = $subscription?->currentPeriodEnd()?->format('d/m/Y') ?? '—';
        $retentionDays = CentralVet\Pilot\PilotSettings::fromEnvironment()->retentionDaysAfterCancel();

        $box->add(TElement::tag('p', CvFormat::e(_t('Your subscription remains active until ^1. After that, the clinic data stays available for export for ^2 days and is then deleted', $periodEnd, (string) $retentionDays))));

        $links = new TElement('p');
        $links->style = 'display:flex;flex-wrap:wrap;gap:var(--cv-space-2)';

        $export = new TElement('a');
        $export->{'class'} = 'btn btn-default cv-touch-target';
        $export->{'href'} = self::EXPORT_URL;
        $export->{'generator'} = 'adianti';
        $export->add(new TImage('fa:download'));
        $export->add(TElement::tag('span', CvFormat::e(_t('Export clinic data'))));
        $links->add($export);

        $terms = new TElement('a');
        $terms->{'class'} = 'btn btn-default cv-touch-target';
        $terms->{'href'} = self::TERMS_URL;
        $terms->{'target'} = '_blank';
        $terms->{'rel'} = 'noopener';
        $terms->add(new TImage('fa:file-alt'));
        $terms->add(TElement::tag('span', CvFormat::e(_t('Terms of use'))));
        $links->add($terms);

        $box->add($links);

        return $box;
    }

    /**
     * Tenant e usuário da sessão autenticada (mesmo fallback de
     * SubscriptionStatusView::resolveTenantId() para sessões legadas).
     *
     * @return array{0: int, 1: int}
     *
     * @throws CentralVet\Tenancy\Exception\MissingTenantContext
     */
    private static function resolveSession(): array
    {
        try
        {
            $context = CentralVet\Tenancy\TenantContext::fromAuthenticatedSession(new CentralVet\Tenancy\AdiantiSessionContextSource());

            return [$context->tenantId(), (int) $context->userId()];
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $userid = TSession::getValue('userid');

            if (TSession::getValue('logged') !== true || empty($userid))
            {
                throw $e;
            }

            TTransaction::open('permission');
            $stmt = TTransaction::get()->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
            $stmt->execute(['userid' => (int) $userid]);
            $tenantId = $stmt->fetchColumn();
            TTransaction::close();

            if (empty($tenantId))
            {
                throw $e;
            }

            return [(int) $tenantId, (int) $userid];
        }
    }
}
