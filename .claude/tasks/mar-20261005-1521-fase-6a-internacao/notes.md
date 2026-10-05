# Notas de execução

## Decisões tomadas
- 2026-10-05 · plano · onda 0 — Admissão só a partir de atendimento (`hospitalization.encounter_id NOT NULL`), porque a conta da Fase 5 é por atendimento (`encounter_account.encounter_id` UNIQUE NOT NULL). A entrada é `EncounterView::PLAN_ACTIONS['hospitalization']`. A admissão direto do paciente foi para Perguntas.
- 2026-10-05 · plano · onda 0 — Baixa de estoque e lançamento de medicações na alta (pedido da demanda), agregados por produto, motivo `hospitalization_consumption`. Estoque insuficiente recusa a alta inteira, e o `TTransaction` único do controller (`HospitalizationView::onDischarge`) desfaz tudo.
- 2026-10-05 · plano · onda 0 — Itens da conta com `source_type` novos (`hospitalization_stay`, `hospitalization_administration`) via `EncounterAccountService::addSourcedItem`, em vez de `addManualItem`: o UNIQUE `(account_id, source_type, source_id)` dá idempotência. `'hospitalization_administration'` tem 30 caracteres e cabe em `source_type varchar(30)`.
- 2026-10-05 · plano · onda 0 — Ocupação do leito por UPDATE condicional (`status='available'` no WHERE, `rowCount() === 1`): resolve a corrida entre tablets sem lock explícito. `bed.current_hospitalization_id` fica sem FK (evita ciclo `bed` ↔ `hospitalization` na criação).
- 2026-10-05 · plano · onda 0 — Prescrição com `ends_at` obrigatório (≤ 30 dias) e agenda inteira gerada na criação; "atrasado" é derivado (`classify`, tolerância 30 min), sem job. Medicação contínua é re-prescrita.
- 2026-10-05 · plano · onda 0 — Diária copiada do leito na admissão; transferência não recalcula. Diárias = `max(1, ceil(horas/24))`. Item com valor 0 não é lançado (o estoque é baixado mesmo assim).
- 2026-10-05 · plano · onda 0 — `$clock` opcional (`?\Closure`, último parâmetro com default) nos services de internação para testar atraso e diárias. Os services existentes usam `new DateTimeImmutable()` direto; o parâmetro novo não muda as assinaturas antigas.
- 2026-10-05 · plano · onda 0 — Migration para MySQL 8 e 5.7:
  - ALTER de CHECK em duas instruções (`DROP CHECK` e depois `ADD CONSTRAINT`), com o preparador 5.7 ganhando `DROP CHECK` → `DROP TRIGGER IF EXISTS` (T-02);
  - CHECKs sem `BETWEEN`, `LIKE` ou funções;
  - todo `timestamp(6) NOT NULL` com `DEFAULT CURRENT_TIMESTAMP(6)`, para o 5.7 sem `explicit_defaults_for_timestamp` não dar `ON UPDATE` implícito.
