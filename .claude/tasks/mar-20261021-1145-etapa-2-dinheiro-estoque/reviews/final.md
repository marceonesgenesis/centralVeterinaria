# Revisão final
Branch task/etapa-2-dinheiro-estoque (d3efb7e) contra task/etapa-1-seguranca-mvp (363582a). 138 arquivos, +10779/-206.
Verificado por mim: diff de Application/Persistence/Domain/control/Security/migration 0016/seed; `php -l` nos 75 PHP A/M em src/ → 75 "No syntax errors detected"; nenhum arquivo de framework_hashes (engine.php, index.php, init.php, src/lib/adianti/, layout.html, custom.css) no diff.
Não verificado por mim (não é read-only): SUITE (1995/1995 segundo notes), prepare-mysql57.py e .verify.sql, fumaça no :8081.

## Triagem
- [aberta] T-02 prova de preservação com 0 linhas → a 0016 só amplia (MODIFY NULL, ADD COLUMN NULL) e o Sale::cancel() antigo não tinha chamador na base; não verificado com dados reais por mim
- [aberta] T-02 cabeçalho diz "migrations 0001..0014" → scripts/test-db/provision.sh:10
- [aberta] T-03 docblock sem a regra do orderColumn → SearchParameterGuard.php:7-20
- [aberta] T-03 orderColumn sensível a maiúsculas sem teste → SearchParameterGuard.php:97 (in_array estrito, nega: fail-closed)
- [aberta] T-04 docblock de decideFromRequest sem a exceção do alias → MutationRequestPolicy.php:133-135
- [aberta] T-04 'resolveDispatch' . 'Target' por causa do grep → MutationGuardWiringTest.php:155
- [aberta] Validador: ExamResultAiIntegrationTest flaky (FK 1451/1452) → não verificado
- [resolvida] Validador: revalidação final só com SUITE → refiz php -l nos 75 PHP da branch: 0 erros; framework_hashes fora do diff
- [aberta] T-05 rebuild não refeito pelo validador → notes diz que houve rebuild na T-17; não verificado
- [aberta] T-06 teste 1205 não isola o lock de A → o código bloqueia (StockBatchRepository.php:768 FOR UPDATE) e a baixa condicional (:108-109) segura o saldo mesmo sem o lock; o problema é de teste, não de estoque
- [aberta] T-06 increaseQuantity ignora rowCount → StockBatchRepository.php:120-135; o lote vem do movimento (FK), então não pode estar ausente
- [aberta] T-06 docblock de consume cita "saving the batch" → StockService.php:126-134
- [aberta] T-06 :quantity_floor em vez de :quantity → StockBatchRepository.php:109 (desvio registrado)
- [aberta] T-07 isDuplicateClaim depende do nome submission_key_uq → SubmissionKeyRepository.php:110-114; se falhar, sobe exceção e tudo é desfeito (não grava em dobro)
- [aberta] T-07 attach de token não reivindicado/malformado sem teste de integração → SubmissionKeyRepositoryIntegrationTest.php
- [aberta] T-08 § RED transcreve 4 de 8 falhas → reports/T-08.md (histórico)
- [aberta] T-08 findByIdForUpdate espera 1205 pelo lock implícito do INSERT → PaymentReversalIntegrationTest.php:151
- [aberta] T-08 teste só do fake; o fake não exige transação → ReceivableSaleOriginTest.php:415
- [aberta] T-08 mensagens de CashSessionRequiredException sem teste; docblocks desatualizados → CashSessionService.php, Receivable.php
- [aberta] T-09 1205 do lock implícito; sem teste 1213 com duas conexões → SaleServiceTest.php:228 (a ordem crescente está no código, SaleService.php:318)
- [resolvida] T-09 compensação do SaleService não devolve movimentos → consume exige transação (StockBatchRepository.php:758) e SaleForm::onSave faz TTransaction::rollback em todo catch (SaleForm.php:593-666); o rollback desfaz os movimentos
- [resolvida] Validador: encounterAccountId() ?int → só dois consumidores: ReceivableRepository.php:136 aceita null, PendingReceivableList.php:270 só roda quando sale_id é null
- [aberta] Validador: ExamResultAiIntegrationTest intermitente → não verificado
- [aberta] T-11 PaymentFormSubmissionTokenTest com strings literais → PaymentFormSubmissionTokenTest.php:18-65
- [resolvida] T-12 totalsByPaymentMethod do caixa fechado só no MySQL → SaleCheckoutFlowIntegrationTest.php:333-357 assere o caixa fechado inalterado e o novo em -10000
- [resolvida] T-12 unidade vinha do chamador; a T-14 devia passar a da venda → SaleCancellationService.php:91-96 passa $sale->systemUnitId()
- [resolvida] T-12 ACTION certo fora do commit RED → corrigido em 94f6705 (histórico)
- [aberta] 1205 sem isolar o lock em T-06/T-08/T-09 → mesmo item acima; segue como limitação de teste
- [aberta] T-13 'Unknown payment method' diverge do domínio e cai na mensagem genérica → SaleCheckoutService.php:88; o combo só oferece Payment::METHODS (SaleForm.php:174-179)
- [aberta] T-13 teste sem caixa não confere FakeSaleItemRepository → SaleCheckoutServiceTest.php:139-141
- [resolvida] T-14 cancelamento pago sem caixa: sem teste e sem rollback → SaleCancelForm.php:152-157 faz rollback; SaleCheckoutFlowIntegrationTest.php:361-386 prova que estoque, venda, recebível e estorno voltam
- [resolvida] T-14 RED com ACTION inválido → corrigido em fcffd4a (histórico)
- [aberta] T-15 SaleFormCheckoutWiringTest assere pouco (grep de fonte) → SaleFormCheckoutWiringTest.php
- [aberta] T-15 METHOD_LABELS duplica CvFormat::paymentMethod → SaleReceiptHtml.php
- [aberta] T-15 recibo mostra tutor e paciente pelo id → SaleReceiptHtml.php:319-332
- [aberta] T-16 'Sale {id} not found' sem tradução → SaleCancellationService.php:57
- [aberta] T-16 botão de cancelar ativo em venda já cancelada → SaleCancelForm.php:97-99 (só mostra aviso; a operação é idempotente pelo FOR UPDATE da venda)
- [resolvida] T-16 sem fumaça e sem rollback do onCancel com MySQL → integração em SaleCheckoutFlowIntegrationTest.php:361 (a fumaça está registrada em notes, mas não a verifiquei)
- [aberta] T-17 audit_log do dev com +15 linhas → mantido por decisão do usuário
- [aberta] T-16 botão segue em venda cancelada (repetido) → mesmo item acima
- [aberta] T-17 (c) não distingue o FOR UPDATE do lote → SaleCheckoutFlowIntegrationTest.php:178
- [aberta] T-17 openCashBalance() ?int sem assert de não nulo → SaleCheckoutFlowIntegrationTest.php:111,136,286,311,498
- [aberta] T-17 fumaça sem status 200 do POST onCancel → scratchpad/ev/cancel-1.txt; não verificado
- [aberta] T-16 SaleListActionCellTest com asserção tautológica, sem render real e com docblock impreciso → SaleListActionCellTest.php

