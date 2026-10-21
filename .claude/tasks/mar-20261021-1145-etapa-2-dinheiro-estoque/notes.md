# Notas de execução

## Decisões tomadas
- 2026-10-21 · plano · onda — — Branch base `task/etapa-1-seguranca-mvp` (HEAD `363582a`) por pedido explícito do desenvolvedor na Fase 1; `branches.py` aponta `main` como produção (fonte `padrão`), confirmada pelo desenvolvedor na Etapa 1.
- 2026-10-21 · plano · onda — — Entrega preserva os commits por onda (premissa do desenvolvedor): a Fase 5 não roda o desfazer do `entrega.py`.
- 2026-10-21 · plano · onda — — Transação: o formulário continua dono da transação (`TTransaction::open` faz `beginTransaction`); o caso de uso a exige — `claim`, `lockByProductOrderedByExpiry` e `findByIdForUpdate` lançam `LogicException('... requires an open transaction')` fora dela. Sem transação aninhada nem SAVEPOINT no código de produção.
- 2026-10-21 · plano · onda — — Concorrência: `FOR UPDATE` nos lotes, no recebível e na venda (padrão `EncounterRepository::lockForDraft`) + `UPDATE` condicional na baixa de estoque; consumo em ordem crescente de `product_id` para não haver deadlock entre carrinhos com os mesmos produtos em ordem inversa.
- 2026-10-21 · plano · onda — — Idempotência: tabela `submission_key` (UNIQUE tenant/escopo/token), token de 32 hex gerado por renderização do formulário (`THidden('submission_token')`); envio concorrente espera o primeiro commit (1205/1062), repetição lança `DuplicateSubmissionException`. `PaymentService::register` sem token (chamada interna do checkout) não deduplica: a deduplicação da venda cobre o pagamento à vista.
- 2026-10-21 · plano · onda — — F3: à vista = recebimento do total no caixa aberto da unidade; sem caixa aberto a venda à vista é recusada antes de gravar; venda com total 0 gera recebível `paid` sem `payment` (`payment_amount_ck`). Recebimento parcial no PDV fica fora (premissa, segue pelo PaymentForm).
- 2026-10-21 · plano · onda — — F3b: estorno em `payment_reversal` (UNIQUE `payment_id`) no caixa aberto atual + `financial_entry` `expense` (`reference_type` `payment_reversal`); caixa já fechado não é reescrito; totais do caixa e saldo do caixa aberto descontam estornos. Cancelamento com recebimento exige caixa aberto.
- 2026-10-21 · plano · onda — — RBAC do cancelamento por programa próprio `SaleCancelForm` (o RBAC Adianti é por programa; método só se nega explicitamente), liberado no seed só para o grupo 1.
- 2026-10-21 · plano · onda — — Recibo passa a ser montado de `sale_item` + `SaleFinanceReader::receiptSummary` em `SaleReceiptHtml` (testável sem Adianti); resolve também a pendência T-08 da Etapa 1 (snapshot do carrinho divergente).
- 2026-10-21 · plano · onda — — P3: interpretado como `enforceMutationGuard` chamando `decideFromRequest($_SERVER, $_REQUEST, $token)` (3 argumentos, resolvedor padrão) com a regra do alias dentro de `resolveCanonical`; barra inicial e caixa divergente continuam negadas (fail-closed).
- 2026-10-21 · plano · onda — — SUITE desta etapa monta também `docker/php/conf.d/app.ini` (T-05) e `docs/` (T-16), além de `.env.example` e `docker-compose.yml` (ruling da Etapa 1).
- 2026-10-21 · plano · onda — — Lacunas de exploração viraram premissa: rótulos de forma de pagamento via `CvFormat::paymentMethod`; `prepare-mysql57.py` aceita `IS NULL`/`AND`/`OR` nos CHECKs (T-02 confere ao rodar o preparador; se recusar, o CHECK vira trigger manual documentado no runbook).

- 2026-10-21 · plano · onda — — Resposta do usuário (1): DDL da 0016 no `centralvet_test` AUTORIZADO; T-02 aplica com root do container mysql (comando da Validação de T-02) e roda o `.verify.sql`.
- 2026-10-21 · plano · onda — — Resposta do usuário (2): aplicar a 0016 e o seed (`SaleList`, `SaleCancelForm`) no banco de dev `centralvet` AUTORIZADO, só para o teste de fumaça da T-17 (Onda 6), antes do rebuild do `app`/`worker`; dados `ZZ-E2-` criados e excluídos pelo validador, como na Etapa 1. Nenhuma outra gravação no dev.
- 2026-10-21 · plano · onda — — Resposta do usuário (3): decisões de produto CONFIRMADAS — à vista recebe no caixa aberto e é recusada sem caixa; estorno no caixa aberto atual e cancelamento com recebimento exige caixa aberto; cancelamento como programa próprio `SaleCancelForm`, liberado só para o grupo 1.
- 2026-10-21 · plano · onda — — Usuário aprovou executar o plano.
- 2026-10-21 · T-01 · onda 1 — Ajuste da contagem em src/tests/Unit/UserMessageTest.php (82→89) em 7c198b6, fora dos arquivos prováveis, aceito como escopo da T-01 (consequência direta das chaves novas).
- 2026-10-21 · T-02 · onda 1 — Falha cruzada sem dona (8 testes de StockSalesOverviewIntegrationTest, 3819 sale_cancellation_ck) corrigida por agente novo no commit 69e1122 com trailer `Task: T-02`, por ser efeito da 0016; relatório em reports/cruzada-onda-1.md.
- 2026-10-21 · onda 1 · onda 1 — O validador da onda foi interrompido por reinício do computador e retomado; reviews/T-NN.md § Gate foram regravados.

