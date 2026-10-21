# Inventário CSRF — `MutationRequestPolicy`

Origem: auditoria `.docs/audits/2026-10-09-prontidao-mvp.md` § S2 (Etapa 1 — Segurança). Regra em
`src/app/Core/Security/MutationRequestPolicy.php`; testes em `src/tests/Unit/MutationRequestPolicyTest.php`.

## Regra

A política é uma allowlist de leitura. Para uma requisição logada com `class` e `method`:

1. Método vazio (só construtor), nome em `READ_METHODS` ou começando por um de `READ_PREFIXES` é leitura,
   exceto `Classe::método` em `MUTATING_OVERRIDES`. Leitura → `allow`.
2. Classe em `EXEMPT_CLASSES` ou `Classe::método` em `EXEMPT_ACTIONS` → `allow`.
3. O resto grava. Por `index.php` (navegação de topo, que não envia cabeçalho) → `strip_method`: a página
   abre sem executar o método.
4. Por `engine.php` → `allow` só com `POST` e cabeçalho `X-CSRF-Token` igual a `TSession['cv_shell_csrf']`
   (`CsrfToken::isValid`, `hash_equals`); senão `deny`.

Antes disso, `decideFromRequest` (Rodada 1 da revisão):

- **Mesma fonte do dispatch.** `AdiantiCoreApplication::run()` e o despacho estático leem `$_REQUEST`, mas o
  despacho de página (`AdiantiPageControlTrait::run`) executa `$_GET['class']`/`$_GET['method']`. Com
  `variables_order=EGPCS`, o corpo POST (ou um cookie) sobrescreve a query em `$_REQUEST`. Se `class`/`method`
  da query (`$_GET`) ou do corpo (`$_POST`) divergirem de `$_REQUEST`, ou se `method` só existir em
  `$_REQUEST` com `class` na query → `deny`. Ex.: `POST engine.php?class=SaleForm&method=onSave` com
  corpo `method=onReload` → `deny`.
- **Nomes canônicos.** O PHP resolve classe e método sem diferenciar maiúsculas. Os nomes são resolvidos por
  `ReflectionClass::getName`/`ReflectionMethod::getName` (`resolveCanonical`); classe ou método inexistente,
  ou grafia diferente da declarada (`SaleForm::onClearcart`, `cashsessionform::onOpen`) → `deny`.
- Como defesa extra, `isReadOnly`/`decide` comparam listas, prefixos e chaves `Classe::método` sem
  diferenciar maiúsculas.

Errar uma leitura como escrita só passa a exigir POST + token; errar uma escrita como leitura abre CSRF.
Na dúvida, o método fica fora das listas de leitura.

Levantamento (2026-10-21): varredura dos métodos públicos de `src/app/control/**` (177 métodos que casam
com `READ_METHODS`/`READ_PREFIXES`, incluindo construtores), com expansão de até 3 níveis de chamadas
`$this->`/`self::` e busca por `store`, `delete`, `save*`, `update*`, `record*`, `start*`, `finish*`,
`open`, `TSession::setValue` e chamadas de serviço. Os achados reais estão em `MUTATING_OVERRIDES`; o
resto era ruído de widget (`->clear()`, `->add()`, `TScript::create`).

## Listas da política

### READ_METHODS

| Nome | Motivo |
|---|---|
| `show` | Ciclo de vida do `TPage`; carregamentos de dropdown (`SystemMessageDropdown`, `SystemNotificationDropdown`) e `AdiantiUploaderService::show` (isento à parte). Nenhum `show` do projeto grava. |
| `onContext` | `CvShellController::onContext` só monta o JSON da casca (`fetch` GET em `cv-shell.js`); o token é gerado na sessão se faltar, sem efeito para o usuário. |
| `onPhoto` | `PatientForm::onPhoto` é `src` de `<img>` (`engine.php?...&method=onPhoto&static=1`): não pode ser POST. Só lê a foto do storage. |
| `onDownload` | `DocumentList::onDownload` é link de download (`engine.php`); só monta a resposta do arquivo. |
| `downloadFile` | `SystemDriveList`/`SystemMessageFormView::downloadFile` abrem por `TPage::openPage`/link; só chamam `SystemDocumentDownloaderService::download`. |
| `onReport` | `ProductList::onReport` é link `engine.php` que gera PDF; só lê. |

