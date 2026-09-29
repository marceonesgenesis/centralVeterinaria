# Plano: Fase 4 — Procedimentos/estoque/vendas

## Objetivo
Dar às ações clínicas um impacto real em estoque e vendas: catálogo de produtos/insumos com lotes e movimentos rastreáveis, catálogo de procedimentos com insumos consumidos automaticamente na execução, e um PDV simples que registra venda de produto/procedimento com baixa de estoque. Fecha o critério de saída do PRD para a Fase 4 ("Clínica impacta estoque/vendas") e conecta a ação inline "Procedimento" do EncounterView (Fase 2/3, hoje só grava em audit_log) a um registro real.

## Premissas
- `procedure_catalog_item` (novo, PRD seção 8.14: "catálogo, preço, duração, preparo e insumos") é uma entidade distinta do `service` da Fase 1 (PRD 8.7, catálogo de agendamento sem insumos) — não reaproveita a tabela `service`.
- Estoque é rastreado por lote (`stock_batch`, com validade) por unidade (`system_unit_id`), consumido por ordem de validade (mais próximo do vencimento primeiro) — modelo citado literalmente na seção 13 do PRD (`product`, `stock_batch`, `stock_movement`).
- `vaccine_catalog_item.stock_quantity` (Fase 3, já aplicado em produção) permanece como contador simples independente — não é migrado nem generalizado para o novo modelo de lotes nesta fase, para não reabrir uma migration já aplicada. Vacina e produto de estoque geral são catálogos separados.
- Execução de procedimento (`procedure_execution`) consome estoque automaticamente via lista de insumos do catálogo (`procedure_catalog_item_input`), sem constraint de banco contra saldo negativo (mesma decisão já documentada na Fase 3 para `vaccine_catalog_item.stock_quantity`: piso defensivo é responsabilidade da Application, não do schema) — a Application recusa a execução se o saldo de nenhum lote combinado for suficiente, lançando exceção de domínio antes de gravar.
- Cobrança automática da execução do procedimento (gerar a venda sozinha) fica fora de escopo: a venda (`sale`/`sale_item`) é criada manualmente na tela de PDV, escolhendo produto ou procedimento do catálogo. Consolidação de conta do atendimento, formas de pagamento, caixa e recebíveis é a Fase 5 (Financeiro integrado, PRD 8.19, épico E12) — aqui a venda só registra total e emite um recibo simples em PDF (mesmo padrão dompdf já usado em `PrescriptionForm`, Fase 3).
- PDV desta fase vende produto (`item_type='product'`) e procedimento (`item_type='procedure'`); não vende `service` de agenda (já cobrado implicitamente pelo fluxo de agendamento) — simplificação documentada, não lacuna.
- `sale_item.item_reference_id` não tem FK de banco (referencia `product.id` ou `procedure_catalog_item.id` dependendo de `item_type`, chave polimórfica) — integridade garantida pela Application, mesmo padrão de simplificação documentada já usado em `vaccine_catalog_item.stock_quantity` (Fase 3).
- "Compras" (PRD 8.17) é simplificada para `stock_movement` do tipo `purchase_entry` registrado manualmente contra um lote novo ou existente — sem fluxo formal de pedido de compra/fornecedor (entidade não citada na seção 13 do PRD), sem cotação e sem nota fiscal de entrada.
- Alertas de estoque mínimo (`product.minimum_stock_quantity`) ficam armazenados e exibidos na tela, sem notificação proativa — mecanismo de notificação/automação é a Fase 7 (Comunicação/automações).
- Toda tela nova usa `RbacAuthorizationService`/`AuthorizationPolicyInterface` desde a criação, com a unidade real do recurso de origem (`system_unit_id` do lote/encontro/venda) como `resourceUnitId` — mesmo padrão maduro das Fases 1-3.
- Toda tela nova é registrada em `system_program`/`system_group_program` (RBAC nativo) além de `menu.xml` — lição repetida desde a Fase 1.
- Projeto não é repositório Git; DDL/DML no MySQL exige autorização SQL explícita do usuário antes de executar (backup, checksum SHA-256, usuário `centralvet_migrator` dedicado), inclusive para os `INSERT`s de RBAC.

## Escopo