## Bloqueios
- nenhum

## Descobertas
- T-01: UserMessage::STATIC tem 89 entradas; UserMessageTest fixa count 89 (quem adicionar mensagens de domínio depois ajusta). Chaves da Interface de T-01 em translations.json (7c198b6).
- T-02: 0016 aplicada no centralvet_test (28f0fd2): receivable.sale_id + receivable_origin_ck, encounter_account_id NULL; sale.cancelled_at/cancelled_by_system_user_id/cancellation_reason + sale_cancellation_ck; tabelas payment_reversal e submission_key; stock_movement_reason_ck aceita 'sale_cancellation'; índices extras por FK.
- T-04: resolveDispatchTarget removido; regra do class_alias em MutationRequestPolicy::resolveCanonical; guard chama decideFromRequest($_SERVER, $_REQUEST, $token); telas novas não passam resolvedor.
- T-03: SearchParameterGuard nega orderColumn fora de key/itens de column; TDBUniqueSearch/TDBMultiSearch novos (T-16) ordenam pela coluna exibida ou omitem orderColumn.
- cruzada-onda-1: fixture createSale do StockSalesOverviewIntegrationTest preenche colunas de cancelamento; SUITE 1880/1880 (69e1122).

## Pendências
- T-02: prova de preservação de dados vazia (receivable e sale com 0 linhas no centralvet_test); ensaio com linhas ou a T-17 cobriria.
- T-02: cabeçalho de scripts/test-db/provision.sh ainda diz "migrations 0001..0014".
- T-03: docblock de SearchParameterGuard não cita a regra nova de orderColumn (SearchParameterGuard.php:7-20).
- T-03: comparação de orderColumn é sensível a maiúsculas; falta teste que fixe isso.
- T-04: docblock de decideFromRequest não cita a exceção do alias do AdiantiClassMap (MutationRequestPolicy.php:133-135).
- T-04: teste monta 'resolveDispatch' . 'Target' só por causa do grep do critério (MutationGuardWiringTest.php:155).
- Validador: ExamResultAiIntegrationTest falhou uma vez (FK 1451/1452) numa das 3 SUITEs e passou nas seguintes, inclusive 1880/1880 no HEAD 69e1122; possível flaky, não confirmado.
- Validador: na revalidação final só a SUITE rodou (lint, prepare-mysql57 e framework_hashes verdes no gate anterior).

## Riscos
- Código novo sem a 0016 aplicada quebra o PDV e o recebimento no ambiente (INSERT de `sale_id`/`submission_key`). Mitigação: rebuild do dev só depois da migration autorizada; runbook da 0016 (T-02).
- `StockService::consume` passa a exigir transação: chamadores (`ProcedureExecutionService`, `HospitalizationDischargeService`, `SurgeryCompletionService`) rodam em `TTransaction` nas telas; validação de T-06 roda os testes deles.
- Consultas com `INNER JOIN encounter_account` (`CopilotFinanceQuery`, `PendingItemQuery`, `ReminderSourceQuery`) não veem recebíveis de venda; aceito nesta etapa (fora do PDV).
- Lock wait (1205) ou deadlock (1213) aparecem ao usuário como "O estoque mudou durante a venda" (T-15); com volume alto, pode exigir retry automático futuro.
- Testes com duas conexões dependem de `innodb_lock_wait_timeout = 1` e não fazem commit; dados não commitados da conexão A são vistos por B só via leitura com bloqueio.

## Retomada
- Pasta: `.claude/tasks/mar-20261021-1145-etapa-2-dinheiro-estoque/`
- Sessões: fc92cc27-9f66-4ad7-bfe3-efed43d88248
- Branch de trabalho: task/etapa-2-dinheiro-estoque (base: task/etapa-1-seguranca-mvp)
- BASE da onda 1: 363582a
- Commits por onda:
  - Onda 1: BASE 363582a → HEAD 69e1122 (49d4480, be89ba0, 7c198b6, f3a7cbd, 28f0fd2, 74862a5, 88b0bca, 69e1122)
- Último status conhecido: onda 1 concluída (T-01..T-04 [x]); SUITE 1880/1880 em 69e1122
- Próxima onda recomendada: onda 2 (T-05, T-06, T-07, T-08, T-09, T-10; dependem só de T-02)