### READ_PREFIXES

| Prefixo | Motivo |
|---|---|
| `onReload` | Recarga de grids/listas (43 métodos); nenhum grava. |
| `onEdit` | Carrega registro no formulário (31); nenhum grava. |
| `onLoad` | Carrega formulário/visão (17); conferidos `SystemProfile2FAForm::onLoad` (gera segredo só para exibir, não grava), `SurgeryChecklistForm::onLoad`, `SurgeryMaterialForm::onLoad`, `SystemDriveList::onLoad`, `SystemPasswordResetForm::onLoad` (link do e-mail). |
| `onSearch` | Filtros (`TutorList`, `PayableList`, `GlobalSearchController`, `AdiantiAutocompleteService`, `AdiantiMultiSearchService`); só `TSession::setValue` de filtro. |
| `onView` | Visualização; `SystemMessageFormView::onView` grava e está em `MUTATING_OVERRIDES`. |
| `onShow` | `onShowCurtainFilters` e `SystemPostCommentForm::onShow`: só montam a cortina/formulário. |
| `onOpen` | `AiCopilotPanel::onOpen` (link do painel), `SystemDriveList::onOpen`, `SystemProgramList::onOpen`; `CashSessionForm::onOpen` abre caixa e está em `MUTATING_OVERRIDES`. |
| `onClear` | Limpa formulário (`ProductForm`, `ServiceForm`, `SystemPostForm`, `SystemMessageForm`, `SystemRegistrationForm`); `SaleForm::onClearCart` e `PrescriptionForm::onClear` apagam rascunho da sessão e estão em `MUTATING_OVERRIDES`. |
| `onChangeLimit` | Tamanho de página do grid em sessão. |
| `onExport` | `FinancialOverview::onExport`, `LandingLeadList::onExport`, `SystemDatabaseExplorer::onExportCSV/SQL` (tabelas vindas de `getDatabaseTables`): só leem; o `unlink` é do arquivo temporário. `SystemTableList::onExportCSV/SQL` e `SystemSQLPanel::onExportCSV` estão em `MUTATING_OVERRIDES`. |
| `onGenerate` | `PrescriptionForm::onGeneratePdf`, `SaleForm::onGenerateReceiptPdf` (links `engine.php`). `SystemSQLPanel::onGenerate` está em `MUTATING_OVERRIDES`. |
| `onPrint` | Reservado para impressão; nenhum método atual. |
| `onDisplay` | `SystemDriveList::onDisplay*`/`SystemDatabaseExplorer::onDisplay*`: só exibem confirmação ou menu; a ação vem depois em outro método. |

`onClose` não é prefixo de leitura: `CashSessionForm::onClose`, `EncounterAccountForm::onClose`,
`FinancialEntryForm::onClose` e `PayableForm::onClose` gravam (fecham caixa/conta/lançamento). Os `onClose`
de cortina (`SystemUserForm::onClose`…) passam a exigir POST + token, sem prejuízo.

### MUTATING_OVERRIDES

| Classe::método | Motivo |
|---|---|
| `AiCopilotPanel::onAsk` | Envia a pergunta à IA (custo) e registra a interação; o nome sugere diálogo de pergunta. |
| `CashSessionForm::onOpen` | Chama `CashSessionService::open` com saldo inicial: abre a sessão de caixa. |
| `SystemMessageFormView::onView` | `->store()` marca a mensagem como lida. |
| `SaleForm::onClearCart` | Apaga o rascunho do carrinho e o tutor da sessão (`TSession::setValue(..., null)`). |
| `PrescriptionForm::onClear` | Descarta o rascunho e o cabeçalho da prescrição na sessão (mesmo efeito de `onClearCart`). |
| `SystemSQLPanel::onGenerate` | Executa o SQL digitado (`$conn->query`) só com checagem de prefixo `SELECT`; com prepares emulados no MySQL admite `SELECT 1; DELETE ...` e `SELECT ... INTO OUTFILE`. |
| `SystemSQLPanel::onExportCSV` | Executa o mesmo `$conn->query($data->select)` de `onGenerate` (só checa o prefixo `SELECT`) e gera CSV. |
| `SystemDataBrowser::onLoad` | Monta `SELECT ... FROM {$table}` e `TFilter` com tabela e coluna vindas do request: SQL arbitrário por concatenação. |
| `SystemTableList::onExportCSV` | Exporta `$param['table']` sem validação, interpolado no SQL. |
| `SystemTableList::onExportSQL` | Idem, gerando `INSERT`s. |