- 2026-10-05 · plano · onda 0 — `cv-components.css` não está em `framework_hashes.php` (só `custom.css`, `layout.html` e o resto de `adminbs5/*` estão). T-16 escreve só uma seção `cv-board-*` no fim dele.
- 2026-10-05 · plano · onda 0 — Programas com nomes ASCII em inglês (`Central Vet - Hospitalization Board` etc.), porque nome com acento ficou com encoding duplo na rodada 2. Concedidos ao grupo 1, como nas fases anteriores. A DML usa ids fixos com guarda de `MAX(id)`, sem `ROW_NUMBER()` (não existe no 5.7).
- 2026-10-05 · plano · onda 0 — Resposta do usuário (1): a admissão é só via atendimento, com `encounter_id` obrigatório. O desenho do plano fica como está.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (2): os 8 programas vão para o grupo Admin (1) e para os grupos clínicos. SELECT só leitura mostrou que não há grupo clínico (`system_group` só tem 1 `Template - Admin`, 2 `Template - Users`, 3 `Application - Programs`; o único usuário é `admin`, nos três), e a pergunta voltou ao usuário.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (3): criar o grupo novo `Clínico – Internação` em `system_group` e conceder a ele os 8 programas, além do grupo 1. Grupos 2 e 3 ficam sem os programas. Isso entra na T-05:
  - `sql/T-05-programs.sql` com o INSERT do grupo e as 16 concessões, todos os ids derivados de `COALESCE(MAX(id),0)+1` no próprio comando (`system_group.id` é `int NOT NULL` sem AUTO_INCREMENT, `MAX(id)=3` em 2026-10-05) e `WHERE NOT EXISTS`;
  - `sql/T-05-programs.verify.sql` (só SELECT);
  - `sql/T-05-programs.rollback.sql` (DELETE com WHERE por nome);
  - o seed de instalação nova com o mesmo grupo.

  A sessão padrão do cliente `mysql` usa `character_set_client=latin1` (causa do encoding duplo da rodada 2). Por isso os três arquivos começam com `SET NAMES utf8mb4;`, o orquestrador aplica com `--default-character-set=utf8mb4`, e o verify confere `CHAR_LENGTH(name) = 20` e `LENGTH(name) = 25`. A DML é aplicada só pelo orquestrador no bloqueio entre as Ondas 1 e 2, com aprovação SQL. A prova de acesso é por banco, porque não há usuário no grupo novo; o gate navega como `admin`.
- 2026-10-05 · plano · onda 0 — Branch de trabalho `feat/fase-6a-internacao`, nome dado pelo orquestrador (o padrão da skill seria `task/fase-6a-internacao`).
- 2026-10-05 · T-03 · onda 1 — `Bed` não muda ocupação (occupy/release só no repositório); extras aditivos `BedUnavailableException::occupied(id)` e getters de autoria/datas; `HospitalizationEvent::notesText()` devolve null para texto vazio.
- 2026-10-05 · T-04 · onda 1 — Acréscimos além da Interface: `reconstitute()`/`assignId()`, `HospitalizationOrder::TYPES`, `AdministrationSchedule::assertValid()` público; `suspend()` fora de `active` lança `InvalidStatusTransitionException`; quantidade sem produto é recusada; período de exatamente 30 dias é aceito.
- 2026-10-05 · T-02 · onda 1 — Conversão da consulta de verificação extraída para `verification_query()` público em `scripts/prepare-mysql57.py`.
- 2026-10-05 · T-05 · onda 1 — `SET NAMES utf8mb4;` também no seed; `FROM DUAL` nos INSERTs sem tabela (5.7); rollback com SELECT prévio de concessões fora dos grupos 1 e novo.
- 2026-10-05 · T-06 · onda 2 — Fakes com `public int $saveCount`; `occupy`/`release` do FakeBedRepository trocam o Bed guardado por `Bed::reconstitute` (Bed sem mutador de ocupação); administrações por scheduled_at,id e eventos por recorded_at DESC,id DESC.
- 2026-10-05 · T-07 · onda 2 — `BedRepository::save` via CASE nunca move leito para/de `occupied` (protege `bed_occupancy_ck`); repositório de eventos só insere (`remove()` lança); `listBoardRows` exclui administrações `cancelled`.
- 2026-10-05 · T-07/T-10 · onda 3 — Lost update no UPDATE de administração (done×skipped/cancel concorrentes) corrigido já na T-07: UPDATE com `AND status='pending'` + rowCount → "Administration <id> is not pending"; Fake espelhado (ea5e5ee RED + 08b0ea1). Sem cobrança/estoque em dobro (UNIQUE de source_id).
- 2026-10-05 · T-07 · onda 3 — Caminhos autorizados por ruling para a correção: src/tests/Support/FakeHospitalizationAdministrationRepository.php e src/tests/Unit/HospitalizationFakesTest.php (RED inválido/escopo apontado pelo validador; sem reescrever histórico).
- 2026-10-05 · T-09 · onda 3 — Corrida do occupy() após a internação salva: o rollback depende do TTransaction do controller; cobrar na revisão da T-13 (e T-14 se aplicável). Admissão simultânea do mesmo paciente sem guarda no banco fica como pendência do MVP.
- 2026-10-05 · T-12/T-13/T-14/T-15 · onda 6 — Lote de correção pós-gate E2E (UX): T-12 é dona do CSS `.cv-touch-target` (44 px) e as demais só aplicam a classe; T-14 única escritora de translations.json. T-12 8ea5a53+d937f32 (ações da BedList saem do dropdown para botões visíveis); T-13 14a0398+152b920; T-14 12c9e28+9efbe58 (transferência sem destino com mensagem; resumo da alta só por POST, sem texto clínico na URL); T-15 909e5bd+1896eaf (rótulo pt no erro, dados mantidos, obrigatórios marcados). Re-gate: todos ≥ 44 px no tablet, SUITE 624/624; re-revisões aprovadas.
- 2026-10-05 · T-20 · onda 6 — Revisão rodada 1 reprovou (conta 371 do atendimento 4304 sem limpeza; atraso do flowboard sem evidência E2E) → f3c5109 + evidência (administração 20, "Atrasado 1" em desktop/tablet); re-validada; rodada 2 aprovada.
- 2026-10-05 · T-20 · onda 6 — Aceitos como cobertos sem E2E: Review Focus 1 (dois tablets no mesmo leito; ocupação condicional + teste de integração) e Review Focus 3 (conta fechada antes da alta; testDischargeRefusedWhenAccountClosedTouchesNoStock); re-gate sem desktop (correção só aplica min-height) e sem listagem de status de rede (gate anterior todas 200).
- 2026-10-05 · T-20 · onda 6 — queue_entry 322: o re-gate relatou avanço por engano, mas o banco mostra 'aguardando' e updated_at = created_at, sem audit; nada a restaurar.

