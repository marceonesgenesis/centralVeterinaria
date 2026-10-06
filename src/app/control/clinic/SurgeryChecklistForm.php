<?php
/**
 * SurgeryChecklistForm
 *
 * Checklist de segurança cirúrgica no tablet (Fase 6B, T-16): uma fase por
 * tela (`sign_in`, `time_out`, `sign_out`), um checkbox grande por item do
 * catálogo fixo `SurgeryChecklist` e o botão "Confirmar fase". A fase já
 * confirmada mostra quem e quando confirmou, sem botão. O botão fica
 * desabilitado no primeiro toque; um segundo envio é recusado pelo service
 * (`Checklist phase "<fase>" is already confirmed for surgery <id>`) e pela
 * chave UNIQUE do banco.
 *
 * Entrada: `surgery_id` e `phase` ($_GET, senão $param). Fase desconhecida
 * ou sem cirurgia: estado vazio, sem tocar no banco.
 *
 * Consome só `SurgeryChecklistService` (T-09): `phaseStatus()` e
 * `confirmPhase()`, cada chamada num único TTransaction.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SurgeryChecklistForm extends TPage
{
    protected $form;

    private const ACTION_LOAD = 'SurgeryChecklistForm::onLoad';
    private const ACTION_CONFIRM = 'SurgeryChecklistForm::onConfirm';

    private const SUBMIT_CLASS = 'cv-checklist-submit';

    public function __construct($param = null)
    {
        parent::__construct();

        $surgeryId = self::paramInt('surgery_id', $param);
        $phase = self::paramString('phase', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';

        if (!in_array($phase, \CentralVet\Domain\SurgeryChecklist::PHASES, true))
        {
            $container->add(CvPage::header(_t('Surgical safety checklist'), null, [self::backAction($surgeryId)]));
            $container->add(self::emptyState(_t('Unknown checklist phase')));
            parent::add($container);
            return;
        }

        $title = _t(\CentralVet\Domain\SurgeryChecklist::phaseLabel($phase));

        if ($surgeryId === null || $surgeryId <= 0)
        {
            $container->add(CvPage::header($title, null, [self::backAction(null)]));
            $container->add(self::emptyState(_t('Provide a surgery_id to fill in the checklist.')));
            parent::add($container);
            return;
        }

        $status = null;

        try
        {
            $status = self::loadPhaseStatus($surgeryId, $phase);
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to access this surgery'));
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

        $container->add(CvPage::header($title, _t('Surgery') . ' #' . $surgeryId, [self::backAction($surgeryId)]));
        $container->add(self::phaseNav($surgeryId, $phase));

        $this->form = new BootstrapFormBuilder('form_SurgeryChecklist');
        $this->form->setFormTitle($title);

        $surgery_id = new THidden('surgery_id');
        $surgery_id->setValue($surgeryId);
        $phase_field = new THidden('phase');
        $phase_field->setValue($phase);

        $hiddenRow = $this->form->addFields([$surgery_id, $phase_field]);
        $hiddenRow->style = 'display: none';

        $confirmed = $status !== null && $status['confirmed'];

        if ($confirmed)
        {
            $this->form->addContent([self::confirmedNotice($status['checked_by'], $status['checked_at'])]);
        }

        $this->form->addContent([self::checklistElement(self::itemFields($phase), $confirmed)]);

        if ($status !== null && !$confirmed)
        {
            $disable = "document.querySelectorAll('." . self::SUBMIT_CLASS . "').forEach(function(b){b.disabled=true;})";

            $confirm = $this->form->addAction(_t('Confirm phase'), new TAction([$this, 'onConfirm']), 'fa:check');
            $confirm->class = 'btn btn-success btn-lg cv-touch-target ' . self::SUBMIT_CLASS;
            $confirm->style = 'min-height:56px;min-width:200px;font-size:1.15rem';
            $confirm->addFunction($disable);
        }

        CvForm::decorate($this->form, 1);

        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Um checkbox por código da fase, na ordem do catálogo: nome `items[]`,
     * valor = código, classe `cv-checklist__item`.
     *
     * @return list<TCheckButton>
     */
    public static function itemFields(string $phase): array
    {
        $fields = [];

        foreach (\CentralVet\Domain\SurgeryChecklist::items($phase) as $code)
        {
            $field = new TCheckButton('items[]');
            $field->setIndexValue($code);
            $field->setProperty('value', $code);
            $field->setProperty('class', 'cv-checklist__item');
            $field->setProperty('id', 'cv-checklist-' . $code);
            $field->setProperty('data-cv-label', _t(\CentralVet\Domain\SurgeryChecklist::label($code)));
            $field->setProperty(
                'onchange',
                "this.classList.toggle('cv-checklist__item--checked', this.checked);"
                . "this.closest('.cv-checklist__row').classList.toggle('cv-checklist__row--checked', this.checked);"
            );
            $fields[] = $field;
        }

        return $fields;
    }

    /** Rota padrão do Adianti: a tela já carrega no construtor. */
    public function onLoad($param = null)
    {
    }

    public function onConfirm($param)
    {
        try
        {
            $surgeryId = self::positiveInt($param['surgery_id'] ?? null, 'surgery_id');
            $phase = (string) ($param['phase'] ?? '');
            $items = $param['items'] ?? [];
            $items = is_array($items) ? array_values(array_map('strval', $items)) : [];

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            self::makeChecklistService($context)->confirmPhase($surgeryId, $phase, $items, self::ACTION_CONFIRM);
            TTransaction::close();

            TToast::show('success', _t('Checklist phase confirmed'));
            TScript::create("__adianti_goto_page('" . self::pageUrl($surgeryId, $phase) . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            self::enableSubmit();
            new TMessage('error', _t('You are not allowed to change this surgery'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            self::enableSubmit();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            self::enableSubmit();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Estado da fase pedida, com o nome de quem confirmou.
     *
     * @return array{confirmed: bool, checked_by: string, checked_at: string}
     */
    private static function loadPhaseStatus(int $surgeryId, string $phase): array
    {
        $context = self::resolveTenantContext();

        TTransaction::open('permission');

        $all = self::makeChecklistService($context)->phaseStatus($surgeryId, self::ACTION_LOAD);
        $state = $all[$phase];

        $checkedBy = '';

        if ($state['checked_by_system_user_id'] !== null)
        {
            $user = SystemUser::find((int) $state['checked_by_system_user_id']);
            $checkedBy = $user instanceof SystemUser ? (string) $user->name : '#' . $state['checked_by_system_user_id'];
        }

        TTransaction::close();

        return [
            'confirmed' => (bool) $state['confirmed'],
            'checked_by' => $checkedBy,
            'checked_at' => $state['checked_at'] instanceof DateTimeInterface ? $state['checked_at']->format('d/m/Y H:i') : '',
        ];
    }

    /** @param list<TCheckButton> $fields */
    private static function checklistElement(array $fields, bool $confirmed): TElement
    {
        $list = new TElement('div');
        $list->{'class'} = 'cv-checklist';

        foreach ($fields as $field)
        {
            if ($confirmed)
            {
                $field->setValue((string) $field->getProperty('value'));
                $field->setProperty('class', 'cv-checklist__item cv-checklist__item--checked');
                $field->setEditable(false);
            }

            $row = new TElement('label');
            $row->{'class'} = 'cv-checklist__row' . ($confirmed ? ' cv-checklist__row--checked' : '');
            $row->{'for'} = (string) $field->getProperty('id');
            $row->add($field);
            $row->add(TElement::tag('span', CvFormat::e((string) $field->getProperty('data-cv-label')), ['class' => 'cv-checklist__label']));
            $list->add($row);
        }

        return $list;
    }

    private static function confirmedNotice(string $checkedBy, string $checkedAt): TElement
    {
        $notice = new TElement('p');
        $notice->{'class'} = 'cv-checklist__done';
        $notice->add(CvFormat::e(_t('Phase confirmed by ^1 at ^2', $checkedBy, $checkedAt)));

        return $notice;
    }

    private static function phaseNav(int $surgeryId, string $current): TElement
    {
        $nav = new TElement('nav');
        $nav->{'class'} = 'cv-checklist__phases';
        $nav->{'aria-label'} = CvFormat::e(_t('Checklist phases'));

        foreach (\CentralVet\Domain\SurgeryChecklist::PHASES as $phase)
        {
            $attributes = [
                'class' => 'btn cv-touch-target ' . ($phase === $current ? 'btn-primary' : 'btn-default'),
                'href' => self::pageUrl($surgeryId, $phase),
                'generator' => 'adianti',
            ];

            if ($phase === $current)
            {
                $attributes['aria-current'] = 'page';
            }

            $nav->add(TElement::tag('a', CvFormat::e(_t(\CentralVet\Domain\SurgeryChecklist::phaseLabel($phase))), $attributes));
        }

        return $nav;
    }

    private static function emptyState(string $message): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->add(TElement::tag('p', CvFormat::e($message), ['class' => 'cv-state__title']));

        return $state;
    }

    private static function backAction(?int $surgeryId): array
    {
        return [
            'icon' => 'fa:arrow-left',
            'title' => _t('Back'),
            'href' => ($surgeryId !== null && $surgeryId > 0)
                ? 'index.php?class=SurgeryView&id=' . $surgeryId
                : 'index.php?class=SurgeryList',
        ];
    }

    private static function pageUrl(int $surgeryId, string $phase): string
    {
        return 'index.php?class=SurgeryChecklistForm&surgery_id=' . $surgeryId . '&phase=' . rawurlencode($phase);
    }

    private static function enableSubmit(): void
    {
        TScript::create("document.querySelectorAll('." . self::SUBMIT_CLASS . "').forEach(function(b){b.disabled=false;})");
    }

    private static function positiveInt($raw, string $name): int
    {
        $raw = trim((string) $raw);

        if ($raw === '' || !ctype_digit($raw) || (int) $raw <= 0)
        {
            throw new InvalidArgumentException($name . ' must be a positive integer');
        }

        return (int) $raw;
    }

    private static function paramInt(string $name, $param): ?int
    {
        if (isset($_GET[$name]) && $_GET[$name] !== '')
        {
            return (int) $_GET[$name];
        }

        if (is_array($param) && isset($param[$name]) && $param[$name] !== '')
        {
            return (int) $param[$name];
        }

        return null;
    }

    private static function paramString(string $name, $param): string
    {
        if (isset($_GET[$name]) && is_string($_GET[$name]))
        {
            return $_GET[$name];
        }

        if (is_array($param) && isset($param[$name]) && is_scalar($param[$name]))
        {
            return (string) $param[$name];
        }

        return '';
    }

    /** Requer TTransaction('permission') aberta. */
    private static function makeChecklistService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SurgeryChecklistService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\SurgeryChecklistService(
            new \CentralVet\Persistence\SurgeryRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryChecklistRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryEventRepository($context, $connection),
            new \CentralVet\Authorization\RbacAuthorizationService(
                new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
                new \CentralVet\Audit\PdoAuditLogWriter($connection),
            ),
            $context,
        );
    }

    /**
     * Contexto do tenant da sessão autenticada (cópia de
     * HospitalizationAdministrationForm::resolveTenantContext()).
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