### EXEMPT_CLASSES

| Classe | Motivo |
|---|---|
| `AdiantiUploaderService` | Upload por XHR do `TFile`/`TMultiFile`/`TImageCropper`/`TSignaturePad` (framework protegido, sem o cabeçalho); só grava em `tmp/`. O arquivo só vira dado quando o formulário é salvo, com token. |
| `SystemDocumentUploaderService` | Mesmo papel no Drive (`SystemDriveDocumentUploadForm`); só `move_uploaded_file` para `tmp/`. |

### EXEMPT_ACTIONS

| Classe::método | Motivo |
|---|---|
| `LoginForm::onLogout` | Link do menu do usuário em `layout.html` (protegido) e abertura direta por `index.php`; CSRF de logout só encerra a sessão da vítima. |
| `LoginForm::reloadPermissions` | Link "Recarregar" do mesmo menu; relê as permissões do banco para a sessão, sem entrada do atacante e idempotente. |

## Formulários

Todos os `onSave*` dos formulários (`PatientForm`, `TutorForm`, `AppointmentForm`, `ProductForm`,
`ServiceForm`, `StockBatchForm`, `VaccinationForm`, `PrescriptionForm::onSave/onSaveTemplate`,
`ExamRequestForm`, `ExamResultForm`, `Hospitalization*Form`, `Surgery*Form`, `DocumentRequestForm`,
`CommunicationComposeForm`, `TutorCommunicationForm`, catálogos…) caem na regra geral: POST + token.
O `__adianti_post_data` já envia POST; o cabeçalho vem do prefilter de `cv-shell.js` (T-06).
`EncounterView::onAutosave` usa `__adianti_ajax_exec` a cada 20 s e também precisa sair com POST + token.

## Grid e exclusão

`Delete` (`SystemUserList`, `SystemMessageList`, logs `System*LogList`, `SystemChangeLogView`,
`SystemWikiSearchList`), `onDelete` (`AiConversationList`, `AiCopilotPanel`, `ServiceList`,
`SystemPostList`, `SystemWikiList`, `SystemMessageFormView`, `VaccineProtocolForm`) e os diálogos
`onAsk*` (`ServiceList::onAskDelete/onAskDuplicate`, `SurgeryMaterialForm::onAskRemove`) exigem POST +
token. Os `onAsk*` só abrem `TQuestion`, mas `onAsk` não é prefixo de leitura por causa de
`AiCopilotPanel::onAsk`; exigir POST não abre brecha.

## Pagamento

`PaymentForm::onSave`, `PayableList::onPay`, `PayableForm::onSave/onClose`, `FinancialEntryForm::onSave/onClose`,
`SaleForm::onSave`, `EncounterAccountForm::onSave/onClose`, `CashSessionForm::onOpen/onClose`,
`BankAccountForm::onSave`: POST + token. `GET PaymentForm::onSave` com token válido → `deny` (teste).

## Cancelamento

`SurgeryView::onCancel/onStart/onStartPreOp`, `CommunicationMessageView::onCancel/onRetry`,
`DocumentList::onRetry`, `EncounterView::onFinish`, `HospitalizationView` (alta e suspensão de prescrição
via `__adianti_post_data`) e os diálogos `onAskCancel/onAskComplete/onAskDischarge/onAskSuspendOrder/
onAskMarkSent/onAskRetry/onAskCheckIn`: POST + token.

