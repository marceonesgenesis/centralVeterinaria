<?php
/**
 * PrescriptionForm
 *
 * Tela de prescrição em página cheia (fase 10, mock "Prescrições"): abas
 * Nova prescrição / Histórico de prescrições / Modelos (desabilitada), coluna
 * principal com o formulário e coluna lateral com o card do paciente, o
 * último atendimento e o histórico de prescrições (ClinicalSummaryService).
 *
 * A gravação continua exclusivamente em
 * CentralVet\Application\PrescriptionService::create()/findById(): nenhuma
 * regra de negócio aqui. Toda validação (encounter_id inexistente/de outro
 * tenant, autorização por unidade) é feita pelo serviço; esta tela captura as
 * recusas e mostra TMessage, nunca erro fatal.
 *
 * Contexto: `encounter_id`/`patient_id` chegam pela URL e viajam como campos
 * ocultos (sem edição). Sem `encounter_id` a tela mostra o aviso "Abra pelo
 * atendimento" e não monta o formulário.
 *
 * Itens: cada "Adicionar outro medicamento" grava o item num rascunho em
 * TSession (chave por encounter_id, nunca compartilhada entre atendimentos) e
 * recarrega a própria tela (__adianti_goto_page) para redesenhar a lista —
 * nada é persistido antes de "Salvar prescrição", único ponto que chama
 * PrescriptionService::create(). Se o bloco de medicamento estiver preenchido
 * no momento do salvar, ele entra como último item.
 *
 * Profissional: combo filtrado pelo tenant da sessão (CvTenantUsers); o serviço revalida no save.
 *
 * PDF: após salvar, a tela oferece "Gerar PDF" (onGeneratePdf, dompdf).
 *
 * Sem schema (omitidos): validade, modelos, anexos e orientação por item.
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

    /** Rótulos (chave en) das colunas de ITEM_FIELDS, na mesma ordem. */
    private const ITEM_LABELS = [
        'medication_name' => 'Medication',
        'dose' => 'Dose',
        'dose_unit' => 'Dose unit',
        'route' => 'Route',
        'frequency' => 'Frequency',
        'duration' => 'Duration',
    ];

    /** Status de prescription (CHECK prescription_status_ck) → [rótulo en, tom do CvBadge]. */
    private const STATUS_BADGES = [
        'draft' => ['Draft', 'warning'],
        'issued' => ['Issued', 'success'],
    ];

    private const SIDE_HISTORY_LIMIT = 5;
    private const TAB_HISTORY_LIMIT = 50;

    protected $form; // header + item-entry form

    private ?int $encounterId = null;
    private ?int $patientId = null;
    private ?int $savedPrescriptionId = null;

    /**
     * Class constructor
     * Reads encounter_id/patient_id from $_GET (falling back to $param).
     * `new PrescriptionForm()` with no parameters must never throw — it
     * renders the "open from the encounter" notice instead.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->encounterId = self::paramInt('encounter_id', $param);
        $this->patientId = self::paramInt('patient_id', $param);
        $this->savedPrescriptionId = self::paramInt('prescription_id', $param);

        $tab = (($_GET['tab'] ?? (is_array($param) ? ($param['tab'] ?? '') : '')) === 'history') ? 'history' : 'new';

        // o formulário existe sempre (onAddItem/onSave/onClear dependem dele),
        // mas só entra na página quando há atendimento
        $this->form = $this->buildForm();

        $container = new TVBox;
        $container->style = 'width: 100%';

        $headerActions = [];
        if ($this->encounterId !== null)
        {
            $headerActions[] = [
                'icon' => 'fa:arrow-left',
                'href' => 'index.php?class=EncounterView&encounter_id=' . $this->encounterId,
            ];
        }

        $container->add(CvPage::header(_t('Prescriptions'), null, $headerActions));
        $container->add(CvNav::tabs('prescription', $tab));

        if ($this->encounterId === null)
        {
            $notice = new TElement('div');
            $notice->class = 'alert alert-warning';
            $notice->role = 'status';
            $notice->add(new TImage('fa:info-circle'));
            $notice->add(' ' . CvFormat::e(_t('Open from the encounter')));
            $container->add($notice);
        }

        $summary = $this->loadSummary($tab);

        if ($tab === 'history')
        {
            $main = CvCard::create(_t('Prescription history'), $this->historyTable($summary['history']));
        }
        else
        {
            $main = new TElement('div');

            if ($this->encounterId !== null)
            {
                if ($this->savedPrescriptionId !== null)
                {
                    $pdfLink = new TElement('a');
                    $pdfLink->href = 'index.php?class=PrescriptionForm&method=onGeneratePdf&prescription_id=' . $this->savedPrescriptionId;
                    $pdfLink->target = '_blank';
                    $pdfLink->class = 'btn btn-sm btn-outline-secondary';
                    $pdfLink->style = 'margin-bottom: var(--cv-space-3)';
                    $pdfLink->add(new TImage('fa:file-pdf'));
                    $pdfLink->add(' ' . CvFormat::e(_t('Generate PDF')));
                    $main->add($pdfLink);
                }

                $main->add($this->form);
            }
        }

        $container->add(CvPage::columns($main, $this->sideColumn($summary)));

        parent::add($container);
    }

    /**
     * Monta o formulário da coluna principal: dados da prescrição (data,
     * veterinário), bloco de medicamento em grid, itens já adicionados,
     * orientações adicionais e as ações Limpar / Salvar prescrição.
     */
    private function buildForm(): BootstrapFormBuilder
    {
        $form = new BootstrapFormBuilder('form_Prescription');
        $form->setFormTitle(_t('Prescription data'));
        $form->enableClientValidation();

        // contexto (vem da URL, sem edição)
        $encounter_id = new THidden('encounter_id');
        $patient_id = new THidden('patient_id');
        $encounter_id->setValue($this->encounterId);
        $patient_id->setValue($this->patientId);

        $prescription_date = new TDate('prescription_date');
        $prescription_date->setMask('dd/mm/yyyy');
        $prescription_date->setValue(date('d/m/Y'));
        $prescription_date->setEditable(false);

        $professional_system_user_id = CvTenantUsers::combo('professional_system_user_id', static fn () => self::resolveTenantContext());
        $professional_system_user_id->setValue(TSession::getValue('userid'));
        $professional_system_user_id->addValidation(_t('Veterinarian'), new TRequiredValidator);

        $hiddenRow = $form->addFields([$encounter_id, $patient_id]);
        $hiddenRow->style = 'display: none';

        $form->addFields(
            [new TLabel(_t('Date'))], [$prescription_date],
            [new TLabel(_t('Veterinarian'))], [$professional_system_user_id]
        );

        // bloco de medicamento (repetível via "Adicionar outro medicamento")
        $medication_name = new TEntry('medication_name');
        $dose = new TEntry('dose');
        $dose_unit = new TEntry('dose_unit');
        $route = new TEntry('route');
        $frequency = new TEntry('frequency');
        $duration = new TEntry('duration');

        $form->addContent([TElement::tag('h3', CvFormat::e(_t('Medication')), [
            'class' => 'cv-card__title',
            'style' => 'margin: var(--cv-space-2) 0 0',
        ])]);

        $form->addFields([new TLabel(_t('Medication'))], [$medication_name]);

        $doseRow = $form->addFields(
            [new TLabel(_t('Dose'))], [$dose],
            [new TLabel(_t('Dose unit'))], [$dose_unit],
            [new TLabel(_t('Route'))], [$route],
            [new TLabel(_t('Frequency'))], [$frequency],
            [new TLabel(_t('Duration'))], [$duration]
        );
        $doseRow->style = '--cv-form-columns: 5';

        $form->addContent([$this->itemsTable()]);

        $addBtn = new TButton('add_item');
        $addBtn->setAction(new TAction([$this, 'onAddItem']), _t('Add another medication'));
        $addBtn->setImage('fa:plus');
        $addBtn->class = 'btn btn-sm btn-outline-secondary';
        $form->addFields([$addBtn]);

        $orientation_text = new TText('orientation_text');
        $orientation_text->setSize('100%', 100);
        $form->addFields([new TLabel(_t('Additional instructions'))], [$orientation_text]);

        CvForm::decorate($form, 2);

        $form->addActionLink(_t('Clear'), new TAction([$this, 'onClear'], [
            'encounter_id' => $this->encounterId,
            'patient_id' => $this->patientId,
        ]), 'fa:eraser');

        $saveBtn = $form->addAction(_t('Save prescription'), new TAction([$this, 'onSave']), 'fa:check');
        $saveBtn->class = 'btn btn-primary';

        return $form;
    }

    /**
     * method onEdit()
     * Kept for compatibility: re-renders the screen (the form already starts
     * from the URL context).
     */
    public function onEdit($param)
    {
    }

    /**
     * method onClear()
     * "Limpar": discards the TSession item draft of this encounter and reloads
     * the screen with an empty form (context kept).
     */
    public function onClear($param)
    {
        TSession::setValue($this->draftSessionKey(), null);

        $this->reloadSelf();
    }

    /**
     * Reads the side-column data (patient card, last encounter, prescription
     * history) through ClinicalSummaryService (tenant-scoped). Any failure
     * degrades to empty cards — never a fatal error on screen.
     *
     * @return array{patient: ?array, last: ?array, history: list<array<string, mixed>>}
     */
    private function loadSummary(string $tab): array
    {
        $summary = ['patient' => null, 'last' => null, 'history' => []];

        if ($this->patientId === null)
        {
            return $summary;
        }

        try
        {
            TTransaction::open('permission');

            $service = new \CentralVet\Application\ClinicalSummaryService(
                new \CentralVet\Persistence\ClinicalSummaryReader(self::resolveTenantContext(), TTransaction::get())
            );

            $summary['patient'] = $service->patientCard($this->patientId);

            if ($summary['patient'] !== null)
            {
                $summary['last'] = $service->lastEncounter($this->patientId, $this->encounterId);
                $summary['history'] = $service->prescriptionHistory(
                    $this->patientId,
                    $tab === 'history' ? self::TAB_HISTORY_LIMIT : self::SIDE_HISTORY_LIMIT
                );
            }

            TTransaction::close();
        }
        catch (\Throwable $e)
        {
            TTransaction::rollback();
        }

        return $summary;
    }

    /**
     * Coluna lateral: card do paciente, último atendimento e histórico curto.
     */
    private function sideColumn(array $summary): TElement
    {
        $side = new TElement('div');
        $side->style = 'display: flex; flex-direction: column; gap: var(--cv-space-4)';

        $patient = $summary['patient'];

        if ($patient === null)
        {
            return $side;
        }

        // paciente
        $identity = new TElement('div');
        $identity->style = 'display: flex; align-items: center; gap: var(--cv-space-3); margin-bottom: var(--cv-space-3)';
        $identity->add(CvAvatar::placeholder((string) $patient['name'], (string) $patient['species']));
        $identity->add(TElement::tag('strong', CvFormat::e((string) $patient['name']), ['style' => 'font-size: 1.05rem']));

        $patientBody = new TElement('div');
        $patientBody->add($identity);
        $patientBody->add(self::definitionList([
            _t('Breed') => $patient['breed'],
            _t('Age') => $patient['age_label'],
            _t('Weight (kg)') => $patient['weight_kg'] === null ? null : number_format((float) $patient['weight_kg'], 1, ',', '.'),
            _t('Tutor') => $patient['tutor_name'],
            _t('Phone') => $patient['tutor_phone'],
            _t('Email') => $patient['tutor_email'],
        ]));

        $side->add(CvCard::create(_t('Patient'), $patientBody));

        // último atendimento
        $last = $summary['last'];
        if ($last !== null)
        {
            $lastBody = self::definitionList([
                _t('Date') => self::formatDate((string) $last['started_at']),
                _t('Diagnosis') => $last['diagnosis_excerpt'],
                _t('Anamnesis') => $last['anamnesis_excerpt'],
            ]);
        }
        else
        {
            $lastBody = TElement::tag('p', '—', ['style' => 'margin: 0; color: var(--cv-color-text-muted)']);
        }
        $side->add(CvCard::create(_t('Last encounter'), $lastBody));

        // histórico curto
        $list = new TElement('ul');
        $list->style = 'list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--cv-space-3)';

        foreach (array_slice($summary['history'], 0, self::SIDE_HISTORY_LIMIT) as $row)
        {
            $item = new TElement('li');
            $item->style = 'display: flex; justify-content: space-between; align-items: flex-start; gap: var(--cv-space-2)';

            $text = new TElement('div');
            $text->add(TElement::tag('div', CvFormat::e((string) ($row['first_medication'] ?? '—')), ['style' => 'font-weight: 600']));
            $text->add(TElement::tag('small', CvFormat::e(
                self::formatDate((string) $row['created_at']) . ' · ' . _t('Items') . ': ' . (int) $row['items_count']
            ), ['style' => 'color: var(--cv-color-text-muted)']));

            $item->add($text);
            $item->add(self::statusBadge((string) $row['status']));
            $list->add($item);
        }

        if (empty($summary['history']))
        {
            $list->add(TElement::tag('li', '—', ['style' => 'color: var(--cv-color-text-muted)']));
        }

        $side->add(CvCard::create(
            _t('Prescription history'),
            $list,
            _t('View all'),
            CvNav::group('prescription')['history']['href']
        ));

        return $side;
    }

    /**
     * Aba "Histórico de prescrições": tabela com data, primeiro medicamento,
     * quantidade de itens e status.
     */
    private function historyTable(array $history): TElement
    {
        $table = new TElement('table');
        $table->class = 'table cv-table';

        $thead = new TElement('thead');
        $headerRow = new TElement('tr');
        foreach (['Date', 'Medication', 'Items', 'Status'] as $label)
        {
            $headerRow->add(TElement::tag('th', CvFormat::e(_t($label))));
        }
        $thead->add($headerRow);
        $table->add($thead);

        $tbody = new TElement('tbody');

        foreach ($history as $row)
        {
            $tr = new TElement('tr');
            $tr->add(TElement::tag('td', CvFormat::e(self::formatDate((string) $row['created_at']))));
            $tr->add(TElement::tag('td', CvFormat::e((string) ($row['first_medication'] ?? '—'))));
            $tr->add(TElement::tag('td', (string) (int) $row['items_count'], ['data-items-count' => (string) (int) $row['items_count']]));
            $statusTd = new TElement('td');
            $statusTd->add(self::statusBadge((string) $row['status']));
            $tr->add($statusTd);
            $tbody->add($tr);
        }

        if (empty($history))
        {
            $tbody->add(TElement::tag('tr', TElement::tag('td', '—', ['colspan' => '4'])));
        }

        $table->add($tbody);

        return $table;
    }

    private static function statusBadge(string $status): TElement
    {
        [$label, $tone] = self::STATUS_BADGES[$status] ?? [$status, 'neutral'];

        return CvBadge::create(isset(self::STATUS_BADGES[$status]) ? _t($label) : $label, $tone);
    }

    /**
     * Lista rótulo/valor; valor nulo ou vazio vira "—" (dado ausente no schema/cadastro).
     *
     * @param array<string, mixed> $pairs
     */
    private static function definitionList(array $pairs): TElement
    {
        $dl = new TElement('dl');
        $dl->style = 'display: grid; grid-template-columns: auto minmax(0, 1fr); column-gap: var(--cv-space-3); row-gap: var(--cv-space-1); margin: 0';

        foreach ($pairs as $label => $value)
        {
            $value = ($value === null || $value === '') ? '—' : (string) $value;
            $dl->add(TElement::tag('dt', CvFormat::e((string) $label), ['style' => 'font-weight: 600; color: var(--cv-color-text-muted)']));
            $dl->add(TElement::tag('dd', CvFormat::e($value), ['style' => 'margin: 0; overflow-wrap: anywhere']));
        }

        return $dl;
    }

    private static function formatDate(string $datetime): string
    {
        $time = strtotime($datetime);

        return $time === false ? $datetime : date('d/m/Y', $time);
    }

    /**
     * Renders the current TSession draft of items as a plain HTML table,
     * each row with a "Remove" link. No business rule here — purely a read
     * of the draft array assembled by onAddItem()/onRemoveItem().
     */
    private function itemsTable(): TElement
    {
        $table = new TElement('table');
        $table->class = 'table table-sm cv-table';

        $items = $this->draftItems();

        if (empty($items))
        {
            $table->style = 'display: none';
        }

        $thead = new TElement('thead');
        $headerRow = new TElement('tr');

        foreach (self::ITEM_LABELS as $label)
        {
            $headerRow->add(TElement::tag('th', CvFormat::e(_t($label))));
        }
        $headerRow->add(TElement::tag('th', ''));

        $thead->add($headerRow);
        $table->add($thead);

        $tbody = new TElement('tbody');

        foreach ($items as $index => $item)
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
            $removeLink->title = _t('Remove');
            $removeLink->{'aria-label'} = _t('Remove');
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

            // bloco de medicamento preenchido e ainda não adicionado entra
            // como último item (só em memória; o rascunho não muda aqui)
            $filled = array_filter(self::ITEM_FIELDS, fn ($field) => trim((string) ($data->$field ?? '')) !== '');

            if (count($filled) === count(self::ITEM_FIELDS))
            {
                $items[] = array_combine(self::ITEM_FIELDS, array_map(
                    fn ($field) => (string) $data->$field,
                    self::ITEM_FIELDS
                ));
            }
            elseif (!empty($filled))
            {
                throw new InvalidArgumentException(_t('Fill in all item fields before adding'));
            }

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

        return new \CentralVet\Application\PrescriptionService($prescriptions, $encounters, $authorization, $context, new \CentralVet\Persistence\TenantUserDirectory($context, $connection));
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
