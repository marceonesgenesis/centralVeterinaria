<?php
/**
 * OnboardingChecklistView — primeiros passos da clínica (Etapa 5, T-15).
 *
 * Lista os 4 itens do CentralVet\Onboarding\OnboardingChecklistService
 * (dados da clínica, equipe, serviços e preços, orientações do primeiro
 * atendimento) com o estado Feito/Pendente e o atalho de cada um; completo,
 * mostra "Configuração inicial concluída". O botão "Trabalho sozinho"
 * (onMarkTeamReviewed) marca a equipe como revisada para quem não tem equipe.
 *
 * onMarkTeamReviewed é mutação (POST + X-CSRF-Token pela MutationRequestPolicy
 * central, por isso fica num TForm); a abertura da tela só lê.
 *
 * @version    1.0
 * @package    control
 * @subpackage admin
 */
class OnboardingChecklistView extends TPage
{
    /** item → [rótulo, atalhos [rótulo, classe]]. */
    private const ITEMS = [
        CentralVet\Onboarding\OnboardingChecklistService::ITEM_CLINIC => ['Clinic data', [['Open', 'ClinicProfileForm']]],
        CentralVet\Onboarding\OnboardingChecklistService::ITEM_TEAM => ['Team', [['Open', 'ClinicUserList']]],
        CentralVet\Onboarding\OnboardingChecklistService::ITEM_SERVICES => ['Services and prices', [['Open', 'ServiceList'], ['Import services', 'ServiceImportForm']]],
        CentralVet\Onboarding\OnboardingChecklistService::ITEM_GUIDE => ['First encounter guide', [['Open', 'FirstEncounterGuideView']]],
    ];

    public function __construct($param = null)
    {
        parent::__construct();

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('First steps'), _t('Administration'), [], false));

        try
        {
            $service = self::makeService();
            $status = $service->status();
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', _t('An authenticated session with an active unit is required'));
            parent::add($container);
            return;
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
            parent::add($container);
            return;
        }

        $complete = !in_array(false, array_column($status, 'done'), true);
        if ($complete)
        {
            $alert = new TElement('div');
            $alert->{'class'} = 'alert alert-success';
            $alert->{'role'} = 'status';
            $alert->add(CvFormat::e(_t('Initial setup completed')));
            $container->add($alert);
        }

        $container->add($this->checklistCard($status));

        parent::add($container);
    }

    /**
     * Marca a equipe como revisada ("Trabalho sozinho") e recarrega a lista.
     */
    public function onMarkTeamReviewed($param = null)
    {
        try
        {
            self::makeService()->markTeamReviewed(new DateTimeImmutable());

            TToast::show('success', _t('Record saved'));
            TScript::create("__adianti_goto_page('index.php?class=OnboardingChecklistView')");
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

    /**
     * @param list<array{item: string, done: bool}> $status
     */
    private function checklistCard(array $status): TElement
    {
        $card = new TElement('div');
        $card->{'class'} = 'cv-card';

        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->style = 'overflow-x:auto';
        $card->add($body);

        $table = new TElement('table');
        $table->{'class'} = 'table cv-table cv-onboarding-checklist';
        $table->style = 'width:100%';

        $thead = new TElement('thead');
        $head = new TElement('tr');
        foreach ([_t('Item'), _t('Status'), ''] as $title)
        {
            $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col', 'style' => 'text-align:left']));
        }
        $thead->add($head);
        $table->add($thead);

        $tbody = new TElement('tbody');
        foreach ($status as $row)
        {
            if (!isset(self::ITEMS[$row['item']]))
            {
                continue;
            }
            [$label, $shortcuts] = self::ITEMS[$row['item']];

            $tr = new TElement('tr');
            $tr->add(TElement::tag('th', CvFormat::e(_t($label)), ['scope' => 'row', 'style' => 'text-align:left']));

            $state = new TElement('td');
            $state->add($row['done'] ? CvBadge::create(_t('Done'), 'success') : CvBadge::create(_t('Pending'), 'warning'));
            $tr->add($state);

            $actions = new TElement('td');
            $actions->{'class'} = 'cv-onboarding-checklist__actions';
            foreach ($shortcuts as [$text, $class])
            {
                $link = new TElement('a');
                $link->{'class'} = 'btn btn-sm btn-default cv-touch-target me-1 mb-1';
                $link->{'href'} = 'index.php?class=' . $class;
                $link->{'generator'} = 'adianti';
                $link->add(TElement::tag('span', CvFormat::e(_t($text))));
                $actions->add($link);
            }
            if ($row['item'] === CentralVet\Onboarding\OnboardingChecklistService::ITEM_TEAM && !$row['done'])
            {
                $actions->add($this->workAloneForm());
            }
            $tr->add($actions);

            $tbody->add($tr);
        }
        $table->add($tbody);
        $body->add($table);

        return $card;
    }

    /** Formulário só com o botão "Trabalho sozinho" (POST para onMarkTeamReviewed). */
    private function workAloneForm(): TForm
    {
        $form = new TForm('form_OnboardingWorkAlone');
        $form->style = 'display:inline-block';

        $button = new TButton('work_alone');
        $button->setAction(new TAction([$this, 'onMarkTeamReviewed']), _t('I work alone'));
        $button->setImage('fa:user');
        $button->class = 'btn btn-sm btn-default cv-touch-target mb-1';

        $form->add($button);
        $form->setFields([$button]);

        return $form;
    }

    private static function makeService(): CentralVet\Onboarding\OnboardingChecklistService
    {
        $pdo = TConnection::open('permission');

        return new CentralVet\Onboarding\OnboardingChecklistService($pdo, self::tenantContext($pdo));
    }

    /**
     * Tenant da sessão autenticada, com o fallback por tenant_user das demais
     * telas da clínica (ClinicUnitForm::resolveTenantContext). Usado também
     * por ClinicProfileForm e FirstEncounterGuideView.
     *
     * @throws CentralVet\Tenancy\Exception\MissingTenantContext
     */
    public static function tenantContext(PDO $pdo): CentralVet\Tenancy\TenantContext
    {
        try
        {
            return CentralVet\Tenancy\TenantContext::fromAuthenticatedSession(new CentralVet\Tenancy\AdiantiSessionContextSource());
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $userid = TSession::getValue('userid');

            if (TSession::getValue('logged') !== true || empty($userid))
            {
                throw $e;
            }

            $stmt = $pdo->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
            $stmt->execute(['userid' => (int) $userid]);
            $tenant_id = $stmt->fetchColumn();

            if (empty($tenant_id))
            {
                throw $e;
            }

            $unit_id = TSession::getValue('userunitid');

            return CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}
