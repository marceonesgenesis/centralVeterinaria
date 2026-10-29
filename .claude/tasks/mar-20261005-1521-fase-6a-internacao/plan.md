# Plano: Fase 6A — Internação (MVP operacional)

## Objetivo
Entregar a internação operacional do PRD §8.16 para uso em tablet. O escopo cobre cadastro de leitos por unidade, admissão a partir do atendimento, prescrição interna com agenda de administrações, registro de administração, parâmetros vitais e evolução, flowboard do turno e transferência de leito. Fecha com a alta integrada: ela encerra a internação, libera o leito, lança diárias e medicações na conta do atendimento (Fase 5) e baixa o estoque dos produtos administrados. Cirurgia fica para a 6B.

## Premissas
- Repositório único `/var/www/html/centralvet`. O orquestrador cria a branch de trabalho `feat/fase-6a-internacao` a partir de `task/landing-a11y-i18n` @ `8f9ebfc` (`repos.py --preparar`). O checkout é compartilhado, com caminho exclusivo, RED antes da implementação e trailers `Task: T-xx` / `Task: T-xx (RED)`.
- Comandos rodados de `/var/www/html/centralvet` (convenções das rodadas 2, 3 e landing):
  - **LINT** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>`;
  - **SUITE** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`. Não filtra, então use `| /usr/bin/grep -E '<Classe>|Failed:'`. Roda no `centralvet_test`; não interromper; SUITEs simultâneas podem dar falso FAIL em testes Redis ou deadlock de outros arquivos. O `vendor/` do host é PSR-4: classes novas aparecem na SUITE sem rebuild;
  - **PYTEST57** = `python3 scripts/test-prepare-mysql57.py`;
  - **GATE**: antes de cada gate com tela, o orquestrador roda `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`, faz login como admin (senha do `.env`, com autorização do usuário, nunca registrada) e o validador usa o Playwright MCP só em `http://127.0.0.1:8081` (nunca `localhost`).
- Registros criados nos gates levam o prefixo `F6 teste` (nome de leito, motivo de internação, descrição de prescrição, produto de teste). O SQL de limpeza (T-20) os cobre e só o orquestrador o executa, com aprovação.
- Migration `0010` e DML de `system_program` são preparadas pelas tasks e aplicadas só pelo orquestrador, com backup, `gzip -t`, SHA-256 e aprovação SQL explícita do usuário (skill `sql-write-approval`). Isso acontece no **bloqueio entre a Onda 1 e a Onda 2**: migration em `centralvet` e em `centralvet_test`, DML de programas só em `centralvet`. Nenhuma task executa SQL de escrita.
- A admissão sempre parte de um atendimento (`encounter_id NOT NULL`): a conta do atendimento (`encounter_account.encounter_id` UNIQUE NOT NULL) é onde a alta lança diárias e medicações. A entrada é o plano clínico do `EncounterView` (ação `hospitalization`). Admissão direto do paciente, sem atendimento, fica fora (ver Perguntas no retorno do planejador).
- Prescrição interna exige início e fim (`ends_at` obrigatório, no máximo 30 dias). A agenda inteira é gerada na criação, e medicação contínua é re-prescrita. "Atrasado" é derivado, não armazenado: pendente com `scheduled_at` mais de 30 min no passado; feito com `performed_at` acima dessa tolerância vira `done_late`.
- Diária: `bed.daily_rate_cents` copiado para `hospitalization.daily_rate_cents` na admissão. A transferência não muda o valor. Diárias cobradas = `max(1, ceil(horas/24))` entre `admitted_at` e a alta.
- A baixa de estoque e o lançamento de medicações acontecem na alta, só para administrações `done` de prescrições com `product_id`. Quantidade = `quantity_per_administration` × administrações feitas, por produto, motivo `hospitalization_consumption`, via `StockService::consume` (FEFO). O preço vem de `product.sale_price_cents`: com `NULL` ou 0 o item não é cobrado, mas o estoque é baixado. Estoque insuficiente recusa a alta inteira (rollback do `TTransaction` do controller).
- RBAC: 8 programas novos, concedidos ao grupo 1 (`Template - Admin`) e a um grupo novo `Clínico – Internação`, que T-05 cria (decisão do usuário; o banco não tinha grupo clínico). Grupos 2 e 3 não recebem nada. A autorização usa a unidade real do recurso persistido (`requiresUnitScope: true`, `resourceUnitId` da internação ou do leito). A auditoria vem do `RbacAuthorizationService` (`PdoAuditLogWriter`) a cada decisão; os eventos clínicos (`hospitalization_event`) guardam autoria e horário.
- Nada em `src/lib/adianti` nem em arquivo listado em `src/app/config/framework_hashes.php` (`index.php`, `engine.php`, `init.php`, `app/lib/menu|util|validator`, `app/templates/adminbs5/*` como `custom.css` e `layout.html`). `src/app/templates/adminbs5/cv-components.css` não está na lista e recebe a seção `cv-board-*` (só T-16).
- Telas novas não usam `TXMLBreadCrumb` (ele lança exceção para classe fora do `menu.xml`); usam `CvPage::header`.

