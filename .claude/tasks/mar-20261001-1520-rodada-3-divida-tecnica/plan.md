# Plano: Rodada 3 — dívida técnica da CentralVet

## Objetivo
Fechar a dívida que a rodada 2 deixou aberta: mensagens de exceção cruas nos controllers (self-XSS), mensagens de domínio em inglês, anexos sem filtro de disponibilidade e unidade, `TutorService` sem `TenantContext`, a dependência Application→Presentation, as lacunas de teste e a infra de testes instável. Sem tela nova e, se possível, sem schema.

## Premissas
- **Pré-requisito cumprido:** a rodada 2 (`.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/`) está fechada: T-62..T-65 `[x]`, HEAD `39de5ef` e revisão final das ondas 13–16 aprovada. T-65 não é replanejada aqui.
- Branch de trabalho `feat/rodada-3-divida-tecnica` (pedido do usuário, que prevalece sobre o padrão `task/<contexto>`), criada pelo orquestrador a partir de `feat/rodada-2-cadastros-schema-acoes` @ `39de5ef`. Base da revisão final: `feat/rodada-2-cadastros-schema-acoes`.
- Fontes: `notes.md` da rodada 2 (§ Pendências, § Descobertas, § Decisões), `reviews/final.md` (três triagens, inclusive a das ondas 13–16, itens `[aberta]`, e Achados), sugestões abertas de `reviews/T-62.md`, `T-63.md`, `T-64.md` e a seção T-65 de `tasks.md`.
- Sem migration no banco da aplicação (`centralvet`): nenhuma task precisa de schema novo (`stored_object.system_unit_id` já existe). Se surgir necessidade, a task para com `precisa de contexto`, e a migration só entra com aprovação SQL do usuário (skill `sql-write-approval`, `docs/runbooks/migrations.md`).
- Banco MySQL de teste `centralvet_test` (aprovado pelo usuário em 2026-10-01). T-05 redige `scripts/test-db/provision.sh` e `verify.sql` sem executá-los. **Bloqueio entre as ondas 1 e 2:** o orquestrador apresenta objetos, efeito e risco, obtém a aprovação SQL específica, roda `make backup` + `gzip -t` (precaução para o dev), executa o `provision.sh` e o `verify.sql` e registra o resultado em `notes.md § Bloqueios`. T-19 (onda 2) só começa depois disso.
- T-14 (recusar `<`/`>` em nomes de Patient, Tutor, Service e Product, na criação e na edição; na importação CSV, a linha vira `skipped`) foi aprovada pelo usuário em 2026-10-01.
- Os catches crus do template admin/communication (103 em `src/app/control/admin` e `src/app/control/communication`) ficam fora por decisão do usuário (2026-10-01): só os 70 do produto entram.
- Mesmas convenções e ambiente da rodada 2:
  - **LINT** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>`;
  - **SUITE** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`. Não filtra por arquivo: leia as linhas `PASS/FAIL  <Suite>\<Classe>::`. Grava e faz rollback no banco local; não interromper no meio;
  - **GATE**: o orquestrador reconstrói (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`), e só então o validador usa o Playwright MCP, **sempre** em `http://127.0.0.1:8081` (nunca `localhost`, outro cookie jar), com a sessão admin logada pelo orquestrador. A credencial não fica gravada.
- Controllers Adianti não são carregados pela suíte: nas tasks só de controller, `Teste RED` = `sem teste`, e a prova é grep, `php -r` com `init.php` e gate. A trava permanente dos catches é T-17.
- `translations.json` tem escritor único: T-16, na onda 3. Quem precisar de chave nova usa `_t('<chave en>')` e registra no board `- [T-xx] i18n: <chave en> → <texto pt>`. Até T-16 o Adianti mostra "Message not found" para chaves novas, aceito nos gates das ondas 1–2.
- Mutação de prova (para mostrar que um teste discrimina) só em worktree isolada em `/tmp/claude-1000/wt-T-xx`, removida ao fim; nunca no checkout compartilhado (lição de T-49 da rodada 2).
- Registros de teste criados nos gates levam o prefixo `R3 varredura` (ou `R3 …` quando a task fixa o nome). Os dados R2 ficam no banco. Só há o tenant 1: o caso de outro tenant é coberto por teste unitário com Fake de tenant 2 e, na UI, pelo id `999999`.

## Escopo

### Incluso
- Escape dos 70 `new TMessage(..., $e->getMessage())` fora do template (68 em `control/clinic`, `SearchBox.php`, `log/SystemRequestLogView.php`) para `CvFormat::userError` → T-10, T-11, T-12; trava de regressão → T-17.
- Defesa na entrada (`<`/`>` em nomes de Patient, Tutor, Service, Product) → T-14.
- `StoredObjectRepository::findByPublicId` só com `status = 'available'` e `deleted_at IS NULL`; download de anexo conferindo unidade e existência do atendimento → T-03.
- `TutorService` com `TenantContext` → T-04.
- `UserMessage::PATTERNS` com modificador `D` → T-01.
- i18n: mensagens de domínio em inglês (BankAccountService, Encounter, foto, "not found", "is required", itens de modelo, tamanhos), reason dinâmica do `ServiceImportForm` (resolvida pelo catálogo), "%s days" que `_t` não substitui, chave órfã `Uploaded file was not found` → T-01, T-11, T-16.
- Arquitetura: `AppointmentService` sem import de `CentralVet\Presentation\DateTimeInput` → T-02.
- Lacunas de teste ainda não cobertas: reschedule sem `service_id`/profissional, `CvAvatar::placeholder` → `titleFor`, `prescription.valid_until` no banco → T-09; ramo `select()` falso do Redis → T-07.
- Infra de testes: guarda contra commit fora da transação de teste, resolvedor do banco de teste e script de criação do `centralvet_test` → T-05; SUITE no `centralvet_test` por padrão → T-19; `RedisQueueIntegrationTest` sob suítes simultâneas → T-06.
- Pequenos: lista única de formas de pagamento (`Payment::METHODS`) → T-08 e T-10; `screenError` redundante do `EncounterView` → T-13; `RedisConnectionFactory` com `close()` antes de lançar → T-07; `SystemMessageForm` mantém os dados no erro, `SystemDatabaseExplorer` sem `$table` indefinido e `cv_uploads` limpo no login → T-15.
- Uploads (revisão final das ondas 13–16, prioridade alta): extensão conferida mesmo sem `extensions` na URL do `CvUploaderService`, MIME real no Drive, `resolveForSession` aparando uma vez, docblock de `resolve()`, `unlink` da foto recusada no `SystemProfileForm` e teste de `\0` nas pontas → T-20.
- `SystemWikiPagePicker` (TDBCombo com `enableSearch`, classificado errado como "select nativo" no relatório de T-65): título escapado pelo `CvSafeLabelTrait` → T-21.
- Da revisão final das ondas 13–16, já cobertos: `SystemMessageForm` sem `setData` e `$table` sem rollback no `SystemDatabaseExplorer` → T-15; `CvAvatar::placeholder` → `titleFor` sem teste → T-09; chave órfã `Uploaded file was not found` → T-16; `cv_uploads` no login → T-15. GATE não rodado na rodada 2 (avatares de `EncounterView`/`PrescriptionForm` com o paciente R2, `EncounterAccountForm` autorizador, `procedure_id` do `SaleForm`, `SystemMessageForm` com anexo legítimo) → varredura de T-18.
- Validação final da rodada → T-18.

### Excluído
- Telas "Em breve" de produto: Dashboard, Prontuário, Cirurgias, CRM, Relatórios, Ajuda e as abas Preços/Vínculos/Histórico/Orientações/Modelos.
- Itens de `plan.md § Excluído` da rodada 2: preço de venda automático em `SaleForm`, tela de gestão de modelos de prescrição, conciliação bancária, foto nas listas, exportar ou importar outras telas.
- Framework `src/lib/adianti` e todo arquivo de `src/app/config/framework_hashes.php` (inclui o JS do tippy e `theme.js`).
- Evidências históricas já excluídas por ruling na rodada 2 (contagem de `<option>` de T-05, screenshot da BASE de T-19, RED de T-33, migrations 0007/0008 amarradas ao checksum, ids fixos do DML de T-01, índice redundante de T-40).
- Já resolvido, confirmado na exploração e sem task: `json_encode` com `JSON_INVALID_UTF8_SUBSTITUTE` (`AuthorizationRequest.php:43`); N+1 de `PrescriptionTemplateService::listAll` e validação de item vazio e tamanhos do modelo (T-38 da rodada 2); `MAX_DATABASE` já constante (`RedisConnectionFactory.php:29`); chave "This entry cannot advance right now: ^1" e "%s days" existentes no JSON; `ms-1` em `span.agenda-block-time` (`AgendaView.php:404`); docblock "AdiantiUploaderService" do `ServiceImportForm`; integração de `payment_method`, INSERT/`findByCode` de produto, `paused_*`, teste de `CvFormat::userError`, clamp de `accumulatePause`, `ClinicalSummary` de outro tenant e empate; `FinancialEntryForm` já usa `FinancialEntry::PAYMENT_METHODS`.
- Os 103 catches crus do template admin/communication (decisão do usuário).
- As 26 chaves órfãs herdadas de `translations.json` (podem ser usadas por `_t($variável)`; decisão da rodada 2, onda 9).
- Sugestões de baixa explorabilidade de T-63 sem correção barata: corrida de `cv_uploads` no Redis (falha fechada), limpeza de `tmp/` de uploads abandonados.
- Framework, da revisão final das ondas 13–16: `TMultiEntry` com tag crua em `<option title>`, `theme.js:352` fora da tabela de sinks, hash do `AdiantiMultiSearchService` sem cobrir `mask`.
- Sem correção em código ou fora do runner: `CvPage::header` e `cvEscapeTitle` sem teste automatizado (dependem do Adianti e do JS do navegador, fora de `tests/run.php`); entidade visível no tooltip nativo do `cv-shell.js` (:152,258), cosmético porque os rótulos `_t` atuais não têm `& < > "` nem apóstrofo; o relatório `reports/T-65.md:60` da rodada 2, evidência histórica (a classificação correta vai no relatório de T-21).

## Contexto técnico
- Camadas envolvidas: backend (`src/app/Core`: Presentation/UserMessage, Support, Application, Persistence, Domain, Redis, Queue), frontend (controllers Adianti em `src/app/control/clinic`, `SearchBox.php`, `log/`, três arquivos do template, `app/service/auth`, `translations.json`), qa (`src/tests` Unit/Integration/Support, varredura Playwright), docs (`docs/runbooks/tests.md`).
- Projeto/base analisada: `/var/www/html/centralvet` (repositório git único; `git -C /var/www/html/centralvet rev-parse --show-toplevel` = `/var/www/html/centralvet`), código em `src/`, base `feat/rodada-2-cadastros-schema-acoes` @ `39de5ef` (rodada 2 fechada).
- Integrações: storage S3 do anexo (`S3CompatibleStorage::fromEnvironment`), Redis (sessão, fila, `TEST_REDIS_DATABASE`), MySQL local `centralvet` e o novo `centralvet_test` (base Adianti `permission.sql`/`communication.sql`/`log.sql` + migrations).

## Baseline
- php-lint: `php -l` em todo `.php` de `app/control`, `app/lib/widget`, `app/Core`, `app/service`, `app/model` e `tests` (512 arquivos, via LINT com `sh -c` no container, só as linhas diferentes de `No syntax errors detected`) em raiz, no HEAD `39de5ef` → baseline/php-lint.txt (0 linhas). Sem erros prévios: "nenhum erro novo em relação a `baseline/php-lint.txt`" equivale a `No syntax errors detected` em cada arquivo tocado.

## Exploração read-only
- Caminhos relevantes:
  - `src/app/Core/Presentation/UserMessage.php` (STATIC 15, PATTERNS 14, `resolve()` :60; contagens fixadas em `UserMessageTest.php:82-84`), `src/app/lib/widget/CvFormat.php` (`userError` :89, `userMessage` :114, `e` :65);
  - `src/app/Core/Presentation/DateTimeInput.php` (`final`, `parse` :36), usado por `AppointmentService.php:16,98,201`, `AppointmentForm.php:3,365`, `EncounterView.php:3,1801`, `AppointmentFormPostIntegrationTest`;
  - `src/app/Core/Persistence/StoredObjectRepository.php` (:70-76, :91-95), `src/app/Core/Application/EncounterDocumentService.php` (:49-52, :121-148), `EncounterView.php:1928-1934,2060-2066`;
  - `src/app/Core/Application/TutorService.php` (:19, :54, :64) e seus 4 controllers;
  - `src/app/Core/Redis/RedisConnectionFactory.php` (:29, :37-58); `src/app/Core/Domain/Payment.php:43`, `FinancialEntry.php:37`, `PaymentForm.php:278-284`;
  - `src/tests/run.php` (descoberta :140-151, Redis :48-112), `src/tests/Support/MysqlIntegrationTestCase.php` (:26-58), `src/tests/Support/Assert.php`, `src/tests/Integration/RedisQueueIntegrationTest.php`;
  - `src/app/config/translations.json` (array `{"en","pt"}` em ordem casefold), `src/lib/util/ApplicationTranslator.php:148` (`_t` só troca `^1..^4`).
- Padrões identificados:
  - catch de controller: `error_log(__METHOD__ . ': ' . $e->getMessage()); new TMessage('error', CvFormat::userError($e));` (52 usos), sem `CvFormat::e` por cima;
  - mensagem de domínio em inglês no Core, traduzida na tela pelo catálogo `UserMessage` + `_t`;
  - services recebem `CentralVet\Tenancy\TenantContext` no construtor (26 services); Fakes em `tests/Support` com `tenantId` no construtor;
  - testes Unit sem base, `Assert::*`, `require` de `app/lib` dentro de `namespace { if (!class_exists(...)) }`;
  - commits por caminho com trailer `Task: T-xx` e `Task: T-xx (RED)`.
- Scripts úteis: LINT, SUITE e GATE (em Premissas); contagem de i18n do critério de T-16; `php -r 'chdir("/var/www/html/src"); require "init.php"; …'` no container.
- Riscos identificados:
  - `UserMessage.php`/`UserMessageTest.php` e `TutorService.php` são tocados por T-01/T-04 (onda 1) e T-14 (onda 2): serializados;
  - `TutorForm`, `TutorList` e `GlobalSearchController` são tocados por T-04 (onda 1) e T-12 (onda 2); `EncounterView` por T-03 (onda 1) e T-13 (onda 2): serializados;
  - os 70 catches estão em 33 arquivos; a divisão em T-10/T-11/T-12 é disjunta;
  - a suíte roda num processo só e o `_t` global é um stub: carregar o Adianti in-process quebra `CvFormatUserErrorTest`;
  - rebuild e Playwright são estado global (só o orquestrador reconstrói, só o validador navega);
  - SUITEs simultâneas de agentes diferentes podem dar falso FAIL em testes Redis até T-06.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| ⚠ `src/app/Core/Presentation/UserMessage.php` | catálogo mensagem → chave | modificar | T-01, T-14 |
| ⚠ `src/tests/Unit/UserMessageTest.php` | teste do catálogo | modificar | T-01, T-14 |
| `src/app/Core/Support/DateTimeInput.php` | parser estrito de data e hora (neutro) | criar | T-02 |
| `src/app/Core/Presentation/DateTimeInput.php` | delegação para o parser neutro | modificar | T-02 |
| `src/app/Core/Application/AppointmentService.php` | agendamento | modificar | T-02 |
| `src/tests/Unit/CoreLayerDependencyTest.php` | trava de camadas do Core | criar | T-02 |
| `src/app/Core/Persistence/StoredObjectRepository.php` | índice de objetos | modificar | T-03 |
| `src/app/Core/Application/EncounterDocumentService.php` | anexos do atendimento | modificar | T-03 |
| `src/tests/Support/FakeStoredObjectRepository.php` | fake do índice | modificar | T-03 |
| `src/tests/Unit/EncounterDocumentServiceTest.php` | teste do download | modificar | T-03 |
| `src/tests/Integration/StoredObjectRepositoryIntegrationTest.php` | integração do índice | modificar | T-03 |
| ⚠ `src/app/control/clinic/EncounterView.php` | atendimento | modificar | T-03, T-13 |
| ⚠ `src/app/Core/Application/TutorService.php` | cadastro de tutor | modificar | T-04, T-14 |
| `src/tests/Unit/TutorServiceTest.php` | teste do tutor | modificar | T-04 |
| ⚠ `src/app/control/clinic/TutorForm.php` | formulário de tutor | modificar | T-04, T-12 |
| ⚠ `src/app/control/clinic/TutorList.php` | lista de tutores | modificar | T-04, T-12 |
| ⚠ `src/app/control/clinic/GlobalSearchController.php` | busca global | modificar | T-04, T-12 |
| `src/app/control/clinic/PatientForm.php` | formulário de paciente (cadastro rápido de tutor) | modificar | T-04 |
| `src/tests/Support/MysqlIntegrationTestCase.php` | base dos testes MySQL | modificar | T-05 |
| ⚠ `src/tests/Support/TestDatabase.php` | nome do banco de teste | criar | T-05, T-19 |
| ⚠ `src/tests/Unit/TestDatabaseTest.php` | teste do resolvedor | criar | T-05, T-19 |
| `src/tests/Integration/MysqlIsolationGuardIntegrationTest.php` | teste da guarda | criar | T-05 |
| `scripts/test-db/provision.sh` | criação do `centralvet_test` (executada pelo orquestrador com aprovação) | criar | T-05 |
| `scripts/test-db/verify.sql` | conferência do banco de teste (só SELECT) | criar | T-05 |
| ⚠ `docs/runbooks/tests.md` | runbook da suíte | modificar | T-05, T-19 |
| `src/tests/run.php` | runner (recusa o banco da aplicação) | modificar | T-19 |
| `src/tests/Integration/RedisQueueIntegrationTest.php` | integração da fila | modificar | T-06 |
| `src/app/Core/Queue/RedisQueue.php` | fila Redis | modificar | T-06 |
| `src/app/Core/Redis/RedisConnectionFactory.php` | conexão Redis | modificar | T-07 |
| `src/tests/Unit/RedisConnectionFactoryTest.php` | teste do factory | criar | T-07 |
| `src/app/Core/Domain/Payment.php` | pagamento | modificar | T-08 |
| `src/app/Core/Domain/FinancialEntry.php` | lançamento | modificar | T-08 |
| `src/tests/Unit/PaymentMethodsTest.php` | lista única de formas | criar | T-08 |
| `src/tests/Unit/AppointmentServiceTest.php` | teste do agendamento | modificar | T-09 |
| `src/tests/Unit/CvAvatarTitleTest.php` | teste do avatar | modificar | T-09 |
| `src/tests/Integration/PrescriptionRepositoryIntegrationTest.php` | integração da prescrição | criar | T-09 |
| `src/app/control/clinic/EncounterAccountForm.php` | conta do atendimento | modificar | T-10 |
| `src/app/control/clinic/SaleForm.php` | venda | modificar | T-10 |
| `src/app/control/clinic/PaymentForm.php` | pagamento | modificar | T-10 |
| `src/app/control/clinic/PayableList.php` | contas a pagar | modificar | T-10 |
| `src/app/control/clinic/PayableForm.php` | conta a pagar | modificar | T-10 |
| `src/app/control/clinic/CashSessionForm.php` | caixa | modificar | T-10 |
| `src/app/control/clinic/FinancialEntryList.php` | lançamentos | modificar | T-10 |
| `src/app/control/clinic/FinancialEntryForm.php` | lançamento | modificar | T-10 |
| `src/app/control/clinic/FinancialOverview.php` | visão financeira | modificar | T-10 |
| `src/app/control/clinic/PendingReceivableList.php` | recebíveis | modificar | T-10 |
| `src/app/control/clinic/ProcedureExecutionForm.php` | execução de procedimento | modificar | T-11 |
| `src/app/control/clinic/ExamRequestForm.php` | pedido de exame | modificar | T-11 |
| `src/app/control/clinic/QueueEntryView.php` | fila | modificar | T-11 |
| `src/app/control/clinic/VaccinationForm.php` | vacinação | modificar | T-11 |
| `src/app/control/clinic/ProcedureInputForm.php` | insumos de procedimento | modificar | T-11 |
| `src/app/control/clinic/VaccineProtocolForm.php` | protocolo de vacina | modificar | T-11 |
| `src/app/control/clinic/VaccinationCardView.php` | carteira de vacinação | modificar | T-11 |
| `src/app/control/clinic/PendingExamResultList.php` | resultados pendentes | modificar | T-11 |
| `src/app/control/clinic/VaccineCatalogList.php` | catálogo de vacinas | modificar | T-11 |
| `src/app/control/clinic/VaccineCatalogForm.php` | vacina | modificar | T-11 |
| `src/app/control/clinic/ExamCatalogList.php` | catálogo de exames | modificar | T-11 |
| `src/app/control/clinic/ProcedureCatalogList.php` | catálogo de procedimentos | modificar | T-11 |
| `src/app/control/clinic/PatientList.php` | lista de pacientes | modificar | T-12 |
| `src/app/control/clinic/ProductList.php` | lista de produtos | modificar | T-12 |
| `src/app/control/clinic/ProductForm.php` | produto | modificar | T-12 |
| `src/app/control/clinic/ServiceForm.php` | serviço | modificar | T-12 |
| `src/app/control/clinic/ServiceList.php` | lista de serviços | modificar | T-12 |
| `src/app/control/clinic/StockBatchForm.php` | lote de estoque | modificar | T-12 |
| `src/app/control/SearchBox.php` | busca do topo | modificar | T-12 |
| `src/app/control/log/SystemRequestLogView.php` | log de requisições | modificar | T-12 |
| `src/app/Core/Domain/NameText.php` | regra de nome sem `<`/`>` | criar | T-14 |
| `src/tests/Unit/NameTextTest.php` | teste da regra | criar | T-14 |
| `src/app/Core/Application/PatientService.php` | cadastro de paciente | modificar | T-14 |
| `src/app/Core/Application/ServiceCatalogService.php` | catálogo de serviços | modificar | T-14 |
| `src/app/Core/Application/ProductService.php` | cadastro de produto | modificar | T-14 |
| `src/app/control/communication/messages/SystemMessageForm.php` | mensagem interna (template) | modificar | T-15 |
| `src/app/control/admin/SystemDatabaseExplorer.php` | explorador de banco (template) | modificar | T-15 |
| `src/app/service/auth/ApplicationAuthenticationService.php` | variáveis de sessão no login | modificar | T-15 |
| `src/app/config/translations.json` | i18n | modificar | T-16 |
| `src/app/Core/Presentation/UploadedTmpFile.php` | nomes e resolução de tmp/ | modificar | T-20 |
| `src/app/lib/widget/CvUpload.php` | registro de uploads da sessão | modificar | T-20 |
| `src/app/service/upload/CvUploaderService.php` | uploader da aplicação | modificar | T-20 |
| `src/app/control/communication/documents/SystemDriveDocumentUploadForm.php` | upload do Drive (template) | modificar | T-20 |
| `src/app/control/admin/SystemProfileForm.php` | perfil e foto (template) | modificar | T-20 |
| `src/tests/Unit/UploadedTmpFileTest.php` | teste dos helpers de upload | modificar | T-20 |
| `src/app/model/communication/pages/SystemWikiPage.php` | página de wiki (rótulo escapado) | modificar | T-21 |
| `src/app/control/communication/pages/SystemWikiPagePicker.php` | picker de wiki | modificar | T-21 |
| `src/tests/Unit/ControllerRawExceptionMessageTest.php` | trava dos catches | criar | T-17 |
| `.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/reports/T-18.md` | relatório da validação final | criar | T-18 |

Arquivo tocado por mais de uma task leva ⚠ na linha e a resolução fica em "Estratégia de execução": todos os ⚠ estão serializados em ondas diferentes.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Mensagem de domínio continua em inglês no Core e é traduzida na tela pelo catálogo `UserMessage` (padrões genéricos `^1 is required`, `Record not found`, `^1 must have at most ^2 characters`) | traduzir as 304 mensagens no Core; uma chave por mensagem | segue o desenho de T-28 da rodada 2 (Core sem Adianti); os genéricos cobrem dezenas de `throw` com 6 regex |
| `CentralVet\Support\DateTimeInput` como fonte, `Presentation\DateTimeInput` delega | mover e trocar os 4 usos; `class_alias` | `AppointmentForm` e `EncounterView` não mudam; a trava `CoreLayerDependencyTest` impede a volta |
| Download confere `stored_object.system_unit_id` e o atendimento pelo `EncounterRepository` injetado (4º argumento opcional; sem ele, nega) | filtrar só pela linha do `stored_object`; checar no controller | a coluna já é gravada; o service é o ponto que o teste unitário cobre; negar por padrão fecha o caminho de quem esquecer o repositório |
| Upload: lista padrão de extensões no servidor quando a URL não traz `extensions`, e MIME real só no Drive | exigir `setAllowedExtensions` em todo `TFile`; MIME em todos os handlers | não toca os 9 handlers (vários já em outras tasks); o Drive é o único que aceita tipos de documento amplos e perdeu a checagem que tinha |
| `SystemWikiPagePicker` entra na mitigação do `CvSafeLabelTrait` | só corrigir a justificativa do relatório | o título de wiki é editado por um perfil e exibido a outros no select2 (XSS armazenado entre usuários); custo de 2 arquivos, com o padrão do `EncounterAccountForm` |
| Escape dos catches dividido em 3 tasks por área (financeiro, clínico, cadastros) | uma task com 33 arquivos; uma por arquivo | arquivos disjuntos permitem paralelo; tasks de tamanho revisável |
| Defesa na entrada no service (create/update/import), não no `reconstitute` | validar na entidade em toda construção | os registros com `<`/`>` já no banco (payloads R2) continuam abrindo; a recusa vale só para gravação nova |
| Banco `centralvet_test` criado do zero pela base Adianti + migrations, guarda no `tearDown` e recusa no `run.php` quando o banco é o da aplicação | cópia do dev por dump; só a guarda | o schema de teste nasce dos mesmos artefatos auditáveis; o dump copiaria dados reais e R2; a recusa espelha a do Redis (`run.php:48-112`) |
| `Payment::METHODS` público como fonte, `FinancialEntry::PAYMENT_METHODS` derivada | o contrário | `Payment` é quem valida a forma no pagamento; o nome público de `FinancialEntry` continua para quem já o usa |

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04  T-05  T-06  T-07  T-08  T-09  T-20  T-21
T-01, T-08 → T-10
T-01 → T-11
T-01, T-04 → T-12
T-03 → T-13
T-01, T-04 → T-14
T-15 (sem dependência, onda 2)
T-05 → [provisionamento do centralvet_test, aprovação SQL] → T-19
T-01, T-11, T-14 → T-16
T-10, T-11, T-12 → T-17
T-13, T-15, T-16, T-17, T-19, T-20, T-21 → T-18
```

## Estratégia de execução
- Branch de trabalho: `feat/rodada-3-divida-tecnica`
- Branch base: `feat/rodada-2-cadastros-schema-acoes`
- Nota de branch: o orquestrador cria `feat/rodada-3-divida-tecnica` a partir do HEAD de `feat/rodada-2-cadastros-schema-acoes` depois do fechamento da rodada 2, antes da onda 1; todos os agentes trabalham nela, no checkout compartilhado, sem trocar de branch.
- Commits da onda: cada implementador commita os próprios caminhos (`git -C /var/www/html/centralvet add <caminhos>` + `git -C /var/www/html/centralvet commit -m "<assunto>" -m "Task: T-xx" -- <caminhos>`). Nas tasks com teste, o commit do teste falhando (só os arquivos do bloco Teste RED) leva `Task: T-xx (RED)` e vem antes da implementação. Proibidos: `git add -A`/`.`, `commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`. Commit que falhar por `index.lock` é repetido após alguns segundos. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task no checkout compartilhado; os arquivos ⚠ estão em ondas diferentes; rebuild e Playwright são estado global — só o orquestrador reconstrói e só o validador navega; mutação de prova só em worktree isolada em `/tmp/claude-1000/wt-T-xx`)
- SUITE durante a onda: cada implementador roda a SUITE para o próprio RED e para a validação; falha em teste Redis de outro arquivo, com outra SUITE rodando ao mesmo tempo, é ruído até T-06 (onda 1) e vai em Pendências, sem correção.
- Gate de cada onda: rebuild pelo orquestrador; o validador confere trailer, escopo e ordem RED por `git -C /var/www/html/centralvet log <BASE da onda>..HEAD`, roda LINT dos arquivos da onda, SUITE (sozinha, sem outra em paralelo), os passos de Validação e a varredura Playwright da onda (`§ Critérios gerais de aceite`).
- Entre as ondas 1 e 2 (bloqueio): provisionamento do `centralvet_test` pelo orquestrador, com a aprovação SQL específica do usuário (`sql-write-approval`):
  1. mostrar ao usuário os objetos (`CREATE DATABASE centralvet_test`, os `GRANT` em `centralvet_test.*`, a lista de arquivos aplicados), o efeito e o risco (nenhum objeto de `centralvet` é tocado);
  2. `make backup` e `gzip -t` do dev, por precaução;
  3. `bash scripts/test-db/provision.sh`;
  4. `scripts/test-db/verify.sql` (nenhuma tabela de `centralvet` ausente; `system_users` e `tenant` com linhas);
  5. registrar em `notes.md § Bloqueios` (comandos, contagens e a lista de `schema_migrations`).
  Sem aprovação, T-19 fica `[!]`, e o resto da onda 2 segue no banco de dev, como hoje.
- A partir do commit de T-19, toda SUITE usa `centralvet_test`, e o gate confere que `MAX(id)` de `tutor`/`patient` em `centralvet` não muda com a SUITE.

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-20, T-21

### Onda 2
- T-10, T-11, T-12, T-13, T-14, T-15, T-19

### Onda 3
- T-16, T-17

### Onda 4
- T-18

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Platão | general-purpose | inherit | T-01, T-16 |
| Darwin | general-purpose | inherit | T-02 |
| Jaspion | general-purpose | inherit | T-03, T-14 |
| Athena | general-purpose | inherit | T-04 |
| Naruto | general-purpose | inherit | T-05, T-06, T-19 |
| Saitama | general-purpose | inherit | T-07 |
| Arquimedes | general-purpose | inherit | T-08 |
| Sherlock | general-purpose | inherit | T-09 |
| Levi | general-purpose | inherit | T-10, T-17 |
| Kratos | general-purpose | inherit | T-20, T-11 |
| Aang | general-purpose | inherit | T-21 |
| Thanos | general-purpose | inherit | T-12 |
| Yoda | general-purpose | inherit | T-13 |
| Maquiavel | general-purpose | inherit | T-15 |
| Spock — validador | geduc:validador | sonnet | T-18, gates das ondas 1–4 |

## Review Focus
- Nome repetido com `<img src=x onerror=alert(1)> R3` no `ProductForm`, `ServiceForm` e `TutorForm` → diálogo com o texto literal, 0 dialogs JS → T-12
- Erro de domínio no `EncounterAccountForm` (desconto acima do subtotal) e no `SaleForm` (estoque insuficiente) com produto de nome com tag → mensagem em pt com a tag em texto literal, 0 dialogs → T-10
- URL de download de anexo com public_id válido e `encounter_id` de outro atendimento → 404 sem corpo → T-03
- `TutorForm` (novo e editar) e cadastro rápido de tutor no `PatientForm` depois da troca do construtor → gravam com o tenant da sessão → T-04
- Upload direto ao `CvUploaderService` sem `extensions` na URL, com `.xhtml` → `Extension not allowed`, nada gravado em `tmp/` → T-20

## Critérios gerais de aceite
- SUITE termina com `Failed: 0` e `Total` ≥ o da BASE da onda 1 + os testes novos de cada onda, no gate de cada onda.
- LINT de todo arquivo PHP tocado imprime `No syntax errors detected` (nenhum erro novo em relação a `baseline/php-lint.txt`, 0 linhas).
- `SELECT COUNT(*)` de `tutor`, `patient`, `service`, `product`, `stored_object` e `financial_entry` em `centralvet` na BASE da onda e no gate: só os acréscimos `R3` que o validador registrou; nenhum registro existente some. Do gate da onda 2 em diante, `MAX(id)` dessas tabelas em `centralvet` não muda com a SUITE.
- Commits (todo gate): `git -C /var/www/html/centralvet log --format='%h %s%n%b' <BASE da onda>..HEAD` mostra só commits com trailer `Task: T-xx` de tasks da onda (ou `chore(tasks)` restrito a `.claude/tasks/`); `git show --stat` de cada um lista só caminhos de "Arquivos prováveis" da task; nas tasks com teste o commit `Task: T-xx (RED)` toca só os arquivos do bloco Teste RED e vem antes de todo commit `Task: T-xx` da implementação.
- Varredura Playwright no gate (`http://127.0.0.1:8081`, sessão admin): em cada tela, abrir, listar, abrir registro, provocar o erro da task e voltar; depois de cada tela, `browser_console_messages` (nível `error`) e `browser_network_requests` (≥ 400, exceto `favicon`) e `browser_handle_dialog`. Tabela `Tela | Fluxos | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` com 0/0/nenhum em cada linha. Telas por onda:
  - Onda 1: POST forçado ao `CvUploaderService`, Drive (PDF falso e real), uploads legítimos de foto, anexo e CSV (T-20), picker de wiki com a página R3 (T-21), `TutorForm` e `TutorList` (T-04), `PatientForm` cadastro rápido de tutor (T-04), `AppointmentForm` novo e remarcar com "01/10/2026 11:00" (T-02), `EncounterView` download legítimo e `encounter_id=999999` (T-03), `PaymentForm` abrir (T-08), login depois do rebuild (T-07);
  - Onda 2: as telas do GATE de T-10, T-11, T-12, T-13 e T-15 e os 4 cadastros de T-14 com `<b>R3</b>`; "Message not found" aceito nas chaves novas até a onda 3;
  - Onda 3: as telas da onda 2 de novo, sem "Message not found": `VaccineProtocolForm` ("dias"), `PatientForm` (foto inválida em pt), `BankAccountForm` (unidade), `EncounterView` (pausa de finalizado), `PrescriptionForm` (modelo com item vazio);
  - Onda 4 (T-18): todas as telas acima e os GATEs que ficaram `não rodado` na rodada 2: hover nos avatares do `EncounterView` e do `PrescriptionForm` com o paciente R2 9179, o combo autorizador do `EncounterAccountForm`, `procedure_id` do `SaleForm` e envio de mensagem com anexo legítimo no `SystemMessageForm`.
