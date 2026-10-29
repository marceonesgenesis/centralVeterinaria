# Plano: Etapa 2 — Dinheiro e estoque (F2, F3, F3b) e pendências de segurança P1–P3

## Objetivo
Fechar a Etapa 2 da auditoria `.docs/audits/2026-10-09-prontidao-mvp.md`: estoque e recebimento sem corrida nem duplicidade (F2), venda avulsa à vista ou a prazo ligada a recebível, recebimento e caixa (F3), cancelamento rastreável de venda com estorno (F3b). Na mesma branch, três pendências de segurança da Etapa 1: `request_order="GP"` (P1), `orderColumn` restrito às colunas pesquisadas (P2) e a regra do `class_alias` dentro da `MutationRequestPolicy` (P3).

## Premissas
- Branch base `task/etapa-1-seguranca-mvp` (HEAD `363582a`), por pedido explícito do desenvolvedor; produção é `main`.
- A entrega preserva os commits por onda: a Fase 5 não roda o desfazer do `entrega.py`.
- Migrations compatíveis com MySQL 5.7 (prefixo `20-` na cópia preparada, `.verify.sql` só com SELECT). Nenhum SQL contra dev/produção sem autorização expressa. Os testes usam o `centralvet_test` do runner. Aplicar a 0016 nesse banco foi autorizado pelo usuário (T-02).
- À vista: a venda finalizada com forma de pagamento informada recebe o total de uma vez, no caixa aberto da unidade. Sem forma informada, o recebível fica em aberto e o recebimento parcial ou total segue pelo `PaymentForm` existente.
- O estorno de recebimento entra no caixa aberto atual da unidade (tabela `payment_reversal` + lançamento `expense`). O caixa original, já fechado, não é reescrito.
- A transação continua aberta pelo formulário (`TTransaction::open`, que chama `beginTransaction`). O caso de uso exige a transação aberta: a primeira leitura com bloqueio ou o `claim` da chave de envio lança `LogicException('... requires an open transaction')` fora dela. O rollback é do formulário.
- O teste de fumaça no navegador pelo validador roda no banco de dev, com dados `ZZ-` criados e excluídos por ele e o cookie-jar fornecido pelo orquestrador. A 0016 e o seed no dev foram autorizados pelo usuário só para essa fumaça (T-17).
- Regras de UI do `CLAUDE.md`: rótulos e cabeçalhos de grid à esquerda, alvos de toque de 44 px. Mensagens em pt-BR via `translations.json` com `^1..^4`.

## Escopo

### Incluso
- F2 estoque: leitura dos lotes com `SELECT ... FOR UPDATE` e baixa por `UPDATE` condicional (`quantity >= :q`) no `StockService::consume`, transação obrigatória (T-06). Consumo em ordem crescente de `product_id` (T-09).
- F2 recebimento: `findByIdForUpdate` do recebível no `PaymentService::register` (T-08, T-11).
- F2 idempotência: tabela `submission_key` e token por envio de formulário de venda e de recebimento (T-02, T-07, T-11, T-13, T-15).
- F2 aceite: duas vendas da última unidade não têm sucesso juntas; o mesmo pagamento repetido não duplica o recebimento; falha em qualquer escrita reverte tudo. Testes com duas conexões no MySQL de teste (T-06, T-07, T-11, T-17).
- F3: a venda gera o recebível vinculado (`receivable.sale_id`). À vista, registra o recebimento e a entrada no caixa aberto na mesma transação; a prazo, o recebível fica em aberto (T-02, T-08, T-13, T-15).
- F3 recibo: o recibo é montado com os dados gravados (`sale_item` + recebimentos menos estornos) e só mostra valor recebido quando existe recebimento (T-10, T-15).
- F3 coerência: lista de vendas com situação financeira (T-16) e coluna Origem na lista de recebíveis (T-11). Os totais de caixa descontam os estornos (T-08).
- F3b: cancelamento de venda (devolve estoque, cancela o recebível, estorna recebimento e caixa, grava quem, quando e motivo), idempotente, com RBAC (programa `SaleCancelForm`), POST + token e entrada no inventário CSRF (T-09, T-12, T-14, T-16).
- P1: `request_order = "GP"` em `docker/php/conf.d/app.ini`, teste do arquivo e seção no runbook da hospedagem compartilhada (T-05).
- P2: `SearchParameterGuard` nega `orderColumn` fora de `column`/`key` (T-03).
- P3: alias do framework aceito em `MutationRequestPolicy::resolveCanonical` e `resolveDispatchTarget` removido. `enforceMutationGuard` passa a chamar `decideFromRequest` com 3 argumentos (T-04).
- Mensagens pt-BR novas (T-01).