## Escopo

### Incluso
- Migration `0010` (5 tabelas + ampliação dos CHECKs `encounter_account_item_source_type_ck` e `stock_movement_reason_ck`), `.verify.sql` e `provision.sh` do banco de teste → T-01.
- Preparador MySQL 5.7: `DROP CHECK` vira `DROP TRIGGER IF EXISTS` e a verificação de CHECK deixa de fixar `landing_lead` → T-02.
- Domain de leito, internação e evento, mais os tipos novos de item da conta e o motivo novo de estoque → T-03.
- Domain de prescrição interna, administração e agenda (`AdministrationSchedule`) com classificação de atraso → T-04.
- Programas RBAC e grupo `Clínico – Internação` (seed + DML, verify e rollback do banco atual) → T-05.
- Fakes dos 5 repositórios → T-06; repositórios PDO com ocupação atômica de leito e consulta do flowboard → T-07.
- `BedService` (cadastro de leitos por unidade) → T-08; `HospitalizationService` (admissão, transferência, evolução, parâmetros) → T-09; `HospitalizationOrderService` (prescrição, suspensão, administração, dados do flowboard) → T-10; `HospitalizationDischargeService` + `EncounterAccountService::addSourcedItem` (alta integrada) → T-11.
- Telas: leitos → T-12; admissão → T-13; ficha da internação com transferência e alta → T-14; prescrição, administração e evolução/parâmetros → T-15; flowboard do turno (tablet) → T-16; navegação (menu, abas `CvNav`, ação no `EncounterView`) → T-17.
- i18n pt/en das telas e mensagens de domínio (`translations.json`, `UserMessage`) → T-18; documentação operacional e técnica do módulo → T-19.
- Validação final ponta a ponta e SQL de limpeza dos registros `F6 teste` → T-20.

### Excluído
- Mapa gráfico de leitos, escalas clínicas avançadas (Glasgow, dor por escala validada etc.), integração com cirurgia (6B), comunicação com o tutor (Fase 7), IA.
- Admissão sem atendimento (direto do paciente) e internação sem conta do atendimento.
- Item da Central de Pendências (PRD §8.23): ainda não existe tela de pendências. O flowboard cobre os atrasos da internação.
- Prescrição sem data de fim, recálculo de diária por transferência, cobrança de alimentação/procedimento sem produto vinculado.
- Executar migration, DML ou SQL de limpeza (só o orquestrador, com aprovação).
- Alterar `sale`/`sale_item`, o fluxo de PDV ou a regra de `syncAutomaticItems`.

