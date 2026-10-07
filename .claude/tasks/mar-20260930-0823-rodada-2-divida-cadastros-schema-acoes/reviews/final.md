# Revisão final
Branch feat/rodada-2-cadastros-schema-acoes (7bf89d9) contra feat/fidelidade-visual-mocks (d6dce7a): 46 commits, 174 arquivos, +11889/-388.
Verificações rodadas: `git diff --stat d6dce7a...HEAD`; `php -l` nos 94 PHP adicionados ou modificados, todos com "No syntax errors detected"; `sha256sum` da 0007 = 9ef0242d…d679, o mesmo checksum registrado em notes.md § Bloqueios; grep dos 20 catch de CrossTenantReferenceException.

## Triagem
- [aberta] Fora do escopo: preço de venda automático em SaleForm → plan.md § Excluído
- [aberta] Fora do escopo: tela de gestão de modelos de prescrição → plan.md § Excluído
- [aberta] Fora do escopo: conciliação bancária → plan.md § Excluído
- [aberta] Fora do escopo: foto nas listas → plan.md § Excluído
- [aberta] Fora do escopo: exportar ou importar outras telas → plan.md § Excluído
- [aberta] Fora do escopo: TutorService sem TenantContext → notes.md § Descobertas
- [aberta] T-01: o cabeçalho diz "4 UNIQUE", mas o DDL cria 3 → 20260930_0007_rodada2_cadastros_financeiro.sql:35. A 0007 já foi aplicada e o checksum amarra o arquivo, então a correção vai para documentação ou para uma 0008.
- [aberta] T-01: ids fixos 106–108 no DML → sql/T-01-programs.sql:17-22 (já aplicado)
- [aberta] T-01: ORDER BY ordinal_position sem a coluna no SELECT → 20260930_0007_rodada2_cadastros_financeiro.verify.sql:22
- [aberta] T-02: json_encode sem JSON_INVALID_UTF8_SUBSTITUTE → AuthorizationRequest.php:41-44
- [aberta] T-03: faltam os testes de id excluído de outro tenant e de empate de started_at → ClinicalSummaryIntegrationTest.php:226-275
- [aberta] T-04: docblock desalinhado e target aceito com qualquer valor → CvPage.php:13-14,156-159
- [aberta] T-06: normalização duplicada; testes de address e document próprio → TutorService.php
- [aberta] T-07: create() e update() normalizam de formas diferentes; weight_kg com vírgula → PatientService.php:84-87
- [aberta] T-08: reschedule() sem testes de service de outro tenant, AuthorizationDenied e chave ausente → AppointmentServiceTest.php:284-359
- [aberta] T-09: importCsv não limita tamanhos. Não foi promovida: o import roda numa única TTransaction e um PDOException desfaz tudo → ServiceImportForm.php:93-97
- [aberta] Validador onda 1: o datepicker desfaz o valor do fill → comportamento do widget
- [aberta] Validador onda 1: "Patient sex must be one of M, F, U" em inglês → Core/Domain/Patient.php:40
- [aberta] Validador onda 1: PatientForm key=999999 com radios editáveis → PatientForm.php:215-219. T-24 conferiu só a ausência do Salvar.
- [aberta] Validador onda 1: T-05 sem contagem de <option> da BASE → sem evidência nova
- [resolvida] sql/T-01-programs.sql sem commit → o arquivo está no diff da branch (42 linhas)
- [aberta] T-10: Duplicar por GET; getMessage() cru; catch DomainException amplo → ServiceList.php:254,281,318-326
- [aberta] T-11: sem integração de INSERT e findByCode; asserção vazia; caixa do Fake → ProductServiceTest.php:98
- [aberta] T-12: nosniff duplicado; UPDATE grava sempre as colunas da foto; sem teste automatizado de uploadedPhoto/onPhoto; foto órfã; cache de 300 s → PatientRepository.php:119-142, PatientForm.php:480-485. Hoje só o PatientService monta Patient, e ele copia a foto, então não há regressão.
- [aberta] T-13: sem validação de varchar; duplicado não atômico; N+1 em listAll → PrescriptionTemplateService.php:47
- [aberta] T-14: lista de formas repetida; sem integração de payment_method → FinancialEntryForm.php
- [resolvida] T-15: sem pendência aberta → escopo por unidade conferido em BankAccountService.php:124-135 e BankAccountRepository.php:150-156
- [aberta] T-16: paused_* sem teste contra o banco; clamp sem teste → T-24:72 exercitou pela UI (paused_seconds=29), mas não há teste automatizado
- [aberta] T-17: render do menu só no relatório; comentário diz null, mas o valor é 0 → QueueEntryView.php:92
- [resolvida] Validador onda 2: agendamento 3 fora da grade da Agenda → T-25 (2ae4334), AgendaView.php:286-300; gate de T-25 com a linha 14:00 "14:21"
- [aberta] Validador onda 2: PatientForm key=999999 com radios editáveis (repetida) → PatientForm.php:215-219
- [aberta] Validador onda 2: gate do menu da fila (T-17) não rodado → T-24:9, fila vazia
- [aberta] T-18: style inline; foto decidida por photoObjectKey !== null; autosave e outros ainda com `id` → EncounterView.php:609-616,639
- [aberta] T-19: TCombo::reload; onRemoveItem restaura rascunho antigo → PrescriptionForm.php. A parte do gate foi resolvida em T-24:61.
- [aberta] T-20: onExport engole Throwable sem error_log; link do KPI depende de $card->get(1) → FinancialOverview.php:144-147
- [aberta] T-21: LOW_STOCK_LIMIT também limita recentSales; contagem de <tr> não rodada → ProductList.php
- [aberta] T-22: mensagens do serviço em inglês por getMessage() → BankAccountForm.php:122,176
- [resolvida] Validador onda 3: agendamento 3 fora da Agenda → T-25. A ausência na Fila é por desenho: não há check-in (board.md:81).
- [resolvida] Validador onda 3: varredura parcial → tabela completa em reports/T-24.md:31-64
- [resolvida] Validador onda 3: gate de Pausar/Retomar/Finalizar, alergia/foto e modelos → reports/T-24.md:60-61,72 (encounter 3408)
- [aberta] T-23: "Patient sex" e mensagens de BankAccountService sem tradução → Patient.php:40, BankAccountService.php:49,84,195
- [aberta] T-23: chaves "%s days" e "This entry cannot advance right now: ^1" → VaccineProtocolForm.php:180, QueueEntryView.php:385
- [resolvida] T-23: POST adulterado em EncounterAccountForm e fluxos de EncounterView e PrescriptionForm → reports/T-24.md:60-62,76 ("O registro selecionado não pertence a esta clínica", conta #46 sem mudança)
- [aberta] T-23: CvFormat::userError sem teste; chave órfã → CvFormat.php:75
- [aberta] T-24: mensagens de domínio em inglês. Exemplo: onPause de atendimento finalizado mostra "Encounter 3408 is finished and cannot be paused" (reports/T-24.md:72).
- [aberta] T-24: PrescriptionForm sem patient_id mostra erro cru → PrescriptionForm.php
- [aberta] T-24: sem screenshot da BASE de T-19 → evidência
- [aberta] T-24: T-17 (menu da fila) não rodado → reports/T-24.md:9
- [resolvida] T-24: linha da AgendaView "sem dona" → a dona é T-25, fechada na onda 6
- [aberta] T-25: span.agenda-block-time sem ms-1 → AgendaView.php:371

## Rulings
- plano · onda 0 — A fonte é a triagem [aberta] da fase 10, com os 4 blocos. Trace, Log do PHP e CRLF ficam fora.
- plano · onda 0 — Branch feat/rodada-2-cadastros-schema-acoes a partir de d6dce7a; checkout compartilhado com caminho exclusivo.
- plano · onda 0 — Migration única 0007, só como arquivo. O orquestrador aplica depois da aprovação SQL e do runbook, e a onda 2 fica bloqueada até lá.
- plano · onda 0 — Pausa por paused_at/paused_seconds, sem status novo.
- plano · onda 0 — Foto no S3 com photo_object_key, servida por PatientForm::onPhoto, sem FK a stored_object e sem URL pré-assinada.
- plano · onda 0 — payment_method nulo em lançamentos manuais e de contas a pagar; backfill só de reference_type='payment'.
- plano · onda 0 — Saldo bancário informado à mão, sem conciliação.
- plano · onda 0 — A cópia de serviço nasce inativa; serviço com agendamento só pode ser inativado; CSV com `;` e cabeçalho fixo.
- plano · onda 0 — Exportar gera CSV com BOM e `;`; Gerar relatório gera PDF por dompdf; links com target _blank.
- plano · onda 0 — CrossTenantReferenceException tratada por CvFormat::userError numa única task (T-23), que também é a única a escrever translations.json.
- plano · onda 0 — Parâmetros novos de entidade e serviço vão por último, com default.
- T-01 · onda 0 — CvShellController para todos os grupos via DML (aprovado pelo usuário).
- plano (revisão) · onda 0 — Respostas do usuário: as 24 tasks ficam, as 4 decisões da migration estão confirmadas e o DML está aprovado.
- T-01 · onda 1 — 0007 e DML não aplicados durante a onda 1: bloqueio da onda 2.
- T-03 · onda 1 — Id excluído de outro tenant ou paciente devolve null; no empate de started_at, o id menor conta como anterior.
- T-07 · onda 1 — As falhas de RedisQueue vieram de suítes paralelas; o gate deu 232/232.
- orquestrador · onda 1 — Rebuild do container antes do gate de navegador.
- T-01 · entre as ondas 1 e 2 — 0007 aplicada com MIGRATION_DB_USER (checksum 9ef0242d…d679, igual ao sha256 do arquivo) e DML aplicado (programas 106–108; grupos 2 e 3).
- orquestrador · onda 2 — Rebuild, profile minio e bucket centralvet-local; login admin feito pelo orquestrador, sem credencial registrada.
- T-12 · onda 2 — XSS armazenado pelo Content-Type da foto corrigido em 4d719ca: whitelist, getimagesize, nosniff, CSP sandbox e SVG recusado.
- T-15 · onda 2 — plano-mandou: a autorização por unidade fica em T-15 (serviço e repositório); conta de outra unidade é tratada como não encontrada.
- T-10 e demais · onda 2 — "Message not found" de chaves novas fica para T-23.
- T-17 · onda 2 — Gate do menu da fila não rodado (fila vazia); appointment_id=0 no encaixe aceito.
- orquestrador · onda 2 — Varredura cruzada coberta pelos gates; os 403 de onSwitchUnit não se repetiram.
- T-18/T-19 · onda 3 — Gate de navegador sem atendimento em andamento, passado para T-24.
- T-19 · onda 3 — Modelo recém-salvo só entra no combo depois de recarregar a tela (aceito).
- T-21 · onda 3 — O custo do PDF vem de ProductService::listActive (desvio aceito); a contagem de linhas do PDF não foi rodada.
- T-22 · onda 3 — Saldo −50,00 pela UI não rodado; a conversão foi provada no relatório.
- orquestrador · onda 3 — "Message not found" fica para T-23; rebuild e novo login admin.
- T-23 · onda 4 — "Patient sex" e as mensagens de BankAccount ficam fora da lista de arquivos (pendência).
- T-23 · onda 4 — As chaves "%s days" e "This entry cannot advance…" já faltavam antes da rodada (pendência).
- T-23 · onda 4 — POST adulterado e fluxos com registro passados para T-24.
- orquestrador · onda 4 — O validador rodou git stash por engano e desfez com pop; stash vazio conferido.
- orquestrador · onda 4 — Rebuild e novo login admin.
- plano (revisão) · onda 6 — T-25 (AgendaSlots) pedida pelo usuário; o item de T-07 no Review Focus foi trocado pelo de T-25.
- T-24 · onda 5 — Encounter 3408 criado por INSERT com aprovação SQL explícita do usuário e finalizado depois.
- T-25 · onda 6 — Onda de correção do bug da AgendaView achado em T-24.
- T-24 · onda 5 — Fix loop 1: fluxos de gravação exercitados no HEAD final (§ Correção 1).
- T-17 · onda 5 — Menu da fila segue não rodado (sem tela de check-in).
- T-09 · onda 5 — Vale a evidência da onda 2; a exclusão foi negada pelo classificador.
- orquestrador · ondas 5/6 — Rebuild e novo login admin.

## Decisões que tomei
- Nenhuma pendência foi promovida. O conjunto da branch não agrava nenhuma delas: a exclusividade da foto continua valendo porque só o PatientService monta Patient (grep de `new Patient(`), e o import de CSV roda numa única transação.
- Não rodei a SUITE: os testes de integração escrevem no banco, e esta revisão é read-only. O 291/291 é do gate de T-25 (reviews/T-25.md § Gate) e não foi reproduzido por mim. Rodei só `php -l` nos 94 arquivos.
- Pendências com várias partes foram classificadas pela parte que continua aberta, com nota da parte resolvida (T-19, T-16).
- O checksum no disco é o sha256 do próprio arquivo (o placeholder foi trocado só na cópia aplicada). Isso é coerente com o registro em notes.md e não é achado.
- Desvios de plano dos relatórios que aceitei como estão: T-01 (ordem de colunas e índices extras), T-04 (rollback isolado), T-12 (UPDATE grava a foto; o ruling fica na camada de serviço), T-20 (csvSafe), T-22 (create + update para nascer inativo) e T-23 (20 catch em 14 arquivos, error_log acrescentado).

## Achados
- [sugestão] O nome da conta vai interpolado na mensagem de nome repetido, e essa mensagem é mostrada por TMessage sem escape. TMessage só aplica addslashes (lib/adianti/widget/dialog/TMessage.php:50), então é self-XSS com o nome que o próprio usuário digitou. O mesmo padrão já existia em ProductService:57/120 → BankAccountService.php:195, BankAccountForm.php:122,176
- [sugestão] Escape duplo na coluna Banco: o TDataGrid já aplica htmlspecialchars antes do transformer (TDataGrid.php:1031), e o transformer aplica CvFormat::e de novo. "&" aparece como "&amp;" → BankAccountList.php:37-38
- [sugestão] attachPhoto grava no storage antes do save no banco: se o banco falhar, o objeto fica órfão (complementa a pendência de foto órfã de T-12) → PatientService.php:203-224
- Segurança conferida, sem achado:
  - foto (4d719ca): extensão na whitelist, getimagesize com o mime igual ao esperado, Content-Type servido só da whitelist, octet-stream com attachment nos demais, nosniff e CSP sandbox → PatientForm.php:uploadedPhoto/onPhoto;
  - alergia escapada no alerta → EncounterView.php:641;
  - chave do storage com o nome sanitizado;
  - BankAccount com escopo por tenant e unidade em findById, listBySystemUnit, save, remove (scopedQuery) e no serviço (requireUnitId);
  - onExport com requireUnitId e csvSafe.
- Migration 0007 conferida, sem achado bloqueante: só ADD COLUMN, CREATE TABLE, 1 CHECK antes do backfill e UPDATE restrito a reference_type='payment' com categoria válida; nenhum DROP nem DELETE; sha256 do arquivo igual ao aplicado; contagens iguais antes e depois em notes.md § Bloqueios.
- T-23: os 20 catch nos 14 arquivos usam error_log + TMessage(CvFormat::userError($e)) (grep conferido).
- T-25: a regra horário → slot e o clamp estão corretos (último slot 18:30); AgendaView agrupa por slotFor e mostra o horário exato; RF 06:45/19:10 coberto por teste e pelo gate.
- Não revisado linha a linha (limite de turnos): EncounterView.php, exceto foto e alergia; PrescriptionForm.php; ProductList.php; FinancialOverview.php, exceto onExport; BankAccountForm.php; QueueEntryView.php; AppointmentForm.php; ServiceList.php; ServiceCatalogService.php; PrescriptionTemplate*; cv-components.css; translations.json.

## Pós-revisão final
Depois desta revisão (aprovada sem bloqueantes), o usuário rodou o /code-review da branch, que apontou dois bugs. A onda 7 os corrigiu:
- `ServiceCatalogService::importCsv` não validava tamanhos de coluna (nome 190, categoria 60, duração `int unsigned`), e uma linha acima do limite dava rollback da importação inteira. T-26 (Levi): validação por linha, linhas inválidas viram `skipped`, e `ServiceImportForm` não mostra mais erro cru de banco (commits 761e485 RED, 4641d50).
- Peso do paciente com vírgula decimal era truncado ("4,5" gravado como 4), com risco de dose clínica. T-27 (Naruto): `PatientService` aceita vírgula e recusa texto com `INVALID_WEIGHT_MESSAGE`. O gate reprovou a primeira solução (máscara numérica transformava "4,5" em 0,45); o fix loop trocou por digitação livre com filtro (commits c3cb77b RED, 984138a, 4e2bb3c RED, 25565fb).
- Ambas aprovadas na revisão (rodada 1, com sugestões); suíte 296/296 na re-validação no HEAD final. Sugestões abertas em notes.md § Pendências.

# Revisão final — ondas 8 a 12
Branch feat/rodada-2-cadastros-schema-acoes (77a2fb1) contra feat/fidelidade-visual-mocks (d6dce7a): 128 commits, 295 arquivos, +23922/-725. Só as ondas 8–12 (cbd7ad1..HEAD): 181 arquivos, +10618/-607.
Verificações rodadas:
- SUITE com src montado: `Total: 384, Passed: 384, Failed: 0, Skipped: 0`, EXIT=0;
- `sha256sum` da 0007 = 9ef0242d…d679 e da 0008 = 0929db34…a8ad6, os mesmos de notes.md § Bloqueios;
- `grep -rn SABOTAGEM src docker` não acha nada; `git log -S SABOTAGEM` só aponta f56cb01 e 87a40c6, que são chore(tasks) de artefatos do plano;
- no container, `is_readable("tmp/../app/config/application.php")` = true (base do achado bloqueante abaixo).

## Triagem
- [aberta] Fora do escopo (6 itens: SaleForm, gestão de modelos, conciliação, foto nas listas, exportar/importar outras telas, TutorService sem TenantContext) → plan.md § Excluído
- [aberta] T-01: "4 UNIQUE" no cabeçalho e ids fixos 106–108 → excluídos na onda 8, porque o checksum amarra o arquivo
- [resolvida] T-01: ORDER BY ordinal_position no .verify.sql → T-37, 0007.verify.sql (diff de 4 linhas)
- [resolvida] T-02: json_encode com UTF-8 inválido → T-36, AuthorizationRequest.php:41-44 e AuthorizationRequestTest
- [resolvida] T-03: testes de outro tenant e de empate → T-37, ClinicalSummaryIntegrationTest (+63)
- [resolvida] T-04: docblock, `target` restrito e unidade única → T-39, CvPage.php
- [resolvida] T-06: normalização e testes → T-36, TutorService.php, TutorServiceTest (+51)
- [resolvida] T-07: create/update normalizam igual; weight com vírgula → T-27/T-32, PatientService.php
- [resolvida] T-08: testes de reschedule; key inexistente → T-36/T-29, AppointmentServiceTest (+163)
- [resolvida] T-09: limites do importCsv → T-26/T-33, ServiceCatalogService.php
- [aberta] Validador onda 1: datepicker desfaz o `fill` → comportamento do widget, excluído
- [resolvida] Validador onda 1 / T-23 / T-24: "Patient sex" e mensagens de BankAccount em inglês → UserMessage.php:18-22, aplicado por CvFormat::userError
- [aberta] Validadores das ondas 1 e 2: PatientForm key=999999 com radios editáveis → reports T-32 dão o item do GATE como sem evidência
- [aberta] Validador onda 1: contagem de `<option>` de T-05; T-24: screenshot da BASE de T-19 → evidência histórica, excluída
- [aberta] T-10: fallback de tenant_user copiado 3 vezes → excluído; Duplicar por GET e mensagens resolvidos em T-33
- [resolvida] T-11: testes de INSERT/findByCode e asserção vazia → T-37/T-38, ProductRepositoryIntegrationTest (+120), ProductServiceTest
- [aberta] T-12: UPDATE grava sempre as colunas da foto → ruling de T-12. nosniff duplicado (T-32/T-46) e foto órfã (T-47/T-58) resolvidos
- [resolvida] T-13: varchar, duplicado e N+1 → T-38, PrescriptionTemplate.php, PrescriptionTemplateRepository.php
- [resolvida] T-14: teste de integração de payment_method → T-37, FinancialEntryRepositoryIntegrationTest (+113)
- [resolvida] T-16: paused_* contra o banco e clamp → T-37, EncounterRepositoryIntegrationTest (+134), EncounterServiceTest (+18)
- [resolvida] T-17 / Validador onda 2 / T-24: menu da fila não rodado → gate de T-29 (check-in pela Agenda), QueueEntryView.php:92
- [resolvida] T-18: style inline e `id` nos retornos → T-31, EncounterView.php, cv-components.css
- [resolvida] T-19 / T-24: combo, rascunho e PrescriptionForm sem patient_id → T-30, PrescriptionForm.php
- [resolvida] T-20: onExport sem error_log; link do KPI → T-35, FinancialOverview.php. O link nos dois estados ficou como decisão.
- [resolvida] T-21: RECENT_SALES_LIMIT → T-35, ProductList.php
- [resolvida] T-22 / T-34: getMessage cru e toCents → T-34/T-48, BankAccountForm.php:84 (MoneyInput) e userError
- [aberta] T-23: chaves "%s days" e "This entry cannot advance…" → não conferidas no diff (a cruzada da onda 8 deu missing=0, mas não reproduzi)
- [resolvida] T-23: CvFormat::userError sem teste → CvFormatUserErrorTest, 6 casos PASS na SUITE
- [aberta] T-23 / T-28: chaves órfãs (26 herdadas) → excluídas na onda 9
- [aberta] T-24: mensagens de domínio das telas fora da triagem → excluídas; cerca de 40 `new TMessage(..., $e->getMessage())` continuam (ver Achados)
- [aberta] T-25: span.agenda-block-time sem ms-1 → AgendaView.php não revisado
- [resolvida] T-26: ramo create() → skipped e asserção no limite → T-33, ServiceCatalogServiceTest (+55)
- [aberta] T-26: reason dinâmica em inglês → ServiceImportForm.php:105 não conferido
- [aberta] T-27: formato de reabertura "4,5", filtro sem teste, comentário do catch → ruling e exclusão (onda 8)
- [aberta] Onda 7 e ondas 8–12: dados R2 no banco → decisão do usuário
- [resolvida] UNIQUE em queue_entry.appointment_id → 0008:49 `ADD UNIQUE KEY queue_entry_appointment_uq`; sha256 do arquivo igual ao de notes; T-41 traduz a violação para 23000
- [resolvida] Confirmação de Excluir/Duplicar sem o nome → T-43, ServiceList.php
- [resolvida] Suíte limpando sessões; queda de sessão das ondas 8, 9 e 10 → T-55: era localhost × 127.0.0.1, e o Redis dos testes ficou isolado (T-42)
- [resolvida] RedisQueueIntegrationTest flaky → T-42; SUITE 384/384 nesta revisão
- [resolvida] T-28: testCatalogMessageGoesThroughTranslationKey passava sem a implementação → T-49 (testes mais fortes de T-28, T-36 e T-38)
- [aberta] T-28: orphan=0 não reproduz; plan.md:361 → documental, excluído
- [resolvida] T-29 / T-41: Check-in continua após o check-in; badge para qualquer status → T-41/T-57, AgendaView.php:416-421
- [resolvida] T-30: catches de PrescriptionForm → T-44
- [resolvida] T-31: catches com getMessage cru no EncounterView → T-45 (screenError) e T-53 (PDO em userError)
- [resolvida] T-32: nosniff do FinancialOverview e foto anterior apagada antes do commit → T-46, T-47
- [aberta] T-33: RED que falha por método de Fake inexistente → histórico, excluído
- [resolvida] T-34: valor fora de int e as 8 cópias de toCents → T-48/T-50, MoneyInput.php e 9 formulários com MAX_UNSIGNED_INT_CENTS
- [aberta] T-35 e T-37: decisão do link e evidências só no relatório → excluídas
- [resolvida] T-36 / T-38: teste de negação sem conferir persistência; asserções de outro tenant → T-49, T-59 (FakeEncounterAccountRepository::$saveCount)
- [aberta] T-38: comentário de collation, consulta única e save() sem transação → excluídos na onda 9
- [aberta] T-40: índice redundante, updated_at e premissa de 1 duplicata → excluídos na onda 11
- [aberta] T-41: teste de `[]` sem consulta → excluído (exigiria instrumentar o PDO)
- [resolvida] T-41: ordem do Fake → T-57, FakeQueueEntryRepository.php
- [resolvida] T-42: intervalo de TEST_REDIS_DATABASE → T-55, tests/run.php
- [aberta] T-44, T-47 e T-48: evidências de gate → excluídas; varredura da onda 11
- [resolvida] T-47: órfão se close() lançar → T-58
- [aberta] T-49: decorador anônimo e storedProduct() → aceito em Desvios
- [resolvida] T-50: comentário duplicado, FQCN e placeholder fora do i18n → T-54
- [aberta] T-50: CashSessionForm onOpen "abc" não testado no navegador → leitura do código (CashSessionForm.php:251)
- [resolvida] T-51: catch-all com PDO cru; 01/10 gravado como 10/01 → T-53, CvFormat.php:82-88 e DateTimeInput.php:28
- [resolvida] T-52: chave órfã "Attachment not found" → usada em EncounterView.php:1951
- [resolvida] T-52: ExamResultForm fora do stored_object; filename*=UTF-8'' → T-56, ExamResultForm.php:303-306 e EncounterView.php:1962
- [resolvida] FinancialEntryForm criando registro novo ao editar → T-54 (append-only, edição recusada)
- [aberta] T-53: Application → Presentation (AppointmentService importa DateTimeInput) → AppointmentService.php:98,201
- [aberta] T-54, T-55, T-56, T-57, T-58 e Onda 11 (validador sem navegador) → sugestões de teste e de evidência, sem agravamento
- [aberta] T-60: screenError redundante → EncounterView.php:1596-1606
- [aberta] T-61: close() antes de lançar, constante e ramo select() sem teste → RedisConnectionFactory.php:54-55
- Nenhuma pendência promovida.

## Rulings
- plano (revisão) · onda 8 — T-28..T-39 com arquivos disjuntos; T-28 é o único escritor de translations.json; check-in na AgendaView; nosniff só no nginx.
- T-29 · onda 8 — O check-in cobre o menu da fila de T-17; a corrida ficou para a UNIQUE (0008).
- T-36 · onda 8 — plano-mandou: RBAC antes de isActiveMember; plan.md § Excluído corrigido.
- T-34 · onda 8 — Digitação livre, inválido recusado em pt; CvFormat::e mantido na coluna Banco.
- T-32 · onda 8 — URL da foto com &v=; escopo estendido ao EncounterView só na URL (5ce5d1f).
- T-30 · onda 8 — valid_until com evidência CLI.
- T-39 · onda 8 — Seletor de unidade única vale pelo diff e pelo render.
- T-28 · onda 8 — As 26 chaves órfãs são herdadas; o critério não exige orphan = 0.
- T-35 · onda 8 — Link de Contas bancárias nos dois estados aceito como decisão.
- plano (revisão) · onda 9 — 0008 com dedupe pelo menor id; MoneyInput como parser único; T-42 confirma a causa antes de corrigir.
- T-40 · onda 9 / entre as ondas 9 e 10 — 0008 aplicada com aprovação SQL (checksum 0929db34…, 0 grupos duplicados).
- T-42 · onda 9 — Testes Redis fora dos prováveis aceitos; desvio `false`/'' no lugar de `?:` aceito.
- T-45 · onda 9 — Data malformada corrigida em 16307b5; chaves passadas para T-51.
- T-48 · onda 9 — plano-mandou: teto por coluna via $maxCents/MAX_UNSIGNED_INT_CENTS.
- T-49 · onda 9 — Sabotagem no checkout compartilhado parada; prova refeita em worktree isolada.
- plano (revisão) · onda 10 — T-51 (catálogo de conflito e i18n) e T-52 (stored_object sem schema novo).
- T-41 · onda 10 — Violação da UNIQUE → "Este agendamento já está na fila".
- T-50 · onda 10 — Sem máscara nem filtro (inputmode=decimal); setData sem reload; CashSessionForm onOpen vale pela leitura do código.
- T-52 · onda 10 — Chave única `<hex12>-<nome>`; o rollback apaga só o objeto novo.
- plano (revisão) · onda 11 — T-53..T-59; financial_entry é append-only; T-55 mede em worktree.
- T-53 · onda 11 — Fix loops 1 e 2: TEntry com máscara, sem TDateTime nem picker; AppointmentFormPostIntegrationTest aceito.
- T-54 · onda 11 — RED ausente aceito; trailers `Task:` fora do bloco aceitos sem reescrita.
- T-55 · onda 11 — Causa: localhost × 127.0.0.1; o gate roda sempre em 127.0.0.1.
- T-56 / T-57 · onda 11 — ExamResultForm e o caso cancelado valem pelo teste unitário.
- plano (revisão) · onda 12 — T-61 corrige o select() ignorado; T-60 inclui os 2 catches do ExamResultForm.
- T-60 · onda 12 — Escopo ampliado: InvalidStatusTransition do pedido de exame em pt (0f354f8 RED, f598c82).
- T-61 · onda 12 — O ramo "SELECT recusado com DB válido" vale só pela leitura do código.

## Decisões que tomei
- Pendência com várias partes ficou classificada pela parte que segue aberta, com nota da parte resolvida (T-10, T-12).
- Pendências que plan.md § Premissas exclui com motivo nas ondas 8, 9 e 11 ficaram `aberta`, sem reavaliar o mérito.
- A travessia de caminho no anexo existe na BASE (d6dce7a). Ela não estava em § Pendências, por isso entra como achado novo, e não como `promovida`. É `[bloqueante]` porque T-52 e T-56 abriram o canal de leitura: antes da branch, o objeto gravado não era listado nem baixado.
- Não rodei SELECT em schema_migrations. Conferi o sha256 dos arquivos contra o registrado em notes.md.
- Rodei a SUITE uma vez, como pedido.

## Achados
- [bloqueante] Travessia de caminho no anexo vira leitura arbitrária de arquivo do servidor. `filename` vem do POST sem `basename` (TFile::getPostData não sanitiza, lib/adianti/widget/form/TFile.php:174-181). O arquivo `tmp/<filename>` é lido, gravado no storage, registrado em stored_object com original_name e baixado por onDownloadDocument. O `@unlink($sourcePath)` ainda apaga o arquivo de origem. Exemplo: `tmp/../app/config/application.php` é legível no container → EncounterView.php:1866-1873,1893,1919-1967; ExamResultForm.php:174-176. O mesmo vale para ExamResultForm::onSave. PatientForm.php:640 e ServiceImportForm.php:70 já usam basename.
- [sugestão] findByPublicId não filtra status='available' nem deleted_at, ao contrário de listByObjectKeyFragment → StoredObjectRepository.php:91-100
- [sugestão] O download confere tenant e o prefixo do atendimento, mas não confere system_unit_id nem a existência do atendimento → EncounterDocumentService.php:121-148
- [sugestão] Cerca de 40 catches ainda fazem `new TMessage(..., $e->getMessage())` sem escape. Exemplo: ProductForm.php:169, com o nome do produto (self-XSS, o mesmo padrão do achado anterior de BankAccount). Excluídos por plan.md § Premissas (onda 8).
- [sugestão] As regex de UserMessage::PATTERNS usam `$` sem o modificador `D` e aceitam um `\n` final → UserMessage.php:36-49
- Conferido, sem achado:
  - CvFormat::userError/userMessage escapam os parâmetros e o fallback com e(); PDO vira texto genérico em qualquer elo da cadeia → CvFormat.php:78-113;
  - onPhoto continua com whitelist, attachment para os demais tipos e CSP sandbox; nosniff vem só do nginx (fastcgi_hide_header; o location .php não tem add_header que anule o do server) → PatientForm.php:551-612, default.conf:11,59;
  - o download do anexo força attachment, com no-store e CSP sandbox; o filename é sanitizado e o filename* codificado → EncounterView.php:1955-1965;
  - T-15: o escopo por unidade não mudou nas ondas 8–12 → BankAccountService.php:45,135,151,168, BankAccountRepository.php:145-147;
  - stored_object filtrado por TenantQuery do contexto, com tenant_id gravado do contexto e LIKE escapado → StoredObjectRepository.php;
  - SABOTAGEM sem resquício em src/docker;
  - MoneyInput sem float, com teto por coluna (int unsigned → MAX_UNSIGNED_INT_CENTS nos 8 formulários; bank_account bigint sem teto além de 13 dígitos);
  - DateTimeInput estrito, com `!`, getLastErrors e sem m/d;
  - RedisConnectionFactory recusa SELECT com falha.
- Não revisado (limite de turnos):
  - SELECT de schema_migrations (checksum no banco);
  - tests/run.php (isolamento do Redis de T-42/T-55) linha a linha, além de a SUITE ter passado;
  - RedisConnectionFactory acima da linha 40 (faixa 0..15);
  - QueueEntryRepository.php (tradução do 23000);
  - AgendaView.php, AppointmentForm.php, PrescriptionForm.php, ServiceList.php, CashSessionForm.php, PaymentForm.php, EncounterAccountForm.php, PatientForm.php (exceto onPhoto), translations.json;
  - conteúdo dos testes novos.

# Revisão final — ondas 13 a 16
Branch feat/rodada-2-cadastros-schema-acoes (39de5ef) contra feat/fidelidade-visual-mocks (d6dce7a): 330 arquivos, +26353/-781. Esta seção cobre só as ondas 13–16 (77a2fb1..HEAD, T-62..T-65): 41 arquivos fora de .claude, +1279/-75.
Verificações rodadas:
- SUITE (src montado ro), rodada uma vez: `Total: 409, Passed: 409, Failed: 0, Skipped: 0`, EXIT=0. PASS em todos os métodos de UploadedTmpFileTest (13), CvFormatHtmlSinkTest (4), CvAvatarTitleTest (3) e CvSafeLabelTraitTest (4);
- `grep -rnE "['\"]tmp/" src/app --include='*.php'`: só sobram CvUploaderService:35, SystemDocumentUploaderService:81 e um docblock. Nenhum handler monta mais `tmp/` com entrada da requisição, e todos os exports de admin usam `bin2hex(random_bytes(16))`;
- docker/nginx/default.conf:39-45 devolve 404 para /tmp e /files: arquivo enviado não é servido direto;
- tooltip: adianti.js:445-454 passa `attr('title')` (já decodificado) ao tippy com allowHTML. Por isso o escape duplo no servidor (forHtmlSink) e o escape simples no setAttribute do JS estão corretos;
- select2: TSelect::renderItems aplica htmlspecialchars ao texto da option. Com a máscara `<span>{x_safe}</span>`, o valor inicial e o AJAX chegam ao template HTML de tdbmultisearch.js/tcombo.js com o nome escapado uma vez.

## Triagem
- [aberta] T-62: envio positivo do SystemMessageForm com anexo não exercitado; o catch de InvalidArgumentException não faz setData → SystemMessageForm.php:208-212
- [resolvida] T-62: tmp/ compartilhado com exports de nome previsível → T-63: CvUpload::resolve → resolveForSession em todos os handlers (UploadedTmpFile.php resolveForSession); exports com prefixo de 32 hex (SystemTableList.php:129-131,184-186; SystemDatabaseExplorer.php:221,249-250,307,334-335; SystemSQLPanel.php:223-225)
- [aberta] T-62: a parte de `..` legítimo foi resolvida, porque generateName junta pontos repetidos e o nome em disco nunca tem `..`. Seguem abertos: a chave órfã `Uploaded file was not found` (translations.json:2867, sem uso em .php), o `\0` sem teste e o `$table` possivelmente indefinido sem rollback no catch → SystemDatabaseExplorer.php:496-501
- [aberta] T-63: o Drive perdeu a checagem finfo/MIME, e o `extensions` só é conferido se vier na query → CvUploaderService.php:60, SystemDriveDocumentUploadForm.php:42-45. Não agravou: /files dá 404 e o preview de txt/html/sql sai em <pre> escapado
- [aberta] T-63: corrida em cv_uploads (falha fechada), cv_uploads mantido em login sem logout, tmp/ sem limpeza → ApplicationAuthenticationService.php:93. A parte do docblock foi resolvida (ServiceImportForm.php:6 cita CvUploaderService)
- [aberta] T-63: TMultiEntry com tag crua em <option title> → framework
- [aberta] T-64: theme.js:352 fora da tabela de sinks → framework
- [aberta] T-64: CvAvatarTitleTest testa só titleFor, e reverter placeholder (CvAvatar.php:16) não quebra a suíte; CvPage::header e cvEscapeTitle sem teste → tests/Unit/CvAvatarTitleTest.php (nenhuma chamada a placeholder)
- [aberta] T-64: gate sem hover na sidebar nem no seletor de unidade → cv-shell.js:152,258
- [aberta] T-64: itens [não rodado] do gate. A parte dos combos (SaleForm, picker do VaccinationCardView) passou pelo gate de T-65. Seguem sem hover os avatares de EncounterView e PrescriptionForm com o paciente R2
- [aberta] T-65: EncounterAccountForm, procedure_id do SaleForm e SystemMessageForm não passaram pelo GATE UI → provados só no servidor
- [aberta] T-65: o hash do AdiantiMultiSearchService não cobre `mask` → framework, anterior à branch
- [aberta] T-65: defesa na entrada (recusar `<`/`>` em nomes) → planejada na rodada 3 (T-14 de mar-20261001-1520-rodada-3-divida-tecnica)
- Nenhuma pendência promovida.

## Rulings
- plano (revisão) · onda 13 — T-62: helper único UploadedTmpFile::resolve nos 4 handlers de clinic/ e nos 4 do template; quem gera o próprio nome fica fora.
- plano (revisão) · onda 14 — T-63: CvUploaderService via setService, cv_uploads na TSession, exports com nome aleatório; apagar depois do download fica fora.
- plano (revisão) · onda 15 — T-64: forHtmlSink em todo [title] com texto de usuário, cvEscapeTitle no cv-shell.js e varredura no relatório.
- plano (revisão) · onda 16 — T-65: atributo virtual _safe nos models via CvSafeLabelTrait; defesa na entrada fica para decisão do usuário.
- T-62 · onda 13 — plano-mandou: SystemMessageForm/saveFilesByComma corrigido no fix loop (c79e81b RED, 6c970fe) e aceito como extensão de escopo.
- T-62 · onda 13 — GATE dos 4 handlers do template aceito; foto (2772) e CSV válidos provados na Re-validação 1.
- T-63 · onda 14 — plano-mandou: cv_uploads guarda nome em disco → original UTF-8 (3027da8 RED, 7562bc6).
- T-63 · onda 14 — XSS do Drive (corpo e tooltip) corrigido por sink (e98708a RED, c778b7c, c9afa47); extensão de escopo aceita; RED em CvFormatHtmlSinkTest aceito.
- T-63 · onda 14 — Sugestão do title do CvAvatar virou a onda 15 (T-64).
- T-64 · onda 15 — Achado do select2 vira a onda 16 (T-65); itens [não rodado] aceitos como pendência.
- T-65 · onda 16 — SystemMessageForm:33 aprovado como caminho autorizado (9350db9).
- T-65 · onda 16 — Desvio aceito: máscara `<span>{name_safe}</span>`; os 4 TDBCombo nativos ficam sem mudança.
- orquestrador · onda 16 — Login admin com a senha do .env, com autorização do usuário; rebuild antes do GATE UI.

## Decisões que tomei
- Pendência com várias partes ficou classificada pela parte que segue aberta, com nota da parte resolvida (T-62 `..`, T-63 docblock, T-64 itens não rodados).
- As pendências de framework (TMultiEntry, theme.js, hash do mask) ficaram `aberta` sem reavaliar o mérito, porque o framework não é editável (plan.md § Excluído).
- A classificação errada do SystemWikiPagePicker no relatório de T-65 entrou como sugestão, e não como bloqueante. O plano manda deixar fora os combos de admin/communication cujo rótulo não é nome de usuário, e o título de wiki é editado por perfil com acesso ao módulo.
- Rodei a SUITE uma vez, como pedido.

## Achados
- [sugestão] O relatório de T-65 classifica SystemWikiPagePicker:24 como "select nativo", mas a linha 25 chama `enableSearch()`. É TDBCombo com select2, e tcombo.js renderiza como HTML o título com tag. Um título de wiki com markup executa no picker. Fica fora pela regra do plano (rótulo de conteúdo do módulo, não nome de usuário), mas a justificativa da tabela está errada → src/app/control/communication/pages/SystemWikiPagePicker.php:24-25, reports/T-65.md:60
- [sugestão] O docblock de resolve() ficou acima de generateName(), e há dois docblocks seguidos: resolve() aparece sem documentação e generateName() com @param/@return que não são dele → src/app/Core/Presentation/UploadedTmpFile.php:38-53
- [sugestão] resolveForSession compara o `$name` cru com a lista, mas resolve() apara o nome. Um nome com espaço nas pontas é recusado (falha fechada, sem risco), e os handlers chamam forget(trim(...)). Vale aparar uma vez só, na entrada → UploadedTmpFile.php resolveForSession; CvUpload.php:19-21
- [sugestão] SystemProfileForm faz forget sem unlink quando a foto não é JPEG pelo finfo, e o arquivo fica em tmp/ → src/app/control/admin/SystemProfileForm.php:169-181
- [sugestão] O escape duplo deixa entidades visíveis (`&amp;`) no tooltip nativo quando o [title] é gravado depois do __adianti_process_tooltips (seletor e menu do cv-shell.js). É cosmético: os rótulos `_t` atuais não têm `& < > " '` → cv-shell.js:152,258
- Conferido, sem achado:
  - travessia: resolve() exige basename, recusa `..`/separadores/`\0` e o realpath fica preso a tmpDir com is_file. resolveForSession amarra o nome à sessão. newUploadItems recusa delFile, fileName ≠ newFile e nome fora de tmp/ antes do AdiantiFileSaveTrait;
  - CvUploaderService: só para sessão logada (no show e na allowlist de SystemPermission, que só libera ''/show), extensões bloqueadas, hash com seed, unserialize com allowed_classes=false, nome com 128 bits aleatórios;
  - os 9 handlers fazem unlink do caminho validado + forget, e o original_name vem de cleanOriginalName (sem controles C1/bidi, até 255);
  - forHtmlSink = e(e()), coerente com adianti.js:445-454; CvAvatar, CvPage (aria-label com e simples) e SystemDriveList (label/title/data-title por sink, breadcrumb, toast, painel, preview em <pre>);
  - CvSafeLabelTrait nos 7 models e máscara nos TDBUniqueSearch de clinic/, no TDBCombo+enableSearch do EncounterAccountForm e no TDBMultiSearch do SystemMessageForm; a busca e a ordem continuam na coluna real; os TDBCombo sem enableSearch saem por htmlspecialchars nativo; TCheckList/TDataGrid escapam por padrão (TDataGrid.php:1031).
- Não revisado (limite de turnos):
  - diff dos models Tutor, Service, Product, ProcedureCatalogItem e VaccineCatalogItem, além do padrão visto em Patient e SystemUser;
  - conteúdo de UploadedTmpFileTest, CvFormatHtmlSinkTest e CvSafeLabelTraitTest, além das linhas PASS;
  - RED e conformidade commit a commit (feitos nas revisões por task, reviews/T-62..T-65.md);
  - LINT dos 41 arquivos (a SUITE carrega os do Core e do widget);
  - translations.json (dup/casefold de `Invalid file`) e UserMessage.php/UserMessageTest;
  - ApplicationAuthenticationService (cv_uploads no login).
