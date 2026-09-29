<?php
/**
 * PrescriptionForm
 *
 * Formulario de prescricao (T-06), consumindo exclusivamente
 * CentralVet\Application\PrescriptionService::create()/findById() (T-03).
 * Nao contem nenhuma regra de negocio propria: toda validacao (encounter_id
 * inexistente/de outro tenant, autorizacao por unidade) e feita
 * inteiramente por PrescriptionService, mirando o padrao ja estabelecido em
 * AppointmentForm.php (Fase 1) e EncounterView.php (Fase 2).
 *
 * Entrada: a tela recebe `encounter_id`/`patient_id` por querystring
 * (mesmo helper paramInt() de EncounterView, $_GET com fallback para
 * $param). Os itens da prescricao (medicamento/dose/unidade/via/
 * frequencia/duracao) sao repetiveis: cada "Adicionar item" grava o item
 * num rascunho em TSession (chave por encounter_id, nunca compartilhada
 * entre atendimentos) e recarrega a propria tela (__adianti_goto_page, como
 * EncounterView::onReload()) para redesenhar a lista — nenhum item e
 * persistido no banco antes do "Salvar", que e o unico ponto que chama
 * PrescriptionService::create().
 *
 * PDF: apos salvar, a tela oferece um link "Gerar PDF" que chama
 * onGeneratePdf(), recarrega a prescricao ja salva via
 * PrescriptionService::findById() (tenant-scoped) e renderiza um PDF simples
 * (dompdf, ja disponivel no composer.json) com os dados — sem estilizacao,
 * apenas funcional, conforme o criterio de aceite da task.
 *
 * Quando PrescriptionService::create() recusa por CrossTenantReferenceException
 * (encounter_id de outro tenant/inexistente) ou por AuthorizationDenied
 * (unidade ativa nao bate com a unidade do encounter, ou usuario sem
 * permissao), esta tela captura a excecao e exibe a mensagem de recusa em
 * tela via TMessage, sem propagar um erro fatal.
 *
 * PENDENTE: as tabelas `prescription`/`prescription_item` ainda dependem da
 * migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01), que ainda nao foi aplicada ao MySQL. Esta classe e apenas
 * preparada/validada com `php -l` e `new PrescriptionForm()` (sem
 * parametros, sem erro fatal); nao deve ser exercida contra um banco real
 * antes da migration ser aplicada.
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 * @license    https://adiantiframework.com.br/license-template
 */
class PrescriptionForm extends TPage
{
    /**
     * Item field names accepted by PrescriptionService::create()'s
     * items[] entries, matching CentralVet\Domain\PrescriptionItem::create()
     * 1:1.
     */
    private const ITEM_FIELDS = [
        'medication_name',
        'dose',
        'dose_unit',
        'route',
        'frequency',
        'duration',
    ];

    protected $form; // header + item-entry form

    private ?int $encounterId = null;
    private ?int $patientId = null;
    private ?int $savedPrescriptionId = null;