## Contexto técnico
- Camadas envolvidas: database, backend, frontend, infra, shared (testes), docs, qa.
- Projeto/base analisada: `/var/www/html/centralvet` (repositório único; `git -C <DIR> rev-parse --show-toplevel` = `/var/www/html/centralvet`), branch `task/landing-a11y-i18n` @ `8f9ebfc`, Adianti 8.6, PHP 8.4, MySQL 8.0.43 local / 5.7 na hospedagem.
- Integrações: `EncounterAccountService` (Fase 5), `StockService::consume` (Fase 4), `RbacAuthorizationService` + `PdoAuditLogWriter`, `TenantContext`, `TenantUserDirectoryInterface::isActiveMember`.

## Baseline
- php-lint: `php -l` (via LINT com `sh -c`) em todo `.php` de `app/Core`, `app/control/clinic`, `app/lib/widget`, `tests/Unit`, `tests/Support`, `tests/Integration` (396 arquivos), só as linhas diferentes de `No syntax errors detected`, em raiz → baseline/php-lint.txt (0 linhas). Critério: nenhum erro novo em relação a `baseline/php-lint.txt`, ou seja, `No syntax errors detected` em cada PHP tocado.
- test-prepare-mysql57: `python3 scripts/test-prepare-mysql57.py` em raiz → baseline/test-prepare-mysql57.txt (5 linhas: `Ran 6 tests`, status final sem falhas).

## Exploração read-only
- Caminhos relevantes:
  - Domain e contratos: `src/app/Core/Domain/*` (factory estática + `reconstitute` + `assignId`; `const STATUS_*`), `Domain/Contract/*RepositoryInterface` (estendem `TenantRepositoryInterface`: `findById`, `save`, `remove`, `tenantId`) e `Domain/Exception/*` (`InvalidStatusTransitionException`, `CrossTenantReferenceException`, `InsufficientStockException`).
  - Application: `Application/EncounterAccountService.php` (`openOrGet`, `syncAutomaticItems`, `addManualItem`, `assertAccountOpen`) e `Application/StockService.php::consume(int $tenantId, int $systemUnitId, int $productId, int $quantity, string $reason, ?string $referenceType, ?int $referenceId, int $professionalSystemUserId): void`.
  - Persistence: `Persistence/AbstractTenantRepository.php` (`tenantQuery()`, `assertEntityTenant()`) e `Persistence/EncounterAccountItemRepository.php` (modelo PDO).
  - Telas: `control/clinic/EncounterAccountForm.php` (modelo de `make*Service`/`resolveTenantContext`/`TTransaction::open('permission')`, ~linha 762), `control/clinic/EncounterView.php` (`PLAN_ACTIONS` linha 82), `control/clinic/QueueEntryView.php` (KPI + datagrid).
  - Helpers: `lib/widget/CvPage|CvCard|CvBadge|CvKpiCard|CvForm|CvDatagrid|CvNav|CvFormat::userError|CvSafeLabelTrait`; `app/Core/Presentation/UserMessage.php`.
  - Navegação e i18n: `src/menu.xml` (Surgeries na linha 50), `app/config/translations.json` (array `en`/`pt` em ordem alfabética de `en`).
  - Banco e scripts: `app/database/seeds/initial-application-programs.sql`, `app/database/migrations/README.md`, `scripts/prepare-mysql57.py`, `scripts/test-db/provision.sh`, `docs/runbooks/migrations.md` e `shared-hosting-mysql57.md`.
- Padrões identificados:
  - Services recebem repositórios por interface, `AuthorizationPolicyInterface` e `TenantContext`, e autorizam com `decide(new AuthorizationRequest(context:, action:, requiresUnitScope: true, resourceUnitId:, entityType:, entityId:))->assertAllowed()`.
  - `$action` = `'Controller::method'`, vindo do controller; services não abrem transação, o controller abre `TTransaction::open('permission')`.
  - Erros na tela via `TMessage('error', CvFormat::userError($e))`; o `ControllerRawExceptionMessageTest` reprova `getMessage()` na tela.
  - Migration: `CONSTRAINT <tabela>_<x>_ck CHECK (...)` nomeado, `timestamp(6)`, `*_cents int unsigned`, `*_system_user_id int` → `system_users`, FKs `ON UPDATE RESTRICT ON DELETE RESTRICT`.
