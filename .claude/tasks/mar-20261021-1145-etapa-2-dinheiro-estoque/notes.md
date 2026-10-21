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
- 2026-10-21 · T-05 · onda 2 — Implementador sem credencial para o curl autenticado; o orquestrador criou o cookie-jar admin (unit_id=1) em scratchpad lendo o .env sem imprimir e o repassou só para leitura.
- 2026-10-21 · T-07 · onda 2 — RED inválido do validador (commit RED tocou SubmissionTokenTest.php além dos declarados) aceito no histórico sem fix loop; revisor julgou os testes RED e aprovou.
- 2026-10-21 · T-08 · onda 2 — RED inválido do validador (commit RED tocou ReceivableSaleOriginTest.php além dos declarados) aceito no histórico sem fix loop; revisor aprovou.
- 2026-10-21 · T-09 · onda 2 — RED inválido do validador (commit RED tocou SaleRepositoryCancelIntegrationTest.php e SaleServiceTest.php além dos declarados) aceito no histórico sem fix loop; revisor aprovou.
- 2026-10-21 · onda 2 · onda 2 — prepare-mysql57.py e .verify.sql da 0016 não rodados: a onda não tocou src/app/database nem scripts e ambos estavam verdes na onda 1; aceito como pendência.
- 2026-10-21 · T-11 · onda 3 — Achado [plano-mandou] da rodada 1 (1205 do teste vinha do claim/FK de tenant, não de findByIdForUpdate) decidido como corrigir; B chama register sem token (caminho interno do checkout), pois COMMIT de dados de base é proibido pelo MysqlIntegrationTestCase; sensibilidade provada (com findById o teste falha). Desvio em reports/T-11.md § Correção 1 (bcfe53f).
- 2026-10-21 · T-12 · onda 3 — Commit 94f6705 ajustou 1 linha do teste RED (ACTION 'test::payment-reversal' → 'test::reversal'), sem mudar asserções; aceito, validador confirmou RED por classe inexistente.
- 2026-10-21 · onda 3 · onda 3 — prepare-mysql57.py e .verify.sql da 0016 não rodados: a onda não tocou src/app/database nem scripts (ruling vigente desde a onda 2).
- 2026-10-21 · T-13 · onda 4 — Desvios aceitos (revisor, rodada 1): assertTenant antes do claim do token; forma de pagamento desconhecida lança InvalidArgumentException sem chave da T-01 (0e5e318).
- 2026-10-21 · T-14 · onda 4 — Commit fcffd4a trocou 1 linha do teste RED (ACTION 'test::sale-cancel' → 'test::cancel', formato sem hífen), sem mudar asserções; aceito, validador confirmou RED por classe inexistente.
- 2026-10-21 · T-14 · onda 4 — Orquestrador repassou ao implementador (a) passar a unidade da venda ao cancelReceivable (contrato da T-12) e (b) action sem hífen (apontado pela T-13); ambos atendidos.
- 2026-10-21 · T-14 · onda 4 — Sem caixa aberto, CashSessionRequiredException sai após reverseReferenceConsumption gravar o estoque; rollback fica com o chamador (TTransaction da T-16); T-16 deve garanti-lo e T-17 prová-lo com MySQL; aceito por validador e revisor.
- 2026-10-21 · onda 4 · onda 4 — prepare-mysql57.py e .verify.sql da 0016 não rodados: a onda não tocou src/app/database nem scripts (ruling vigente).

## Bloqueios
- nenhum

