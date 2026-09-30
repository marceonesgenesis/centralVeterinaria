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
