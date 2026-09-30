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