## Rulings
- plano · onda — — Branch base task/etapa-1-seguranca-mvp (363582a) por pedido do desenvolvedor; produção main
- plano · onda — — A entrega preserva os commits por onda (sem o desfazer do entrega.py)
- plano · onda — — O formulário é dono da transação; claim, lock e FOR UPDATE lançam LogicException fora dela; sem SAVEPOINT em produção
- plano · onda — — FOR UPDATE em lote, recebível e venda + UPDATE condicional; consumo em ordem crescente de product_id
- plano · onda — — Idempotência pela tabela submission_key; register sem token (checkout) não deduplica
- plano · onda — — F3: à vista recebe no caixa aberto e é recusada sem caixa; total 0 gera recebível paid sem payment
- plano · onda — — F3b: estorno em payment_reversal no caixa aberto atual + financial_entry expense; caixa fechado não muda
- plano · onda — — RBAC do cancelamento pelo programa próprio SaleCancelForm, liberado só para o grupo 1
- plano · onda — — Recibo montado de sale_item + receiptSummary em SaleReceiptHtml (resolve a T-08 da Etapa 1)
- plano · onda — — P3: decideFromRequest com 3 argumentos e a regra do alias em resolveCanonical
- plano · onda — — A SUITE monta app.ini e docs/, além de .env.example e docker-compose.yml
- plano · onda — — Rótulos de CvFormat::paymentMethod; CHECKs com IS NULL/AND/OR no preparador
- plano · onda — — Usuário autorizou o DDL da 0016 no centralvet_test
- plano · onda — — Usuário autorizou a 0016 e o seed no dev, só para a fumaça da T-17
- plano · onda — — Usuário confirmou as decisões de produto (à vista, estorno, SaleCancelForm)
- plano · onda — — Usuário aprovou executar o plano
- T-01 · onda 1 — UserMessageTest 82→89 fora dos arquivos prováveis, aceito
- T-02 · onda 1 — Falha cruzada do StockSalesOverviewIntegrationTest corrigida em 69e1122 com trailer T-02
- onda 1 · onda 1 — Validador interrompido por reinício e retomado; § Gate regravados
- T-05 · onda 2 — Cookie-jar admin criado pelo orquestrador e repassado só para leitura
- T-07 · onda 2 — RED que tocou SubmissionTokenTest.php além dos declarados, aceito
- T-08 · onda 2 — RED que tocou ReceivableSaleOriginTest.php além dos declarados, aceito
- T-09 · onda 2 — RED que tocou SaleRepositoryCancelIntegrationTest.php e SaleServiceTest.php além dos declarados, aceito
- onda 2 · onda 2 — prepare-mysql57 e .verify.sql não rodados (a onda não tocou database/scripts)
- T-11 · onda 3 — [plano-mandou] 1205 vinha do claim; o teste B chama register sem token, com sensibilidade provada (bcfe53f)
- T-12 · onda 3 — 94f6705 ajustou 1 linha do RED (ACTION), aceito
- onda 3 · onda 3 — prepare-mysql57 e .verify.sql não rodados (ruling vigente)
- T-13 · onda 4 — assertTenant antes do claim; forma desconhecida lança InvalidArgumentException sem chave da T-01
- T-14 · onda 4 — fcffd4a trocou 1 linha do RED (ACTION), aceito
- T-14 · onda 4 — Unidade da venda passada ao cancelReceivable; action sem hífen
- T-14 · onda 4 — Sem caixa, a exceção sai depois da devolução de estoque; o rollback fica com o chamador (T-16/T-17)
- onda 4 · onda 4 — prepare-mysql57 e .verify.sql não rodados (ruling vigente)
- T-15 · onda 5 — RED 4c35afe que tocou SaleFormCheckoutWiringTest.php além dos declarados, aceito
- T-15 · onda 5 — Colunas Qty/Unit price/Total do carrinho à esquerda (CLAUDE.md), fix 1606c82
- T-16 · onda 5 — Runbook sem número fixo de programas; conferência por grep/SELECT (138e5e3)
- T-16 · onda 5 — Contrato da T-14 (rollback no onCancel) atendido
- onda 5 · onda 5 — Seed tocado: prepare-mysql57 e SELECT do .verify.sql no centralvet_test com 0 violações
- T-17 · onda 6 — 0016 e seed aplicados no dev com autorização; .verify.sql com 0 violações; nginx -s reload
- T-17 · onda 6 — Cookie-jar admin recriado duas vezes (sessão anterior ao seed)
- T-17 · onda 6 — Fumaça do cancelamento rodada pelo orquestrador com confirmação do usuário; limpeza zz_rows=0
- T-17 · onda 6 — Cenários sem caixa e de caixa fechado não rodados no navegador (cobertos pelos testes)
- T-16 · onda 6 — Correção 2: o botão Cancelar venda renderiza como TElement (1d3563a, 5c0bcdd)
- T-16 · onda 6 — SaleListActionCellTest.php fora dos arquivos prováveis, aceito (correção pedida pelo usuário)
- T-17 · onda 6 — No cenário (c), o 1205 vem do claim; mesma limitação de T-06/T-08/T-09

