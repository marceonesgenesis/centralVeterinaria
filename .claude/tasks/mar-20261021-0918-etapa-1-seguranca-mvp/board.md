# Board — mar-20261021-0918-etapa-1-seguranca-mvp

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261021-0918-etapa-1-seguranca-mvp/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-01] UserMessageTest::testCatalogHasExactlyTheContractEntries ajustado: STATIC agora tem 82 entradas e uma exceção à identidade mensagem→chave ('patient_id is required when encounter_id is informed' → 'Select the patient of the encounter'). Quem acrescentar entradas em STATIC precisa atualizar esse contador.
- [T-02] MutationRequestPolicy pronta (f9094ab): decideFromRequest lê o token só do cabeçalho HTTP_X_CSRF_TOKEN (não do campo csrf_token do corpo). Links generator="adianti" (__adianti_load_page = GET) e __adianti_ajax_exec para métodos que gravam passam a receber deny por engine.php: view.html de mensagens (onCheck/onUnCheck/onDelete/onUndelete), SystemNotificationFormView::onExecuteAction, AgendaView::onAskCheckIn, SurgeryMaterialForm::onAskRemove, AiCopilotPanel::onClose, SystemMessageList::filter*, SaleForm::onClearCart, EncounterView::onAutosave. T-06 precisa enviar esses carregamentos como POST + X-CSRF-Token.
- [T-02] Listas extras além do plano: READ_METHODS inclui onPhoto, onDownload, downloadFile, onReport (img src/links engine.php que não podem ser POST); MUTATING_OVERRIDES inclui CashSessionForm::onOpen, SystemMessageFormView::onView, SaleForm::onClearCart; EXEMPT_ACTIONS inclui LoginForm::reloadPermissions (link do layout.html). Justificativas em docs/security/csrf-inventario.md.
- [T-03] Rodada 1: PasswordResetRequest limita por conta (login normalizado, qualquer IP) e por IP (qualquer conta), com identificadores 'account:<login>' e 'ip:<ip>' no mesmo LoginRateLimiter (PREFIX). Construtor e handle() inalterados para T-07.
- [T-02] Rodada 1 (6454f50): decideFromRequest($server, $request, $sessionToken, ?array $query = null, ?array $body = null, ?callable $resolver = null) — params novos opcionais; null usa $_GET/$_POST e Reflection (MutationRequestPolicy::resolveCanonical). T-06 pode manter a chamada de 3 args, mas precisa chamá-la depois do autoload do Adianti (class_exists). Passa a dar deny: class/method de $_GET ou $_POST diferentes de $_REQUEST; method só em $_REQUEST com class na query; classe/método inexistente; grafia de caixa diferente da declarada (inclusive por index.php: deny, não strip_method). Comparações das listas sem diferenciar maiúsculas. MUTATING_OVERRIDES ganhou PrescriptionForm::onClear, SystemSQLPanel::onGenerate, SystemDataBrowser::onLoad, SystemTableList::onExportCSV/onExportSQL.
- [T-02] Rodada 2 (8dd9f54): MUTATING_OVERRIDES ganhou SystemSQLPanel::onExportCSV (static, engine.php): passa a exigir POST + X-CSRF-Token. Teste de fumaça de T-06 deve incluir "abrir tabela (SystemDataBrowser::onLoad) + exportar CSV" no admin; aba nova em index.php?class=SystemDataBrowser&method=onLoad cai em strip_method (janela sem dados).
- [T-02] Achado sem decisão: AdiantiAutocompleteService/AdiantiMultiSearchService::onSearch concatenam operator e orderColumn (fora do hash) no SQL. É injeção de SQL para usuário logado, não CSRF; continuam como leitura para não quebrar o autocomplete GET. Ver docs/security/csrf-inventario.md § Varredura.
