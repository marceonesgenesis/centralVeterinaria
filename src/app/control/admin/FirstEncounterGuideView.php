<?php
/**
 * FirstEncounterGuideView — orientações do primeiro atendimento (Etapa 5, T-15).
 *
 * Mostra o passo a passo de app/view/onboarding/first-encounter-guide.html
 * (agenda → chegada na fila → atendimento → prescrição e PDF → conta do
 * atendimento → recebimento parcial/total → conferência do caixa) e o botão
 * "Marcar como lida" (onMarkRead), que marca o item do checklist pelo
 * OnboardingChecklistService::markGuideRead() e volta aos primeiros passos.
 *
 * onMarkRead é mutação (POST + X-CSRF-Token pela MutationRequestPolicy
 * central, por isso fica num TForm); a abertura da tela só lê.
 *
 * @version    1.0
 * @package    control
 * @subpackage admin
 */
class FirstEncounterGuideView extends TPage
{
    private const GUIDE_FILE = __DIR__ . '/../../view/onboarding/first-encounter-guide.html';

    public function __construct($param = null)
    {
        parent::__construct();

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('First encounter guide'), _t('First steps'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=OnboardingChecklistView', 'class' => 'btn btn-default cv-touch-target', 'title' => _t('Back')],
        ], false));

        $card = new TElement('div');
        $card->{'class'} = 'cv-card';

        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $guide = is_file(self::GUIDE_FILE) ? file_get_contents(self::GUIDE_FILE) : false;
        if (is_string($guide))
        {
            // Conteúdo estático do próprio repositório (sem dado do usuário).
            $body->add($guide);
        }
        $card->add($body);

        $form = new TForm('form_FirstEncounterGuide');
        $button = new TButton('mark_read');
        $button->setAction(new TAction([$this, 'onMarkRead']), _t('Mark as read'));
        $button->setImage('fa:check');
        $button->class = 'btn btn-primary cv-touch-target';
        $form->add($button);
        $form->setFields([$button]);

        $footer = new TElement('div');
        $footer->{'class'} = 'cv-card__footer';
        $footer->add($form);
        $card->add($footer);

        $container->add($card);

        parent::add($container);
    }

    /**
     * Marca o guia como lido e volta aos primeiros passos.
     */
    public function onMarkRead($param = null)
    {
        try
        {
            $pdo = TConnection::open('permission');
            (new CentralVet\Onboarding\OnboardingChecklistService($pdo, OnboardingChecklistView::tenantContext($pdo)))
                ->markGuideRead(new DateTimeImmutable());

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
}