    /**
     * Class constructor
     * Creates the prescription form. Reads encounter_id/patient_id from
     * $_GET (falling back to $param), mirroring EncounterView::paramInt().
     * `new PrescriptionForm()` with no parameters at all must never throw —
     * it renders an empty-state form instead (validation command for this
     * task).
     */
    public function __construct($param = null)
    {
        parent::__construct();

        parent::setTargetContainer('adianti_right_panel');

        $this->encounterId = self::paramInt('encounter_id', $param);
        $this->patientId = self::paramInt('patient_id', $param);
        $this->savedPrescriptionId = self::paramInt('prescription_id', $param);

        $this->form = new BootstrapFormBuilder('form_Prescription');
        $this->form->setFormTitle(_t('Prescription'));
        $this->form->enableClientValidation();

        // header fields
        $encounter_id = new TEntry('encounter_id');
        $patient_id = new TEntry('patient_id');
        $professional_system_user_id = new TEntry('professional_system_user_id');
        $orientation_text = new TText('orientation_text');

        $encounter_id->setNumericMask(0, '', '', false, false, false);
        $patient_id->setNumericMask(0, '', '', false, false, false);
        $professional_system_user_id->setNumericMask(0, '', '', false, false, false);

        $this->form->addFields( [new TLabel(_t('Encounter'))] );
        $this->form->addFields( [$encounter_id] );
        $this->form->addFields( [new TLabel(_t('Patient'))] );
        $this->form->addFields( [$patient_id] );
        $this->form->addFields( [new TLabel(_t('Professional'))] );
        $this->form->addFields( [$professional_system_user_id] );
        $this->form->addFields( [new TLabel(_t('Orientation'))] );
        $this->form->addFields( [$orientation_text] );

        $encounter_id->setSize('100%');
        $patient_id->setSize('100%');
        $professional_system_user_id->setSize('100%');
        $orientation_text->setSize('100%', 80);

        $encounter_id->setValue($this->encounterId);
        $patient_id->setValue($this->patientId);
        $professional_system_user_id->setValue(TSession::getValue('userid'));

        $encounter_id->addValidation( _t('Encounter'), new TRequiredValidator );
        $patient_id->addValidation( _t('Patient'), new TRequiredValidator );
        $professional_system_user_id->addValidation( _t('Professional'), new TRequiredValidator );

        // item entry sub-fields (repeatable: "Add item" stashes one line in
        // the TSession draft below and reloads the screen; nothing is
        // persisted until "Save")
        $medication_name = new TEntry('medication_name');
        $dose = new TEntry('dose');
        $dose_unit = new TEntry('dose_unit');
        $route = new TEntry('route');
        $frequency = new TEntry('frequency');
        $duration = new TEntry('duration');

        $this->form->addFields( [new TLabel(_t('Medication'))] );
        $this->form->addFields( [$medication_name] );
        $this->form->addFields( [new TLabel(_t('Dose'))] );
        $this->form->addFields( [$dose] );
        $this->form->addFields( [new TLabel(_t('Dose unit'))] );
        $this->form->addFields( [$dose_unit] );
        $this->form->addFields( [new TLabel(_t('Route'))] );
        $this->form->addFields( [$route] );
        $this->form->addFields( [new TLabel(_t('Frequency'))] );
        $this->form->addFields( [$frequency] );
        $this->form->addFields( [new TLabel(_t('Duration'))] );
        $this->form->addFields( [$duration] );

        $medication_name->setSize('100%');
        $dose->setSize('100%');
        $dose_unit->setSize('100%');
        $route->setSize('100%');
        $frequency->setSize('100%');
        $duration->setSize('100%');

        // form actions
        $addBtn = $this->form->addAction(_t('Add item'), new TAction(array($this, 'onAddItem')), 'fa:plus');
        $addBtn->class = 'btn btn-sm btn-secondary';

        $saveBtn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $saveBtn->class = 'btn btn-sm btn-primary';

        $this->form->addActionLink(_t('Clear'), new TAction(array($this, 'onEdit')), 'fa:eraser red');
        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';

        $pageHeader = new TElement('header');
        $pageHeader->class = 'cv-page-header';
        $pageHeaderTitleWrap = new TElement('div');
        $pageHeaderTitle = new TElement('h1');
        $pageHeaderTitle->class = 'cv-page-title';
        $pageHeaderTitle->add(_t('Prescription'));
        $pageHeaderTitleWrap->add($pageHeaderTitle);
        $pageHeader->add($pageHeaderTitleWrap);
        $container->add($pageHeader);

        $container->add($this->form);

        $itemsPanel = new TPanelGroup(_t('Items'));
        $itemsPanel->add($this->itemsTable());
        $container->add($itemsPanel);

        if ($this->savedPrescriptionId !== null)
        {
            $pdfLink = new TElement('a');
            $pdfLink->href = 'index.php?class=PrescriptionForm&method=onGeneratePdf&prescription_id=' . $this->savedPrescriptionId;
            $pdfLink->target = '_blank';
            $pdfLink->class = 'btn btn-sm btn-outline-secondary';
            $pdfLink->add('<i class="fa fa-file-pdf"></i> ' . _t('Generate PDF'));
            $container->add($pdfLink);
        }

        parent::add($container);
    }

    /**
     * on close
     */
    public static function onClose($param)
    {
        TScript::create("Template.closeRightPanel()");
    }

    /**
     * method onEdit()
     * Clears the header form (item draft in TSession is untouched — the
     * user may still want to keep the items already added for this
     * encounter).
     */
    public function onEdit($param)
    {
        $this->form->clear();
    }

    /**
     * Renders the current TSession draft of items as a plain HTML table
     * (mirrors AgendaView's `new TElement('table')` usage), each row with a
     * "Remove" link. No business rule here — purely a read of the draft
     * array assembled by onAddItem()/onRemoveItem().
     */
    private function itemsTable(): TElement
    {
        $table = new TElement('table');
        $table->class = 'table table-sm';

        $thead = new TElement('thead');
        $headerRow = new TElement('tr');

        foreach (array_merge(self::ITEM_FIELDS, ['']) as $field)
        {
            $th = new TElement('th');
            $th->add($field === '' ? '' : _t(ucfirst(str_replace('_', ' ', $field))));
            $headerRow->add($th);
        }

        $thead->add($headerRow);
        $table->add($thead);

        $tbody = new TElement('tbody');

        foreach ($this->draftItems() as $index => $item)
        {
            $row = new TElement('tr');

            foreach (self::ITEM_FIELDS as $field)
            {
                $td = new TElement('td');
                $td->add(htmlspecialchars((string) ($item[$field] ?? ''), ENT_QUOTES, 'UTF-8'));
                $row->add($td);
            }

            $removeTd = new TElement('td');
            $removeLink = new TElement('a');
            $removeLink->href = "javascript:__adianti_post_lock_function('index.php?class=PrescriptionForm&method=onRemoveItem&index={$index}&encounter_id={$this->encounterId}&patient_id={$this->patientId}')";
            $removeLink->add('<i class="fa fa-trash red"></i>');
            $removeTd->add($removeLink);
            $row->add($removeTd);

            $tbody->add($row);
        }

        $table->add($tbody);

        return $table;
    }