- Scripts úteis: LINT, SUITE, PYTEST57 (Premissas); `python3 scripts/prepare-mysql57.py <privdir> <outdir>`; `scripts/test-db/provision.sh --check`; `./scripts/backup.sh` (sem `make` no host).
- Riscos identificados:
  - `encounter_account_item.source_type` e `stock_movement.reason` têm CHECK e lista no Domain: precisam de `ALTER ... DROP CHECK` + `ADD CONSTRAINT`, e o preparador 5.7 não trata `DROP CHECK` (T-02).
  - O preparador fixa `landing_lead` na verificação de CHECK (T-02).
  - CHECK no 5.7 vira trigger com `NEW.` antes de todo identificador: nada de `BETWEEN`, `LIKE` ou funções.
  - A ocupação de leito tem corrida entre dois tablets: UPDATE condicional (T-07).
  - A alta encadeia estoque e conta: a atomicidade depende do `TTransaction` do controller (T-14).
  - `EncounterView.php` (2092 linhas), `menu.xml`, `CvNav.php`, `translations.json`, `UserMessage.php`, o seed de programas e `cv-components.css` são compartilhados: cada um tem um escritor só.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20261005_0010_phase6a_hospitalization.sql` | DDL das 5 tabelas e ampliação de 2 CHECKs | criar | T-01 |
| `src/app/database/migrations/20261005_0010_phase6a_hospitalization.verify.sql` | Verificação só com SELECT | criar | T-01 |
| `scripts/test-db/provision.sh` | Inclui 0010 na lista do banco de teste | modificar | T-01 |
| `scripts/prepare-mysql57.py` | `DROP CHECK` → `DROP TRIGGER IF EXISTS`; verificação por tabela citada | modificar | T-02 |
| `scripts/test-prepare-mysql57.py` | Testes do preparador | modificar | T-02 |
| `docs/runbooks/shared-hosting-mysql57.md` | Passo da 0010 na hospedagem 5.7 | modificar | T-02 |
| `src/app/Core/Domain/Bed.php` | Entidade leito | criar | T-03 |
| `src/app/Core/Domain/Hospitalization.php` | Agregado internação (admissão, troca de leito, alta, diárias) | criar | T-03 |
| `src/app/Core/Domain/HospitalizationEvent.php` | Evento clínico imutável (admissão, transferência, evolução, parâmetros, alta) | criar | T-03 |
| `src/app/Core/Domain/Contract/BedRepositoryInterface.php` | Contrato de leito | criar | T-03 |
| `src/app/Core/Domain/Contract/HospitalizationRepositoryInterface.php` | Contrato de internação | criar | T-03 |
| `src/app/Core/Domain/Contract/HospitalizationEventRepositoryInterface.php` | Contrato de evento | criar | T-03 |
| `src/app/Core/Domain/Exception/BedUnavailableException.php` | Leito ocupado/inativo/de outra unidade | criar | T-03 |
| `src/app/Core/Domain/Exception/PatientAlreadyHospitalizedException.php` | Paciente já internado | criar | T-03 |
| `src/app/Core/Domain/EncounterAccountItem.php` | Tipos `hospitalization_stay` e `hospitalization_administration` | modificar | T-03 |
| `src/app/Core/Domain/StockMovement.php` | Motivo `hospitalization_consumption` | modificar | T-03 |
| `src/tests/Unit/HospitalizationDomainTest.php` | Testes do Domain de leito/internação/evento | criar | T-03 |
| `src/app/Core/Domain/HospitalizationOrder.php` | Prescrição interna | criar | T-04 |
| `src/app/Core/Domain/HospitalizationAdministration.php` | Administração agendada + classificação de atraso | criar | T-04 |
| `src/app/Core/Domain/AdministrationSchedule.php` | Geração dos horários | criar | T-04 |
| `src/app/Core/Domain/Contract/HospitalizationOrderRepositoryInterface.php` | Contrato de prescrição | criar | T-04 |
| `src/app/Core/Domain/Contract/HospitalizationAdministrationRepositoryInterface.php` | Contrato de administração + linhas do flowboard | criar | T-04 |
| `src/tests/Unit/AdministrationScheduleTest.php` | Testes de agenda e atraso | criar | T-04 |
| `src/app/database/seeds/initial-application-programs.sql` | Grupo `Clínico – Internação` e 8 programas em instalação nova | modificar | T-05 |
| `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.sql` | DML do grupo `Clínico – Internação`, dos 8 programas e das 16 concessões | criar | T-05 |
| `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.verify.sql` | Verificação só com SELECT (concessões por grupo, encoding, contagens) | criar | T-05 |
| `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.rollback.sql` | Reversão preparada com WHERE por nome | criar | T-05 |
| `src/tests/Support/FakeBedRepository.php` | Dublê de leito (ocupação condicional) | criar | T-06 |
| `src/tests/Support/FakeHospitalizationRepository.php` | Dublê de internação | criar | T-06 |
| `src/tests/Support/FakeHospitalizationOrderRepository.php` | Dublê de prescrição | criar | T-06 |
| `src/tests/Support/FakeHospitalizationAdministrationRepository.php` | Dublê de administração | criar | T-06 |
| `src/tests/Support/FakeHospitalizationEventRepository.php` | Dublê de evento | criar | T-06 |
| `src/tests/Unit/HospitalizationFakesTest.php` | Contrato dos dublês | criar | T-06 |
| `src/app/Core/Persistence/BedRepository.php` | PDO de leito (ocupação atômica) | criar | T-07 |
| `src/app/Core/Persistence/HospitalizationRepository.php` | PDO de internação | criar | T-07 |
| `src/app/Core/Persistence/HospitalizationOrderRepository.php` | PDO de prescrição | criar | T-07 |
| `src/app/Core/Persistence/HospitalizationAdministrationRepository.php` | PDO de administração + flowboard | criar | T-07 |
| `src/app/Core/Persistence/HospitalizationEventRepository.php` | PDO de evento | criar | T-07 |
| `src/tests/Integration/HospitalizationRepositoryIntegrationTest.php` | Integração no `centralvet_test` | criar | T-07 |
| `src/app/Core/Application/BedService.php` | Cadastro de leitos por unidade | criar | T-08 |
| `src/tests/Unit/BedServiceTest.php` | Testes do cadastro de leitos | criar | T-08 |
| `src/app/Core/Application/HospitalizationService.php` | Admissão, transferência, evolução, parâmetros | criar | T-09 |
| `src/tests/Unit/HospitalizationServiceTest.php` | Testes de admissão e transferência | criar | T-09 |
| `src/app/Core/Application/HospitalizationOrderService.php` | Prescrição, suspensão, administração, flowboard | criar | T-10 |
| `src/tests/Unit/HospitalizationOrderServiceTest.php` | Testes de prescrição e administração | criar | T-10 |
| `src/app/Core/Application/HospitalizationDischargeService.php` | Alta integrada | criar | T-11 |
| `src/app/Core/Application/EncounterAccountService.php` | `addSourcedItem` idempotente | modificar | T-11 |
| `src/tests/Unit/HospitalizationDischargeServiceTest.php` | Testes da alta integrada | criar | T-11 |
| `src/app/control/clinic/BedList.php` | Lista de leitos da unidade | criar | T-12 |
| `src/app/control/clinic/BedForm.php` | Cadastro/edição de leito | criar | T-12 |
| `src/tests/Integration/BedFormIntegrationTest.php` | Campos do formulário de leito | criar | T-12 |
| `src/app/control/clinic/HospitalizationAdmissionForm.php` | Admissão a partir do atendimento | criar | T-13 |
| `src/tests/Integration/HospitalizationAdmissionFormIntegrationTest.php` | Campos da admissão | criar | T-13 |
| `src/app/control/clinic/HospitalizationView.php` | Ficha da internação, transferência e alta | criar | T-14 |
| `src/tests/Integration/HospitalizationViewIntegrationTest.php` | Estado vazio sem id | criar | T-14 |
| `src/app/control/clinic/HospitalizationOrderForm.php` | Prescrição interna | criar | T-15 |
| `src/app/control/clinic/HospitalizationAdministrationForm.php` | Registro de administração (touch) | criar | T-15 |
| `src/app/control/clinic/HospitalizationEventForm.php` | Evolução e parâmetros | criar | T-15 |
| `src/tests/Integration/HospitalizationClinicalFormsIntegrationTest.php` | Campos dos 3 formulários | criar | T-15 |
| `src/app/control/clinic/HospitalizationBoard.php` | Flowboard do turno | criar | T-16 |
| `src/app/Core/Presentation/HospitalizationBoardView.php` | Agrupamento puro do flowboard | criar | T-16 |
| `src/app/templates/adminbs5/cv-components.css` | Seção `cv-board-*` (touch) | modificar | T-16 |
| `src/tests/Unit/HospitalizationBoardViewTest.php` | Testes do agrupamento do flowboard | criar | T-16 |
| `src/app/control/clinic/EncounterView.php` | Ação `hospitalization` no plano clínico | modificar | T-17 |
| `src/menu.xml` | Item Internação e Leitos | modificar | T-17 |
| `src/app/lib/widget/CvNav.php` | Grupo de abas `hospitalization` | modificar | T-17 |
| `src/tests/Integration/HospitalizationNavigationIntegrationTest.php` | Navegação registrada | criar | T-17 |
| `src/app/config/translations.json` | Chaves pt/en da internação | modificar | T-18 |
| `src/app/Core/Presentation/UserMessage.php` | Mensagens de domínio da internação | modificar | T-18 |
| `src/tests/Unit/UserMessageTest.php` | Casos das mensagens novas | modificar | T-18 |
| `docs/runbooks/internacao.md` | Fluxos, regras, schema, programas e operação | criar | T-19 |
| `docs/runbooks/README.md` | Índice dos runbooks | modificar | T-19 |
| `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-20-cleanup.sql` | Limpeza dos registros `F6 teste` (preparada) | criar | T-20 |

Nenhum arquivo é tocado por mais de uma task. Os arquivos compartilhados entre módulos (`EncounterView.php`, `menu.xml`, `CvNav.php`, `translations.json`, `UserMessage.php`, seed de programas, `cv-components.css`, `EncounterAccountService.php`, `EncounterAccountItem.php`, `StockMovement.php`) têm uma task dona cada.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Tabelas `bed`, `hospitalization`, `hospitalization_order`, `hospitalization_administration`, `hospitalization_event` (nomes do PRD §13) | Tabela única de eventos para tudo; tabelas separadas para parâmetros e evolução | Administração tem ciclo próprio (pendente → feita/não feita/cancelada) e chave única por horário; evolução, parâmetros, transferência, admissão e alta são eventos imutáveis com autoria, que preservam o histórico (PRD §27) |
| Ocupação do leito por `UPDATE bed SET status='occupied', current_hospitalization_id=? WHERE id=? AND tenant_id=? AND status='available'` (rowCount = 1) | Checar status no service e depois salvar; lock pessimista | Dois tablets admitindo no mesmo leito: só um UPDATE vence, sem lock explícito, igual no MySQL 8 e 5.7 |
| Itens da conta com `source_type` novos (`hospitalization_stay`, `hospitalization_administration`) e `source_id` real, via `EncounterAccountService::addSourcedItem` | `addManualItem` (sem `source_id`, não deduplica); tabela de cobrança própria | O UNIQUE `(account_id, source_type, source_id)` já existente torna a alta idempotente no banco |
| Baixa de estoque na alta, agregada por produto, motivo `hospitalization_consumption` | Baixar a cada administração | A demanda pede a baixa na alta integrada; recusar o registro de uma medicação já dada por falta de estoque seria pior. O `TTransaction` do controller torna a alta tudo-ou-nada |
| Agenda inteira gerada na prescrição (`ends_at` obrigatório, ≤ 30 dias, frequência 1–168 h, fim exclusivo) | Geração preguiçosa por janela ao abrir o flowboard | Não escreve em GET; a agenda é previsível e testável (`AdministrationSchedule::generate`) |
| "Atrasado" derivado (`HospitalizationAdministration::classify`, tolerância 30 min) | Status `late` gravado por job | Sem worker nem relógio no banco; o flowboard calcula no carregamento |
| Admissão só a partir de atendimento (`encounter_id NOT NULL`) | `encounter_id` opcional com conta avulsa | A conta da Fase 5 é por atendimento; outra conta exigiria um agregado novo |
| ALTER de CHECK em duas instruções (`DROP CHECK` e depois `ADD CONSTRAINT`), com o preparador 5.7 traduzindo `DROP CHECK` para `DROP TRIGGER IF EXISTS <ck>_bi/_bu` | Migration 5.7 manual à parte | Mantém um único SQL auditável e o fluxo do runbook |
| Grupo novo `Clínico – Internação` com os 8 programas, além do Admin (decisão do usuário) | Conceder ao grupo 2 `Template - Users`; só ao grupo 1 | O grupo 2 é o genérico de todos os usuários; um grupo próprio permite liberar a internação à equipe clínica sem abrir o resto. A DML deriva os ids e grava o nome em utf8mb4 (`SET NAMES`) |
| Rotas fixas entre telas (abaixo) | Cada tela decide seus parâmetros | As telas da Onda 4 se ligam por URL sem depender umas das outras |

Rotas fixadas (todas `index.php?class=...`):
- `BedList`; `BedForm` (`&id=<bed_id>` para editar).
- `HospitalizationAdmissionForm&encounter_id=<id>&patient_id=<id>`.
- `HospitalizationView&id=<hospitalization_id>`.
- `HospitalizationOrderForm&hospitalization_id=<id>`.
- `HospitalizationAdministrationForm&administration_id=<id>`.
- `HospitalizationEventForm&hospitalization_id=<id>&type=vitals|evolution`.
- `HospitalizationBoard`.

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04  T-05
        [bloqueio: 0010 em centralvet + centralvet_test; T-05-programs.sql em centralvet]
Onda 2: T-06 (T-03,T-04)   T-07 (T-01,T-03,T-04)
Onda 3: T-08 (T-03,T-06)  T-09 (T-03,T-06)  T-10 (T-03,T-04,T-06)  T-11 (T-03,T-04,T-06)
Onda 4: T-12 (T-07,T-08)  T-13 (T-07,T-09)  T-14 (T-07,T-09,T-10,T-11)
        T-15 (T-07,T-09,T-10)  T-16 (T-04,T-07,T-09,T-10)  T-17 (T-05)
Onda 5: T-18 (T-12..T-17)   T-19 (T-01,T-02,T-05,T-11)
Onda 6: T-20 (T-18,T-19)
```

