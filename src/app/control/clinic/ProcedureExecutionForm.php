<?php
/**
 * ProcedureExecutionForm
 *
 * Tela de execucao de procedimento (T-09), consumindo exclusivamente
 * CentralVet\Application\ProcedureExecutionService::execute() (T-05) e
 * CentralVet\Application\ProcedureCatalogService::listActive() (T-04) para o
 * combo de procedimentos. Nenhuma regra de negocio propria: baixa de
 * estoque dos insumos do procedimento e autorizacao por unidade sao
 * inteiramente responsabilidade de ProcedureExecutionService::execute(),
 * mirando o mesmo padrao ja usado por PrescriptionForm (T-06), ExamRequestForm
 * (T-07) e VaccinationForm (T-08).
 *
 * Entrada: a tela recebe `encounter_id`/`patient_id` por querystring (mesmo
 * helper paramInt() de EncounterView/ExamRequestForm, $_GET com fallback
 * para $param). Sem nenhum dos dois parametros, renderiza um estado vazio em
 * vez de tocar o banco, nunca lancando um erro fatal (mesma convencao de
 * ExamRequestForm).
 *
 * Assinatura real consumida (confirmada lendo
 * src/app/Core/Application/ProcedureExecutionService.php, T-05):
 *   execute(int $encounterId, int $procedureCatalogItemId,
 *           int $professionalSystemUserId, ?string $notesText,
 *           string $action): ProcedureExecution
 * O parametro $action extra (nao previsto literalmente na Interface de
 * tasks.md) segue o mesmo padrao de EncounterService::finish(),
 * VaccinationService::apply() e ExamService::requestExam(): a acao e uma
 * string "ClassName::method" fornecida pelo controller, aqui
 * 'ProcedureExecutionForm::onSave'.
 *
 * Assinatura real de ProcedureCatalogService::listActive() (confirmada
 * lendo src/app/Core/Application/ProcedureCatalogService.php, T-04): sem
 * parametros (tenant resolvido internamente via TenantContext, nunca
 * aceito como input do chamador) — diferente do texto literal de tasks.md
 * ("listActive(int $tenantId)").
 *
 * Quando ProcedureExecutionService::execute() recusa por
 * InsufficientStockException (saldo de estoque insuficiente em algum
 * insumo), CrossTenantReferenceException (encounter_id/procedure_catalog_
 * item_id de outro tenant ou inexistente) ou AuthorizationDenied (unidade
 * ativa nao bate com a unidade do encounter, ou usuario sem permissao),
 * esta tela captura a excecao e exibe a recusa como TMessage de erro, sem
 * navegar para outra tela e sem propagar um erro fatal. Apenas em caso de
 * sucesso a tela navega de volta para o EncounterView do encontro de
 * origem (index.php?class=EncounterView&encounter_id=...), mesma URL usada
 * por EncounterView::onReload().
 *
 * PENDENTE: depende das tabelas `procedure_catalog_item`/
 * `procedure_execution`/`stock_batch`/`stock_movement` criadas pela
 * migration ainda nao aplicada
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql
 * (T-01). Esta classe e apenas preparada/validada com `php -l` e `new
 * ProcedureExecutionForm()` (sem parametros, sem erro fatal) ate a
 * migration ser aplicada.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ProcedureExecutionForm extends TPage
{
    protected $form;
    private ?int $encounterId;
    private ?int $patientId;

    /**
     * Page constructor.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->encounterId = self::paramInt('encounter_id', $param);
        $this->patientId = self::paramInt('patient_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Execute procedure'), null, self::backAction($this->encounterId)));

        if ($this->encounterId === null || $this->patientId === null)
        {
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        $this->form = new BootstrapFormBuilder('form_ProcedureExecution');
        $this->form->setFormTitle(_t('Execute procedure'));
        $this->form->enableClientValidation();

        // contexto (vem da URL, sem edição)
        $encounter_id = new THidden('encounter_id');
        $encounter_id->setValue($this->encounterId);
        $patient_id = new THidden('patient_id');
        $patient_id->setValue($this->patientId);

        $procedure_catalog_item_id = new TCombo('procedure_catalog_item_id');
        $procedure_catalog_item_id->addItems($this->loadCatalogOptions());
        $procedure_catalog_item_id->addValidation(_t('Procedure'), new TRequiredValidator);

        // system_user não tem tenant_id: combo sem filtro de tenant (mesma
        // exceção documentada em PrescriptionForm)
        $professional_system_user_id = CvTenantUsers::combo('professional_system_user_id', static fn () => self::resolveTenantContext());
        $professional_system_user_id->setValue(TSession::getValue('userid'));
        $professional_system_user_id->addValidation(_t('Professional'), new TRequiredValidator);

        $notes_text = new TText('notes_text');
        $notes_text->setSize('100%', 80);

        $hiddenRow = $this->form->addFields([$encounter_id, $patient_id]);
        $hiddenRow->style = 'display: none';

        $this->form->addFields(
            [new TLabel(_t('Procedure'))], [$procedure_catalog_item_id],
            [new TLabel(_t('Professional'))], [$professional_system_user_id]
        );
        $this->form->addFields([new TLabel(_t('Notes'))]);
        $this->form->addFields([$notes_text]);

        $btn = $this->form->addAction(_t('Execute'), new TAction([$this, 'onSave']), 'fa:syringe');
        $btn->class = 'btn btn-primary';

        CvForm::decorate($this->form, 2);

        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Voltar ao atendimento de origem (só quando há encounter_id de contexto).
     */
    private static function backAction(?int $encounterId): array
    {
        if ($encounterId === null)
        {
            return [];
        }

        return [[
            'icon' => 'fa:arrow-left',
            'href' => 'index.php?class=EncounterView&encounter_id=' . $encounterId,
        ]];
    }

    private function emptyStatePanel(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Execute procedure'));
        $panel->class = 'cv-section';
        $panel->add('<p>' . _t('Provide encounter_id and patient_id to execute a procedure.') . '</p>');

        return $panel;
    }

    /**
     * Loads the procedure catalog combo options from
     * ProcedureCatalogService::listActive(). Best effort: any failure (e.g.
     * migration not applied yet) is caught and shown as a TMessage,
     * returning an empty list instead of throwing.
     */
    private function loadCatalogOptions(): array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $catalog = self::makeProcedureCatalogService($context);
            $items = $catalog->listActive();

            TTransaction::close();

            $options = [];

            foreach ($items as $item)
            {
                $options[$item->id()] = $item->name() . ' (R$ ' . number_format($item->priceCents() / 100, 2, ',', '.') . ')';
            }

            return $options;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('warning', _t('Could not load the procedure catalog') . ': ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Persists the procedure execution through
     * ProcedureExecutionService::execute(). No business rule is decided
     * here — unit-scope authorization and stock consumption are entirely
     * enforced inside the Application service. InsufficientStockException,
     * AuthorizationDenied and CrossTenantReferenceException are caught and
     * shown as a handled TMessage, never a fatal error, and none of them
     * navigate away from this screen. Only a successful save navigates back
     * to the origin encounter's EncounterView.
     */
    public function onSave($param)
    {
        try
        {
            $encounterId = isset($param['encounter_id']) ? (int) $param['encounter_id'] : 0;
            $patientId = isset($param['patient_id']) ? (int) $param['patient_id'] : 0;
            $procedureCatalogItemId = isset($param['procedure_catalog_item_id']) && $param['procedure_catalog_item_id'] !== ''
                ? (int) $param['procedure_catalog_item_id']
                : 0;
            $professionalId = isset($param['professional_system_user_id']) && $param['professional_system_user_id'] !== ''
                ? (int) $param['professional_system_user_id']
                : 0;
            $notesText = (isset($param['notes_text']) && $param['notes_text'] !== '')
                ? (string) $param['notes_text']
                : null;

            if ($encounterId <= 0 || $patientId <= 0 || $procedureCatalogItemId <= 0 || $professionalId <= 0)
            {
                throw new InvalidArgumentException(_t('All fields are required'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeProcedureExecutionService($context);

            $execution = $service->execute(
                $encounterId,
                $procedureCatalogItemId,
                $professionalId,
                $notesText,
                'ProcedureExecutionForm::onSave'
            );

            TTransaction::close();

            TToast::show('success', _t('Procedure executed successfully') . ' (#' . $execution->id() . ')');
            TScript::create("__adianti_goto_page('index.php?class=EncounterView&encounter_id={$encounterId}')");
        }
        catch (\CentralVet\Domain\Exception\InsufficientStockException $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Insufficient stock to execute this procedure') . ': ' . $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to execute a procedure for this unit'));
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
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

    /**
     * Wires ProcedureCatalogService (T-04) from its Persistence/PDO
     * implementations. Requires an already-open TTransaction('permission')
     * connection.
     */
    private static function makeProcedureCatalogService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\ProcedureCatalogService
    {
        $connection = TTransaction::get();

        $catalog = new \CentralVet\Persistence\ProcedureCatalogRepository($context, $connection);
        $inputs = new \CentralVet\Persistence\ProcedureCatalogItemInputRepository($context, $connection);

        return new \CentralVet\Application\ProcedureCatalogService($catalog, $inputs, $context);
    }

    /**
     * Wires ProcedureExecutionService (T-05) from its Persistence/PDO
     * implementations. Mirrors ExamRequestForm::makeExamService(): the real
     * RbacAuthorizationService (Fase 0), backed by
     * AdiantiProgramPermissionProvider and PdoAuditLogWriter against this
     * same 'permission' connection, so every execute() call is both
     * unit-scope-checked and audited to `audit_log`. Requires an
     * already-open TTransaction('permission') connection.
     */
    private static function makeProcedureExecutionService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\ProcedureExecutionService
    {
        $connection = TTransaction::get();

        $executions = new \CentralVet\Persistence\ProcedureExecutionRepository($context, $connection);
        $encounters = new \CentralVet\Persistence\EncounterRepository($context, $connection);
        $catalog = self::makeProcedureCatalogService($context);
        $stockBatches = new \CentralVet\Persistence\StockBatchRepository($context, $connection);
        $stockMovements = new \CentralVet\Persistence\StockMovementRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        $stock = new \CentralVet\Application\StockService($stockBatches, $stockMovements, $authorization, $context);

        return new \CentralVet\Application\ProcedureExecutionService($executions, $encounters, $catalog, $stock, $authorization, $context, new \CentralVet\Persistence\TenantUserDirectory($context, $connection));
    }

    /**
     * Resolves the tenant context of the authenticated session. Copied
     * verbatim from ExamRequestForm::resolveTenantContext() (T-03 pattern):
     * falls back to the tenant_user membership table for legacy sessions
     * created before TSession carried 'tenantid'.
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