## Bloqueios
- RESOLVIDO em 2026-10-05 (orquestrador, com aprovação SQL explícita do usuário): bloqueio entre a Onda 1 e a Onda 2.
  - Backup `var/backups/centralvet-20261005T190855Z.sql.gz` (gzip -t ok).
  - 0010 aplicada com `MIGRATION_DB_USER` em `centralvet` e `centralvet_test` a partir de cópia temporária com SHA-256 `2a8f4ae1d3275f02520edb64233a2460e11fbbb02719ab69a51a9b20c8ce8666` (o arquivo commitado mantém o placeholder). Verify nos dois: 5 tabelas; CHECKs por tabela bed 2, hospitalization 2, hospitalization_administration 2, hospitalization_event 2, hospitalization_order 6 (14 novos); `source_type`/`reason` ampliados (2 alterados).
  - T-05 DML em `centralvet` com utf8mb4: grupo id 4 `Clínico – Internação` (20 caracteres / 25 bytes), programas 110–117, concessões grupo 1 = 8, grupo 4 = 8, grupos 2 e 3 = 0. Totais: `system_program` 109→117, `system_group` 3→4, `system_group_program` 111→127.
  - Contagens antes para as próximas ondas: `encounter_account_item` = 6, `stock_movement` = 1.
- Bloqueio entre a Onda 1 e a Onda 2 (orquestrador, com aprovação SQL explícita do usuário pela skill `sql-write-approval`):
  1. `./scripts/backup.sh` e `gzip -t`.
  2. SHA-256 da 0010 numa cópia temporária.
  3. Aplicar em `centralvet` e em `centralvet_test` com `MIGRATION_DB_USER` e rodar o `.verify.sql` nos dois.
  4. Registrar `COUNT(*)`/`MAX(id)` de `system_group`, `system_program` e `system_group_program` e aplicar `sql/T-05-programs.sql` em `centralvet` com `--default-character-set=utf8mb4`. Depois rodar `sql/T-05-programs.verify.sql`. O rollback preparado é `sql/T-05-programs.rollback.sql`, com nova aprovação.
  5. Registrar contagens antes/depois de `encounter_account_item`, `stock_movement` e `system_program`.

  Desbloqueia quando o `.verify.sql` lista as 5 tabelas e os 16 CHECKs (14 novos, 2 alterados) nos dois bancos e o verify da T-05 mostra 8 concessões no grupo 1, 8 em `Clínico – Internação`, 0 nos grupos 2 e 3 e o nome com 20 caracteres / 25 bytes.
- Execução de `sql/T-20-cleanup.sql`: só depois do gate final, pelo orquestrador, com aprovação SQL.

