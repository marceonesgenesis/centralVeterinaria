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
