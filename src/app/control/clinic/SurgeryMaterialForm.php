<?php
/**
 * SurgeryMaterialForm
 *
 * Materiais usados na cirurgia (Fase 6B, T-16): produto ativo do tenant,
 * quantidade, botão "Adicionar" e a lista dos materiais já registrados com
 * "Remover" (confirmação por TQuestion, que só carrega ids). Gravar e remover
 * só com a cirurgia `in_progress`; fora disso a tela é só leitura. Estoque e
 * cobrança acontecem na conclusão (T-11), não aqui.
 *
 * Entrada: `surgery_id` ($_GET, senão $param). Sem ele, estado vazio sem
 * tocar no banco.
 *
 * Consome só `SurgeryMaterialService` (T-10): `listMaterials()`,
 * `listActiveProducts()`, `addMaterial()` e `removeMaterial()`, cada ação
 * num único TTransaction.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SurgeryMaterialForm extends TPage
{
    protected $form;

    private const ACTION_LOAD = 'SurgeryMaterialForm::onLoad';
    private const ACTION_ADD = 'SurgeryMaterialForm::onAdd';
    private const ACTION_REMOVE = 'SurgeryMaterialForm::onRemove';

    private const SUBMIT_CLASS = 'cv-material-submit';

    public function __construct($param = null)
    {
        parent::__construct();

        $surgeryId = self::paramInt('surgery_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';

        if ($surgeryId === null || $surgeryId <= 0)
        {
            $container->add(CvPage::header(_t('Surgery materials'), null, [self::backAction(null)]));
            $state = new TElement('div');
            $state->{'class'} = 'cv-state cv-state--empty';
            $state->add(TElement::tag('p', CvFormat::e(_t('Provide a surgery_id to record materials.')), ['class' => 'cv-state__title']));
            $container->add($state);
            parent::add($container);
            return;
        }

        $data = null;

        try
        {
            $data = self::loadData($surgeryId);
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

        $editable = $data !== null && $data['editable'];

        $container->add(CvPage::header(_t('Surgery materials'), _t('Surgery') . ' #' . $surgeryId, [self::backAction($surgeryId)]));

        $this->form = new BootstrapFormBuilder('form_SurgeryMaterial');
        $this->form->setFormTitle(_t('Surgery materials'));

        $surgery_id = new THidden('surgery_id');
        $surgery_id->setValue($surgeryId);
        $hiddenRow = $this->form->addFields([$surgery_id]);
        $hiddenRow->style = 'display: none';

        $product_id = new TCombo('product_id');
        $product_id->setSize('100%');
        $product_id->addItems($data['products'] ?? []);

        $quantity = new TEntry('quantity');
        $quantity->setSize('100%');
        $quantity->setProperty('type', 'number');
        $quantity->setProperty('min', '1');
        $quantity->setProperty('step', '1');
        $quantity->setProperty('inputmode', 'numeric');
        $quantity->setValue('1');

        $this->form->addFields([new TLabel(_t('Product'))], [$product_id], [new TLabel(_t('Quantity'))], [$quantity]);

        if ($editable)
        {
            $add = $this->form->addAction(_t('Add'), new TAction([$this, 'onAdd']), 'fa:plus');
            $add->class = 'btn btn-primary btn-lg cv-touch-target ' . self::SUBMIT_CLASS;
            $add->addFunction("document.querySelectorAll('." . self::SUBMIT_CLASS . "').forEach(function(b){b.disabled=true;})");
        }
        else
        {
            $product_id->setEditable(false);
            $quantity->setEditable(false);

            if ($data !== null)
            {
                $this->form->addContent([TElement::tag('p', CvFormat::e(_t('Materials can only be changed while the surgery is in progress')), ['class' => 'text-muted'])]);
            }
        }

        CvForm::decorate($this->form, 2);

        $container->add($this->form);
        $container->add(self::materialsPanel($surgeryId, $data['materials'] ?? [], $editable));

        parent::add($container);
    }

    /** Rota padrão do Adianti: a tela já carrega no construtor. */
    public function onLoad($param = null)
    {
    }

    public function onAdd($param)
    {
        try
        {
            $surgeryId = self::positiveInt($param['surgery_id'] ?? null, 'surgery_id');
            $productId = self::positiveInt($param['product_id'] ?? null, 'product_id');
            $quantity = self::positiveInt($param['quantity'] ?? null, 'quantity');

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            self::makeMaterialService($context)->addMaterial($surgeryId, $productId, $quantity, self::ACTION_ADD);
            TTransaction::close();

            TToast::show('success', _t('Material added'));
            TScript::create("__adianti_goto_page('" . self::pageUrl($surgeryId) . "')");
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
     * Confirmação da remoção: a TQuestion só carrega ids.
     */
    public static function onAskRemove($param = null)
    {
        $action = new TAction([__CLASS__, 'onRemove']);
        $action->setParameter('surgery_id', (int) ($param['surgery_id'] ?? 0));
        $action->setParameter('material_id', (int) ($param['material_id'] ?? 0));

        new TQuestion(_t('Remove this material from the surgery?'), $action);
    }

    public function onRemove($param)
    {
        try
        {
            $surgeryId = self::positiveInt($param['surgery_id'] ?? null, 'surgery_id');
            $materialId = self::positiveInt($param['material_id'] ?? null, 'material_id');

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            self::makeMaterialService($context)->removeMaterial($materialId, self::ACTION_REMOVE);
            TTransaction::close();

            TToast::show('success', _t('Material removed'));
            TScript::create("__adianti_goto_page('" . self::pageUrl($surgeryId) . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this surgery'));
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
     * Materiais (autoriza a cirurgia), status e, se em andamento, produtos ativos.
     *
     * @return array{editable: bool, materials: list<array{id: int, product: string, quantity: int, recorded_at: string}>, products: array<int, string>}
     */
    private static function loadData(int $surgeryId): array
    {
        $context = self::resolveTenantContext();

        TTransaction::open('permission');
        $connection = TTransaction::get();

        $service = self::makeMaterialService($context);
        $rows = $service->listMaterials($surgeryId, self::ACTION_LOAD);

        $surgery = (new \CentralVet\Persistence\SurgeryRepository($context, $connection))->findById($surgeryId);
        $editable = $surgery instanceof \CentralVet\Domain\Surgery
            && $surgery->status() === \CentralVet\Domain\Surgery::STATUS_IN_PROGRESS;

        $products = [];

        if ($editable)
        {
            foreach ($service->listActiveProducts(self::ACTION_LOAD) as $product)
            {
                $products[(int) $product->id()] = $product->name() . ' (' . $product->unitOfMeasure() . ')';
            }
        }

        TTransaction::close();

        $materials = [];

        foreach ($rows as $row)
        {
            $material = $row['material'];
            $materials[] = [
                'id' => (int) $material->id(),
                'product' => (string) $row['product_name'],
                'quantity' => $material->quantity(),
                'recorded_at' => $material->recordedAt()->format('d/m/Y H:i'),
            ];
        }

        return ['editable' => $editable, 'materials' => $materials, 'products' => $products];
    }

    /** @param list<array{id: int, product: string, quantity: int, recorded_at: string}> $materials */
    private static function materialsPanel(int $surgeryId, array $materials, bool $editable): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Materials used'));

        if ($materials === [])
        {
            $state = new TElement('div');
            $state->{'class'} = 'cv-state cv-state--empty';
            $state->add(TElement::tag('p', CvFormat::e(_t('No materials recorded for this surgery')), ['class' => 'cv-state__title']));
            $panel->add($state);

            return $panel;
        }

        $html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>'
            . '<th>' . CvFormat::e(_t('Product')) . '</th>'
            . '<th class="text-end">' . CvFormat::e(_t('Quantity')) . '</th>'
            . '<th>' . CvFormat::e(_t('Recorded at')) . '</th>'
            . ($editable ? '<th><span class="visually-hidden">' . CvFormat::e(_t('Actions')) . '</span></th>' : '')
            . '</tr></thead><tbody>';

        foreach ($materials as $material)
        {
            $html .= '<tr>'
                . '<td>' . CvFormat::e($material['product']) . '</td>'
                . '<td class="text-end">' . (int) $material['quantity'] . '</td>'
                . '<td>' . CvFormat::e($material['recorded_at']) . '</td>';

            if ($editable)
            {
                $url = 'index.php?class=SurgeryMaterialForm&method=onAskRemove&surgery_id=' . $surgeryId . '&material_id=' . (int) $material['id'];
                $html .= '<td class="text-end"><a class="btn btn-outline-danger cv-touch-target" generator="adianti" href="' . CvFormat::e($url) . '">'
                    . CvFormat::e(_t('Remove')) . '</a></td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        $element = new TElement('div');
        $element->add($html);
        $panel->add($element);

        return $panel;
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

    private static function pageUrl(int $surgeryId): string
    {
        return 'index.php?class=SurgeryMaterialForm&surgery_id=' . $surgeryId;
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

    /** Requer TTransaction('permission') aberta. */
    private static function makeMaterialService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SurgeryMaterialService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\SurgeryMaterialService(
            new \CentralVet\Persistence\SurgeryRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryMaterialRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryEventRepository($context, $connection),
            new \CentralVet\Persistence\ProductRepository($context, $connection),
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
