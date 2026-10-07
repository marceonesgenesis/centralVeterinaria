# Revisão final
Branch feat/rodada-3-divida-tecnica (HEAD eed18af) contra feat/rodada-2-cadastros-schema-acoes (b75d7cc): 86 arquivos fora de .claude, +2014/-260. Árvore limpa (`git status --short` vazio).
SUITE (uma execução, árvore parada, centralvet_test): `Total: 446, Passed: 446, Failed: 0, Skipped: 0`, exit 0.

## Triagem
- [aberta] T-02: a trava de camadas não pega `use CentralVet\Presentation;` nem import agrupado; Core/README sem a camada Support → src/tests/Unit/CoreLayerDependencyTest.php (sem mudança depois da onda 1)
- [aberta] T-03: ramo "system_unit_id nulo" sem asserção; docblock da interface sem o filtro available/deleted_at → src/app/Core/Domain/Contract/StoredObjectRepositoryInterface.php:33
- [aberta] T-04: o grep do critério só casa o teste; o foco de tenant fica no TutorForm (todos os 7 `new TutorService(` passam o TenantContext: TutorForm.php:119,175, TutorList.php:145, PatientForm.php:86, PatientList.php:151, GlobalSearchController.php:159, PendingReceivableList.php:303)
- [resolvida] T-05: resolveName devolvia '' com DB_DATABASE vazio → src/tests/Support/TestDatabase.php:26-30 (DEFAULT_NAME = centralvet_test desde a T-19)
- [aberta] T-05 (e o item "T-05 rodada 2"): a guarda aceita qualquer RuntimeException; MYSQL_PWD no argv; sem instrução de recuperação de falha parcial; verify.sql só nomes; runbook sem tenant; env_value só tira aspas duplas → src/tests/Integration/MysqlIsolationGuardIntegrationTest.php:25, scripts/test-db/provision.sh (env_value, passo 2)
- [aberta] T-06: sem teste determinístico do score; 5x4 SUITEs não reproduzido (esta revisão rodou 1 SUITE só) → src/app/Core/Queue/RedisQueue.php
- [aberta] T-07: captureRuntimeException declara ?string e nunca devolve null → src/tests/Unit/RedisConnectionFactoryTest.php:46
- [aberta] T-20: Interface de tasks.md fala em mimeAllowed/lista plana; EncounterView docFile sem setAllowedExtensions (vale DEFAULT_EXTENSIONS, sem accept); CvUpload::forget/originalName/displayName e `$upload_name` do Drive com trim() padrão → src/app/control/communication/documents/SystemDriveDocumentUploadForm.php:119
- [resolvida] T-21: sem evidência de banco → reports/T-18.md:47 (combo do SystemWikiPagePicker com a página `<img..> R3 wiki`, texto literal, 0 dialogs)
- [resolvida] Validador onda 1: GATE do AppointmentForm (T-02) e do PaymentForm (T-08) → reports/T-18.md:37,41-42
- [aberta] Validador onda 1: critério de 5 rodadas x 4 SUITEs da T-06 medido só pelo orquestrador → não reproduzido aqui
- [aberta] Ambiente: deadlock MySQL 1213 sob SUITEs simultâneas → a SUITE agora roda no centralvet_test (run.php:136-185); concorrência não medida nesta revisão
- [aberta] Registros R3 no banco de dev (onda 1, onda 2 e T-18: tutor 13876, wiki 1, system_document 5–7, anexos do 4304, agendamento 831, system_message 1, dose na vacina 280, arquivos em tmp/) → limpeza manual pendente, fora do código
- [resolvida] T-10: GATE de PaymentForm/SaleForm/EncounterAccountForm → d04f13a (3 padrões em UserMessage.php) + reports/T-18.md (gate da correção 1 aprovado)
- [resolvida] T-11: chave `^1 days` ausente → src/app/config/translations.json:7 ("^1 dias")
- [aberta] T-11: GATE de QueueEntryView, VaccinationForm, ExamRequestForm e ProcedureExecutionForm → ausente da tabela de reports/T-18.md:30-55 (só VaccineProtocolForm, :49)
- [resolvida] T-12: GATE de nome com tag em ProductForm/ServiceForm/TutorForm e AgendaView.php:94 com TAlert cru → reports/T-18.md:33-34; 8c1c24a + ControllerRawExceptionMessageTest (TAlert na trava)
- [aberta] T-13: GATE do retorno "abc" → "Data e hora inválidas" não rodado (a pausa do 3408 em pt foi: reports/T-18.md:44)
- [aberta] T-14: update de Tutor/Patient/Product/Service e PatientService::create sem teste de recusa; leitura de registro antigo com < > sem teste automatizado (o gate da T-18 mostrou o produto `<img..> R2 Prod` literal no SaleForm); create sem trim é inócuo para strpbrk → src/app/Core/Application/PatientService.php:85
- [aberta] T-15: catch Exception genérico do SystemDatabaseExplorer com `$table` sem isset e sem rollback → src/app/control/admin/SystemDatabaseExplorer.php:507-510
- [aberta] T-15: GATE do POST forçado no SystemMessageForm e do segundo login sem logout → reports/T-18.md:54 (não rodado)
- [aberta] T-19: sem teste de resolveName(['DB_DATABASE' => 'centralvet_test']) nem do ramo "not found"; prepare/execute do preflight fora do try (PDOException vira fatal 255); docblock defasado ("the same schema the application itself uses... safe to run against the real development database") → src/tests/run.php:167-170, src/tests/Support/MysqlIntegrationTestCase.php:7-12
- [resolvida] Validador onda 2: "Name must not contain < or >" em inglês e OverpaymentException com centavos → translations.json:1511-1512, UserMessage.php PATTERNS (Payment of … → 'The payment exceeds the open balance', sem números), d04f13a
- [aberta] Validador onda 2: GATEs restantes (T-11 demais telas, T-15 POST forçado e segundo login) → mesmos itens acima
- [aberta] T-16: `%s days` órfã (1 ocorrência em translations.json, nenhum uso em app/) e GATE do retorno inválido do EncounterView não rodado
- [aberta] T-17: a trava não pega variável intermediária, alias de use, closure com `;`; SystemLogDashboard.php:244 `parent::add($e->getMessage())` cru; GATE da AgendaView com erro genérico não rodado → src/tests/Unit/ControllerRawExceptionMessageTest.php:78-86, src/app/control/log/SystemLogDashboard.php:244
- [aberta] T-18: sem teste negativo nem de "\n" final para os 3 padrões novos → src/tests/Unit/UserMessageTest.php:103-118
- [aberta] T-18: itens não rodados aceitos (erro de unidade do BankAccountForm, procedure_id do SaleForm, login/segundo login, hover do avatar 9179; contagens BASE de service/product/stored_object/financial_entry) → reports/T-18.md:40,52-55
## Rulings
- plano · onda 0 — Fontes da rodada 2; repositório único, checkout compartilhado, isolamento por caminho exclusivo
- plano · onda 0 — Branch feat/rodada-3-divida-tecnica a partir do HEAD da rodada 2
- plano · onda 0 — 173 catches crus: 70 do produto (T-10/T-11/T-12) entram, 103 do template ficam fora (usuário)
- plano · onda 0 — Mensagens de domínio em inglês no Core; tradução via UserMessage + translations.json; genéricos mostram o nome técnico do campo
- plano · onda 0 — EncounterDocumentService::download nega sem o EncounterRepository; ExamResultForm só faz attach
- plano (revisão) · onda 0 — T-14 aprovada (validação no caminho de gravação, nunca no reconstitute); 103 catches fora; centralvet_test com provision.sh/verify.sql aplicados pelo orquestrador com aprovação SQL
- plano · onda 0 — Sem migration no banco da aplicação
- plano (revisão) · onda 0 — Achados das ondas 13–16 da rodada 2 distribuídos em T-20, T-15, T-09, T-21; framework e CvPage::header em Excluído
- T-03 · onda 1 — 404 com corpo genérico "Anexo não encontrado" aceito; caso "apagado" vale pelo teste
- T-03 · onda 1 — RED 662c637 tocar FakeStoredObjectRepository.php aceito
- T-04 · onda 1 — 5e058b0 tocar PatientList.php:150 e PendingReceivableList.php:302 aceito
- T-20 · onda 1 — Drive com MIMES_BY_EXTENSION/mimeMatchesExtension; DEFAULT_EXTENSIONS restrita; SystemMessageForm/SystemSupportForm com ATTACHMENT_EXTENSIONS (extensão de escopo)
- T-06 · onda 1 — critério Redis cumprido; deadlock 1213 vira pendência de ambiente
- T-02 e T-08 · onda 1 — GATEs do AppointmentForm e PaymentForm passam para a T-18
- T-01 · onda 1 — grep do critério vale com `grep -F` (ugrep)
- orquestrador · onda 1 — SUITE da re-validação da T-20 no HEAD final: 436/436
- orquestrador · onda 1 — rebuild e sessão admin mantidos pelo orquestrador
- orquestrador · entre as ondas 1 e 2 — provisionamento do centralvet_test com aprovação SQL, backup verificado, 2 execuções (a 1ª falhou em permission.sql, corrigida na T-05)
- T-14 · onda 2 — RED cdcfd37 tocar UserMessageTest.php aceito
- T-05 · onda 2 — 669a3ac criar provision-check.test.sh aceito
- T-10 · onda 2 — correção do TAlert cru no CashSessionForm aceita
- T-13 · onda 2 — alerta de Information Disclosure ao remover screenError é falso positivo
- T-15 · onda 2 — descartar cv_uploads também no Reload do menu é aceito
- orquestrador · onda 2 — rebuild e sessão admin mantidos pelo orquestrador
- T-16 · onda 3 — translations.json reordenado inteiro; `%s days` mantida
- T-17 · onda 3 — extensão de escopo: AgendaView.php:94 e trava estendida a TAlert (1d630f6 RED, 8c1c24a); 6be5900 sem RED conforme tasks.md
- orquestrador · onda 3 — rebuild e sessão admin mantidos pelo orquestrador
- orquestrador · onda 4 — 1ª execução da T-18 interrompida pelo usuário, refeita do zero
- orquestrador · onda 4 — login admin refeito com autorização do usuário para ler o .env
- T-18 · onda 4 — correção 1 sem dona: 3 padrões em UserMessage::PATTERNS + chaves (288626f RED, d04f13a)
- T-18 · onda 4 — itens não rodados aceitos como pendência
- orquestrador · onda 4 — rebuild antes do gate
## Achados
- [sugestão] TestDatabase compara com `$env['DB_DATABASE'] ?? null`, mas a aplicação cai em 'centralvet' quando DB_DATABASE não está definida (config/database.php:8): com DB_DATABASE ausente e TEST_DB_DATABASE=centralvet a guarda passa e a SUITE roda no banco da aplicação. O compose sempre define DB_DATABASE (docker-compose.yml:16), então hoje não é explorável → src/tests/Support/TestDatabase.php:32
- [sugestão] CvFormat::userMessage devolve o texto traduzido sem escapar (só os parâmetros passam por e()): "O nome não pode conter < ou >" vai cru para o TMessage. O parser HTML o renderiza como texto porque `<` vem antes de um espaço (o gate da T-18 confirmou), mas a segurança depende de como a tradução foi escrita → src/app/lib/widget/CvFormat.php:123, src/app/config/translations.json:1512
- [sugestão] O Drive continua aceitando .html e .svg (com MIME coerente), herdado do template: o conteúdo ativo fica guardado. Vale confirmar que o download do Drive serve como attachment e com nosniff → src/app/control/communication/documents/SystemDriveDocumentUploadForm.php:29,45,82
- [sugestão] Os testes dos 3 padrões novos (e do MARKUP_MESSAGE) comparam com strings literais, não com a mensagem das exceções reais (OverpaymentException, DiscountExceedsSubtotalException, InsufficientStockException): se o texto mudar no domínio, a tradução cai no fallback em inglês e nenhum teste falha → src/tests/Unit/UserMessageTest.php:103-118
- Verificado sem achado: download (unidade selecionada obrigatória, encounter da unidade, system_unit_id da linha, findByPublicId com status available e deleted_at IS NULL, prefixo tenant/encounter, Content-Disposition attachment) em EncounterDocumentService.php:131-165, StoredObjectRepository.php:92-98, EncounterView.php:1915-1948; NameText (strpbrk) em PatientService create/update, TutorService create/update, ProductService create/update, ServiceCatalogService create/update e importCsv (skipped via catch, reason escapada em ServiceImportForm.php:109-113); TutorService::create usa só o TenantContext; os 23 PATTERNS têm /D e nenhuma chave expõe SQL ou centavos (os genéricos `^1 is required` mostram o nome técnico do campo, como manda o ruling do plano); userError filtra PDOException/SQLSTATE em toda a cadeia; nenhum `new TMessage(`/`new TAlert(` com getMessage() em clinic/, SearchBox.php e log/ (só PatientForm.php:542, comparação, e SystemLogDashboard.php:244, pendência T-17); CvUploaderService confere a extensão sempre (DEFAULT_EXTENSIONS sem markup); picker de wiki com safeSearchMask('title_safe'); run.php recusa o banco igual a DB_DATABASE e o banco inexistente; provision.sh só cria e grava em centralvet_test (nenhum `USE`/`centralvet.` nas migrations, recusa se o banco existir); a guarda do tearDown lança quando a transação some.
## Não revisado (limite de turnos)
- RedisConnectionFactory.php (close() antes de lançar), RedisQueue.php, Payment::METHODS/FinancialEntry/PaymentForm (lista única), AppointmentService/Support\DateTimeInput, PrescriptionRepositoryIntegrationTest, CvAvatarTitleTest, AttachmentUploadExtensionsIntegrationTest, UploadedTmpFileTest, docs/runbooks/tests.md, verify.sql e provision-check.test.sh foram lidos só pelo diff --stat (nos testes, o PASS da SUITE). Também não revisados: os demais controllers de T-10/T-11/T-12 além do grep de getMessage(), EncounterAccountForm/SaleForm/PaymentForm linha a linha e o JS/download do Drive.