## Admin

`SystemUserForm/SystemGroupForm/SystemProgramForm/SystemUnitForm::onSave`, `SystemUserList::onTurnOnOff`,
`SystemScheduleList::onTurnOnOff`, `SystemScheduleForm::onSave`, `SystemDatabaseExplorer::onConfirmImport`,
`AiSettingsForm::onSave`, comunicação/drive (`SystemDocument*`, `SystemFolder*`, `SystemPost*`,
`SystemWikiForm`, `SystemMessageForm::onSend`, `SystemSupportForm::onSend`): POST + token.
`SystemSQLPanel::onGenerate/onExportCSV`, `SystemDataBrowser::onLoad` e `SystemTableList::onExportCSV/SQL` executam SQL
montado a partir do request e estão em `MUTATING_OVERRIDES`.

## Copiloto IA

`AiCopilotPanel::onAsk` (override), `AiCopilotPanel::onDelete/onClose`, `EncounterView::onAiDecide/
onAiOrganizeAnamnesis/onAiPatientSummary`, `ExamResultForm::onAiDecide/onAiExamSummary`: POST + token.
`AiCopilotPanel::onOpen` (link de topo que abre o painel) e `AiConversationList/AiInteractionList/
AiToolCallList::onReload` são leitura. `GET AiCopilotPanel::onAsk` sem token → `deny` (teste).

## Troca de unidade

`CvShellController::onSwitchUnit` já valida `csrf_token` no corpo contra `cv_shell_csrf`
(`CsrfToken::isValid`) e passa a exigir também o cabeçalho; `onContext` é leitura (`fetch` GET).
`MutationRequestPolicy::SESSION_KEY` é a mesma chave `cv_shell_csrf` de `CvShellController::csrfToken()`.

## Perfil e 2FA

`SystemProfileForm::onSave`, `SystemProfile2FAForm::onSave` (ativa/desativa 2FA): POST + token.
`SystemProfileForm::onEdit`, `SystemProfile2FAForm::onLoad` (links em `profile.html`) e
`SystemProfileView::onLoad` são leitura; o 2FA gera um segredo só para exibir o QR, sem gravar.

## Endpoints fora do engine

O guard roda em `checkMultiSession()`, chamado por `engine.php` e `index.php`. Ficam fora dele:

- `download.php`: só lê arquivo de `files/` com sessão logada (`realpath` restrito).
- `lead.php`: POST público da landing com `LeadFormToken` próprio e limitador (`LeadSubmissionHandler`).
- `landing.php`: página pública de leitura.
- `cmd.php`: só CLI (`PHP_SAPI !== 'cli'` encerra).

## Navegações de topo para métodos que gravam

Grep de `__adianti_goto_page`, `location.href`, `window.location` e `index.php?...&method=` /
`engine.php?...&method=` em `src/app` e `src/*.xml` (`menu*.xml` não têm `method=`):

- Leituras (seguem funcionando por GET): `CvShellController::onContext`, `PatientForm::onPhoto`,
  `DocumentList::onDownload`, `ProductList::onReport`, `FinancialOverview::onExport`,
  `PrescriptionForm::onGeneratePdf`, `SaleForm::onGenerateReceiptPdf`, `System*::downloadFile`,
  `GlobalSearchController::onSearch`, `SystemPasswordResetForm::onLoad`, `SystemWikiView::onLoad`,
  `SystemDriveList::onLoad`, `*::onEdit`, `SystemMessageForm::onLoad/onClear`,
  `AiCopilotPanel::onOpen`, `EncounterView` (construtor) por `__adianti_goto_page`.