### Excluído
- F4/F5 (contratação, assinatura, recorrência, baixa externa).
- S5 (dependências) e S6 (downloads genéricos).
- Revogação de sessões após reset de senha.
- Modo `template=iframe` sem `cv-shell.js`.
- Timing do SMTP no reset de senha.
- Recebimento parcial no próprio PDV (à vista é o total; o parcial segue pelo `PaymentForm`).
- Cancelamento de recebível de conta de atendimento.

## Contexto técnico
- Camadas envolvidas: database, backend, frontend, infra, shared, docs, qa
- Projeto/base analisada: `/var/www/html/centralvet` (raiz do repositório, `git -C <DIR> rev-parse --show-toplevel`); código em `src/app/Core` (namespace `CentralVet\`), telas em `src/app/control/clinic`, testes em `src/tests` (runner próprio `tests/run.php`, container `app`, PHP 8.4)
- Integrações: nenhuma

## Baseline
- php-lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>` nos 36 arquivos PHP existentes que o plano modifica, em raiz → baseline/php-lint.txt (0 linhas)

## Exploração read-only
- Caminhos relevantes: `src/app/Core/Application/{PaymentService,StockService,SaleService,CashSessionService,FinancialEntryService}.php`; `src/app/Core/Persistence/{ReceivableRepository,StockBatchRepository,StockMovementRepository,SaleRepository,FinancialOverviewReader,EncounterRepository}.php` (`EncounterRepository::lockForDraft` é o padrão de `FOR UPDATE` com `inTransaction()`); `src/app/control/clinic/{SaleForm,PaymentForm,PendingReceivableList}.php`; `src/app/database/migrations/` (última 0015); `scripts/test-db/provision.sh`; `src/tests/Support/MysqlIntegrationTestCase.php`; `src/tests/Integration/SurgeryRepositoryIntegrationTest.php` (`secondConnection()`, erro 1205); `src/app/Core/Security/{SearchParameterGuard,MutationRequestPolicy}.php`; `src/app/service/auth/ApplicationAuthenticationService.php`; `docker/php/conf.d/app.ini`; `docs/security/csrf-inventario.md`; `docs/runbooks/shared-hosting-mysql57.md`
- Padrões identificados: repositórios `(TenantContext, PDO)` com `tenantQuery()`; fakes em memória em `src/tests/Support/Fake*Repository.php`; exceções de domínio em `CentralVet\Domain\Exception`; mensagens cruas em inglês → `UserMessage` → `_t()`; RBAC por programa Adianti (`Classe::metodo`, método liberado salvo negação explícita); migrations `YYYYMMDD_NNNN_slug.sql` + `.verify.sql` com CHECK nomeado (`ADD CONSTRAINT ... CHECK`), ampliação de CHECK por `DROP CHECK` + `ADD CONSTRAINT`; testes MySQL em transação com rollback e guarda contra commit
- Scripts úteis: LINT e SUITE no cabeçalho de `tasks.md`; `python3 scripts/prepare-mysql57.py <dir> <dir>/out`; `scripts/test-db/provision.sh --check` (sem docker nem SQL)
- Riscos identificados: `receivable.encounter_account_id` é NOT NULL e UNIQUE (vira NULL com CHECK de origem exclusiva); `StockService` tem 4 chamadores de `consume` além da venda, todos dentro de `TTransaction`; os construtores de `PaymentService` (2 telas e testes) e `StockService` (6 pontos) são compartilhados, e o de `StockService` não muda; `FakeReceivableRepository` devolve a mesma instância (não simula corrida); coluna ausente no `centralvet_test` dá FAIL, não SKIP; o container :8081 serve a imagem (mudanças de UI só após rebuild); o código novo sem a 0016 aplicada quebra o PDV no dev

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/config/translations.json` | Textos pt-BR das chaves novas | modificar | T-01 |
| `src/app/Core/Presentation/UserMessage.php` | Mensagem crua de domínio → chave | modificar | T-01 |
| `src/tests/Unit/Etapa2MessagesTest.php` | Chaves e mapeamentos da Etapa 2 | criar | T-01 |
| `src/app/database/migrations/20261021_0016_etapa2_sale_finance.sql` | Schema: `receivable.sale_id`, cancelamento em `sale`, `payment_reversal`, `submission_key`, motivo `sale_cancellation` | criar | T-02 |
| `src/app/database/migrations/20261021_0016_etapa2_sale_finance.verify.sql` | Conferência só com SELECT | criar | T-02 |
| `scripts/test-db/provision.sh` | Lista de schema do banco de teste | modificar | T-02 |
| `docs/runbooks/tests.md` | Lista de migrations do banco de teste | modificar | T-02 |
| `docs/runbooks/shared-hosting-mysql57.md` ⚠ | Seção da 0016 (T-02), `request_order` (T-05), contagem de programas do seed (T-16) | modificar | T-02, T-05, T-16 |
| `src/app/Core/Security/SearchParameterGuard.php` | `orderColumn` restrito | modificar | T-03 |
| `src/tests/Unit/SearchParameterGuardTest.php` | Casos de `orderColumn` | modificar | T-03 |
| `docs/security/csrf-inventario.md` ⚠ | Parágrafo do `orderColumn` (T-03); linhas de `SaleList`/`SaleCancelForm` (T-16) | modificar | T-03, T-16 |
| `src/app/Core/Security/MutationRequestPolicy.php` | Regra do alias em `resolveCanonical` | modificar | T-04 |
| `src/app/service/auth/ApplicationAuthenticationService.php` | Chamada de 3 argumentos; remove `resolveDispatchTarget` | modificar | T-04 |
| `src/tests/Unit/MutationRequestPolicyTest.php` | Resolvedor real com alias | modificar | T-04 |
| `src/tests/Unit/MutationGuardWiringTest.php` | Wiring sem resolvedor ad hoc | modificar | T-04 |
| `docker/php/conf.d/app.ini` | `request_order = "GP"` | modificar | T-05 |
| `src/tests/Unit/PhpRequestOrderTest.php` | Lê `app.ini` | criar | T-05 |
| `src/app/Core/Domain/StockMovement.php` | `REASON_SALE_CANCELLATION` | modificar | T-06 |
| `src/app/Core/Domain/Contract/StockBatchRepositoryInterface.php` | Leitura com bloqueio e baixa condicional | modificar | T-06 |
| `src/app/Core/Domain/Contract/StockMovementRepositoryInterface.php` | `listByReference` | modificar | T-06 |
| `src/app/Core/Persistence/StockBatchRepository.php` | `FOR UPDATE`, `UPDATE` condicional | modificar | T-06 |
| `src/app/Core/Persistence/StockMovementRepository.php` | `listByReference` | modificar | T-06 |
| `src/app/Core/Application/StockService.php` | `consume` com bloqueio; `reverseReferenceConsumption` | modificar | T-06 |
| `src/tests/Support/FakeStockBatchRepository.php` | Fake dos métodos novos | modificar | T-06 |
| `src/tests/Support/FakeStockMovementRepository.php` | Fake de `listByReference` | modificar | T-06 |
| `src/tests/Unit/StockServiceTest.php` | Baixa condicional e devolução | modificar | T-06 |
| `src/tests/Integration/StockConcurrencyIntegrationTest.php` | Duas conexões na última unidade | criar | T-06 |
| `src/app/Core/Domain/SubmissionToken.php` | Gera e valida token de envio | criar | T-07 |
| `src/app/Core/Domain/Contract/SubmissionKeyRepositoryInterface.php` | Contrato da chave de envio | criar | T-07 |
| `src/app/Core/Persistence/SubmissionKeyRepository.php` | INSERT único por tenant/escopo/token | criar | T-07 |
| `src/app/Core/Domain/Exception/DuplicateSubmissionException.php` | Envio repetido | criar | T-07 |
| `src/tests/Support/FakeSubmissionKeyRepository.php` | Fake em memória | criar | T-07 |
| `src/tests/Unit/SubmissionTokenTest.php` | Formato do token | criar | T-07 |
| `src/tests/Integration/SubmissionKeyRepositoryIntegrationTest.php` | Unicidade e bloqueio entre conexões | criar | T-07 |
| `src/app/Core/Domain/Receivable.php` | `openForSale`, `saleId`, `cancel`, recusa pagamento em cancelado | modificar | T-08 |
| `src/app/Core/Domain/Contract/ReceivableRepositoryInterface.php` | `findByIdForUpdate`, `findBySaleId` | modificar | T-08 |
| `src/app/Core/Persistence/ReceivableRepository.php` | `sale_id`, `FOR UPDATE` | modificar | T-08 |
| `src/tests/Support/FakeReceivableRepository.php` | Fake dos métodos novos | modificar | T-08 |
| `src/app/Core/Domain/PaymentReversal.php` | Entidade do estorno | criar | T-08 |
| `src/app/Core/Domain/Contract/PaymentReversalRepositoryInterface.php` | Contrato do estorno | criar | T-08 |
| `src/app/Core/Persistence/PaymentReversalRepository.php` | Persistência do estorno | criar | T-08 |
| `src/tests/Support/FakePaymentReversalRepository.php` | Fake do estorno | criar | T-08 |
| `src/app/Core/Domain/Exception/CashSessionRequiredException.php` | Caixa fechado ao receber ou estornar | criar | T-08 |
| `src/app/Core/Application/CashSessionService.php` | Totais por forma menos estornos | modificar | T-08 |
| `src/app/Core/Persistence/FinancialOverviewReader.php` | Saldo do caixa aberto menos estornos | modificar | T-08 |
| `src/tests/Unit/ReceivableSaleOriginTest.php` | Recebível de venda e cancelamento | criar | T-08 |
| `src/tests/Integration/PaymentReversalIntegrationTest.php` | Totais e saldo líquidos; `sale_id` e bloqueio | criar | T-08 |
| `src/app/Core/Domain/Sale.php` | `cancel(reason, user, at)` e campos de cancelamento | modificar | T-09 |
| `src/app/Core/Domain/Contract/SaleRepositoryInterface.php` | `findByIdForUpdate` | modificar | T-09 |
| `src/app/Core/Persistence/SaleRepository.php` | Colunas de cancelamento, `FOR UPDATE` | modificar | T-09 |
| `src/tests/Support/FakeSaleRepository.php` | Fake de `findByIdForUpdate` | modificar | T-09 |
| `src/app/Core/Application/SaleService.php` | Consumo em ordem de `product_id` | modificar | T-09 |
| `src/tests/Unit/SaleCancelTest.php` | Regras de `Sale::cancel` | criar | T-09 |
| `src/tests/Unit/SaleServiceTest.php` | Ordem de consumo | modificar | T-09 |
| `src/tests/Integration/SaleRepositoryCancelIntegrationTest.php` | Ida e volta do cancelamento e bloqueio | criar | T-09 |
| `src/app/Core/Persistence/SaleFinanceReader.php` | Lista de vendas e resumo do recibo | criar | T-10 |
| `src/tests/Integration/SaleFinanceReaderIntegrationTest.php` | Valores recebidos líquidos | criar | T-10 |
| `src/app/Core/Application/PaymentService.php` | `register` com bloqueio e token | modificar | T-11 |
| `src/app/control/clinic/PaymentForm.php` | Token oculto, wiring, mensagens | modificar | T-11 |
| `src/app/control/clinic/PendingReceivableList.php` | Wiring e coluna Origem | modificar | T-11 |
| `src/tests/Unit/PaymentServiceTest.php` | Construtor novo e duplicidade | modificar | T-11 |
| `src/tests/Unit/PaymentFormSubmissionTokenTest.php` | Fonte do formulário | criar | T-11 |
| `src/tests/Integration/PaymentIdempotencyIntegrationTest.php` | Repetição e corrida de recebimento | criar | T-11 |
| `src/app/Core/Application/PaymentReversalService.php` | Estorna e cancela o recebível | criar | T-12 |
| `src/tests/Unit/PaymentReversalServiceTest.php` | Estorno idempotente | criar | T-12 |
| `src/app/Core/Application/SaleCheckoutService.php` | Venda + recebível + recebimento | criar | T-13 |
| `src/app/Core/Application/SaleCheckoutResult.php` | Resultado do checkout | criar | T-13 |
| `src/tests/Unit/SaleCheckoutServiceTest.php` | À vista, a prazo, duplicidade, sem caixa | criar | T-13 |
| `src/app/Core/Application/SaleCancellationService.php` | Cancelamento rastreável | criar | T-14 |
| `src/app/Core/Application/SaleCancellationResult.php` | Resultado do cancelamento | criar | T-14 |
| `src/tests/Unit/SaleCancellationServiceTest.php` | Devolução, estorno, idempotência | criar | T-14 |
| `src/app/control/clinic/SaleForm.php` | Forma de pagamento, token, checkout, recibo dos dados gravados | modificar | T-15 |
| `src/app/Core/Presentation/SaleReceiptHtml.php` | HTML do recibo sem Adianti | criar | T-15 |
| `src/tests/Unit/SaleReceiptHtmlTest.php` | Recibo não indica recebimento inexistente | criar | T-15 |
| `src/tests/Unit/SaleFormPricingTest.php` | Wiring mantido | modificar | T-15 |
| `src/tests/Unit/SaleFormCheckoutWiringTest.php` | Fonte do checkout no formulário | criar | T-15 |
| `src/app/control/clinic/SaleList.php` | Histórico de vendas com situação financeira | criar | T-16 |
| `src/app/control/clinic/SaleCancelForm.php` | Cortina de cancelamento (POST) | criar | T-16 |
| `src/app/lib/widget/CvNav.php` | Aba "Histórico de vendas" | modificar | T-16 |
| `src/app/database/seeds/initial-application-programs.sql` | Programas `SaleList` e `SaleCancelForm` | modificar | T-16 |
| `src/tests/Unit/SaleCancelFormWiringTest.php` | Fonte, seed, inventário, aba | criar | T-16 |
| `src/tests/Integration/SaleCheckoutFlowIntegrationTest.php` | Fluxos ponta a ponta no MySQL | criar | T-17 |

Os três arquivos ⚠ são serializados em ondas diferentes: o runbook nas Ondas 1, 2 e 5 e o inventário nas Ondas 1 e 5.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Bloqueio `FOR UPDATE` na leitura dos lotes e do recebível + `UPDATE` condicional do estoque | Coluna `version` com CAS; só `UPDATE` condicional | Segue o padrão já usado (`EncounterRepository::lockForDraft`) e serializa a decisão FEFO inteira, sem migration de versão. O condicional na baixa é a segunda barreira, barata |
| Transação aberta pelo formulário; o caso de uso exige `inTransaction()` e lança `LogicException` | Serviço abre a própria transação ou `SAVEPOINT` | `TTransaction::open` já faz `beginTransaction` e todos os repositórios dividem o PDO. Uma transação aninhada conflitaria com o Adianti |
| Tabela `submission_key` (UNIQUE tenant+escopo+token), `claim` no início do caso de uso | Coluna de idempotência em `sale`/`payment`; cache Redis | O INSERT único bloqueia o envio concorrente até o primeiro commit e cai no 1062 depois dele. O rollback devolve a chave, e serve a venda e o recebimento com um só contrato |
| `SaleCheckoutService` novo orquestra `SaleService` + recebível + `PaymentService` | Ampliar `SaleService::create` (10 dependências) | Mantém o construtor de `SaleService` e os testes existentes. A finalização com financeiro fica num caso de uso próprio |
| `receivable.sale_id` NULL + `encounter_account_id` NULL com CHECK de origem exclusiva | Tabela ponte venda↔recebível | `PaymentService`/`PaymentForm`/lista de recebíveis passam a valer para a venda sem novo JOIN. As consultas com `INNER JOIN encounter_account` só deixam de ver os recebíveis de venda |
| Estorno em `payment_reversal` (UNIQUE `payment_id`) no caixa aberto atual + `financial_entry` `expense` | Apagar o `payment`; valor negativo em `payment` | Rastreável e idempotente, e o caixa já fechado não muda. `payment_amount_ck` proíbe valor negativo |
| Cancelamento por programa próprio `SaleCancelForm` (cortina, `onCancel` POST) | Método `SaleList::onCancel` | O RBAC do Adianti é por programa (método só se nega explicitamente): o programa separado permite liberar a venda sem liberar o cancelamento |
| Recibo montado de `sale_item` + `SaleFinanceReader::receiptSummary`, em `SaleReceiptHtml` testável | Snapshot do carrinho na sessão | O recibo não pode indicar dinheiro sem recebimento; também resolve a pendência T-08 da Etapa 1 (snapshot divergente) |
| Consumo de produtos em ordem crescente de `product_id` | Ordem do carrinho | Duas vendas com A,B e B,A pegam os bloqueios na mesma ordem, sem deadlock 1213 |

## Diagrama de dependências

```text
T-02 → T-05, T-06, T-07, T-08, T-09, T-10
T-01, T-07, T-08 → T-11
T-01, T-08 → T-12
T-06, T-07, T-08, T-09, T-11 → T-13
T-06, T-08, T-09, T-12 → T-14
T-01, T-10, T-13 → T-15
T-01, T-03, T-10, T-14 → T-16
T-13, T-14, T-15, T-16 → T-17
T-04 (sem dependentes)
```

## Estratégia de execução
- Branch de trabalho: `task/etapa-2-dinheiro-estoque`
- Branch base: `task/etapa-1-seguranca-mvp`
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`) com o trailer da task. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Por premissa do desenvolvedor, a entrega da Fase 5 preserva os commits por onda (não os desfaz); push e PR só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task; os arquivos ⚠ estão em ondas diferentes)
- Pré-condição da Onda 2: a 0016 aplicada ao `centralvet_test` (T-02, autorizada pelo usuário). Sem ela, todo teste de integração das Ondas 2–6 sai FAIL por coluna ausente.

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04