## Descobertas
- T-01: UserMessage::STATIC tem 89 entradas; UserMessageTest fixa count 89 (quem adicionar mensagens de domínio depois ajusta). Chaves da Interface de T-01 em translations.json (7c198b6).
- T-02: 0016 aplicada no centralvet_test (28f0fd2): receivable.sale_id + receivable_origin_ck, encounter_account_id NULL; sale.cancelled_at/cancelled_by_system_user_id/cancellation_reason + sale_cancellation_ck; tabelas payment_reversal e submission_key; stock_movement_reason_ck aceita 'sale_cancellation'; índices extras por FK.
- T-04: resolveDispatchTarget removido; regra do class_alias em MutationRequestPolicy::resolveCanonical; guard chama decideFromRequest($_SERVER, $_REQUEST, $token); telas novas não passam resolvedor.
- T-03: SearchParameterGuard nega orderColumn fora de key/itens de column; TDBUniqueSearch/TDBMultiSearch novos (T-16) ordenam pela coluna exibida ou omitem orderColumn.
- cruzada-onda-1: fixture createSale do StockSalesOverviewIntegrationTest preenche colunas de cancelamento; SUITE 1880/1880 (69e1122).
- T-05: app/worker reconstruídos com request_order=GP (CLI e FPM); cookies não entram mais em $_REQUEST (df71f73, a4e7142).
- T-07: SubmissionToken/SubmissionKeyRepository/DuplicateSubmissionException/Fake disponíveis (31a38f8); claim/attach/findReference validam o token.
- T-09: Sale::cancel(reason, cancelledBySystemUserId, cancelledAt); SaleRepositoryInterface::findByIdForUpdate (LogicException fora de transação) (7237261).
- T-10: SaleFinanceReader (e36c011): listSales traz completed e cancelled; datas Y-m-d H:i:s; venda sem recebível → receivable_id null e received_cents 0.
- T-06: StockService::consume lê com FOR UPDATE e exige transação; reverseReferenceConsumption devolve unidades repostas, 0 se já houver reversão (d39d5c0).
- T-08: Receivable::encounterAccountId() agora ?int (único consumidor ReceivableRepository.php:136 aceita null); openForSale, findByIdForUpdate/findBySaleId, PaymentReversal e repositórios (d5318e5).
- T-12: PaymentReversalService::cancelReceivable (94f6705): autoriza antes de ler; estorna só pagamentos sem existsForPayment; ordem das gravações = reversal → financial_entry expense, por pagamento; Receivable::cancel()+save por último; exige transação no PDO real.
- T-11: PaymentService ganhou 7º parâmetro SubmissionKeyRepositoryInterface; register(..., ?string $submissionToken = null) lê com findByIdForUpdate (exige transação), com token faz claim antes de ler e attach('payment', token, paymentId) ao fim; PaymentForm/PendingReceivableList instanciam o repositório (4971ff6, bcfe53f).
- T-13: SaleCheckoutService::checkout/SaleCheckoutResult(sale, receivable, ?payment) (0e5e318); assertTenant antes do claim; forma desconhecida lança InvalidArgumentException('Unknown payment method "<x>"'); exige transação no PDO real; T-15 deve restringir o combo a Payment::METHODS.
- T-14: SaleCancellationService::cancel/SaleCancellationResult (fcffd4a); exige transação; autoriza na unidade da venda; sem caixa e com pagamento lança CashSessionRequiredException após devolver estoque, então o chamador (T-16) faz rollback.

