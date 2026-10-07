# Revisão final
Branch feat/fase-6a-internacao (3aeea3d) contra task/landing-a11y-i18n (8f9ebfc), 135 arquivos, +17134/-13.
Verificação read-only:
- PYTEST57 → Ran 8 tests, OK.
- prepare-mysql57.py sobre a 0010 (cópia no scratchpad) → 16 checks, 32 triggers, DROP TRIGGER IF EXISTS antes dos 2 CHECKs ampliados, collation trocada, verify de TRIGGERS com as 7 tabelas.
- SELECTs no centralvet batem com o cabeçalho do T-20-cleanup.sql: enc 7, itens 12, mov 4, contas 5, programas 117, bed 1 ocupado pela internação 2, hosp 1..4, ord 7, adm 25, evt 13, contas 46 18000/500/17500 e 371 12000/0/12000.
Pontos de atenção conferidos:
- Tenant/unidade: todas as queries novas partem de tenantQuery(), e o JOIN do flowboard fixa o tenant em h, p, b e o. As mutações autorizam com o system_unit_id persistido.
- RBAC: as 8 classes estão em T-05-programs.sql, e toda action é Classe::método.
- Texto clínico: o resumo da alta só vai por $_POST (HospitalizationView::postedSummary). A suspensão só leva ids.
- Atomicidade da alta: um único TTransaction em onDischarge, e release() com rowCount serializa a alta dupla.
- MySQL 5.7: compatível.
## Triagem
- [aberta] Central de Pendências (PRD §8.23) sem tela → fora do escopo (Excluído)
- [aberta] migrations.md cita $MIGRATION_USER, mas o .env define MIGRATION_DB_USER → docs/runbooks/migrations.md:41,51 (anterior à fase)
- [resolvida] T-04: mensagens de limite/rota/tipo e asserções → UserMessage.php:92-97 (o padrão genérico "must have at most" cobre 255/120/500); UserMessageTest.php:136,190-191
- [aberta] T-03: discharge() aceita $at anterior a admittedAt; record() não limita sinais vitais ao range do schema (vira erro SQL genérico) → Domain/Hospitalization.php, Domain/HospitalizationEvent.php
- [aberta] T-02: DROP CHECK com crases e combinações sem teste; RED por AttributeError → scripts/test-prepare-mysql57.py
- [aberta] T-05: rollback sem dependentes por FK; collation insensível a acento; grupo 4 sem tenant_group → sql/T-05-programs.rollback.sql
- [aberta] T-01: tenant/unidade só na aplicação; discharge_ck não exige discharged_by → migration 0010
- [aberta] T-06: FakeBedRepository::save diverge do PDO; ordenação dos fakes → src/tests/Support/
- [resolvida] T-07: UPDATE de administração sem guarda pending → HospitalizationAdministrationRepository.php:166-188 (AND status='pending' + rowCount)
- [resolvida] T-07/T-09 (era promovida; corrigida em 3cded2e/ebf01c3 RED + b32c7c3/ac52d25, re-revisão T-07 § Rodada 3 e T-09 § Rodada 2: UPDATE só sobre linha admitted com rowCount + FOR UPDATE, e transfer() lança se release() falha): UPDATE de internação sem guarda status='admitted' + transfer() ignora o retorno de release(). Uma transferência concorrente com a alta desfaz a alta: a transferência lê admitted, a alta commita (itens na conta, estoque baixado, leito X liberado), e a transferência ocupa Y. Em seguida release(X) dá 0 linhas e é ignorado, e o save grava status='admitted', discharged_at=NULL. Nada lança exceção, então o TTransaction não desfaz. Resultado: internação reaberta no leito Y com a conta já cobrada, e uma nova alta baixa o estoque de novo (addSourcedItem pula os itens, o consume não). → HospitalizationService.php:171-175; HospitalizationRepository.php:110-117
- [aberta] T-08: create concorrente com o mesmo código dá PDOException da UNIQUE; falta teste da guarda de unidade → BedService.php:43-57
- [aberta] T-09: admissões simultâneas do mesmo paciente sem guarda no banco (MVP); teste de corrida sem saveCount; helper eventsOfType → HospitalizationServiceTest.php
- [resolvida] T-09: rollback da corrida do occupy() pelo TTransaction → HospitalizationAdmissionForm.php onSave e HospitalizationView::onTransfer abrem e fecham uma transação única, com rollback em todo catch
- [aberta] T-10: suspend não exige internação admitted (a tela só esconde o botão de prescrição inativa) → HospitalizationOrderService.php suspend
- [aberta] T-11: alta dupla concorrente termina em PDOException da UNIQUE ou em "Bed is not occupied" (há rollback, sem corrupção); fechamento concorrente da conta pode ser reaberto; sem teste de release falso nem de conta não aberta → HospitalizationDischargeService.php:199-243
- [aberta] T-07: CASE do save de leito reativa em silêncio um leito inativado entre a leitura e o save → BedRepository.php:151-162
- [aberta] T-12: BedList sem teste automatizado; Voltar só com ícone, sem aria-label → BedList.php
- [aberta] T-13: catch (Exception) não cobre \Error; loadOptions() roda de novo em onSave → HospitalizationAdmissionForm.php
- [resolvida] T-14: resumo clínico via GET no TQuestion → HospitalizationView.php:157-199 (só POST, a query string é ignorada)
- [aberta] T-14: alta dupla cai em PDOException sem mensagem amigável; get(ACTION_READ) extra dentro da transação → HospitalizationView.php:216
- [aberta] T-15: resolveTenantContext() e a montagem dos services copiados em 3 forms
- [aberta] T-16: N+1 em admittedCards; KPI "Done" conta as puladas → HospitalizationBoardView.php:27-29
- [aberta] T-17: teste "Beds inside Settings" compara posição de string; stderr descartado
- [aberta] T-18: padrões genéricos mostram o nome técnico do campo; o padrão "was not found ... authenticated tenant" muda mensagens de outras fases, sem teste delas; grupo (new) morto → UserMessage.php
- [aberta] T-19: o runbook aponta a DML em .claude/tasks/... → docs/runbooks/internacao.md:91
- [aberta] T-12: .cv-touch-target fixa 44px em vez de var(--cv-touch-target) → cv-components.css
- [resolvida] T-20: comentários de estado desatualizados e audit_log não documentado → T-20-cleanup.sql:13-41 (estado das 17:25 confere com SELECT agora)
- [aberta] T-20: executar sql/T-20-cleanup.sql (orquestrador, backup + aprovação SQL)
## Rulings
- plano · onda 0 — admissão só a partir de atendimento (encounter_id NOT NULL)
- plano · onda 0 — baixa de estoque e lançamento na alta, agregados por produto; estoque insuficiente recusa a alta inteira (TTransaction único)
- plano · onda 0 — source_type novos via addSourcedItem, idempotente pela UNIQUE
- plano · onda 0 — ocupação do leito por UPDATE condicional; current_hospitalization_id sem FK
- plano · onda 0 — ends_at obrigatório (≤ 30 dias), agenda gerada na criação, atraso derivado
- plano · onda 0 — diária copiada na admissão; max(1, ceil(h/24)); item de valor 0 não é lançado
- plano · onda 0 — $clock opcional nos services
- plano · onda 0 — migration 8/5.7: DROP CHECK + ADD CONSTRAINT, sem BETWEEN/LIKE, timestamps com DEFAULT
- plano · onda 0 — cv-components.css fora de framework_hashes; seção cv-board-* na T-16
- plano · onda 0 — programas com nome ASCII em inglês; DML com MAX(id), sem ROW_NUMBER
- plano · onda 0 — respostas do usuário: admissão só via atendimento; grupo novo "Clínico – Internação" + grupo 1; grupos 2/3 sem acesso; SET NAMES utf8mb4
- plano · onda 0 — branch feat/fase-6a-internacao
- T-03 · onda 1 — Bed sem mutador de ocupação; extras aditivos
- T-04 · onda 1 — reconstitute/assignId/TYPES/assertValid; período de 30 dias exatos aceito
- T-02 · onda 1 — verification_query() pública
- T-05 · onda 1 — SET NAMES no seed; FROM DUAL; rollback com SELECT prévio
- T-06 · onda 2 — fakes com saveCount; occupy/release por reconstitute
- T-07 · onda 2 — save de leito via CASE; eventos append-only; board exclui cancelled
- T-07/T-10 · onda 3 — UPDATE de administração condicional a pending (corrigido na T-07)
- T-07 · onda 3 — caminhos extras autorizados (fake e teste de administração)
- T-09 · onda 3 — corrida do occupy() delegada ao TTransaction; admissão simultânea do mesmo paciente fica como pendência do MVP
- T-12/T-13/T-14/T-15 · onda 6 — lote de correção UX; T-12 dona de .cv-touch-target; resumo da alta só por POST
- T-20 · onda 6 — rodada 1 reprovada (conta 371, atraso sem E2E) → f3c5109; rodada 2 aprovada
- T-20 · onda 6 — Review Focus 1 e 3 aceitos sem E2E; re-gate sem desktop e sem listagem de rede
- T-20 · onda 6 — queue_entry 322 sem nada a restaurar
## Achados
- [resolvida] (era bloqueante; ver Triagem T-07/T-09) Transferência concorrente com a alta reabre a internação já faturada e dá baixa no estoque em dobro na alta seguinte. Os dois defeitos estão na promovida acima: o save sem guarda status='admitted' e o release() ignorado. Correção mínima: checar o retorno de release() em transfer() e/ou condicionar o UPDATE de hospitalization a status='admitted' com rowCount → HospitalizationService.php:171; HospitalizationRepository.php:110-117
- [sugestão] Prescrição concorrente com a alta deixa administrações pending numa internação discharged. Elas somem do flowboard (h.status='admitted') e nunca são cobradas nem canceladas → HospitalizationOrderService.php prescribe (assertAdmitted lido antes do commit da alta)