### Onda 2
- T-05, T-06, T-07, T-08, T-09, T-10

### Onda 3
- T-11, T-12

### Onda 4
- T-13, T-14

### Onda 5
- T-15, T-16

### Onda 6
- T-17

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Maquiavel | general-purpose | inherit | T-01 |
| Darwin | general-purpose | inherit | T-02 |
| Sherlock | general-purpose | inherit | T-03, T-04 |
| Kratos | general-purpose | inherit | T-05, T-16 |
| Goku | general-purpose | inherit | T-06 |
| Naruto | general-purpose | inherit | T-07 |
| Thanos | general-purpose | inherit | T-08, T-11 |
| Batman | general-purpose | inherit | T-09, T-14 |
| Saitama | general-purpose | inherit | T-10, T-17 |
| Hegel | general-purpose | inherit | T-12 |
| Athena | general-purpose | inherit | T-13 |
| Tesla | general-purpose | inherit | T-15 |

## Review Focus
- Venda só com itens de preço 0 e forma de pagamento informada → recebível `paid` com `total_cents = 0`, nenhuma linha em `payment` (`payment_amount_ck`), recibo com "No payment received" → T-13
- Venda à vista sem caixa aberto na unidade → `CashSessionRequiredException` com `Open a cash session for this unit before receiving payment` antes de gravar venda, item, movimento ou recebível → T-13
- Cancelamento de venda paga num caixa já fechado → `payment_reversal.cash_session_id` = caixa aberto atual; `totalsByPaymentMethod` do caixa fechado inalterado → T-12
- Duas vendas simultâneas com os mesmos dois produtos em ordem inversa no carrinho → movimentos gravados em ordem crescente de `product_id` nas duas, sem deadlock 1213 → T-09
- `PaymentForm` em recebível de venda cancelada → `InvalidStatusTransitionException` `This receivable is cancelled and cannot receive payments`, nenhuma linha nova em `payment` → T-11

## Critérios gerais de aceite
- SUITE completo (cabeçalho de `tasks.md`) com MySQL no ar e a 0016 no `centralvet_test` imprime `Failed: 0` e nenhuma classe de integração nova em `SKIP`.
- LINT de cada arquivo PHP tocado imprime `No syntax errors detected`; nenhuma linha nova em relação a `baseline/php-lint.txt` (0 linhas).
- `python3 scripts/prepare-mysql57.py` gera a cópia `20-` da 0016 sem erro, e o `.verify.sql` aplicado ao `centralvet_test` mostra 0 violações.
- Nenhum arquivo de `src/app/config/framework_hashes.php` alterado (`git diff --name-only task/etapa-1-seguranca-mvp...HEAD` sem `engine.php`, `index.php`, `init.php`, `src/lib/adianti/`, `layout.html`, `custom.css`).