### Incluso
- Migration com 8 tabelas: `product`, `stock_batch`, `stock_movement`, `procedure_catalog_item`, `procedure_catalog_item_input`, `procedure_execution`, `sale`, `sale_item`.
- Catálogo de produtos/insumos (`ProductService`) com CRUD e estoque mínimo.
- Estoque por lote e por unidade (`StockService`): entrada de lote, consumo automático por ordem de validade, ledger de movimentos.
- Catálogo de procedimentos com lista de insumos consumidos por execução (`ProcedureCatalogService`).
- Execução de procedimento dentro de um atendimento (`ProcedureExecutionService`), consumindo estoque via os insumos do catálogo, com autorização pela unidade real do encontro.
- Venda simples/PDV (`SaleService`): itens de produto ou procedimento, baixa de estoque para itens de produto, total calculado, recibo em PDF.
- Telas: catálogo de produto, entrada de lote, catálogo de procedimento (com insumos), execução de procedimento, PDV/venda.
- Conectar a ação inline "Procedimento" do `EncounterView` à tela real de execução de procedimento.
- Registro das telas novas em `menu.xml` e RBAC nativo (`system_program`/`system_group_program`).
- Testes automatizados da lógica de consumo de estoque por validade, recusa por saldo insuficiente, e venda com baixa de estoque.
- Revisão final de segurança e quality gate.

### Excluído
- Módulo de compras formal (pedido de compra, fornecedor, cotação, nota fiscal de entrada) — PRD 8.17 citado, mas não modelado na seção 13; simplificado para `stock_movement` manual.
- Contas a receber/pagar, pagamento, caixa, relatórios financeiros (PRD 8.19, Fase 5).
- Cirurgia e internação (PRD 8.15/8.16, Fase 6).
- Notificação/alerta proativo de estoque mínimo (Fase 7).
- Inventário com contagem cíclica e auditoria de divergência (PRD 8.17, além do escopo desta fase).
- Venda de `service` de agenda pelo PDV.

## Contexto técnico
- Camadas envolvidas: database, backend, frontend, infra (RBAC/menu), shared (testes).
- Projeto/base analisada: `/var/www/html/centralvet` (Adianti Framework 8.6, PHP 8.4, MySQL 8, Docker Compose, sem bind-mount de `app`/`worker`).
- Integrações: nenhuma nova; reaproveita `CentralVet\Tenancy\TenantContext`, `CentralVet\Authorization\RbacAuthorizationService`, `CentralVet\Storage\StorageInterface` (Fase 0-2).