## Estratégia de execução
- Branch de trabalho: `feat/fase-6a-internacao`
- Branch base: `task/landing-a11y-i18n`
- Ponto de partida: `task/landing-a11y-i18n` @ `8f9ebfc`. O orquestrador cria a branch de trabalho com `repos.py --preparar`, e o nome `feat/` foi pedido pelo usuário em vez de `task/fase-6a-internacao`.
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`); com `worktree por agente` eles chegam pelos merges de `wave<N>/T-XX`. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`.
- Isolamento em ondas com edições paralelas: caminho exclusivo — nenhum arquivo é dividido entre tasks; os compartilhados entre módulos têm um escritor só (Mapa de arquivos).
- Bloqueio entre Onda 1 e Onda 2 (orquestrador): backup + `gzip -t`, SHA-256 da 0010, aprovação SQL, aplicação em `centralvet` e em `centralvet_test` com `MIGRATION_DB_USER`, `.verify.sql` nos dois, e `sql/T-05-programs.sql` + `sql/T-05-programs.verify.sql` em `centralvet` com `mysql --default-character-set=utf8mb4` (ids derivados de `MAX(id)+1` no próprio INSERT; último conhecido `system_group` 3, `system_program` 109, `system_group_program` 111). O rollback preparado é `sql/T-05-programs.rollback.sql`. Contagens antes e depois em `notes.md § Bloqueios`. A Onda 2 não abre sem isso (T-07 roda integração no `centralvet_test`).
- Gates: Ondas 1–3 com LINT + SUITE (+ PYTEST57 na Onda 1); Ondas 4–6 com rebuild, login e Playwright MCP em `http://127.0.0.1:8081`, com console sem erro e rede sem status ≥ 400 por tela, em viewport desktop (1366×768) e tablet (820×1180).
- i18n: até a Onda 5, as telas usam `_t('<en>')` e anotam `- [T-xx] i18n: <en> → <pt>` no board; "Message not found" no gate da Onda 4 é aceito até T-18.

