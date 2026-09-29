# Plano: Fase 5 — Financeiro integrado

## Objetivo
Fazer o atendimento fechar financeiramente: consolidar automaticamente numa conta os itens cobráveis já gerados pelo atendimento (procedimentos executados, exames solicitados), permitir desconto autorizado, gerar um recebível, registrar pagamentos parciais em múltiplas formas contra um caixa aberto por unidade, e manter contas a pagar e lançamentos financeiros simples. Fecha o critério de saída do PRD para a Fase 5 ("Atendimento fecha financeiramente").

## Premissas
- "Conta do atendimento" é um agregado novo (`encounter_account` + `encounter_account_item`), distinto de `sale`/`sale_item` (Fase 4, PDV) — a PDV continua exatamente como está, sem alteração de schema ou comportamento. Padrão de dados do PRD (seção 13) não lista uma entidade "conta" explícita, mas a seção 8.19 a nomeia como feature própria ("Conta do atendimento e itens automáticos") distinta de "Vendas/PDV" (8.18) — mesmo tipo de decisão de modelagem já tomada na Fase 3 ao criar `VaccineProtocolService` além do que a seção 13 listava.
- `encounter_account_item` só é preenchido automaticamente com itens que já têm preço capturável hoje: execuções de procedimento (`procedure_execution` → `procedure_catalog_item.price_cents`, Fase 4) e solicitações de exame (`exam_request` → `exam_catalog_item.price_cents`, Fase 3). Vacinação (`vaccine_catalog_item` não tem preço) e prescrição (medicamento é texto livre, sem catálogo) NÃO entram automaticamente — podem virar item manual (`source_type='manual'`) se o profissional quiser cobrar, decisão explícita no momento de fechar a conta, não uma tabela de preço nova para vacina/medicamento. Mesma lógica de simplificação documentada já usada nas Fases 3/4.
- Venda avulsa do PDV (`sale` sem `encounter_id`, ou mesmo com `encounter_id`, Fase 4) continua sem fluxo de recebível/pagamento nesta fase — só conta de atendimento gera `receivable`. `receivable.encounter_account_id` é `NOT NULL`, sem polimorfismo. Extensão do PDV para também gerar recebível fica para trabalho futuro.
- `payment` tem uma lista fechada e simples de formas de pagamento (dinheiro, débito, crédito, pix, transferência), sem integração real com gateway/adquirente — fora de escopo desta versão do PRD.
- `cash_session` é por unidade, com abertura (saldo inicial) e fechamento (saldo final informado pelo operador), sem conciliação automática de divergência — isso fica para uma fase futura de relatórios/auditoria financeira mais madura. Só um caixa aberto por unidade por vez — checado pela Application, não por constraint de banco (mesmo padrão de simplificação já usado em outras fases).
- `payable` (contas a pagar) e `financial_entry` (lançamento de receita/despesa) são independentes da conta do atendimento — lançamentos operacionais simples, sem workflow de aprovação ou recorrência. `payment`/`payable.pay()` geram automaticamente um `financial_entry` correspondente (receita/despesa), o usuário não lança isso duas vezes.
- Desconto na conta do atendimento (`EncounterAccountService::applyDiscount()`) exige uma autorização própria via `AuthorizationPolicyInterface`, distinta da autorização padrão de fechar/pagar conta — "descontos por permissão" do PRD (8.19) é uma ação nomeada separadamente, não a mesma checagem de unidade de sempre. O mecanismo exato de granularidade por método (`SystemUser::getMethods()`, visto mas não aprofundado nas fases anteriores) é investigado e registrado por T-12 (RBAC) no momento de registrar as telas.
- "Relatórios" citado no roadmap da Fase 5 fica reduzido a uma consulta simples de fechamento de caixa (totais por forma de pagamento de uma `cash_session`, exibidos na própria tela de fechamento) — um módulo de relatórios completo com filtros/exportação é a seção 8.24 do PRD, fora de escopo desta fase.
- Toda tela nova usa `RbacAuthorizationService`/`AuthorizationPolicyInterface` desde a criação, com a unidade real do recurso de origem como `resourceUnitId` — mesmo padrão maduro das Fases 1-4.
- Toda tela nova é registrada em `system_program`/`system_group_program` além de `menu.xml` — lição repetida desde a Fase 1.
- Projeto não é repositório Git; DDL/DML no MySQL exige autorização SQL explícita do usuário antes de executar (backup, checksum SHA-256, usuário `centralvet_migrator` dedicado), inclusive para os `INSERT`s de RBAC.

## Escopo