## Achados
- [sugestão] Corrida entre o fechamento do caixa e um pagamento ou estorno: close lê a sessão sem FOR UPDATE, e register/cancelReceivable leem o caixa sem bloqueio. Um recebimento ou estorno simultâneo ao fechamento pode cair num caixa já fechado. É pré-existente e fica fora do Incluso (que bloqueia lote, recebível e venda); candidato à Etapa 3 → CashSessionService.php:127; PaymentService.php:130; PaymentReversalService.php:97
- [sugestão] SaleList e a cortina SaleCancelForm::onEdit mostram vendas de todas as unidades do tenant. O cancelamento é limitado à unidade, a leitura não → SaleFinanceReader.php:59-80; SaleCancelForm.php:87
- [sugestão] actionCell mostra "Cancelar venda" também a quem tem SaleList sem SaleCancelForm; o clique dá "Permissão negada" → SaleList.php:185-205
- [sugestão] CLAUDE.md: as colunas Total e Paid da PendingReceivableList seguem 'right'. O arquivo foi tocado na T-11 e há o precedente do ruling da T-15 → PendingReceivableList.php:100-101
- [sugestão] Cancelar venda gravada antes da T-09 devolve o estoque na ordem do carrinho e pode dar 1213 contra um checkout. É seguro (rollback e mensagem), mas não está registrado → StockService.php:215-245
- [sugestão] Duplo envio da venda que espera mais que o lock wait cai em 1205 e mostra "O estoque mudou durante a venda", não "já processado" → SaleForm.php:614-631
- [sugestão] O estorno vira financial_entry expense, e totalsByType/incomeByCategory mostram receita bruta mais despesa, não o líquido da venda cancelada → FinancialOverviewReader.php:46-95
- [sugestão] SaleFormPricingTest.php está no Mapa como "modificar", mas não aparece no diff (wiring mantido sem precisar mudar?) → plan.md § Mapa de arquivos

