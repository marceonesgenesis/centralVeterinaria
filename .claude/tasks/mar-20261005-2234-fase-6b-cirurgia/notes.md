# Notas de execução

## Decisões tomadas
- 2026-10-05 · plano · onda 0 — Cirurgia só a partir de atendimento (`surgery.encounter_id NOT NULL`), como a admissão da 6A: a conta da Fase 5 é por atendimento. Entrada por `EncounterView::PLAN_ACTIONS['surgery']` (T-18).
- 2026-10-05 · plano · onda 0 — Sala como cadastro novo `surgery_room` por unidade (não existe tabela de sala no banco; `appointment` não tem sala). Conflito de sala por trava `SELECT ... FOR UPDATE` na linha da sala + consulta de sobreposição (status `scheduled`/`pre_op`/`in_progress`). Conflito de agenda do cirurgião fica fora do MVP.
- 2026-10-05 · plano · onda 0 — `procedure_catalog_item` não tem tipo/categoria: qualquer procedimento ativo pode ser agendado como cirurgia; nome e `price_cents` copiados no agendamento (a cobrança usa a cópia). Nada de ALTER na tabela da Fase 4.
- 2026-10-05 · plano · onda 0 — Consentimento como colunas de `surgery` (signatário, texto integral aceito, data, quem registrou) + evento `consent`; regravar só antes do início. Sem assinatura digital/PDF (Fase 7). Texto padrão por chave de tradução `Surgery consent default text` (T-15/T-19).
- 2026-10-05 · plano · onda 0 — Checklist fixo em `SurgeryChecklist` (5 + 4 + 4 itens). `sign_in`/`time_out` em `pre_op`, `sign_out` em `in_progress`; `start` exige consentimento + `sign_in` + `time_out`; `complete` exige `sign_out`. Uma linha por item com UNIQUE `(surgery_id, phase, item_code)`: o toque duplo vira `Checklist phase "<fase>" is already confirmed for surgery <id>`.
- 2026-10-05 · plano · onda 0 — Materiais só em `in_progress`, removíveis até a conclusão; baixa de estoque (`surgery_consumption`, FEFO) e cobrança (`surgery_procedure` com `source_id` = cirurgia; `surgery_material` com `source_id` = material) **na conclusão**, como a alta da 6A. Estoque insuficiente recusa a conclusão inteira (TTransaction único em `SurgeryView::onComplete`). Item com valor 0 não é lançado; o estoque é baixado mesmo assim.
- 2026-10-05 · plano · onda 0 — Concorrência com as lições da 6A: `SurgeryRepository::save` de linha existente só grava se o status no banco ainda é `loadedStatus()` (com reconferência `FOR UPDATE` quando `rowCount` é 0); material, conclusão e retorno chamam `lockStatus` antes de ler e decidir (resolve o equivalente cirúrgico do achado "prescrição concorrente com a alta" da revisão final da 6A).
- 2026-10-05 · plano · onda 0 — Internação pós-operatória pela tela existente `HospitalizationAdmissionForm&encounter_id=&patient_id=` (6A inalterada), depois da conclusão, e não dentro da transação da conclusão; o motivo da internação não vai pela URL. Retorno via `AppointmentService::schedule` com o cirurgião, `followup_appointment_id` gravado sob trava.
- 2026-10-05 · plano · onda 0 — Cancelamento só em `scheduled`/`pre_op` (motivo obrigatório, só POST). Remarcação = cancelar e agendar de novo. Equipe trocável em `scheduled`/`pre_op` (`SurgeryScheduleForm&id=`).
- 2026-10-05 · plano · onda 0 — Programas (9, nomes ASCII em inglês); DML com ids de `COALESCE(MAX(id),0)+1`, `SET NAMES utf8mb4`, sem `ROW_NUMBER` (grupo definido pela resposta (1) abaixo).
- 2026-10-05 · plano · onda 0 — Migration `20261005_0011_phase6b_surgery` (6 tabelas, 11 CHECKs novos + 2 ampliados), compatível com o preparador 5.7 (prefixo `15-`), timestamps `NOT NULL` com `DEFAULT CURRENT_TIMESTAMP(6)`, CHECKs sem `BETWEEN`/`LIKE`/funções. `surgery_room` não tem coluna apontando para cirurgia (sem ciclo de FK).
- 2026-10-05 · plano · onda 0 — Gates econômicos: Ondas 1–3 só LINT + SUITE; Onda 4 smoke desktop por tela; Onda 5 fetch autenticado em pt; Onda 6 E2E em dois disparos de validador (roteiro A fluxo completo desktop+tablet; roteiro B Review Focus + permissão negada). Login admin no Playwright pelo orquestrador.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (1): criar o grupo novo `Clínico – Cirurgia` (18 caracteres / 21 bytes) e conceder os 9 programas a ele e ao grupo 1 (Admin); nada para o grupo 4 `Clínico – Internação` nem para os grupos 2 e 3. T-04 ajustada no padrão da T-05 da 6A: INSERT do grupo com id de `MAX(id)+1` e `NOT EXISTS`, `SET NAMES utf8mb4`, verify com `CHAR_LENGTH`/`LENGTH` do nome e rollback com WHERE por nome.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (2): sala como cadastro próprio `surgery_room` com recusa de horário sobreposto — confirmado, plano mantido.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (3): baixa de estoque e cobrança dos materiais só na conclusão — confirmado, plano mantido.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (4): internação pós-operatória abre a admissão da 6A depois de concluir, em transação separada — confirmado, plano mantido.
- 2026-10-05 · ambiente · onda 0 — Login admin no navegador com as credenciais do `.env` autorizado pelo usuário para toda a 6B; feito pelo orquestrador, senha nunca registrada.
- 2026-10-05 · plano · onda 0 — Branch de trabalho `feat/fase-6b-cirurgia` (nome dado pelo orquestrador; o padrão da skill seria `task/fase-6b-cirurgia`), base `feat/fase-6a-internacao` @ `09ce2d5`.
- 2026-10-06 · T-01..T-04 · onda 1 — Sem rulings novos além do ruling da T-03: público extra `SurgeryChecklist::assertItemOfPhase()` (reuso por entidade e service da T-09), contrato intacto.
- 2026-10-06 · T-05/T-06 · onda 2 — Semântica oficial do save condicional de cirurgia = a do PDO: status esperado = último status gravado pela própria instância (WeakMap) → `loadedStatus()` → `scheduled`; mesma instância salva várias vezes, cópia antiga de outra instância lança "changed status concurrently". Fake e docblock de `SurgeryRepositoryInterface` corrigidos (701e4cb RED, f1dc5d8); substitui a nota do board que mandava recarregar a entidade entre saves.
- 2026-10-06 · T-08 · onda 3 — Correção só de testes (21dbc10): resourceUnitId do atendimento/cirurgia e caminho negado, mutações provadas em worktree removida; ausência de RED aceita (código já correto).
- 2026-10-06 · T-10 · onda 3 — Remoção dupla concorrente gerava evento fantasma: corrigido já (4678b75 RED + 6929b48). Novo `delete(): int` com rowCount no contrato/repositório/Fake da T-06 (`remove()` void delega; desvio aceito); service lança "Material <id> was already removed" sem evento. Caminhos autorizados por ruling.
- 2026-10-06 · T-09 · onda 3 — `confirmPhase` sem `lockStatus`; toque duplo fica com o UNIQUE (aceito pela revisão).
- 2026-10-05 · T-12..T-18 · onda 4 — Fluxos do Review Focus no navegador (sala sobreposta, iniciar sem consentimento/checklist, toque duplo no checklist, material/conclusão com e sem estoque, outra unidade) adiados para a T-21 (roteiro B do gate final) por limite de turnos; nenhum registro de teste criado na onda 4.
- 2026-10-05 · T-15 · onda 4 — Revisão rodada 1 reprovou (XSS armazenado: nome do procedimento sem escape no título do SurgeryEventForm) → 2d05dd9 (RED) + 78aa2e0 (CvFormat::e; varredura do SurgeryConsentForm sem outros casos); re-validada (SUITE 746/746); re-revisão rodada 2 aprovada.
- 2026-10-05 · T-12..T-18 · onda 4 — Login admin no Playwright refeito pelo orquestrador (autorização do usuário para a 6B).
- 2026-10-06 · T-19 · onda 5 — Gate de tela em pt não rodou (sessão admin expirada); transferido para a T-21. Aceitas: mensagens sem id interno; "Circulating nurse" → "Circulante" (plano dizia "Volante"; ajustável depois); obrigatórios "Campo obrigatório: <rótulo>".
- 2026-10-06 · T-20 · onda 5 — A regex do critério devolve 8 (não casa SurgeryList/SurgeryView); os 9 controllers conferidos um a um.- 2026-10-06 · T-06, T-08 · onda 7 (correção da revisão final) — Bloqueante: agendamentos simultâneos na mesma sala gravavam cirurgias sobrepostas (checagem sem leitura travante sob REPEATABLE READ). 219f1e2 (RED) + aeeb85d (hasOverlapInRoom com FOR UPDATE) + f1c0688 (teste-guarda da ordem trava→checagem→insert; T-08 sem RED aceito, ordem já correta; SurgeryService inalterado para preservar lockCalls=0 com permissão negada). Re-revisão aprovada.