- 2026-10-05 · T-15 · onda 4 — Fix loop rodada 1 (6beb807 RED + d29e899): ACTION_VIEW do AdministrationForm passou a 'HospitalizationAdministrationForm::onLoad'; re-validado no navegador (toque duplo em Feito → done=1); revisão rodada 1 aprovada.
- 2026-10-05 · T-16 · onda 4 — Revisão rodada 1 reprovou (AuthorizationDenied/MissingTenantContext não capturados no HospitalizationBoard); fix f6cef53 (RED) + 3bc672a; SUITE 607/607; re-revisão rodada 2 aprovada. Caminho autorizado: src/tests/Integration/HospitalizationBoardIntegrationTest.php.
- 2026-10-05 · T-17 · onda 4 — Não era bug: botão Internar é TButton com onclick (#tbutton_inline_hospitalization), confirmado no atendimento 2189; xmllint ausente, python minidom aceito.
- 2026-10-05 · T-13 · onda 4 — Rollback da admissão com leito ocupado no meio conferido na revisão (operação inteira em TTransaction); corrida real não reproduzível pela UI.
- 2026-10-05 · ambiente · onda 4 — Login admin no Playwright feito pelo orquestrador com autorização do usuário para toda a Fase 6A.
- 2026-10-05 · gate · onda 5 — Gate em pt feito por fetch autenticado das 9 telas em vez de navegação; console e rede não checados nesta onda, ficam para a T-20.
- 2026-10-05 · T-18 · onda 5 — Mensagens com id interno viram chaves sem id ("Este leito não está disponível"); uma tradução por chave `en`, chaves antigas mantêm a tradução anterior (Vital signs → Sinais vitais, Active → Ativo, Late → Atrasado).

## Descobertas
- [T-03] `Bed` não muda ocupação: occupy/release só no repositório (fakes de T-06 montam o leito ocupado via `Bed::reconstitute` com status/current_hospitalization_id). Extras aditivos: `BedUnavailableException::occupied(id)`, getters `admittedBySystemUserId()`/`dischargedBySystemUserId()`/`createdAt()`/`updatedAt()`; `HospitalizationEvent::notesText()` devolve null para texto vazio; `discharge()` guarda summary vazio como null.
- [T-04] Além do contrato: `HospitalizationOrder::reconstitute(...)`, `assignId()`, `prescribedBySystemUserId()`, `suspendedAt()`, `TYPES`; `suspend()` fora de `active` → `InvalidStatusTransitionException` `Order <id> is not active`; quantidade sem produto → `quantity_per_administration requires a product`; mensagens extras: `description_text is required`, `Unknown route "<r>"`, `Unknown order_type "<t>"`. `HospitalizationAdministration::reconstitute(...)` e `assignId()`; `markDone` aceita nota vazia (vira null). `AdministrationSchedule::assertValid(startsAt, endsAt, frequencyHours)` público (usado pelo prescribe).
- [T-02] `scripts/prepare-mysql57.py`: `ALTER TABLE t DROP CHECK x` vira `DROP TRIGGER IF EXISTS x_bi/x_bu`; verify 5.7 usa `verification_query()` e exige literal `table_name = '<t>'` ou `table_name IN (...)` em toda consulta a check_constraints (senão ValueError).
- [T-01] 0010 preparada (743537a): 5 tabelas, 16 CHECKs (14 novos + 2 ampliados), 2 UNIQUEs, 23 FKs; `provision.sh` lista a 0010 depois da 0009. Aplicada no bloqueio da onda 1.
- [T-06] Fakes prontos (c2306e7): construtor (int $tenantId, Entidade ...$seed), `saveCount` zerado após o seed; `FakeBedRepository::occupy/release` trocam o Bed por reconstitute (use findById depois); `seedBoardRows(array)` no fake de administração.
- [T-07] Repositórios PDO prontos (bad05c6): `BedRepository::save` preserva ocupação via CASE; `inactive` com leito ocupado lança `BedUnavailableException::occupied`; `remove()` de leito só se livre; eventos append-only; save de internação grava só bed_id/status/alta; save de prescrição só status/suspended_at; `listBoardRows` exclui `cancelled`, datas `Y-m-d H:i:s`.
- [T-08] BedService: create autoriza antes da checagem de código duplicado; leito inexistente/de outro tenant → CrossTenantReferenceException antes de `decide`; listAvailableForUnit filtra isAvailable() sobre listByUnit.
- [T-10] HospitalizationOrderService: OUTCOME_DONE/OUTCOME_SKIPPED, BOARD_LOOKBACK_HOURS=12; outcome inválido → `Unknown administration outcome "<x>"`; windowHours<1 → `window_hours must be positive`; ids inexistentes/produto inativo → CrossTenantReferenceException; suspend não exige admitted; board autoriza com requireUnitId.
- [T-09] HospitalizationService: authorize 'hospitalization' com requiresUnitScope; leito ocupado/inativo/outra unidade recusado antes de salvar; transfer para o mesmo leito → `to_bed_id must differ from from_bed_id`; `expected_discharge_date must be a Y-m-d date`; evento admission grava notes=motivo e to_bed_id.
- [T-11] HospitalizationDischargeService: valida status/conta/produtos antes de escrever; item de administração usa performedAt (fallback scheduledAt) `d/m/Y H:i`; mensagens novas para T-18: `source_type "<t>" is not accepted for sourced items`, `Bed <id> is not occupied by hospitalization <id>`, `hospitalization_id|bed_id|product_id <id> was not found for the authenticated tenant`.
- [T-07] Correção 1 (08b0ea1): UPDATE de administração só sobre `pending`; suspend/alta concorrentes com "Feito" propagam a exceção e o TTransaction do controller desfaz (afeta T-10/T-11/T-15).

- [T-12] BedList/BedForm prontos: ações BedList::onReload|onActivate|onDeactivate, BedForm::onSave|onEdit; aba 'beds' do CvNav.
- [T-13] HospitalizationAdmissionForm: admit em TTransaction único (corrida do occupy desfaz a internação); recusas via CvFormat::userError.
- [T-14] HospitalizationView: rota id[&tab], onTransfer, onAskDischarge→onDischarge, onAskSuspendOrder→onSuspendOrder; alta em TTransaction único.
- [T-15] Forms de prescrição/administração/evento prontos; leitura do AdministrationForm autoriza 'HospitalizationAdministrationForm::onLoad' (board.md antigo dizia sem método); toda action de service é Classe::método.
- [T-16] Flowboard pronto: janela 2 h, ação 'HospitalizationBoard::onReload'; captura AuthorizationDenied/MissingTenantContext (3bc672a).
- [T-17] Navegação pronta: menu Internação/Leitos, CvNav group 'hospitalization', PLAN_ACTIONS['hospitalization'] 'Hospitalize'.
- [onda 4] "Message not found" em textos novos até a T-18 (chaves em board.md). Ids de teste para a T-20: beds 1–3 (F6 teste L1..L3); hospitalization 1 (encounter 1708) e 2 (encounter 2189, paciente 1452); orders 1–3; administrations 1–9; eventos vitals/evolução; lote 'F6 teste lote' (produto 8545); 2 itens hospitalization* na conta 46; 1 stock_movement hospitalization_consumption.
- [T-20] Gate final E2E (leito→admissão→prescrição→Feito/Não feito→parâmetros/evolução→transferência→alta; rollback por estoque insuficiente; permissão negada em outra unidade; toque duplo → 1 done; console 0 errors; rede 200) OK; reprovou por UX em T-12..T-15, corrigido e re-gateado. Prints em .playwright-mcp/ (ignorado pelo git).

## Pendências
- Central de Pendências (PRD §8.23) sem tela própria: o item de internação fica para quando a central existir.
- `docs/runbooks/migrations.md` cita `$MIGRATION_USER`, mas o `.env` define `MIGRATION_DB_USER`: divergência anterior a esta fase, fora do escopo.
- T-04: lista de mensagens para a T-18 omite `description_text must have at most 255 characters`, `dose_text must have at most 120 characters`, `notes_text must have at most 500 characters`, `Unknown route "<x>"`, `Unknown order_type "<x>"`; falta asserção de texto para `Order <id> is not active` e `quantity_per_administration requires a product`.
- T-03: `Hospitalization::discharge()` aceita `$at` anterior a `admittedAt`; `HospitalizationEvent::record()` não limita sinais vitais aos tipos do schema (valor acima chega ao PDO como erro SQL cru).
- T-02: `DROP CHECK` com tabela entre crases não é convertido; sem teste de `DROP CHECK` + `ADD CONSTRAINT` na mesma instrução nem de `prepare()` chamando `verification_query`; RED de `test_verify_uses_table_from_query` falhou por AttributeError (função nova), não pelo `landing_lead` previsto.
- T-05: rollback não cobre dependentes por FK (`system_user_program`, `system_program_method_role`, `system_users.frontpage_id`, `tenant_group`); collation `utf8mb4_0900_ai_ci` é insensível a acento (considerar `COLLATE utf8mb4_bin`); grupo 4 sem linha em `tenant_group` (nenhum PHP lê hoje).
- T-01: tenant/unidade só na aplicação (sem FK composta); admissões simultâneas do mesmo paciente sem guarda no banco (cobrir no service/teste da T-09); `hospitalization_discharge_ck` não exige `discharged_by_system_user_id`; falha entre `DROP CHECK` e `ADD CONSTRAINT` se recupera reaplicando só o ADD.
- T-06: FakeBedRepository::save grava a entidade inteira, diferente do PDO (corrida leito ocupado/desativado passa no Fake e falha no PDO); ordenação de `listByHospitalization` (ordens) e `listActiveByUnit` no Fake é por inserção (PDO: starts_at,id / admitted_at,id); `$boardRows` declarada no meio da classe do fake de administração.
- T-07: UPDATE de administração sem `AND status = 'pending'` (dois "Feito" simultâneos; avaliar UPDATE condicional em T-10); UPDATE de internação sem guarda `status = 'admitted'` (alta dupla concorrente; T-11 depende do TTransaction); CASE do save de leito reativa em silêncio leito inativado entre leitura e save.
- T-08: create concorrente com o mesmo código estoura a UNIQUE `bed_unit_code_uq` como PDOException crua; falta teste da guarda `system_unit_id must be positive` em listAvailableForUnit.
- T-09: admissões simultâneas do mesmo paciente sem guarda no banco (pendência do MVP; FOR UPDATE ou coluna gerada com UNIQUE); corrida do occupy() depende do TTransaction do controller (cobrar na revisão da T-13/T-14); transfer() ignora o retorno de release(); teste de corrida não afirma saveCount === 1; helper eventsOfType varre só ids 1..8.
- T-10: `suspend` não exige internação admitted (avaliar na T-15).
- T-11: alta dupla concorrente falha com PDOException de chave duplicada em vez de `is not admitted` (UPDATE condicional ou mapeamento no controller da T-14); fechamento concorrente da conta pode ser reaberto em silêncio (FOR UPDATE/UPDATE condicional); sem teste de `release` falso nem de `addSourcedItem` com conta não aberta.
- T-07: restam sem guarda o UPDATE de internação (`status='admitted'`) e o CASE do save de leito; o UPDATE de administração foi corrigido na onda 3.

- T-12: BedList sem teste automatizado (CvBadge, display conditions, estado vazio); botão Voltar só com ícone, sem aria-label (corrigir no kit).
- T-13: catch (Exception) não cobre \Error (transação sem rollback explícito, mensagem crua); loadOptions() roda de novo em onSave.
- T-14: alta dupla concorrente cai em PDOException (UNIQUE) sem mensagem amigável; onDischarge faz get(ACTION_READ) extra dentro da transação; resumo clínico (até 2000 chars) vai como GET no TQuestion.
- T-15: resolveTenantContext() e montagem do service copiados em 3 forms; board.md antigo com ação sem método (ver Descobertas).
- T-16: N+1 em admittedCards; KPI "Done in shift" conta puladas.
- T-17: teste "Beds inside Settings" compara só posição de string; subprocesso descarta stderr.
- T-18: padrões genéricos `^1 must be a number|whole number|positive integer` e `^1 is required` mostram o nome técnico do campo em pt; padrão `was not found ... authenticated tenant` vale para todos os serviços (muda mensagem de outras fases para "Registro não encontrado") e sem teste para eles; "Provide a hospitalization_id ..." mantém o nome técnico; grupo `\(new\)` do padrão `Hospitalization ... is not admitted` é morto e aceita "Hospitalization is not admitted".
- T-19: runbook aponta SQL em `.claude/tasks/.../sql/T-05-programs*.sql`, pasta de plano que pode ser arquivada; copiar a DML para local estável ou avisar.
- T-12: `.cv-touch-target` fixa `44px`; usar `var(--cv-touch-target)` (custom.css:34) manteria um valor só; correção escreveu em cv-components.css fora dos arquivos prováveis da T-12.
- T-20: comentários de estado do sql/T-20-cleanup.sql desatualizados em relação ao banco pós-gate; os ~312 registros de audit_log dos gates ficam fora da limpeza (documentar).
- T-20: executar `sql/T-20-cleanup.sql` pelo orquestrador após a revisão final, com backup e aprovação SQL do usuário.

## Riscos
- `EncounterAccountService.php` (Fase 5) ganha um método (T-11): regressão em `syncAutomaticItems`/`addManualItem`. Mitigação: `EncounterAccountServiceTest` na validação de T-11 e construtor inalterado.
- Alta não atômica fora do controller: os services não abrem transação. Mitigação: `onDischarge` com `TTransaction` único, e o Review Focus de estoque insuficiente é conferido no gate (T-14).
- Banco de teste sem 0010 faz `HospitalizationRepositoryIntegrationTest` dar `SKIP` ou `FAIL`. Mitigação: o bloqueio aplica nos dois bancos e T-07 exige `PASS` (não `SKIP`).
- Hospedagem 5.7 com triggers `encounter_account_item_source_type_ck_bi/_bu` e `stock_movement_reason_ck_bi/_bu` antigos. Mitigação: T-02 gera `DROP TRIGGER IF EXISTS` antes do `CREATE TRIGGER`, e o runbook documenta.
- `translations.json` (≈ 800 entradas) tem um escritor só (T-18) na Onda 5; até lá "Message not found" é tolerado no gate da Onda 4.
- SUITEs simultâneas na Onda 3/4 (até 6 agentes) dão falso FAIL em testes Redis ou deadlock. Mitigação: cada agente filtra a própria classe, e o validador roda a SUITE inteira sozinho no gate.

## Retomada
- Pasta: `.claude/tasks/mar-20261005-1521-fase-6a-internacao/`
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c
- Branch de trabalho: feat/fase-6a-internacao (base: task/landing-a11y-i18n)
- BASE da onda 1: 8f9ebfc
- Commits por onda:
  - Onda 1: BASE 8f9ebfc → HEAD 743537a (743537a, def287d, 78db7e1, 7a2e9c9, 6d5be25, 2f89c78, 1299883, e343686)
  - Onda 2: BASE 4bc287e → HEAD bad05c6 (bad05c6, c2306e7, 623b4f3, 0f7a949)
  - Onda 3: BASE f3e64aa → HEAD 08b0ea1 (08b0ea1, ea5e5ee, 2df0ddc, 2d7f4f8, e58c2f9, 1ee515d, 9ff9aaf, 43a556a, 059ff3f, 2eed5f8, db2f0de)
  - Onda 4: BASE 7d96545 → HEAD 3bc672a (3bc672a, f6cef53, d29e899, 6beb807, c6b2900, 435a93d, f93a63d, ca02c03, cad4416, d805a14, f13a863, d380bb7, f05eef9, 69e0ab0, 8f07c60, 9420e8c)
  - Onda 5: BASE 3f32dab → HEAD 7d16fd8 (7d16fd8, 7c66aa1, 2993ddd, 9e9bf37, ce1912d)
  - Onda 6: BASE a72d78e → HEAD f3c5109 (f3c5109, 9efbe58, 1896eaf, 12c9e28, 909e5bd, d937f32, 8ea5a53, 152b920, 14a0398, 0b5a6c2)
- Último status conhecido: onda 6 concluída (T-20 [x]; gate final E2E aprovado após correção de UX em T-12..T-15, SUITE 624/624)
- Próxima onda recomendada: nenhuma (revisão final; depois executar sql/T-20-cleanup.sql com aprovação)