## Decisões que tomei
- Nenhuma pendência foi promovida. Os itens de concorrência que restam (1205 sem isolar o lock, falta de teste 1213) são lacunas de teste: o código tem FOR UPDATE nos lotes, no recebível e na venda, UPDATE condicional na baixa e ordem crescente de product_id. Os itens de idempotência (nome do índice no 1062, attach) falham fechados: sobe exceção, tudo é desfeito e nada é gravado em dobro.
- F3 aceita: checkout, recebível, recebimento e lançamento ficam na mesma TTransaction do SaleForm, com rollback em todo catch. O rollback do cancelamento sem caixa está no SaleCancelForm::onCancel e foi provado no MySQL (:361).
- CSRF e RBAC aceitos: onCancel não é prefixo de leitura (POST + token, SaleCancelFormWiringTest:22), há programa próprio no seed para o grupo 1, e o serviço autoriza na unidade da venda com action SaleCancelForm::onCancel.
- O token ausente vira '' e é recusado (PaymentForm.php e SaleForm.php passam `?? ''`): não dá para pular a deduplicação omitindo o campo.
- A corrida no fechamento do caixa ficou como sugestão porque é pré-existente e está fora do Incluso, mas é o risco de dinheiro mais relevante que sobra para o MVP.

## Pendências
- Abertas: as 35 [aberta] da Triagem e as 8 sugestões acima.
- Não verificado por mim: SUITE 1995/1995, prepare-mysql57/.verify.sql, fumaça no :8081 (status 200 do POST onCancel), flaky do ExamResultAiIntegrationTest, rebuild da imagem.