## Bloqueios
- Bloqueio entre a Onda 1 e a Onda 2 (orquestrador, com aprovação SQL explícita do usuário pela skill `sql-write-approval`):
  1. `./scripts/backup.sh` e `gzip -t`.
  2. SHA-256 da 0011 numa cópia temporária (o arquivo commitado mantém o placeholder).
  3. Aplicar em `centralvet` e em `centralvet_test` com `MIGRATION_DB_USER` e rodar o `.verify.sql` nos dois.
  4. Registrar `COUNT(*)`/`MAX(id)` de `system_group`, `system_program` e `system_group_program` (esperado 4/117/127) e aplicar `sql/T-04-programs.sql` (grupo novo + 9 programas + 18 concessões) em `centralvet` com `--default-character-set=utf8mb4`; depois `sql/T-04-programs.verify.sql`. Rollback preparado: `sql/T-04-programs.rollback.sql`, com nova aprovação.
  5. Registrar contagens antes/depois de `encounter_account_item`, `stock_movement`, `appointment`, `hospitalization` e `system_program`.

  RESOLVIDO em 2026-10-06 (aprovação SQL explícita do usuário): backup var/backups/centralvet-20261006T021120Z.sql.gz (gzip -t ok); 0011 aplicada com MIGRATION_DB_USER em centralvet e centralvet_test a partir de cópia com SHA-256 12f7cfbc780221f843fd74a758cb734662f1f15837258f3f6231597b3bcded76 (commitado mantém placeholder); verify nos dois: 6 tabelas vazias, 11 CHECKs novos + 2 ampliados; T-04 DML: grupo id 5 'Clínico – Cirurgia' (18 chars/21 bytes), 9 programas, concessões grupo 1 = 9, grupo 5 = 9, grupos 2/3/4 = 0; system_group 4→5, system_program 117→126, system_group_program 127→145.
  Critério original: desbloqueia quando o `.verify.sql` lista as 6 tabelas e os 13 CHECKs (11 novos, 2 ampliados) nos dois bancos e o verify da T-04 mostra o grupo `Clínico – Cirurgia` (18 caracteres / 21 bytes), 9 concessões no grupo 1, 9 em `Clínico – Cirurgia` e 0 em `Clínico – Internação` e nos grupos 2 e 3.