### Incluso
- Migration com 7 tabelas: `encounter_account`, `encounter_account_item`, `receivable`, `payment`, `cash_session`, `payable`, `financial_entry`.
- `EncounterAccountService`: abrir/obter a conta de um atendimento, adicionar itens automáticos (procedimento/exame ainda não cobrados), adicionar item manual, aplicar desconto (autorização própria), fechar (gera `Receivable`), cancelar.
- `CashSessionService`: abrir caixa por unidade (recusa se já houver um aberto), fechar caixa com saldo informado, totais por forma de pagamento no fechamento.
- `PayableService`/`FinancialEntryService`: CRUD de conta a pagar, marcar como paga (gera lançamento), lançamento manual de receita/despesa.
- `PaymentService`: registrar pagamento contra um recebível, parcial ou total, só contra caixa aberto da unidade correta, nunca ultrapassa o total devido, atualiza status do recebível, gera lançamento financeiro.
- Telas: conta do atendimento (itens, desconto, fechar), abertura/fechamento de caixa, contas a pagar, lançamentos financeiros, registrar pagamento.
- Conectar a tela de conta do atendimento ao `EncounterView`.
- Registro das telas novas em `menu.xml` e RBAC nativo, incluindo a permissão específica de desconto.
- Testes automatizados da lógica de fechamento de conta, pagamento parcial/total, recusa de sobre-pagamento, e caixa único aberto por unidade.
- Revisão final de segurança e quality gate.

### Excluído
- Alteração de schema ou comportamento de `sale`/`sale_item` (Fase 4) — PDV continua exatamente como está.
- Geração automática de recebível para venda avulsa do PDV.
- Integração real com gateway/adquirente de pagamento.
- Conciliação automática de divergência de caixa.
- Workflow de aprovação/recorrência de contas a pagar.
- Módulo de relatórios financeiros completo com filtros/exportação (PRD 8.24).
- Tabela de preço para vacina ou catálogo de medicamento para prescrição.

## Contexto técnico
- Camadas envolvidas: database, backend, frontend, infra (RBAC/menu), shared (testes).
- Projeto/base analisada: `/var/www/html/centralvet` (Adianti Framework 8.6, PHP 8.4, MySQL 8, Docker Compose, sem bind-mount de `app`/`worker`).
- Integrações: nenhuma nova; reaproveita `CentralVet\Tenancy\TenantContext`, `CentralVet\Authorization\RbacAuthorizationService` (Fase 0-4).