## Pendências
- T-02: prova de preservação de dados vazia (receivable e sale com 0 linhas no centralvet_test); ensaio com linhas ou a T-17 cobriria.
- T-02: cabeçalho de scripts/test-db/provision.sh ainda diz "migrations 0001..0014".
- T-03: docblock de SearchParameterGuard não cita a regra nova de orderColumn (SearchParameterGuard.php:7-20).
- T-03: comparação de orderColumn é sensível a maiúsculas; falta teste que fixe isso.
- T-04: docblock de decideFromRequest não cita a exceção do alias do AdiantiClassMap (MutationRequestPolicy.php:133-135).
- T-04: teste monta 'resolveDispatch' . 'Target' só por causa do grep do critério (MutationGuardWiringTest.php:155).
- Validador: ExamResultAiIntegrationTest falhou uma vez (FK 1451/1452) numa das 3 SUITEs e passou nas seguintes, inclusive 1880/1880 no HEAD 69e1122; possível flaky, não confirmado.
- Validador: na revalidação final só a SUITE rodou (lint, prepare-mysql57 e framework_hashes verdes no gate anterior).
- T-05: rebuild da imagem não refeito pelo validador (container em execução já tem GP).
- T-06: teste 1205 não isola o bloqueio de A (lock implícito do INSERT não commitado).
- T-06: increaseQuantity() ignora rowCount(); reverseReferenceConsumption pode somar quantidade não reposta (StockBatchRepository.php:545-560).
- T-06: docblock de consume() ainda cita "saving the batch" (StockService.php:126-134).
- T-06: decreaseQuantity usa :quantity_floor em vez do :quantity da Interface (desvio registrado).
- T-07: isDuplicateClaim depende do nome submission_key_uq no texto do 1062 (SubmissionKeyRepository.php:110-114).
- T-07: attach de token não reivindicado e token malformado sem teste de integração.
- T-08: § RED do relatório transcreve só 4 das 8 falhas de ReceivableSaleOriginTest.
- T-08: teste findByIdForUpdate espera 1205 pelo lock implícito do INSERT de A (PaymentReversalIntegrationTest.php:151).
- T-08: testFakeRepositoryFindsBySaleIdAndLocksInsideNoTransaction testa só o fake (ReceivableSaleOriginTest.php:415); FakeReceivableRepository::findByIdForUpdate não exige transação.
- T-08: sem teste das mensagens de CashSessionRequiredException; docblocks de CashSessionService e Receivable desatualizados.
- T-09: 1205 do findByIdForUpdate também vem do lock implícito do INSERT; sem teste com duas conexões para deadlock 1213 (SaleServiceTest:228) e T-17 não o cita.
- T-09: catch de compensação em SaleService.php:335-343 não devolve movimentos já consumidos de produtos anteriores (limitação pré-existente).
- Validador: contrato de encounterAccountId() ?int (T-08) com único consumidor ReceivableRepository.php:136.
- Validador: ExamResultAiIntegrationTest intermitente em execuções de T-07/T-08, verde na SUITE do gate (flaky reforçado, não confirmado).
- T-11: PaymentFormSubmissionTokenTest confere a fonte com strings literais e quebra com reformatação (PaymentFormSubmissionTokenTest.php:18-65).
- T-12: testes do caixa fechado só cobrem listByCashSession(50); totalsByPaymentMethod inalterado só o MySQL cobre (CashSessionService.php:163) — explicitar em T-17.
- T-12: unidade autorizada é a informada pelo chamador (receivable sem system_unit_id); T-14 deve passar a unidade da venda, não a da sessão (PaymentReversalService.php:67).
- T-12: ACTION certo ('test::reversal') não estava no commit RED 23a73f4.
- Pendência T-06/T-08/T-09 (1205 sem isolar o lock) resolvida para T-11; continua para as demais.
- T-13: texto 'Unknown payment method "x"' diverge do domínio ('Unknown payment_method "x"', Payment.php:83/122) e cai na mensagem genérica (SaleCheckoutService.php:88).
- T-13: teste de sem caixa não confere FakeSaleItemRepository (SaleCheckoutServiceTest.php:139-141).
- T-14: sem teste unitário do cancelamento pago sem caixa aberto (SaleCancellationService.php:73-97); T-16 onCancel deve dar TTransaction::rollback e mostrar a mensagem; T-17 cobrir.
- T-14: RED de e5705be usava ACTION inválido (corrigido em fcffd4a; SaleCancellationServiceTest.php:46).

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
  - Onda 2: BASE 5cee1ca → HEAD d39d5c0 (df71f73, a4e7142, b059779, db4a30d, 5e93e5c, 4295ab3, b9a1cfb, 31a38f8, 7237261, d5318e5, e36c011, d39d5c0)
  - Onda 3: BASE fc5e50c → HEAD bcfe53f (23a73f4, 9815aa9, 94f6705, 4971ff6, bcfe53f)
  - Onda 4: BASE 7f1f77b → HEAD fcffd4a (e5705be, e7cbbdf, 0e5e318, fcffd4a)
- Último status conhecido: onda 4 concluída (T-13, T-14 [x]); SUITE 1969/1969
- Próxima onda recomendada: onda 5 (T-15, T-16)