- 2026-10-06 · T-21 · onda 6 — Fluxo completo do roteiro A só em desktop; em tablet rodaram telas, cancelamento e lista.
- 2026-10-06 · T-21 · onda 6 — Review Focus 2 (material com outra aba concluindo) coberto por SurgeryMaterialServiceTest::testMaterialAfterConcurrentCompletionIsRefused, não por navegador.
- 2026-10-06 · T-21 · onda 6 — Login admin do gate refeito pelo orquestrador, com autorização do usuário para a 6B.

## Descobertas
- [T-03] `SurgeryChecklist::assertItemOfPhase` público (extra ao contrato); entidades do checklist com `reconstitute(array)` e `tenantId()`; `SurgeryEvent::record` com notes vazio em tipo não clínico grava null.
- [T-02] `Surgery` com getters extras (scheduledBy/consentRecordedBy/completedBy/cancelledBy, createdAt/updatedAt); `SurgeryRoom` e `SurgeryTeamMember` com `reconstitute(array)`; transições inválidas lançam `InvalidStatusTransitionException`.
- [T-05] `FakeSurgeryRepository::findById` devolve cópia nova (reconstitute), como o PDO; checklist duplicado lança `InvalidStatusTransitionException`; eventos `remove()` lança `LogicException`; Team/Checklist/Event sem seed no construtor.
- [T-06] `SurgeryRepository::save` UPDATE grava só status, consentimento, started/completed/cancelled e `followup_appointment_id` (sala, horário, procedimento e notas imutáveis; remarcar = cancelar e agendar); `SurgeryTeamRepository::save` só insere (use `replaceForSurgery`); checklist/evento com id → `LogicException`; material sem UPDATE.
- [T-10] Correção: `SurgeryMaterialRepositoryInterface::delete(SurgeryMaterial): int` (linhas apagadas, DELETE com tenant+id+surgery_id); `remove()` void delega; Fake ganhou `storedMaterialOfAnyTenant(int)`; "Material <id> was already removed" sem evento. i18n pt: "O material <id> já foi removido" (T-19).
- [T-07..T-11] SurgeryService: sala de outra unidade → InvalidArgumentException `Surgery room <id> belongs to another unit`; cirurgia inexistente → CrossTenantReferenceException; eventos scheduled/consent/status/cancellation; replaceTeam confere lockStatus (T-08). SurgeryCompletionService não abre transação: T-14 envolve em TTransaction único e `complete()` sem leitura prévia; `scheduleFollowUp` valida antes de agendar (T-11).
- [onda 4] Telas prontas: SurgeryRoomList/Form, SurgeryScheduleForm (`onChangeProcedure` extra), SurgeryView (abas summary|checklist|materials|events), SurgeryConsentForm/EventForm (`typeLabels()` público), SurgeryChecklistForm/MaterialForm (`onAskRemove` extra, CSS `cv-checklist-*`), SurgeryList + SurgeryAgendaView, navegação (menu, CvNav 'surgery', EncounterView::PLAN_ACTIONS). Detalhes e chaves i18n no board.md.
- [onda 4] Gate: SUITE 744/744 (746 após fix T-15), LINT 19, PYTEST57 OK; smoke Playwright 820×1180 nas 9 telas vazias (alvos 44 px, console 0, rede sem ≥400).
- [T-21] sql/T-21-cleanup.sql pronto (não executado); gate usou só prefixo 'F6B teste'. ids F6B: sala 1, cirurgias 1-3, paciente 13318, atendimento 11103, fila 818, agendamentos 832/833, produto 11836/lote 11187, movimentos 5/6, conta 373 itens 13/14, leito 4, internação 5; tutor 1595 reutilizado, fora da limpeza.