## Exploração read-only
- Caminhos relevantes: `src/app/Core/Application/` (SaleService, ProcedureExecutionService, ExamService, ProcedureCatalogService — padrão de autorização e de service dependendo de outro service), `src/app/control/clinic/EncounterView.php` (método `inlineActionsPanel()`/array `$kinds`, ponto de conexão da conta do atendimento, mesma técnica das Fases 3/4), `src/app/control/clinic/SaleForm.php` (padrão de tela com carrinho/TDataGrid e captura de exceções de domínio), `src/app/Core/Domain/Contract/` (14+ contratos existentes, padrão a seguir).
- Padrões identificados: `TStandardForm`/`TPage` com `resolveTenantContext()` duplicado por controller; Application service sempre recebe as interfaces de Repository/Authorization no construtor; exceções de domínio capturadas como `TMessage`; toda tela nova precisa de `system_program`+`system_group_program` além de `menu.xml`; `SystemUser::getMethods()` sugere granularidade de permissão por método, a investigar por T-12.
- Scripts úteis: `src/tests/run.php` (runner sem PHPUnit, 146 testes atuais); `docker compose exec app php tests/run.php`; `docker compose build app worker && docker compose up -d app worker` (obrigatório após qualquer arquivo novo, sem bind-mount).
- Riscos identificados: `EncounterView.php` é um arquivo grande e sensível a edição paralela — a conexão da conta do atendimento é isolada em task própria, de escritor único, depois de a tela de conta estar pronta. `system_program`/`system_group_program` não têm `AUTO_INCREMENT` — ids calculados via `MAX(id)+1` só no momento de aplicar, por SELECT. `PaymentService` depende de `Receivable` (T-03), `CashSession` (T-04) e `FinancialEntry` (T-05) simultaneamente — não pode iniciar antes das três.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20260925_0006_phase5_financial.sql` | Migration das 7 tabelas da Fase 5 | criar | T-01 |
| `src/app/database/migrations/20260925_0006_phase5_financial.verify.sql` | Verificação read-only pós-migration | criar | T-01 |
| `src/app/Core/Domain/Contract/EncounterAccountRepositoryInterface.php` | Contrato de conta do atendimento | criar | T-02 |
| `src/app/Core/Domain/Contract/EncounterAccountItemRepositoryInterface.php` | Contrato de item da conta | criar | T-02 |
| `src/app/Core/Domain/Contract/ReceivableRepositoryInterface.php` | Contrato de recebível | criar | T-02 |
| `src/app/Core/Domain/Contract/CashSessionRepositoryInterface.php` | Contrato de sessão de caixa | criar | T-02 |
| `src/app/Core/Domain/Contract/PaymentRepositoryInterface.php` | Contrato de pagamento | criar | T-02 |
| `src/app/Core/Domain/Contract/PayableRepositoryInterface.php` | Contrato de conta a pagar | criar | T-02 |
| `src/app/Core/Domain/Contract/FinancialEntryRepositoryInterface.php` | Contrato de lançamento financeiro | criar | T-02 |
| `src/app/Core/Domain/EncounterAccount.php` | Entidade de domínio conta | criar | T-03 |
| `src/app/Core/Domain/EncounterAccountItem.php` | Entidade de domínio item da conta | criar | T-03 |
| `src/app/Core/Domain/Receivable.php` | Entidade de domínio recebível | criar | T-03 |
| `src/app/Core/Persistence/EncounterAccountRepository.php` | Persistência de conta | criar | T-03 |
| `src/app/Core/Persistence/EncounterAccountItemRepository.php` | Persistência de item da conta | criar | T-03 |
| `src/app/Core/Persistence/ReceivableRepository.php` | Persistência de recebível | criar | T-03 |
| `src/app/Core/Application/EncounterAccountService.php` | Abrir/itens/desconto/fechar/cancelar conta | criar | T-03 |
| `src/app/Core/Domain/Exception/DiscountExceedsSubtotalException.php` | Exceção de desconto inválido | criar | T-03 |
| `src/app/Core/Domain/CashSession.php` | Entidade de domínio sessão de caixa | criar | T-04 |
| `src/app/Core/Persistence/CashSessionRepository.php` | Persistência de sessão de caixa | criar | T-04 |
| `src/app/Core/Application/CashSessionService.php` | Abrir/fechar caixa | criar | T-04 |
| `src/app/Core/Domain/Exception/CashSessionAlreadyOpenException.php` | Exceção de caixa já aberto | criar | T-04 |
| `src/app/Core/Domain/Payable.php` | Entidade de domínio conta a pagar | criar | T-05 |
| `src/app/Core/Domain/FinancialEntry.php` | Entidade de domínio lançamento financeiro | criar | T-05 |
| `src/app/Core/Persistence/PayableRepository.php` | Persistência de conta a pagar | criar | T-05 |
| `src/app/Core/Persistence/FinancialEntryRepository.php` | Persistência de lançamento financeiro | criar | T-05 |
| `src/app/Core/Application/PayableService.php` | CRUD e pagamento de conta a pagar | criar | T-05 |
| `src/app/Core/Application/FinancialEntryService.php` | Lançamento manual e automático | criar | T-05 |
| `src/app/Core/Domain/Payment.php` | Entidade de domínio pagamento | criar | T-06 |
| `src/app/Core/Persistence/PaymentRepository.php` | Persistência de pagamento | criar | T-06 |
| `src/app/Core/Application/PaymentService.php` | Registrar pagamento contra recebível | criar | T-06 |
| `src/app/Core/Domain/Exception/OverpaymentException.php` | Exceção de pagamento acima do devido | criar | T-06 |
| `src/app/control/clinic/EncounterAccountForm.php` | Tela de conta do atendimento | criar | T-07 |
| `src/app/model/clinic/EncounterAccount.php` | Model Adianti de conta | criar | T-07 |
| `src/app/control/clinic/CashSessionForm.php` | Tela de abertura/fechamento de caixa | criar | T-08 |
| `src/app/control/clinic/CashSessionList.php` | Listagem de sessões de caixa | criar | T-08 |
| `src/app/model/clinic/CashSession.php` | Model Adianti de sessão de caixa | criar | T-08 |
| `src/app/control/clinic/PayableForm.php` | Tela de conta a pagar | criar | T-09 |
| `src/app/control/clinic/PayableList.php` | Listagem de contas a pagar | criar | T-09 |
| `src/app/control/clinic/FinancialEntryForm.php` | Tela de lançamento financeiro | criar | T-09 |
| `src/app/control/clinic/FinancialEntryList.php` | Listagem de lançamentos financeiros | criar | T-09 |
| `src/app/model/clinic/Payable.php` | Model Adianti de conta a pagar | criar | T-09 |
| `src/app/control/clinic/PaymentForm.php` | Tela de registrar pagamento | criar | T-10 |
| `src/app/control/clinic/EncounterView.php` | Conexão com a conta do atendimento | modificar ⚠ | T-11 |
| `src/menu.xml` | Registro das telas novas | modificar | T-12 |
| `src/tests/Unit/EncounterAccountServiceTest.php` | Testes de fechamento de conta e desconto | criar | T-13 |
| `src/tests/Unit/PaymentServiceTest.php` | Testes de pagamento parcial/total e sobre-pagamento | criar | T-13 |
| `src/tests/Unit/CashSessionServiceTest.php` | Testes de caixa único aberto por unidade | criar | T-13 |
| `src/tests/Support/FakeEncounterAccountRepository.php` | Dublê de conta | criar | T-13 |
| `src/tests/Support/FakeEncounterAccountItemRepository.php` | Dublê de item da conta | criar | T-13 |
| `src/tests/Support/FakeReceivableRepository.php` | Dublê de recebível | criar | T-13 |
| `src/tests/Support/FakeCashSessionRepository.php` | Dublê de sessão de caixa | criar | T-13 |
| `src/tests/Support/FakePaymentRepository.php` | Dublê de pagamento | criar | T-13 |
| `src/tests/Support/FakeFinancialEntryRepository.php` | Dublê de lançamento financeiro | criar | T-13 |
| `.tasks/07-fase-5-financeiro-integrado/notes.md` | Parecer final da Fase 5 | modificar | T-14 |

`EncounterView.php` (⚠) é tocado só por T-11, escritor único, depois de T-07 concluída — sem paralelismo de edição sobre ele.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| `encounter_account`/`encounter_account_item` como agregado novo, não reaproveitando `sale`/`sale_item` | Estender `sale.status` com um estado `draft` e reaproveitar `sale_item` para os itens automáticos da conta | Reaproveitar exigiria alterar o CHECK de status e o CHECK de item_type de uma tabela já migrada e em produção (Fase 4), mudando a semântica de um fluxo já testado e fechado (venda sempre imediata/atômica); um agregado novo mantém o PDV intocado e a conta do atendimento com seu próprio ciclo de vida (aberta → itens acumulando → fechada) |
| `receivable.encounter_account_id` como FK única obrigatória, sem ligação com `sale` | FK polimórfica (`encounter_account_id` OU `sale_id`, exatamente um), como o precedente de `sale_item.item_reference_id` na Fase 4 | Escopo desta fase é só a conta do atendimento gerar recebível; abrir a porta para venda avulsa do PDV também gerar recebível é decisão de escopo maior, melhor tratada como extensão futura explícita do que como coluna nullable não usada agora |
| Execução de procedimento/exame NÃO consome estoque de novo ao entrar na conta do atendimento | Repetir a baixa de estoque no fechamento da conta | Estoque já foi consumido no momento da execução (`ProcedureExecutionService::execute()`, Fase 4) ou não é consumido por exame; a conta do atendimento só referencia o valor já gerado, sem nova mutação de estoque |

## Diagrama de dependências

```text
T-01 -> T-02 -> T-03 -> T-06 -> T-10 -> T-12 -> T-14
                 |       ^       ^       ^       ^
                 v       |       |       |       |
                T-04 ----+       |       |       |
                 |               |       |       |
                 v               |       |       |
                T-05 ------------+       |       |
                 |                       |       |
                 v                       |       |
                T-07 -> T-11 ------------+       |
                 |                                |
                T-08 ---------------------------->+
                T-09 ---------------------------->+
                T-13 (T-03,T-04,T-05,T-06) ------->+