## Ondas de execução

### Onda 1
- T-01
- T-02
- T-03
- T-04
- T-05

### Onda 2
- T-06
- T-07

### Onda 3
- T-08
- T-09
- T-10
- T-11

### Onda 4
- T-12
- T-13
- T-14
- T-15
- T-16
- T-17

### Onda 5
- T-18
- T-19

### Onda 6
- T-20

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Darwin | general-purpose | inherit | T-01, T-02 |
| Platão | general-purpose | inherit | T-03, T-06 |
| Arquimedes | general-purpose | inherit | T-04, T-10 |
| Jaspion | general-purpose | inherit | T-05, T-08, T-17 |
| Athena | general-purpose | inherit | T-07, T-09 |
| Aang | general-purpose | inherit | T-11, T-14 |
| Saitama | general-purpose | inherit | T-12 |
| Naruto | general-purpose | inherit | T-13 |
| Kratos | general-purpose | inherit | T-15 |
| Tesla | general-purpose | inherit | T-16 |
| Levi | general-purpose | inherit | T-18 |
| Gandalf | geduc:documentador | sonnet | T-19 |
| Spock | general-purpose | inherit | T-20 |

## Review Focus
- Dois tablets admitem pacientes diferentes no mesmo leito ao mesmo tempo → o segundo recebe `Bed <id> is not available`, só uma internação fica com o leito e a internação recusada não é gravada → T-09
- Alta com produto administrado sem saldo suficiente → alta recusada com "Estoque insuficiente", a internação continua `admitted`, o leito continua ocupado e nenhum item entra na conta (rollback) → T-14
- Conta do atendimento fechada antes da alta → alta recusada com mensagem traduzida, nada baixado do estoque → T-11
- Usuário de outra unidade abre `HospitalizationView&id=<id>` pela URL → mensagem de permissão negada, nenhum dado do paciente na página → T-14
- Toque duplo em "Feito" no tablet → uma única administração `done` com um `performed_at`; o segundo envio recebe `Administration <id> is not pending` → T-15

## Critérios gerais de aceite
- SUITE com `Failed: 0` e `Total` maior ou igual ao da BASE da onda somado aos testes novos.
- Nenhum erro novo em relação a `baseline/php-lint.txt`: cada PHP tocado imprime `No syntax errors detected`.
- PYTEST57 com todos os testes passando (`Ran` ≥ 8).
- Toda query nova filtra `tenant_id` (`tenantQuery()`), e toda mutação autoriza com `resourceUnitId` da entidade persistida.
- Toda tela nova mostra estado vazio, erro traduzido via `CvFormat::userError` e permissão negada; botões de ação no tablet com altura ≥ 44 px.
- Contagens de `encounter`, `encounter_account_item`, `stock_movement` e `system_program` antes e depois do bloqueio: as linhas existentes continuam lá (só crescem).