## Pendências
- Herdadas da 6A e fora do escopo: Central de Pendências (PRD §8.23); `docs/runbooks/migrations.md` cita `$MIGRATION_USER`; admissões simultâneas do mesmo paciente sem guarda no banco.
- T-01: [sugestão] FKs de coluna única não garantem consistência tenant/unidade (T-06/T-08 devem validar); `surgery_cancelled_ck` não exige autor do cancelamento; consulta de sobreposição de sala sem limite inferior de janela varre histórico.
- T-02: [sugestão] `recordConsent` sem limite de tamanho de `consent_text` (coluna text); `SurgeryRoom::rename` sem teste.
- T-03: [sugestão] `SurgeryChecklistItem::reconstitute` falha se código sair do catálogo; `assertComplete` caminho feliz com `Assert::true(true)`.
- T-04: [sugestão] rollback só apaga concessões nos grupos 1 e Cirurgia (FK pode parar o script); lacunas de `frontpage_id`/`tenant_group`; nome sem `COLLATE utf8mb4_bin` casa sem acento.
- T-05: [sugestão] `FakeSurgeryChecklistRepository::listBySurgery` ordena só por id (PDO: `checked_at ASC, id ASC`); regravar item de checklist com id lança `InvalidStatusTransitionException` no fake e `LogicException` no PDO.
- T-06: [sugestão] caminho do WeakMap (mesma instância salva 2x) sem teste de integração; WeakMap guarda status mesmo após rollback da transação (falso conflito se a instância for reaproveitada); round trip não relê campos de cancelamento; inserts não validam tenant/unidade (T-08..T-10 conferem sala/unidade e carregam a cirurgia antes de gravar filhos); `replaceForSurgery` com membro repetido e corrida de código de sala sobem `PDOException` crua (services deduplicam/checam antes); docblock de `Surgery.php:21-23` ainda cita `loadedStatus()` como único status esperado.
- T-07: [sugestão] corrida de código de sala entre findByCode e INSERT sobe PDOException crua (já registrado na T-06).
- T-08: [sugestão] nada prova a ordem trava → checagem de sobreposição de sala; sem teste de corrida cancel x start; recordClinicalEvent confere cancelled sem trava; tenant só exercitado com id 404; replaceTeam cai para status carregado quando lockStatus é null (deveria recusar).
- T-09: [sugestão] toque duplo em corrida real vira mensagem genérica (PDOException encadeada em SurgeryChecklistRepository.php:92-96 + CvFormat::userError) — cobrar em T-16/final; sem lockStatus (cancelamento concorrente pode gravar itens); cancelled/completed sem teste.
- T-10: [sugestão] teste não distingue lockStatus de leitura velha; unidade da cirurgia = do contexto no fixture, falta teste de AuthorizationDenied em removeMaterial/listMaterials; Fake devolve 0 para material de outro tenant e o real lança (SurgeryFakesTest assere o 0).
- T-11: [sugestão] atomicidade depende do TTransaction da T-14 (2+ produtos: consume do 1º grava antes do 2º lançar; pré-checar saldo ou testar 2 produtos); `complete()` na T-14 sem leitura prévia na transação (snapshot REPEATABLE READ); testCompletionUsesLockedStatusNotStaleRead não distingue trava; conclusão x close da conta não serializam (herdado da 6A, levar à revisão final).
- T-19: i18n "Material <id> was already removed" → "O material <id> já foi removido".
- T-12: [sugestão] sem teste de tela para ativar/desativar e edição de sala; critério 2 (badge Ativa com dados) só na T-21.
- T-13: [sugestão] onSave/onSaveTeam capturam só `Exception` (Error deixa TTransaction aberta até o fim do request); onChangeProcedure sobrescreve a duração digitada; patient_id da rota ignorado sem conferência.
- T-14: [sugestão] ficha concluída não mostra itens lançados/produtos baixados (só no TMessage); Review Focus 3 sem teste automatizado; onAskComplete sem validar id > 0.
- T-15: [sugestão] sem teste de que consent_signer_name/consent_text da query string são ignorados; makeSurgeryService/resolveTenantContext duplicados nas telas (e na 6A), candidato a helper.
- T-16: [sugestão] toque duplo concorrente vira mensagem genérica (confirmPhase sem trava); link Remover sem static=1; segundo Remover do mesmo material mostra texto enganoso de outra clínica.
- T-17: [sugestão] SELECT IN diretos no controller (seguros, mas únicos entre os clínicos); badges pre_op e in_progress com o mesmo tom; smoke com dados só na T-21.
- T-18: [sugestão] teste de menu não prova posição após `_t{Beds}` nem a ação do item Surgeries; docblock de `onInlineAction` desatualizado em EncounterView.
- T-19: chaves i18n da onda 4 (T-12..T-18) estão em board.md; "Message not found" é tolerado até lá. Mensagens de domínio para UserMessage anotadas em [T-16] do board.
- T-21: fluxos em navegador da onda 4 adiados (sala sobreposta, iniciar sem consentimento/checklist, toque duplo, material/conclusão com e sem estoque, outra unidade).
- T-19: [sugestão] status misturam gêneros nos badges (`Scheduled` "Agendado" ao lado de "Concluída"/"Cancelada"; exige chave própria na tela); mensagens `duration_minutes is required`, `quantity must be >= 1`, `cancellation_reason_text`/`consent_signer_name` com limite de tamanho ainda pelo genérico com nome técnico; `Hospitalize post-op` não criada (nenhuma tela usa).
- T-19: gate de tela em pt (sessão admin expirada, "Message not found" não verificável) → T-21.
- T-20: [sugestão] runbook trata `SurgeryAgendaView` como tela (é classe auxiliar de SurgeryList); passo 2 fixa "esperado 4/117/127" (marcar como contagens de 2026-10-05, delta +1/+9/+18).
- T-21: executar sql/T-21-cleanup.sql após a revisão final (backup, ensaio com ROLLBACK, aprovação SQL explícita).
- T-21: [sugestão] cabeçalho do SQL descreve só o estado pré-gate; registrar os ids reais antes do ensaio (sql/T-21-cleanup.sql:15-22).
- T-21: [sugestão] queue_entry 818 e appointment 832 saem em 3.11 por patient_id sem contagem antes/depois nem linha de revisão (sql/T-21-cleanup.sql:76-88, 300-327).
- T-21: [sugestão] tabela do gate por tela (Tela|Fluxos|Console|Rede|Erro|Veredito|Task dona) não registrada; só linha agregada de 14 telas x 2 viewports.
- T-21: [sugestão] arquivo vazio não rastreado `=` na raiz do repositório; remover antes do fechamento.
- T-21: fora do escopo: QueueEntryView::onAdvance mostra "Status atualizado" mas queue_entry 818 seguiu "aguardando" (provável bug anterior à fase).
- Onda 7: execução do sql/T-21-cleanup.sql (pelo orquestrador, com backup, ensaio e aprovação SQL); aviso eficiencia.py: onda 7 (correção) sem tasks em plan.md, eficiência não gravada.
- Final: [sugestão] isActive() da sala do snapshot; mensagem genérica no toque duplo concorrente do checklist (confirmPhase); QueueEntryView::onAdvance fora do escopo.

