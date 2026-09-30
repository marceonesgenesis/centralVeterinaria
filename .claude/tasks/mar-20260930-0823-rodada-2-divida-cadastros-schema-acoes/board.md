# Board — mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-04] i18n: Could not switch unit → Não foi possível trocar de unidade
- [T-04] CvPage::header aceita 'target' => '_blank' no spec de ação: sai target="_blank" rel="noopener" e sem generator="adianti" (T-20/T-21 podem consumir). O slot do seletor tem data-cv-label="_t('Unit')".
- [T-03] StockSalesOverviewService::overview($month, $search, $category, $status, $lowStockLimit = 5) disponível (bc695d6) → ['summary','products','low_stock']; recentSales/lowStock com limit <= 0 devolvem []. ClinicalSummaryService::lastEncounter($p, $exclude) agora só devolve atendimento anterior ao excluído, ou null se o excluído não for do mesmo tenant e paciente.
- [T-09] ServiceCatalogService pronto: create(['active'=>false]) num save; duplicate(int $id, string $copyLabel='copy'); delete(int $id) (DomainException "Service {id} has appointments; deactivate it instead"); importCsv(string) → {created, skipped[{line, reason}]}; constante ServiceCatalogService::CSV_HEADER = 'name;category;duration_minutes;price'. ServiceRepositoryInterface ganhou hasAppointments(int): bool.
- [T-09] Conexão do banco da clínica no TTransaction é 'permission' (não 'centralvet', como diz o Review Focus de T-09).
- [T-01] Migration 0007 redigida (b532c63), NÃO aplicada: colunas product.sale_price_cents/code, patient.allergies/photo_object_key/photo_content_type, prescription.valid_until, financial_entry.payment_method, encounter.paused_at/paused_seconds; tabelas prescription_template(_item) e bank_account, nomes exatamente como no Produz de T-01. DML sql/T-01-programs.sql: BankAccountList=106, BankAccountForm=107, ServiceImportForm=108 (grupo 1) e CvShellController(105) para os grupos 2 e 3.
- [T-07] PatientService::update(int $id, array $data) monta `new Patient(...)` com argumentos nomeados, copiando id/tenantId/tutorId/createdAt do atual; T-12, ao acrescentar alergia/foto ao Patient, precisa repassar esses campos em update() (senão o UPDATE os zera para o default). PatientForm edita com `onEdit&key=<id>&tutor_id=<tutor>`; o Salvar da edição usa TAction onSave com `key` e `tutor_id`, e onSave desvia para o método privado `saveExisting($data)`.
- [T-17] i18n: Edit patient → Editar paciente
- [T-17] i18n: Edit appointment → Editar agendamento
- [T-17] QueueEntryView: linha tem patient_id e appointment_id (0 no encaixe, não null: TDataGridAction::prepare() lança com {campo} nulo antes da display condition). Menu: Avançar status, Editar paciente (PatientForm::onEdit key=patient_id), Editar agendamento (AppointmentForm::onEdit key=appointment_id, só se ≠ 0).
- [T-16] EncounterService::pause/resume(int $id, string $action, ?DateTimeImmutable $now = null): Encounter prontos (mesma autorização de finish(), agora num helper privado requireAuthorizedEncounter). Encounter::isPaused()/pausedAt()/pausedSeconds(); finish() de pausado soma o trecho e zera paused_at. Mensagens: "Encounter {id} is already paused", "Encounter {id} is not paused", "Encounter {id} is finished and cannot be paused". EncounterRepository grava paused_at/paused_seconds no INSERT e no UPDATE.
- [T-14] FinancialEntry::paymentMethod(): ?string disponível (46494e5); FinancialEntry::record/reconstitute e FinancialEntryService::record ganharam ?string $paymentMethod = null por último; o repositório grava/lê payment_method. PaymentService repassa o método, category inalterada. T-20 pode ler paymentMethod() nos recentes.
- [T-11] Product::salePriceCents()/code(), ProductRepositoryInterface::findByCode(string) e ProductService::create/update(..., ?int $salePriceCents = null, ?string $code = null) prontos; StockSalesOverviewReader::productStocks() devolve também 'code' (?string) e 'sale_price_cents' (?int) em cada linha (T-21 consome). ProductForm tem campos 'sale_price' (moeda, vazio → null) e 'code'.
- [T-11] i18n: Code → Código
- [T-11] i18n: Sale price → Preço de venda
- [T-15] BankAccountService pronto: create(['system_unit_id','name','bank_name'?,'balance_cents']); update(int $id, array) com chaves opcionais name/bank_name/balance_cents/active (só as presentes mudam; active aceita bool/'1'/'0' via FILTER_VALIDATE_BOOLEAN); listByUnit (ativas e inativas, ordem name, id); findById → ?BankAccount; totalBalanceCents(unit) → ?int (null sem conta ativa). Serviço NÃO recebe AuthorizationPolicy (Interface da task): autorização fica no controller (T-22). bank_name vazio vira null. BankAccount tem rename/changeBankName/changeBalance(int, now)/activate/deactivate; balance_updated_at só muda quando o valor muda.
- [T-10] i18n: Import → Importar
- [T-10] i18n: Duplicate → Duplicar
- [T-10] i18n: copy → cópia
- [T-10] i18n: This service has appointments; deactivate it instead → Este serviço tem agendamentos; inative-o em vez de excluir
- [T-10] i18n: Import services → Importar serviços
- [T-10] i18n: CSV file → Arquivo CSV
- [T-10] i18n: Separator ";", first line with the header: → Separador ";", primeira linha com o cabeçalho:
- [T-10] i18n: Choose a CSV file to import → Escolha um arquivo CSV para importar
- [T-10] i18n: The file must be a CSV of up to 1 MB → O arquivo deve ser um CSV de até 1 MB
- [T-10] i18n: Services created: ^1 → Serviços criados: ^1
- [T-10] i18n: Line ^1 → Linha ^1
- [T-10] i18n: Skipped lines → Linhas puladas
- [T-10] i18n: name is required → nome obrigatório
- [T-10] i18n: invalid duration_minutes → duração inválida
- [T-10] i18n: invalid price → preço inválido
- [T-10] i18n: duplicated name → nome repetido
- [T-10] ServiceList ganhou onDuplicate, onAskDelete (static, TQuestion) e onDelete (348aead); ServiceImportForm::onImport lê tmp/<csv_file>. O critério "Duplicar cria R2 varredura Serviço (cópia)" depende de _t('copy') → "cópia" (T-23); até lá a cópia sai com "Message not found: copy".
- [T-13] Core pronto: Prescription::validUntil() (create(..., ?DateTimeImmutable $validUntil = null) por último); PrescriptionService::create aceita 'valid_until' (Y-m-d|DateTimeImmutable; ''/null = sem validade; hoje é aceito). PrescriptionTemplate::create(tenantId, name, orientationText, items, createdBySystemUserId) + reconstitute(row, itemRows), items() como arrays de 6 campos; PrescriptionTemplateService(repo, TenantContext)::saveFromItems/listAll/findById; PrescriptionTemplateRepository(TenantContext, PDO). A validação de valid_until roda antes da busca do encounter.
- [T-12] Patient ganhou allergies/photoObjectKey/photoContentType (últimos params do construtor, default null). PatientService::__construct aceita 4º arg ?StorageInterface; attachPhoto(int, string, string, string): Patient e photo(int): ?array{contents, content_type} prontos; constantes PHOTO_CONTENT_TYPES e PHOTO_MAX_BYTES. Foto servida por engine.php?class=PatientForm&method=onPhoto&static=1&key=<id> (404 sem corpo). O UPDATE do PatientRepository grava também as colunas da foto (a entidade as carrega; update() as preserva do atual). T-18 pode reusar o src de onPhoto.
- [T-12] i18n: Allergies → Alergias
- [T-12] i18n: Photo must be a JPEG, PNG or WEBP image → A foto deve ser uma imagem JPEG, PNG ou WEBP
- [T-12] i18n: Photo must be at most 2 MB → A foto deve ter no máximo 2 MB
- [T-12] Ambiente: o serviço minio (profile `minio` do docker-compose) não está no ar; S3CompatibleStorage falha com "Could not resolve host: minio". O GATE de foto de T-12 (e T-18) precisa de `docker compose --profile minio up -d` pelo orquestrador.
- [T-15] Correção rodada 1 (substitui a nota anterior sobre autorização): BankAccountService agora opera só na unidade corrente (TenantContext::requireUnitId()). create() ignora/aceita system_unit_id só se igual à unidade corrente (outra → InvalidArgumentException "Bank account must belong to the current unit {id}"); findById/update de conta de outra unidade = não encontrada ("Bank account {id} not found for this tenant"); listByUnit(outra) lança a mesma exceção de unidade; totalBalanceCents(outra) → null. balance_cents é obrigatório e inteiro (senão "balance_cents must be an integer"). BankAccountRepository filtra por system_unit_id quando o contexto tem unidade. Contexto sem unidade → MissingTenantContext. T-22/T-20 devem passar TenantContext com unidade.
- [T-22] i18n: Bank accounts → Contas bancárias
- [T-22] i18n: Bank account → Conta bancária
- [T-22] i18n: Bank → Banco
- [T-22] i18n: Balance → Saldo
- [T-22] i18n: Total of active accounts → Total das contas ativas
- [T-22] BankAccountList/BankAccountForm prontos (TPage, sem TRecord): aba 'bank_accounts' em CvNav::group('finance') depois de cashflow; rodapé "Total of active accounts: <CvFormat::money>" (negativo com class text-danger); Form com campos name/bank_name/balance (moeda, negativo)/active e onEdit&key=<id>.
- [T-20] i18n: vs. previous period → vs. período anterior
- [T-20] i18n: Bank balance → Saldo bancário
- [T-20] i18n: No bank account → Nenhuma conta bancária
- [T-20] i18n: Bank accounts → Contas bancárias
- [T-20] CvKpiCard::create(..., ?float $deltaPercent = null, ?string $deltaLabel = null) pronto (null = _t('vs. previous month')); FinancialOverviewService::recentEntries($unit, $limit = 5, ?from, ?to) com limites inclusivos de totals(); FinancialOverviewReader::recentEntries devolve também 'payment_method'. Exportar: engine.php?class=FinancialOverview&method=onExport&static=1&from=Y-m-d&to=Y-m-d (403 sem tenant, 500 em falha, sem corpo).
- [T-18] i18n: Pause → Pausar
- [T-18] i18n: Resume → Retomar
- [T-18] i18n: Paused → Pausado
- [T-18] i18n: Invalid action → Ação inválida
- [T-18] EncounterView: onPause/onResume novos (programa EncounterView; métodos em system_program? não — a permissão é por classe, e a negação explícita em `methods` bloqueia). Finalizar/Pausar/Retomar e onReload usam `encounter_id` (onReload/onFinish/onInlineAction aceitam `id` como fallback); autosave/anexo/retorno seguem com `id`. Alerta de alergia com estilo inline (sem CSS novo); T-23 edita o mesmo arquivo depois.
- [T-21] i18n: Generate report → Gerar relatório
- [T-21] i18n: Stock report → Relatório de estoque
- [T-21] i18n: No products found → Nenhum produto encontrado
- [T-21] ProductList: carga com um overview() (cacheado por filtros), colunas Code/Sale price, ação Gerar relatório → engine.php?class=ProductList&method=onReport&static=1&<filtros> (target _blank); PDF estoque-<Y-m-d>.pdf. Custo no PDF vem de ProductService::listActive (unitCostCents), pois overview() não traz custo.
- [T-19] i18n: Valid until → Válida até
- [T-19] i18n: Save as template → Salvar como modelo
- [T-19] i18n: Template saved → Modelo salvo
- [T-19] i18n: Apply template → Aplicar modelo
- [T-19] i18n: Template → Modelo
- [T-19] PrescriptionForm: onEdit removido; novos onAskTemplateName (TInputDialog), onSaveTemplate (static), onApplyTemplate; campos valid_until (TDate dd/mm/yyyy) e template_id (TCombo). Orientação/validade em edição persistem em TSession 'prescription_form_draft_header_<encounter>'. cv-components.css ganhou bloco .cv-rx-* no fim do arquivo. Os critérios "Modelo salvo"/"Válida até" do GATE dependem de T-23 gravar as chaves acima.
- [T-23] translations.json: 45 chaves novas (todas as linhas i18n: das ondas 1–3 + "The selected record does not belong to this clinic"), ordem casefold preservada; dup=0 dupcase=0 missing=0. CvFormat::userError(\Throwable) pronto; os 20 catch de CrossTenantReferenceException nos 14 controllers usam error_log(__METHOD__ . ': ' . getMessage()) + TMessage(CvFormat::userError($e)) (6db7e48). PatientForm troca "Selected tutor was not found for your account" pelo texto genérico.
- [T-24] AgendaView.php:302 só desenha agendamento com H:i igual a um slot de 30 min: agendamento 3 (14:21) e 1 (15:59) somem da grade (bug anterior à rodada, candidato a T-25). A fila não mostra agendamentos sem check-in (queue_entry vazia; sem tela de check-in).
- [T-24] Nenhuma tela cria atendimento (sem link para EncounterView&patient_id); gates T-18/T-19 aguardam aprovação de INSERT de encounter R2 (paciente 2772).
- [T-25] AgendaSlots (CentralVet\Application) pronto; AgendaView agrupa por slotFor() e mostra span.agenda-block-time com o H:i exato (f63fe25 RED, 2ae4334). Render CLI: ag. 3 na linha 14:00 (14:21), ag. 1 na 15:30 (15:59), ag. 2 na 11:00. Suíte 291/291. GATE de navegador pendente para o validador.
- [T-24] Dados de teste do gate final: encounter 3408 (R2, finalizado pela UI; paused_seconds 29), prescription_template 81 "R2 varredura Modelo" (2 itens), prescription 1677 (valid_until 2026-10-30, itens 2515/2516).
- [T-24] Correção 1 (dados de teste): tutor 3141 address "R2 varredura Rua 1"; serviços 8 "R2 varredura Imp C", 9 "R2 varredura Imp D" (import), 10 "R2 varredura Imp C (cópia)" (Duplicar); produto 946 sale_price 2190; financial_entry 6826 "R2 varredura Despesa" (bank_transfer, 100).
- [T-27] translations.json: 4 chaves gravadas (weight_kg must be a number between 0 and 9999.99, e.g. 4,5; name too long; category too long; Could not import the file. No service was created), dup=0 dupcase=0 missing=0 (984138a). PatientService::INVALID_WEIGHT_MESSAGE pública; weight_kg do PatientForm com setNumericMask(2, ',', '.', true).
- [T-27] Correção 1: weight_kg do PatientForm sem setNumericMask (digitação livre, placeholder _t('e.g. 4,5'), filtro 0-9 , .); PatientService::formatWeightKg(?float): ?string pública (4.5 → "4,5"). i18n: e.g. 4,5 → ex.: 4,5
- [T-34] BankAccountForm onSave/onEdit e BankAccountList onReload usam error_log + TMessage('error', CvFormat::userError($e)); toCents lança \InvalidArgumentException('Invalid amount') fora do formato pt-BR (vazio → 0) (4ca0940). A coluna Banco MANTÉM CvFormat::e: o TDataGrid entrega $raw_data ao transformer (TDataGrid.php:1026-1040), então não há escape duplo; tirar o e() abriria XSS. Mensagens em pt dependem do fix de T-28.
- [T-33] ServiceList: Duplicar (grid e painel) agora vai para onAskDuplicate (static, TQuestion _t('Duplicate this service?')) → onDuplicate; onDuplicate/onDelete usam error_log + CvFormat::userError; onDelete só trata DomainException de delete(). ServiceCatalogService::duplicate corta o nome-base (mb_substr) para caber em 190. FakeServiceRepository::failNextSaveWith(\Throwable) pronto. Chave i18n 'Duplicate this service?' é de T-28.
- [T-38] PrescriptionTemplate::create valida item em branco ("items[].{field} is required") e tamanhos ("{field} must have at most {max} characters"; constantes NAME_MAX_LENGTH/ITEM_FIELD_MAX_LENGTHS). PrescriptionTemplateRepository::save converte a unique tenant+name na mensagem do serviço; listAll carrega itens numa consulta IN. FakeProductRepository::findByCode sem caixa (d613598 RED, e641320).
- [T-36] EncounterAccountService::applyDiscount agora autoriza (requireAccount/assertAllowed) antes de isActiveMember: sem permissão sai AuthorizationDenied para qualquer autorizador; conta inexistente continua CrossTenantReferenceException. TutorService ganhou private static normalize(); mensagens inalteradas. AuthorizationRequest usa JSON_INVALID_UTF8_SUBSTITUTE (97c6997 RED, implementação no commit seguinte).
- [T-30] PrescriptionForm: onSave/onSaveTemplate sem encounter_id ou patient_id (>0) → TMessage(_t('Open the prescription from the encounter')) sem chamar serviço; onSaveTemplate recarrega template_id por TCombo::reload('form_Prescription', ...); remover item é TButton remove_item_<n> que posta o form e onRemoveItem guarda cabeçalho (orientação, validade, profissional) do $param (5963715). Sem params a tela não monta o form: GATE usa encounter_id sem patient_id.
- [T-28] UserMessage (CentralVet\Presentation) + CvFormat::userMessage(string) prontos; userError agora escapa parâmetros e fallback (quem fizer CvFormat::e(CvFormat::userError(...)) escapa duas vezes). translations.json: 32 chaves da Interface de T-28 (inclui T-29/T-30/T-33), órfã removida; dup=0 dupcase=0 (529567f RED, 2f93b0e).
- [T-32] attachPhoto agora gera chave nova a cada envio (`photo-<12 hex>-<nome>`), apaga a foto anterior depois do save e apaga a nova se o save falhar. PatientForm não envia mais X-Content-Type-Options; docker/nginx/default.conf ganhou `fastcgi_hide_header X-Content-Type-Options;` (precisa de restart do nginx no gate). Erros genéricos e não-peso do PatientForm passam por CvFormat::userError (T-28 traduz).
- [T-29] QueueEntryService::checkIn lança DomainException("Appointment {id} is already in the queue") quando appointment_id > 0 já está na fila (antes da autorização, nada gravado). AgendaView: link Check-in (a.agenda-block-checkin, só agendado/confirmado) → onAskCheckIn (static, TQuestion) → onCheckIn(appointment_id, date), TToast "Patient checked in" e recarga na mesma data. AppointmentForm key inexistente: campos não editáveis e sem Salvar (5732895 RED, 28df8c4).
- [T-34] Correção 1 (549b131): Saldo do BankAccountForm sem setNumericMask (digitação livre, filtro [0-9,.-], placeholder 0,00); toCents antes da transação → "Valor inválido" (CLI: __adianti_error com 'Valor inválido' para abc/1,2,3/12.5).
- [T-32] URL da foto do paciente agora versionada: `...method=onPhoto&static=1&key=<id>&v=<PatientService::photoVersion($patient)>` (12 hex do sha1 de photo_object_key). EncounterView.php:611 monta a URL sem `&v=` e pode mostrar a foto antiga por até 300 s; fora do escopo de T-32, não editado.
- [T-32] Por ruling do orquestrador, EncounterView.php também passou a versionar a URL da foto com `&v=<PatientService::photoVersion()>` (5ce5d1f). A pendência da linha anterior sobre EncounterView.php:611 está resolvida.
- [T-46] FinancialOverview::onExport não envia mais X-Content-Type-Options (nginx fonte única). O grep do critério ainda casa um COMENTÁRIO em PatientForm.php:561 (fora do escopo de T-46); não é chamada header().
- [T-42] Aviso: vou rodar SUITEs (inclusive 2 em paralelo, 3 rodadas) e ler o Redis DB 0 (só leitura) a partir de agora.
- [T-40] Migration 0008 redigida (não aplicada): UNIQUE queue_entry_appointment_uq (appointment_id), dedupe mantém menor id. Banco local hoje: queue_entry 1 linha, 0 grupos duplicados, 0 NULL.
- [T-43] translations.json (onda 9): + `Delete the service "^1"?` → `Excluir o serviço "^1"?`, + `Duplicate the service "^1"?` → `Duplicar o serviço "^1"?`, + `In queue` → `Na fila` (T-41); `Duplicate this service?` removida; dup=0 dupcase=0 (cb1e945). ServiceList onAskDuplicate/onAskDelete carregam o serviço por findById; inexistente → TMessage 'Record not found' sem pergunta.
- [T-48] CentralVet\Presentation\MoneyInput::toCents(string $raw, bool $allowNegative = false): int pronto (final, estático, sem float; MAX_INTEGER_DIGITS = 13; fora do formato → \InvalidArgumentException('Invalid amount')). BankAccountForm usa MoneyInput::toCents($raw, true), sem toCents privado e sem o oninput que filtrava o saldo: "abc" e 20 dígitos chegam ao servidor e voltam "Valor inválido" (b2a403d RED, 884b0c5).
- [T-45] EncounterView: nenhum TMessage recebe mais $e->getMessage() (9 catches → error_log + CvFormat::userError) (7ec6808). A SchedulingConflictException ("Requested slot ... conflicts with an existing appointment for professional_system_user_id N") não tem padrão em UserMessage nem chave em translations.json: o conflito do retorno segue em inglês até alguém catalogar (sugestão de chave: "This time slot conflicts with another appointment of the professional" → "Este horário conflita com outro agendamento do profissional"). AppointmentForm.php:225 ainda usa getMessage cru.
- [T-47] PatientService::attachPhoto não apaga mais a foto anterior; previousPhotoKey(): ?string + discardPhoto(string) público (ex-deleteQuietly). PatientForm apaga a anterior só depois de TTransaction::close() (08a26f4 RED, 32f1bb2).
- [T-42] Reprodução: a SUITE roda no Redis DB 0 (REDIS_DATABASE=0 herdado) mas NÃO apaga sessões (centralvet:session:* 3 → 3; MONITOR sem DEL/FLUSH/SCAN fora de cvtest:/cv:testing:). Fila fixa t11-queue confirmada como causa do flaky paralelo. Parado com "precisa de contexto" (reports/T-42.md). SUITEs de T-42 encerradas por ora.
- [T-49] FakeAuthorizationPolicy::setAllowed(bool) pronto; FakeProductRepository::storedProduct(int) lê sem filtro de tenant; _t de CvFormatUserErrorTest devolve '[t]'+chave (SKIP se _t global já existir) (cc00614, só testes). RED feito em worktree isolada, removida.
- [T-42] Pronto (73600c2): tests/run.php sempre usa TEST_REDIS_DATABASE (padrão 15), ignora o REDIS_DATABASE herdado e recusa ("Refusing to run: ... (<n>)", exit 1) quando coincide com o da aplicação. Fila/cache/prefixos dos 4 testes de integração Redis são únicos por instância. 3×2 SUITEs paralelas: 349/349 nas 6. Hipótese SessionRegistry refutada: nenhum teste registra user 1; centralvet:session:* e index:user:1 inalterados. SUITEs de T-42 encerradas.
- [T-45] i18n: Invalid date and time → Data e hora inválidas
- [T-45] i18n: Could not complete the operation. Please try again → Não foi possível concluir a operação. Tente novamente
- [T-45] Correção 1: EncounterView valida a data/hora do retorno antes do AppointmentService (Y-m-d H:i[:s] ou d/m/Y H:i[:s], ano 1900–2100) e todos os catches de erro passam por self::screenError (PDOException/SQLSTATE → texto genérico; resto → CvFormat::userError). As 2 chaves acima são de T-51.
- [T-48] Correção 1 (17b0770 RED, bbda807): MoneyInput::toCents(string $raw, bool $allowNegative = false, ?int $maxCents = null): int. Colunas int unsigned passam MoneyInput::MAX_UNSIGNED_INT_CENTS (4294967295). Acima do teto (valor absoluto, inclusivo) lança 'Invalid amount'. Com null, só vale o limite de 13 dígitos, e zeros à esquerda não contam. $raw precisa ser string: faça (string) $data->campo antes, porque null gera TypeError.