- Isentos: `LoginForm::onLogout`, `LoginForm::reloadPermissions` (`layout.html`).
- Gravam e hoje saem por GET com `generator="adianti"` (`__adianti_load_page` = `$.get`): `view.html`
  de mensagens (`SystemMessageFormView::onCheck/onUnCheck/onDelete/onUndelete`), `SystemNotificationFormView::onExecuteAction`,
  `AgendaView::onAskCheckIn`, `SurgeryMaterialForm::onAskRemove`, `AiCopilotPanel::onClose`,
  `SystemMessageList::filter*` (filtro em sessão), `SaleForm::onClearCart`. Por `engine.php` sem POST
  recebem `deny`; dependem de T-06 converter o carregamento em POST + cabeçalho. Abertos direto por
  `index.php` só renderizam a página (`strip_method`).
- `layout-basic.html` (`SystemUpdateList::fetchProject`) não é o layout em uso.
- Fora do jQuery: `cv-shell.js` usa `fetch` (GET `onContext`, POST `onSwitchUnit`); `admin.js` e
  `theme.js` fazem `$.get` só de construtores (`SystemMessageDropdown`, `SystemNotificationDropdown`,
  `SearchBox`). Nenhum outro `fetch`/`XMLHttpRequest` em `src/app`.

## Gravações fora do alcance do guard

O guard decide por método; construtores que gravam em GET ficam fora e não entram nesta etapa:

- `SystemMessageFormView::__construct` marca a mensagem como lida.
- `EncounterView::__construct` com `patient_id` e sem `encounter_id` inicia o atendimento
  (`startEncounter`) e redireciona por `__adianti_goto_page`.

## Varredura de SQL em métodos de leitura

Rodada 2 (2026-10-21): para cada método público de `src/app/control`, `src/app/service` e
`src/lib/adianti/service` que `MutationRequestPolicy::isReadOnly` classifica como leitura, busca por
`->query(`, `->exec(`, `->prepare(`, `store`/`delete`, `unlink`, `file_put_contents` e
`TSession::setValue`, com expansão de 4 níveis de `$this->`/`self::`/`static::`.

- `->prepare(` com SQL literal e placeholders (resolução do tenant, repositórios, `IN (?,?)` montado só com
  `?`): leitura, mantidos.
- `SystemDatabaseExplorer::onExportCSV/onExportSQL`: o SQL vem de `TSqlSelect` sobre tabelas lidas de
  `getDatabaseTables()` (metadados do banco, não do request); o `unlink` é do temporário. Mantidos em leitura.
- Cobertos por `MUTATING_OVERRIDES`: `SystemSQLPanel::onGenerate`, `SystemSQLPanel::onExportCSV`,
  `SystemDataBrowser::onLoad`, `SystemTableList::onExportCSV`, `SystemTableList::onExportSQL`.
- Sem `->exec(` em método de leitura.
- `TSession::setValue` em leitura: só filtro/paginação/contexto (`onSearch`, `onChangeLimit`,
  `onReload`, `onContext`), sem efeito de negócio.

Achado fora do alcance do CSRF: `AdiantiAutocompleteService::onSearch` e `AdiantiMultiSearchService::onSearch`
(framework protegido, usados por `TDBEntry`/`TDBMultiSearch` em `SystemUserForm`, `AppointmentForm`,
`SaleForm`, `PatientForm`, `StockBatchForm`, `VaccinationCardView` e `SystemMessageForm`) assinam
`database`/`column`/`model` com hash, mas `operator` e `orderColumn` ficam fora do hash e entram no SQL
(`TFilter` concatena o operador; `order` só passa por `addslashes`). Marcar como mutação quebraria o
autocomplete por GET; em vez disso, `CentralVet\Security\SearchParameterGuard::check` (chamado por
`ApplicationAuthenticationService::enforceMutationGuard` antes do despacho) nega `AdiantiAutocompleteService`
e só aceita no `AdiantiMultiSearchService` os formatos que os widgets do app produzem: `operator` vazio,
`like`, `ilike` ou `=`; `key`, `column` e `orderColumn` como identificadores; `orderColumn` vazio, igual a
`key` ou a um dos itens de `column` (todos os widgets ordenam pela coluna exibida, e o omitido vira `column`
em `TDBMultiSearch`), senão `orderColumn is not a searched column`; `mask` restrita a uma coluna buscada;
`criteria` dos modelos com tenant igual ao `$tenant_criteria` da sessão.