## Riscos
- `EncounterAccountService.php` (Fase 5) muda a lista de tipos de `addSourcedItem` (T-11): regressão na alta da 6A. Mitigação: `EncounterAccountServiceTest` e `HospitalizationDischargeServiceTest` na validação de T-11; diff restrito à lista.
- Conclusão não atômica fora do controller (services não abrem transação). Mitigação: `SurgeryView::onComplete` com `TTransaction` único; Review Focus 3 conferido no roteiro B da T-21.
- Banco de teste sem a 0011 faz `SurgeryRepositoryIntegrationTest` falhar. Mitigação: o bloqueio aplica nos dois bancos e T-06 exige `PASS`.
- Hospedagem 5.7 com triggers dos 2 CHECKs ampliados já criados pela 0010. Mitigação: o preparador gera `DROP TRIGGER IF EXISTS` antes do `CREATE TRIGGER`; T-20 documenta.
- `translations.json` (951 entradas) e `UserMessage.php` com um escritor só (T-19, Onda 5); até lá "Message not found" é tolerado no smoke da Onda 4.
- Onda 4 com 7 escritores e SUITEs simultâneas (falso FAIL em Redis/deadlock). Mitigação: cada agente filtra a própria classe; o validador roda a SUITE inteira sozinho no gate.
- Limite de turnos do validador no E2E. Mitigação: gate final dividido em dois disparos e smoke da Onda 4 só desktop.

