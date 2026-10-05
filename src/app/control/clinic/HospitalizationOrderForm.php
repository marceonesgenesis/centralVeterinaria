<?php
/**
 * HospitalizationOrderForm
 *
 * Prescrição interna da internação (Fase 6A, T-15): tipo, descrição,
 * produto opcional (ativos do tenant) com quantidade por administração,
 * dose, via, frequência em horas e período (dd/mm/aaaa hh:mm). Toda regra
 * (internação admitida, período ≤ 30 dias, agenda das administrações,
 * autorização por unidade) fica em
 * CentralVet\Application\HospitalizationOrderService::prescribe().
 *
 * Entrada: `hospitalization_id` ($_GET, senão $param). Sem ele, estado vazio
 * sem tocar no banco. Depois de salvar volta para
 * HospitalizationView&id=<hospitalization_id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class HospitalizationOrderForm extends TPage
{
    protected $form;

    private const ACTION_SAVE = 'HospitalizationOrderForm::onSave';

    /** Obrigatórios para todo tipo (alimentação inclusive), na ordem da tela. */
    private const REQUIRED_FIELDS = ['order_type', 'description_text', 'route', 'frequency_hours', 'starts_at', 'ends_at'];

    public function __construct($param = null)
    {
        parent::__construct();

        $hospitalizationId = self::paramInt('hospitalization_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Hospitalization order'), null, [[
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($hospitalizationId),
        ]]));

        if ($hospitalizationId === null)
        {
            $panel = new TPanelGroup(_t('Hospitalization order'));
            $panel->add('<p>' . _t('Provide a hospitalization_id to prescribe.') . '</p>');
            $container->add($panel);
            parent::add($container);
            return;
        }

        $this->form = new BootstrapFormBuilder('form_HospitalizationOrder');
        $this->form->setFormTitle(_t('Hospitalization order') . ' — #' . $hospitalizationId);

        $hospitalization_id = new THidden('hospitalization_id');
        $hospitalization_id->setValue($hospitalizationId);

        $order_type = new TCombo('order_type');
        $order_type->addItems(self::typeLabels());
        $order_type->setValue(\CentralVet\Domain\HospitalizationOrder::TYPE_MEDICATION);

        $description_text = new TEntry('description_text');
        $description_text->setProperty('maxlength', '255');

        $product_id = new TCombo('product_id');
        $product_id->addItems(self::productOptions());
        $product_id->enableSearch();

        $quantity_per_administration = new TEntry('quantity_per_administration');
        $quantity_per_administration->setProperty('inputmode', 'numeric');

        $dose_text = new TEntry('dose_text');
        $dose_text->setProperty('maxlength', '120');

        $route = new TCombo('route');
        $route->addItems(self::routeLabels());

        $frequency_hours = new TEntry('frequency_hours');
        $frequency_hours->setProperty('inputmode', 'numeric');

        // TEntry com máscara, sem TDateTime (mesmo motivo do AppointmentForm,
        // T-53): o texto digitado vai cru a DateTimeInput::parse (estrito).
        $starts_at = self::dateTimeEntry('starts_at');
        $starts_at->setValue((new DateTimeImmutable())->format('d/m/Y H:i'));
        $ends_at = self::dateTimeEntry('ends_at');

        $hiddenRow = $this->form->addFields([$hospitalization_id]);
        $hiddenRow->style = 'display: none';

        // obrigatórios (Correção 2): rótulo marcado e atributo required/aria
        foreach ([$order_type, $description_text, $route, $frequency_hours, $starts_at, $ends_at] as $requiredField)
        {
            $requiredField->setProperty('required', 'required');
            $requiredField->setProperty('aria-required', 'true');
        }

        $this->form->addFields([self::label('order_type')], [$order_type], [self::label('description_text')], [$description_text]);
        $this->form->addFields([self::label('product_id')], [$product_id], [self::label('quantity_per_administration')], [$quantity_per_administration]);
        $this->form->addFields([self::label('dose_text')], [$dose_text], [self::label('route')], [$route]);
        $this->form->addFields([self::label('frequency_hours')], [$frequency_hours]);
        $this->form->addFields([self::label('starts_at')], [$starts_at], [self::label('ends_at')], [$ends_at]);

        $btn = $this->form->addAction(_t('Prescribe'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary cv-touch-target';
        // um toque: evita prescrição em dobro por clique repetido
        $btn->addFunction("this.disabled=true");

        CvForm::decorate($this->form, 2);

        $container->add($this->form);

        parent::add($container);
    }

    public function onSave($param)
    {
        $dateField = null;

        try
        {
            // obrigatórios antes de qualquer conversão: a mensagem cita o
            // rótulo do primeiro campo vazio, na ordem da tela
            foreach (self::REQUIRED_FIELDS as $name)
            {
                if (trim((string) ($param[$name] ?? '')) === '')
                {
                    throw new InvalidArgumentException("{$name} is required");
                }
            }

            $hospitalizationId = self::requiredInt($param, 'hospitalization_id');
            $productId = self::optionalInt($param, 'product_id');
            $quantity = self::optionalInt($param, 'quantity_per_administration');
            $frequency = self::optionalInt($param, 'frequency_hours') ?? 0;
            $dateField = 'starts_at';
            $startsAt = \CentralVet\Presentation\DateTimeInput::parse(trim((string) ($param['starts_at'] ?? '')));
            $dateField = 'ends_at';
            $endsAt = \CentralVet\Presentation\DateTimeInput::parse(trim((string) ($param['ends_at'] ?? '')));
            $dateField = null;

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $order = self::makeOrderService($context)->prescribe(
                $hospitalizationId,
                trim((string) ($param['order_type'] ?? '')),
                trim((string) ($param['description_text'] ?? '')),
                $productId,
                $quantity,
                trim((string) ($param['dose_text'] ?? '')),
                trim((string) ($param['route'] ?? '')),
                $frequency,
                $startsAt,
                $endsAt,
                self::ACTION_SAVE,
            );

            TTransaction::close();

            TToast::show('success', _t('Order prescribed successfully') . ' (#' . $order->id() . ')');
            TScript::create("__adianti_goto_page('" . self::returnUrl($hospitalizationId) . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            new TMessage('error', _t('You are not allowed to prescribe for this hospitalization'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', self::fieldError($e, $dateField));
        }
    }

    /**
     * Correção 2: a página é reconstruída no POST; sem isto o formulário
     * voltava com os valores padrão e o usuário perdia o que digitou.
     */
    private function keepTypedData($param): void
    {
        if ($this->form !== null && is_array($param))
        {
            $data = [];

            foreach (array_keys(self::fieldLabels()) as $name)
            {
                if (array_key_exists($name, $param))
                {
                    $data[$name] = $param[$name];
                }
            }

            $this->form->setData((object) $data);
        }

        self::enableSubmit();
    }

    /**
     * Mensagem de erro com o rótulo do campo no lugar do nome técnico
     * (`description_text is required` → "Campo obrigatório: Descrição").
     * Falha de banco e mensagens sem campo seguem CvFormat::userError().
     */
    private static function fieldError(Throwable $e, ?string $dateField): string
    {
        $labels = self::fieldLabels();
        $generic = CvFormat::userError($e);

        if (!$e instanceof InvalidArgumentException || $e->getPrevious() instanceof PDOException)
        {
            return $generic;
        }

        if ($dateField !== null && isset($labels[$dateField]))
        {
            return $generic . ': ' . CvFormat::e($labels[$dateField]);
        }

        $resolved = \CentralVet\Presentation\UserMessage::resolve((string) $e->getMessage());

        if ($resolved === null || $resolved['params'] === [] || !isset($labels[$resolved['params'][0]]))
        {
            return $generic;
        }

        $params = $resolved['params'];
        $params[0] = $labels[$params[0]];

        return _t($resolved['key'], ...array_map([CvFormat::class, 'e'], $params));
    }

    /** @return array<string, string> nome do campo → rótulo traduzido */
    private static function fieldLabels(): array
    {
        return [
            'order_type' => _t('Type'),
            'description_text' => _t('Description'),
            'product_id' => _t('Product'),
            'quantity_per_administration' => _t('Quantity per administration'),
            'dose_text' => _t('Dose'),
            'route' => _t('Route'),
            'frequency_hours' => _t('Frequency (hours)'),
            'starts_at' => _t('Starts at'),
            'ends_at' => _t('Ends at'),
        ];
    }

    /** Rótulo do campo; obrigatório ganha " *" em vermelho (convenção Adianti). */
    private static function label(string $name): TLabel
    {
        $text = self::fieldLabels()[$name];

        return in_array($name, self::REQUIRED_FIELDS, true)
            ? new TLabel($text . ' *', '#dc3545')
            : new TLabel($text);
    }

    private static function enableSubmit(): void
    {
        TScript::create("document.querySelectorAll('#form_HospitalizationOrder button').forEach(function(b){b.disabled=false;})");
    }

    /** @return array<string, string> */
    private static function typeLabels(): array
    {
        return [
            \CentralVet\Domain\HospitalizationOrder::TYPE_MEDICATION => _t('Medication'),
            \CentralVet\Domain\HospitalizationOrder::TYPE_FEEDING => _t('Feeding'),
            \CentralVet\Domain\HospitalizationOrder::TYPE_PROCEDURE => _t('Procedure'),
        ];
    }

    /**
     * Rótulos das vias, na ordem e com exatamente as chaves de
     * HospitalizationOrder::ROUTES.
     *
     * @return array<string, string>
     */
    public static function routeLabels(): array
    {
        $labels = [
            'oral' => _t('Oral'),
            'iv' => _t('Intravenous'),
            'im' => _t('Intramuscular'),
            'sc' => _t('Subcutaneous'),
            'topical' => _t('Topical'),
            'inhalation' => _t('Inhalation'),
            'other' => _t('Other'),
        ];

        $items = [];

        foreach (\CentralVet\Domain\HospitalizationOrder::ROUTES as $route)
        {
            $items[$route] = $labels[$route] ?? $route;
        }

        return $items;
    }

    /**
     * Produtos ativos do tenant. Sem sessão/conexão (ex.: inspeção em CLI),
     * a lista fica vazia em vez de quebrar a tela.
     *
     * @return array<int, string>
     */
    private static function productOptions(): array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $products = (new \CentralVet\Persistence\ProductRepository($context, TTransaction::get()))->findActive();
            TTransaction::close();
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            return [];
        }

        $items = [];

        foreach ($products as $product)
        {
            $items[(int) $product->id()] = $product->name() . ' (' . $product->unitOfMeasure() . ')';
        }

        return $items;
    }

    private static function dateTimeEntry(string $name): TEntry
    {
        $entry = new TEntry($name);
        $entry->setMask('99/99/9999 99:99');
        $entry->placeholder = 'dd/mm/aaaa hh:mm';
        $entry->setProperty('inputmode', 'numeric');
        $entry->setProperty('autocomplete', 'off');

        return $entry;
    }

    private static function returnUrl(?int $hospitalizationId): string
    {
        return ($hospitalizationId !== null && $hospitalizationId > 0)
            ? 'index.php?class=HospitalizationView&id=' . $hospitalizationId
            : 'index.php?class=HospitalizationBoard';
    }

    private static function requiredInt($param, string $name): int
    {
        $value = self::optionalInt($param, $name);

        if ($value === null || $value <= 0)
        {
            throw new InvalidArgumentException("{$name} must be a positive integer");
        }

        return $value;
    }

    private static function optionalInt($param, string $name): ?int
    {
        $raw = is_array($param) && isset($param[$name]) ? trim((string) $param[$name]) : '';

        if ($raw === '')
        {
            return null;
        }

        if (!ctype_digit($raw))
        {
            throw new InvalidArgumentException("{$name} must be a positive integer");
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
    private static function makeOrderService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\HospitalizationOrderService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\HospitalizationOrderService(
            new \CentralVet\Persistence\HospitalizationOrderRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationAdministrationRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationRepository($context, $connection),
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
     * ExamResultForm::resolveTenantContext(), padrão T-03).
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
