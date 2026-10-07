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
 * PDF: após salvar, a tela oferece "Gerar PDF" (onGeneratePdf, dompdf), com a
 * linha "Válida até" quando a prescrição tem validade.
 *
 * Validade (`valid_until`, dd/mm/aaaa, opcional) vai como Y-m-d ao create();
 * a regra (não pode ser passada) é do PrescriptionService.
 *
 * Modelos (rodada 2, T-19): "Salvar como modelo" pede o nome num TInputDialog
 * e grava os itens em edição por PrescriptionTemplateService::saveFromItems();
 * "Aplicar modelo" troca os itens e a orientação em edição pelos do modelo.
 * Orientação e validade em edição viajam num segundo rascunho em TSession
 * (mesma chave por encounter_id), para sobreviver às recargas da tela.
 *
 * Estilos: nenhum inline; as regras ficam nas classes .cv-rx-* de
 * cv-components.css.
 *
 * Sem schema (omitidos): anexos e orientação por item.
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

    /** Nome do formulário principal (CvCombo::reload e TButton::setFormName). */
    private const FORM_NAME = 'form_Prescription';

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
        $container->class = 'cv-rx-page';

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
                    $pdfLink->href = 'engine.php?class=PrescriptionForm&method=onGeneratePdf&static=1&prescription_id=' . $this->savedPrescriptionId;
                    $pdfLink->target = '_blank';
                    $pdfLink->rel = 'noopener';
                    $pdfLink->class = 'btn btn-sm btn-outline-secondary cv-rx-pdf-link';
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
        $form = new BootstrapFormBuilder(self::FORM_NAME);
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
        $professional_system_user_id->addValidation(_t('Veterinarian'), new TRequiredValidator);

        $header = $this->draftHeader();

        $professional_system_user_id->setValue(
            $header['professional_system_user_id'] !== '' ? $header['professional_system_user_id'] : TSession::getValue('userid')
        );

        $valid_until = new TDate('valid_until');
        $valid_until->setMask('dd/mm/yyyy');
        $valid_until->setValue($header['valid_until']);

        $template_id = new TCombo('template_id');
        $template_id->addItems($this->templateOptions());

        $applyBtn = new TButton('apply_template');
        $applyBtn->setAction(new TAction([$this, 'onApplyTemplate']), _t('Apply template'));
        $applyBtn->setImage('fa:file-import');
        $applyBtn->class = 'btn btn-sm btn-outline-secondary cv-rx-apply-template';

        $hiddenRow = $form->addFields([$encounter_id, $patient_id]);
        $hiddenRow->class = 'cv-rx-hidden';

        $form->addFields(
            [new TLabel(_t('Date'))], [$prescription_date],
            [new TLabel(_t('Veterinarian'))], [$professional_system_user_id]
        );

        $form->addFields(
            [new TLabel(_t('Valid until'))], [$valid_until],
            [new TLabel(_t('Template'))], [$template_id, $applyBtn]
        );

        // bloco de medicamento (repetível via "Adicionar outro medicamento")
        $medication_name = new TEntry('medication_name');
        $dose = new TEntry('dose');
        $dose_unit = new TEntry('dose_unit');
        $route = new TEntry('route');
        $frequency = new TEntry('frequency');
        $duration = new TEntry('duration');

        $form->addContent([TElement::tag('h3', CvFormat::e(_t('Medication')), [
            'class' => 'cv-card__title cv-rx-section-title',
        ])]);

        $form->addFields([new TLabel(_t('Medication'))], [$medication_name]);

        $doseRow = $form->addFields(
            [new TLabel(_t('Dose'))], [$dose],
            [new TLabel(_t('Dose unit'))], [$dose_unit],
            [new TLabel(_t('Route'))], [$route],
            [new TLabel(_t('Frequency'))], [$frequency],
            [new TLabel(_t('Duration'))], [$duration]
        );
        $doseRow->class = 'cv-rx-dose-row';

        $form->addContent([$this->itemsTable()]);

        $addBtn = new TButton('add_item');
        $addBtn->setAction(new TAction([$this, 'onAddItem']), _t('Add another medication'));
        $addBtn->setImage('fa:plus');
        $addBtn->class = 'btn btn-sm btn-outline-secondary';
        $form->addFields([$addBtn]);

        $orientation_text = new TText('orientation_text');
        $orientation_text->setSize('100%', 100);
        $orientation_text->setValue($header['orientation_text']);
        $form->addFields([new TLabel(_t('Additional instructions'))], [$orientation_text]);

        CvForm::decorate($form, 2);

        $form->addActionLink(_t('Clear'), new TAction([$this, 'onClear'], [
            'encounter_id' => $this->encounterId,
            'patient_id' => $this->patientId,
        ]), 'fa:eraser');

        $form->addAction(_t('Save as template'), new TAction([$this, 'onAskTemplateName']), 'fa:copy');

        $saveBtn = $form->addAction(_t('Save prescription'), new TAction([$this, 'onSave']), 'fa:check');
        $saveBtn->class = 'btn btn-primary';

        return $form;
    }

    /**
     * method onClear()
     * "Limpar": discards the TSession item draft of this encounter and reloads
     * the screen with an empty form (context kept).
     */
    public function onClear($param)
    {
        TSession::setValue($this->draftSessionKey(), null);
        TSession::setValue($this->headerSessionKey(), null);

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
        $side->class = 'cv-rx-side';

        $patient = $summary['patient'];

        if ($patient === null)
        {
            return $side;
        }

        // paciente
        $identity = new TElement('div');
        $identity->class = 'cv-rx-identity';
        $identity->add(CvAvatar::placeholder((string) $patient['name'], (string) $patient['species']));
        $identity->add(TElement::tag('strong', CvFormat::e((string) $patient['name']), ['class' => 'cv-rx-identity__name']));

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
            $lastBody = TElement::tag('p', '—', ['class' => 'cv-rx-empty']);
        }
        $side->add(CvCard::create(_t('Last encounter'), $lastBody));

        // histórico curto
        $list = new TElement('ul');
        $list->class = 'cv-rx-history';

        foreach (array_slice($summary['history'], 0, self::SIDE_HISTORY_LIMIT) as $row)
        {
            $item = new TElement('li');
            $item->class = 'cv-rx-history__item';

            $text = new TElement('div');
            $text->add(TElement::tag('div', CvFormat::e((string) ($row['first_medication'] ?? '—')), ['class' => 'cv-rx-history__title']));
            $text->add(TElement::tag('small', CvFormat::e(
                self::formatDate((string) $row['created_at']) . ' · ' . _t('Items') . ': ' . (int) $row['items_count']
            ), ['class' => 'cv-rx-muted']));

            $item->add($text);
            $item->add(self::statusBadge((string) $row['status']));
            $list->add($item);
        }

        if (empty($summary['history']))
        {
            $list->add(TElement::tag('li', '—', ['class' => 'cv-rx-muted']));
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
        $dl->class = 'cv-rx-dl';

        foreach ($pairs as $label => $value)
        {
            $value = ($value === null || $value === '') ? '—' : (string) $value;
            $dl->add(TElement::tag('dt', CvFormat::e((string) $label)));
            $dl->add(TElement::tag('dd', CvFormat::e($value)));
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
        $items = $this->draftItems();

        $table->class = 'table table-sm cv-table' . (empty($items) ? ' cv-rx-hidden' : '');

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

            // posta o formulário: onRemoveItem recebe validade, orientação e
            // profissional digitados (não os do rascunho anterior)
            $removeTd = new TElement('td');
            $removeBtn = new TButton('remove_item_' . (int) $index);
            $removeBtn->setAction(new TAction([$this, 'onRemoveItem'], ['index' => (int) $index]));
            $removeBtn->setFormName(self::FORM_NAME);
            $removeBtn->setImage('fa:trash red');
            $removeBtn->class = 'btn btn-sm btn-link';
            $removeBtn->title = _t('Remove');
            $removeBtn->{'aria-label'} = _t('Remove');
            $removeTd->add($removeBtn);
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

    private static function templateSourceKey(?int $encounterId): string
    {
        return 'prescription_form_template_source_' . ($encounterId ?? 0);
    }

    private function headerSessionKey(): string
    {
        return 'prescription_form_draft_header_' . ($this->encounterId ?? 0);
    }

    /**
     * Orientação e validade em edição (dd/mm/aaaa), guardadas em TSession
     * pelas ações que recarregam a tela.
     *
     * @return array{orientation_text: string, valid_until: string, professional_system_user_id: string}
     */
    private function draftHeader(): array
    {
        $header = TSession::getValue($this->headerSessionKey());
        $header = is_array($header) ? $header : [];

        return [
            'orientation_text' => (string) ($header['orientation_text'] ?? ''),
            'valid_until' => (string) ($header['valid_until'] ?? ''),
            'professional_system_user_id' => (string) ($header['professional_system_user_id'] ?? ''),
        ];
    }

    private function storeDraftHeader(?string $orientationText, ?string $validUntil, $professionalId = null): void
    {
        TSession::setValue($this->headerSessionKey(), [
            'orientation_text' => (string) $orientationText,
            'valid_until' => (string) $validUntil,
            'professional_system_user_id' => (string) ($professionalId ?? ''),
        ]);
    }

    /**
     * Contexto obrigatório (encounter_id e patient_id > 0) para gravar
     * prescrição ou modelo; ausente → a tela recusa antes de chamar o serviço.
     */
    private static function hasContext($encounterId, $patientId): bool
    {
        return (int) $encounterId > 0 && (int) $patientId > 0;
    }

    /**
     * Rascunho de itens + bloco de medicamento, se todo preenchido (entra
     * como último item). Bloco parcial → InvalidArgumentException; nenhum
     * item → InvalidArgumentException.
     *
     * @return list<array<string, string>>
     */
    private function collectItems(object $data): array
    {
        $items = $this->draftItems();

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

        return $items;
    }

    /**
     * dd/mm/aaaa → Y-m-d; vazio → null. Texto fora do formato segue como
     * veio, para o PrescriptionService recusar ("valid_until must be a Y-m-d
     * date").
     */
    private static function validUntilToIso(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '')
        {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $value);

        if ($date === false || $date->format('d/m/Y') !== $value)
        {
            return $value;
        }

        return $date->format('Y-m-d');
    }

    /**
     * Modelos do tenant (id => nome) para o combo; falha degrada para lista vazia.
     *
     * @return array<int, string>
     */
    private function templateOptions(): array
    {
        if ($this->encounterId === null)
        {
            return [];
        }

        return self::loadTemplateOptions();
    }

    /**
     * @return array<int, string>
     */
    private static function loadTemplateOptions(): array
    {
        $options = [];

        try
        {
            TTransaction::open('permission');

            foreach (self::buildTemplateService(self::resolveTenantContext())->listAll() as $template)
            {
                $options[(int) $template->id()] = $template->name();
            }

            TTransaction::close();
        }
        catch (\Throwable $e)
        {
            TTransaction::rollback();
        }

        return $options;
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
            $this->storeDraftHeader($data->orientation_text ?? null, $data->valid_until ?? null, $data->professional_system_user_id ?? null);

            $this->reloadSelf();
        }
        catch (Exception $e)
        {
            if (isset($data))
            {
                $this->form->setData($data);
            }
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * method onRemoveItem()
     * Removes one line from the TSession draft by its index and reloads.
     * O botão posta o formulário: o cabeçalho (validade, orientação,
     * profissional) é restaurado a partir de $param, não do rascunho anterior.
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

        $this->storeDraftHeader(
            isset($param['orientation_text']) ? (string) $param['orientation_text'] : null,
            isset($param['valid_until']) ? (string) $param['valid_until'] : null,
            isset($param['professional_system_user_id']) ? (string) $param['professional_system_user_id'] : null
        );

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

            if (!self::hasContext($data->encounter_id ?? null, $data->patient_id ?? null))
            {
                $this->form->setData($data);
                new TMessage('error', _t('Open the prescription from the encounter'));

                return;
            }

            $this->form->validate();

            // bloco de medicamento preenchido e ainda não adicionado entra
            // como último item (só em memória; o rascunho não muda aqui)
            $items = $this->collectItems($data);

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildPrescriptionService($tenant_context);

            $prescription = $service->create([
                'encounter_id' => (int) $data->encounter_id,
                'patient_id' => (int) $data->patient_id,
                'professional_system_user_id' => (int) $data->professional_system_user_id,
                'orientation' => $data->orientation_text ?? null,
                'valid_until' => self::validUntilToIso($data->valid_until ?? null),
                'items' => $items,
            ], __CLASS__ . '::' . __FUNCTION__);

            TTransaction::close();

            // draft consumed: clear it so a fresh prescription for the same
            // encounter does not start pre-filled with the previous one
            TSession::setValue($this->draftSessionKey(), null);
            TSession::setValue($this->headerSessionKey(), null);

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
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            // Critério de aceite (T-06): AuthorizationDenied capturada e
            // exibida como TMessage tratado, nunca erro fatal.
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', _t('You are not allowed to issue a prescription for this unit'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * method onAskTemplateName()
     * "Salvar como modelo": separa os itens em edição (rascunho + bloco de
     * medicamento, se todo preenchido) e a orientação numa chave própria de
     * TSession — o rascunho da prescrição não muda — e pede o nome do modelo
     * num TInputDialog.
     */
    public function onAskTemplateName($param)
    {
        try
        {
            $data = $this->form->getData();
            $this->form->setData($data);

            TSession::setValue(self::templateSourceKey($this->encounterId), [
                'items' => $this->collectItems($data),
                'orientation_text' => trim((string) ($data->orientation_text ?? '')),
            ]);

            $dialog = new BootstrapFormBuilder('form_PrescriptionTemplateName');

            $name = new TEntry('template_name');
            $name->setSize('100%');
            $name->addValidation(_t('Name'), new TRequiredValidator);
            $dialog->addFields([new TLabel(_t('Name'))], [$name]);

            $dialog->addAction(_t('Save'), new TAction([__CLASS__, 'onSaveTemplate'], [
                'encounter_id' => $this->encounterId,
                'patient_id' => $this->patientId,
                'static' => '1',
            ]), 'fa:check');

            new TInputDialog(_t('Save as template'), $dialog);
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * method onSaveTemplate()
     * Confirmação do TInputDialog: grava os itens e a orientação do rascunho
     * deste atendimento como modelo do tenant
     * (PrescriptionTemplateService::saveFromItems). Nome repetido ou inválido
     * vira TMessage de erro.
     */
    public static function onSaveTemplate($param)
    {
        try
        {
            $encounterId = isset($param['encounter_id']) && $param['encounter_id'] !== '' ? (int) $param['encounter_id'] : 0;

            if (!self::hasContext($encounterId, $param['patient_id'] ?? null))
            {
                new TMessage('error', _t('Open the prescription from the encounter'));

                return;
            }

            $source = TSession::getValue(self::templateSourceKey($encounterId));
            $items = is_array($source) ? ($source['items'] ?? []) : [];
            $orientation = is_array($source) ? (string) ($source['orientation_text'] ?? '') : '';

            TTransaction::open('permission');

            self::buildTemplateService(self::resolveTenantContext())->saveFromItems(
                trim((string) ($param['template_name'] ?? '')),
                $orientation === '' ? null : $orientation,
                is_array($items) ? $items : [],
                (int) TSession::getValue('userid')
            );

            TTransaction::close();

            TSession::setValue(self::templateSourceKey($encounterId), null);

            // o modelo novo entra no combo sem recarregar a página
            CvCombo::reload(self::FORM_NAME, 'template_id', self::loadTemplateOptions(), true);

            new TMessage('info', _t('Template saved'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * method onApplyTemplate()
     * "Aplicar modelo": troca os itens do rascunho e a orientação pelos do
     * modelo escolhido no combo (validade em edição mantida) e recarrega.
     */
    public function onApplyTemplate($param)
    {
        try
        {
            $data = $this->form->getData();
            $this->form->setData($data);

            $templateId = (int) ($data->template_id ?? 0);
            $template = null;

            if ($templateId > 0)
            {
                TTransaction::open('permission');
                $template = self::buildTemplateService(self::resolveTenantContext())->findById($templateId);
                TTransaction::close();
            }

            if ($template === null)
            {
                new TMessage('error', _t('Record not found'));

                return;
            }

            TSession::setValue($this->draftSessionKey(), $template->items());
            $this->storeDraftHeader($template->orientationText(), $data->valid_until ?? null, $data->professional_system_user_id ?? null);

            $this->reloadSelf();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
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
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
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
            . ($prescription->validUntil() !== null
                ? '<p>' . htmlspecialchars(_t('Valid until'), ENT_QUOTES, 'UTF-8') . ' ' . $prescription->validUntil()->format('d/m/Y') . '</p>'
                : '')
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
     * PrescriptionTemplateService (T-13) sobre o PDO da transação aberta.
     */
    private static function buildTemplateService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\PrescriptionTemplateService
    {
        return new \CentralVet\Application\PrescriptionTemplateService(
            new \CentralVet\Persistence\PrescriptionTemplateRepository($context, TTransaction::get()),
            $context
        );
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