## Retomada
- Pasta: `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/`
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c
- Branch de trabalho: feat/fase-6b-cirurgia (base: feat/fase-6a-internacao)
- BASE da onda 1: 09ce2d5
- Commits por onda:
  - Onda 1: BASE 09ce2d5 → HEAD 1451d0d (be36b80, 6952e1d, e8d6c93, 3936273, e416aa6, 1451d0d)
  - Onda 2: BASE 817a4f3 → HEAD f1dc5d8 (0887153, 223a94b, f245d7a, 98523b5, 701e4cb, f1dc5d8)
  - Onda 3: BASE 9c5bb04 → HEAD 21dbc10 (ddaadf0, a26b298, 6e68554, 96f0567, 3f2038a, 8dacf73, 41cfbee, 063e263, d9b5a81, 5ecd21e, 4678b75, 6929b48, 21dbc10)
  - Onda 4: BASE e09d03d → HEAD 1f7718f (dd31bcb, 24a5c00, b502111, 0388aee, aafdb9c, 192a950, 1a42185, c577b6d, d75ee63, 765b946, f872e4e, a5104a1, 1859572, 92daa99, 118f066, 2d05dd9, 78aa2e0, 1f7718f)
  - Onda 5: BASE c7ce164 → HEAD 5283aaa (193840a, 44343f2, 5283aaa)
  - Onda 6: BASE 2422d4a → HEAD dd2aa3c (dd2aa3c)
  - Onda 7: BASE be11483 → HEAD 219f1e2, aeeb85d, f1c0688
- Último status conhecido: onda 7 (correção da revisão final) concluída; T-06/T-08 [x]; SUITE 751/751, teste de duas conexões e PYTEST57 OK; re-revisão aprovada.
- Próxima onda recomendada: nenhuma; executar sql/T-21-cleanup.sql (backup, ensaio, aprovação SQL)