## Exploração read-only
- Caminhos relevantes: `src/app/Core/Application/` (14 services existentes), `src/app/Core/Domain/`, `src/app/Core/Domain/Contract/`, `src/app/Core/Persistence/`, `src/app/control/clinic/EncounterView.php:994-1036` (`onInlineAction`, branch "Procedimento" só grava `audit_log`), `src/app/control/clinic/ServiceForm.php`/`src/app/model/clinic/Service.php` (catálogo simples de agendamento, sem insumos), `src/app/control/clinic/VaccineCatalogForm.php`/`VaccineProtocolForm.php` (padrão catálogo + lista filha, a replicar para procedimento+insumos), `src/app/control/clinic/PrescriptionForm.php` (padrão de PDF via dompdf).
- Padrões identificados: `TStandardForm`/`TPage` com `resolveTenantContext()` duplicado por controller; Application service sempre recebe as interfaces de Repository/Authorization no construtor; exceções de domínio capturadas como `TMessage`; toda tela nova precisa de `system_program`+`system_group_program` além de `menu.xml`.
- Scripts úteis: `src/tests/run.php` (runner sem PHPUnit, 139 testes atuais); `docker compose exec app php tests/run.php`; `docker compose build app worker && docker compose up -d app worker` (obrigatório após qualquer arquivo novo, sem bind-mount).
- Riscos identificados: `EncounterView.php` é um arquivo grande e sensível a edição paralela (mesma lição da Fase 3) — a conexão da ação "Procedimento" é isolada em task própria, de escritor único, depois de todas as telas novas prontas. `system_program`/`system_group_program` não têm `AUTO_INCREMENT` — ids calculados via `MAX(id)+1` só no momento de aplicar, por SELECT.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql` | Migration das 8 tabelas da Fase 4 | criar | T-01 |
| `src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.verify.sql` | Verificação read-only pós-migration | criar | T-01 |
| `src/app/Core/Domain/Contract/ProductRepositoryInterface.php` | Contrato de produto | criar | T-02 |
| `src/app/Core/Domain/Contract/StockBatchRepositoryInterface.php` | Contrato de lote de estoque | criar | T-02 |
| `src/app/Core/Domain/Contract/StockMovementRepositoryInterface.php` | Contrato de movimento de estoque | criar | T-02 |
| `src/app/Core/Domain/Contract/ProcedureCatalogRepositoryInterface.php` | Contrato de catálogo de procedimento | criar | T-02 |
| `src/app/Core/Domain/Contract/ProcedureCatalogItemInputRepositoryInterface.php` | Contrato de insumo do catálogo | criar | T-02 |
| `src/app/Core/Domain/Contract/ProcedureExecutionRepositoryInterface.php` | Contrato de execução de procedimento | criar | T-02 |
| `src/app/Core/Domain/Contract/SaleRepositoryInterface.php` | Contrato de venda | criar | T-02 |
| `src/app/Core/Domain/Contract/SaleItemRepositoryInterface.php` | Contrato de item de venda | criar | T-02 |
| `src/app/Core/Domain/Product.php` | Entidade de domínio produto | criar | T-03 |
| `src/app/Core/Domain/StockBatch.php` | Entidade de domínio lote | criar | T-03 |
| `src/app/Core/Domain/StockMovement.php` | Entidade de domínio movimento | criar | T-03 |
| `src/app/Core/Persistence/ProductRepository.php` | Persistência de produto | criar | T-03 |
| `src/app/Core/Persistence/StockBatchRepository.php` | Persistência de lote | criar | T-03 |
| `src/app/Core/Persistence/StockMovementRepository.php` | Persistência de movimento | criar | T-03 |
| `src/app/Core/Application/ProductService.php` | CRUD de produto | criar | T-03 |
| `src/app/Core/Application/StockService.php` | Entrada de lote e consumo por validade | criar | T-03 |
| `src/app/Core/Domain/Exception/InsufficientStockException.php` | Exceção de saldo insuficiente | criar | T-03 |
| `src/app/Core/Domain/ProcedureCatalogItem.php` | Entidade de domínio catálogo de procedimento | criar | T-04 |
| `src/app/Core/Domain/ProcedureCatalogItemInput.php` | Entidade de domínio insumo do catálogo | criar | T-04 |
| `src/app/Core/Persistence/ProcedureCatalogRepository.php` | Persistência de catálogo de procedimento | criar | T-04 |
| `src/app/Core/Persistence/ProcedureCatalogItemInputRepository.php` | Persistência de insumo do catálogo | criar | T-04 |
| `src/app/Core/Application/ProcedureCatalogService.php` | CRUD de catálogo de procedimento e insumos | criar | T-04 |
| `src/app/Core/Domain/ProcedureExecution.php` | Entidade de domínio execução | criar | T-05 |
| `src/app/Core/Persistence/ProcedureExecutionRepository.php` | Persistência de execução | criar | T-05 |
| `src/app/Core/Application/ProcedureExecutionService.php` | Executa procedimento e consome insumos | criar | T-05 |
| `src/app/Core/Domain/Sale.php` | Entidade de domínio venda | criar | T-06 |
| `src/app/Core/Domain/SaleItem.php` | Entidade de domínio item de venda | criar | T-06 |
| `src/app/Core/Persistence/SaleRepository.php` | Persistência de venda | criar | T-06 |
| `src/app/Core/Persistence/SaleItemRepository.php` | Persistência de item de venda | criar | T-06 |
| `src/app/Core/Application/SaleService.php` | Cria venda, baixa estoque de itens de produto | criar | T-06 |
| `src/app/control/clinic/ProductForm.php` | Tela de cadastro de produto | criar | T-07 |
| `src/app/control/clinic/ProductList.php` | Listagem de produto | criar | T-07 |
| `src/app/control/clinic/StockBatchForm.php` | Tela de entrada de lote | criar | T-07 |
| `src/app/model/clinic/Product.php` | Model Adianti de produto | criar | T-07 |
| `src/app/control/clinic/ProcedureCatalogForm.php` | Tela de catálogo de procedimento | criar | T-08 |
| `src/app/control/clinic/ProcedureCatalogList.php` | Listagem de catálogo de procedimento | criar | T-08 |
| `src/app/control/clinic/ProcedureInputForm.php` | Tela de insumos do catálogo | criar | T-08 |
| `src/app/model/clinic/ProcedureCatalogItem.php` | Model Adianti de catálogo de procedimento | criar | T-08 |
| `src/app/control/clinic/ProcedureExecutionForm.php` | Tela de execução de procedimento | criar | T-09 |
| `src/app/control/clinic/SaleForm.php` | Tela de PDV/venda | criar | T-10 |
| `src/app/control/clinic/EncounterView.php` | Ação inline "Procedimento" religada | modificar ⚠ | T-11 |
| `src/menu.xml` | Registro das telas novas | modificar | T-12 |
| `src/tests/Unit/StockServiceTest.php` | Testes de consumo de estoque por validade | criar | T-13 |
| `src/tests/Unit/ProcedureExecutionServiceTest.php` | Testes de execução e recusa por saldo insuficiente | criar | T-13 |
| `src/tests/Unit/SaleServiceTest.php` | Testes de venda e baixa de estoque | criar | T-13 |
| `src/tests/Support/FakeProductRepository.php` | Dublê de produto | criar | T-13 |
| `src/tests/Support/FakeStockBatchRepository.php` | Dublê de lote | criar | T-13 |
| `src/tests/Support/FakeStockMovementRepository.php` | Dublê de movimento | criar | T-13 |
| `src/tests/Support/FakeProcedureCatalogRepository.php` | Dublê de catálogo de procedimento | criar | T-13 |
| `src/tests/Support/FakeProcedureExecutionRepository.php` | Dublê de execução | criar | T-13 |
| `src/tests/Support/FakeSaleRepository.php` | Dublê de venda | criar | T-13 |
| `.tasks/06-fase-4-procedimentos-estoque-vendas/notes.md` | Parecer final da Fase 4 | modificar | T-14 |

`EncounterView.php` (⚠) é tocado só por T-11, escritor único, depois de T-07 a T-10 concluídas — sem paralelismo de edição sobre ele.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Estoque rastreado por lote (`stock_batch`) consumido por ordem de validade, não um contador único por produto | Contador simples por produto (como `vaccine_catalog_item.stock_quantity`, Fase 3) | PRD seção 13 cita `stock_batch`/`stock_movement` como entidades próprias; seção 8.17 exige "lote, validade, rastreabilidade" — um contador único não sustenta isso |
| Cobrança da venda é manual (tela de PDV separada), não automática ao executar o procedimento | Gerar `sale`/`sale_item` automaticamente dentro de `ProcedureExecutionService` | Consolidação de conta/cobrança é escopo formal da Fase 5 (Financeiro integrado); acoplar agora criaria uma venda parcial sem conta do atendimento, pagamento ou caixa — pré-requisitos que ainda não existem |
| `sale_item.item_reference_id` sem FK de banco (chave polimórfica) | Duas colunas de FK nullable (`product_id`, `procedure_catalog_item_id`) | Uma linha de venda referencia exatamente um catálogo por vez; duas FKs nullable abririam espaço para os dois preenchidos ou nenhum — a checagem de qual catálogo é referenciado já fica centralizada na Application, mesmo padrão de simplificação documentada da Fase 3 |

## Diagrama de dependências

```text
T-01 -> T-02 -> T-03 -> T-05 -> T-09 -> T-11 -> T-12 -> T-14
                 |       ^       ^              ^       ^
                 v       |       |              |       |
                T-04 ----+       |      T-08 ---+        |
                 |               |       ^               |
                 v               |       |                |
                T-06 -----------------> T-10 -------------+
                 |
                 v
                T-07 ------------------------------------> T-13 -> T-14