```

## Estratégia de execução
- Branch de trabalho: não aplicável — projeto não é repositório Git.
- Branch base: não aplicável.
- Commit por onda: não aplicável (sem Git); registro de progresso feito em `tasks.md`/`notes.md`.
- Isolamento em ondas com edições paralelas: escritor único por arquivo em toda onda; `EncounterView.php` reservado exclusivamente a T-11, depois de T-07 concluída.

## Ondas de execução

### Onda 1
- T-01

### Onda 2
- T-02

### Onda 3
- T-03
- T-04
- T-05

### Onda 4
- T-06

### Onda 5
- T-07
- T-08
- T-09

### Onda 6
- T-10

### Onda 7
- T-11

### Onda 8
- T-12
- T-13

### Onda 9
- T-14

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Athena | general-purpose | herdado | T-01, T-02, T-03, T-06 |
| Jaspion | general-purpose | herdado | T-04, T-05 |
| Tesla | general-purpose | herdado | T-07, T-10 |
| Aang | general-purpose | herdado | T-08, T-09, T-11 |
| Naruto | general-purpose | herdado | T-12 |
| Levi | general-purpose | herdado | T-13, T-14 |

## Critérios gerais de aceite
- `docker compose exec app php tests/run.php` termina em `Failed: 0` com o número de testes anterior (146) mais os novos desta fase, sem regressão.
- Nenhum controller acessa `Persistence`/`Domain` diretamente — sempre via `Core/Application`.
- Toda Application service nova chama `AuthorizationPolicyInterface::decide(...)->assertAllowed()` com a unidade real do recurso de origem antes de qualquer mutação, incluindo `receiveBatch`-like operações de escrita simples (lição da revisão final da Fase 4).
- Migration aplicada apenas mediante autorização SQL explícita do usuário, com backup e checksum prévios.