    /**
     * Reads the item draft stashed in TSession for this encounter. Keyed by
     * encounter_id so drafts from different encounters never mix (an empty
     * key, i.e. no encounter_id yet, uses its own bucket and is discarded
     * once an encounter_id is known).
     *
     * @return list<array<string, string>>
     */
    private function draftItems(): array
    {
        $items = TSession::getValue($this->draftSessionKey());

        return is_array($items) ? $items : [];
    }

    private function draftSessionKey(): string
    {
        return 'prescription_form_draft_items_' . ($this->encounterId ?? 0);
    }

    /**
     * method onAddItem()
     * Executed when the user clicks "Add item": validates the item
     * sub-fields are all filled (basic presence check, not a business rule —
     * PrescriptionService::create() re-validates every item on save
     * regardless) and appends it to the TSession draft, then reloads the
     * screen so the items table below is redrawn with the new line.
     */
    public function onAddItem($param)
    {
        try
        {
            $data = $this->form->getData();

            foreach (self::ITEM_FIELDS as $field)
            {
                if (empty($data->$field))
                {
                    throw new Exception(_t('Fill in all item fields before adding'));
                }
            }

            $items = $this->draftItems();
            $items[] = array_combine(self::ITEM_FIELDS, array_map(
                fn ($field) => (string) $data->$field,
                self::ITEM_FIELDS
            ));

            TSession::setValue($this->draftSessionKey(), $items);

            $this->reloadSelf();
        }
        catch (Exception $e)
        {
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * method onRemoveItem()
     * Removes one line from the TSession draft by its index and reloads.
     */
    public function onRemoveItem($param)
    {
        $this->encounterId = isset($param['encounter_id']) && $param['encounter_id'] !== ''
            ? (int) $param['encounter_id']
            : null;
        $this->patientId = isset($param['patient_id']) && $param['patient_id'] !== ''
            ? (int) $param['patient_id']
            : null;

        $index = isset($param['index']) ? (int) $param['index'] : -1;
        $items = $this->draftItems();

        if (isset($items[$index]))
        {
            unset($items[$index]);
            TSession::setValue($this->draftSessionKey(), array_values($items));
        }

        $this->reloadSelf();
    }

    /**
     * method onSave()
     * Executed whenever the user clicks "Save". Calls
     * PrescriptionService::create() with the header fields plus the
     * TSession item draft, and turns every business-rule refusal
     * (cross-tenant encounter_id, unit-scope authorization) into a message
     * shown on screen instead of a fatal error — mirrors
     * AppointmentForm::onSave().
     */
    public function onSave($param)
    {
        try
        {
            $data = $this->form->getData();
            $this->form->validate();

            $items = $this->draftItems();

            if (empty($items))
            {
                throw new InvalidArgumentException(_t('Add at least one item before saving'));
            }

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildPrescriptionService($tenant_context);

            $prescription = $service->create([
                'encounter_id' => (int) $data->encounter_id,
                'patient_id' => (int) $data->patient_id,
                'professional_system_user_id' => (int) $data->professional_system_user_id,
                'orientation' => $data->orientation_text ?? null,
                'items' => $items,
            ], __CLASS__ . '::' . __FUNCTION__);

            TTransaction::close();

            // draft consumed: clear it so a fresh prescription for the same
            // encounter does not start pre-filled with the previous one
            TSession::setValue($this->draftSessionKey(), null);

            new TMessage('info', _t('Prescription saved successfully'));
            TScript::create(
                "__adianti_goto_page('index.php?class=PrescriptionForm&encounter_id={$this->encounterId}"
                . "&patient_id={$this->patientId}&prescription_id={$prescription->id()}')"
            );
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            // Critério de aceite (T-06): encounter_id de outro tenant ou
            // inexistente recusado por PrescriptionService::create() vira
            // mensagem tratada na tela, nunca uma exceção não tratada.
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            // Critério de aceite (T-06): AuthorizationDenied capturada e
            // exibida como TMessage tratado, nunca erro fatal.
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to issue a prescription for this unit'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
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

    /**
     * method onGeneratePdf()
     * Executed when the user clicks "Generate PDF". Reloads the already
     * saved prescription via PrescriptionService::findById() (tenant-scoped
     * — a cross-tenant id simply resolves to null, same contract as
     * PrescriptionRepository::findById()) and streams a plain PDF built with
     * dompdf (already in composer.json). No business rule here: this method
     * only reads and formats data that PrescriptionService has already
     * validated and persisted.
     */
    public function onGeneratePdf($param)
    {
        try
        {
            $id = isset($param['prescription_id']) ? (int) $param['prescription_id'] : 0;

            if ($id <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid prescription'));
            }

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildPrescriptionService($tenant_context);
            $prescription = $service->findById($id);

            TTransaction::close();

            if ($prescription === null)
            {
                new TMessage('error', _t('Record not found'));

                return;
            }

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml(self::renderPrescriptionHtml($prescription));
            $dompdf->setPaper('A4');
            $dompdf->render();

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="prescription-' . $id . '.pdf"');
            echo $dompdf->output();
            exit;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Builds a bare-bones HTML document for dompdf — functional only, per
     * this task's acceptance criterion ("nao precisa ser bonito").
     */
    private static function renderPrescriptionHtml(\CentralVet\Domain\Prescription $prescription): string
    {
        $rows = '';

        foreach ($prescription->items() as $item)
        {
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($item->medicationName(), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($item->dose(), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($item->doseUnit(), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($item->route(), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($item->frequency(), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($item->duration(), ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }

        $orientation = $prescription->orientationText() !== null
            ? nl2br(htmlspecialchars($prescription->orientationText(), ENT_QUOTES, 'UTF-8'))
            : '';

        return '<html><head><meta charset="utf-8"></head><body>'
            . '<h3>' . _t('Prescription') . ' #' . $prescription->id() . '</h3>'
            . '<p>' . _t('Encounter') . ': ' . $prescription->encounterId() . ' &mdash; '
            . _t('Patient') . ': ' . $prescription->patientId() . '</p>'
            . '<table border="1" cellpadding="4" cellspacing="0" width="100%">'
            . '<thead><tr>'
            . '<th>' . _t('Medication') . '</th><th>' . _t('Dose') . '</th><th>' . _t('Dose unit') . '</th>'
            . '<th>' . _t('Route') . '</th><th>' . _t('Frequency') . '</th><th>' . _t('Duration') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p>' . _t('Orientation') . ': ' . $orientation . '</p>'
            . '</body></html>';
    }

    /**
     * Wires PrescriptionService (T-03) from its Persistence/PDO
     * implementation. Mirrors AppointmentForm::buildAppointmentService()/
     * EncounterView::makeEncounterService(): the real RbacAuthorizationService
     * (Fase 0), backed by AdiantiProgramPermissionProvider (reads the same
     * programs/methods session keys SystemPermission::checkPermission()
     * already uses) and PdoAuditLogWriter against this same 'permission'
     * connection, so every create() call is both unit-scope-checked and
     * audited to `audit_log`. PrescriptionRepositoryInterface's sibling
     * dependency, EncounterRepositoryInterface, is wired the same way
     * EncounterView wires it for EncounterService, since PrescriptionService
     * only uses it read-only to resolve encounter_id -> system_unit_id (see
     * PrescriptionService's own docblock).
     */
    private static function buildPrescriptionService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\PrescriptionService
    {
        $connection = TTransaction::get();

        $prescriptions = new \CentralVet\Persistence\PrescriptionRepository($context, $connection);
        $encounters = new \CentralVet\Persistence\EncounterRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\PrescriptionService($prescriptions, $encounters, $authorization, $context);
    }

    /**
     * Resolves the tenant context of the authenticated session (T-03),
     * mirroring SystemUnitForm::resolveTenantContext()/
     * AppointmentForm::resolveTenantContext() (same fallback for legacy
     * sessions where TSession does not carry 'tenantid' yet).
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

    /**
     * Reloads this same screen (same encounter_id/patient_id), forcing the
     * constructor to re-run and redraw the items table from the TSession
     * draft. Mirrors EncounterView::onReload().
     */
    private function reloadSelf(): void
    {
        TScript::create(
            "__adianti_goto_page('index.php?class=PrescriptionForm&encounter_id={$this->encounterId}"
            . "&patient_id={$this->patientId}')"
        );
    }

    /**
     * Reads a parameter from $_GET (falling back to $param), mirroring
     * EncounterView::paramInt() exactly.
     */
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
}