```

## Estratégia de execução
- Branch de trabalho: não aplicável — projeto não é repositório Git.
- Branch base: não aplicável.
- Commit por onda: não aplicável (sem Git); registro de progresso feito em `tasks.md`/`notes.md`.
- Isolamento em ondas com edições paralelas: escritor único por arquivo em toda onda; `EncounterView.php` reservado exclusivamente a T-11, depois de T-07/T-08/T-09/T-10 concluídas.

## Ondas de execução

### Onda 1
- T-01

### Onda 2
- T-02

### Onda 3
- T-03
- T-04

### Onda 4
- T-05
- T-06

### Onda 5
- T-07
- T-08
- T-09
- T-10

### Onda 6
- T-11

### Onda 7
- T-12
- T-13

### Onda 8
- T-14

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Athena | general-purpose | herdado | T-01, T-02, T-03, T-06 |
| Jaspion | general-purpose | herdado | T-04, T-05 |
| Aang | general-purpose | herdado | T-07, T-10, T-11 |
| Tesla | general-purpose | herdado | T-08, T-09 |
| Naruto | general-purpose | herdado | T-12 |
| Levi | general-purpose | herdado | T-13, T-14 |

## Critérios gerais de aceite
- `docker compose exec app php tests/run.php` termina em `Failed: 0` com o número de testes anterior (139) mais os novos desta fase, sem regressão.
- Nenhum controller acessa `Persistence`/`Domain` diretamente — sempre via `Core/Application`.
- Toda Application service nova chama `AuthorizationPolicyInterface::decide(...)->assertAllowed()` com a unidade real do recurso de origem antes de qualquer mutação.
- Migration aplicada apenas mediante autorização SQL explícita do usuário, com backup e checksum prévios.
