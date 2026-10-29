# Plano: Fase 6B — Cirurgia (MVP operacional)

## Objetivo
Entregar a cirurgia operacional do PRD §8.15: cadastro de salas por unidade, agendamento a partir do atendimento (sala, procedimento do catálogo, cirurgião e equipe com papéis), consentimento registrado, checklist de segurança em 3 momentos, status agendada → pré-op → em andamento → concluída/cancelada, eventos pré/intra/pós-operatórios e materiais consumidos. A conclusão é integrada: baixa o estoque dos materiais, lança procedimento e materiais na conta do atendimento (Fase 5) e oferece internar no pós-operatório (6A) e agendar retorno.

## Premissas
- Repositório único `/var/www/html/centralvet`. O orquestrador cria a branch de trabalho `feat/fase-6b-cirurgia` a partir de `feat/fase-6a-internacao` @ `09ce2d5` (`repos.py --preparar`). O checkout é compartilhado, com caminho exclusivo, RED antes da implementação e trailers `Task: T-xx` / `Task: T-xx (RED)`.
- Comandos rodados de `/var/www/html/centralvet` (mesmas convenções da 6A):
  - **LINT** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>`;
  - **SUITE** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`. Não filtra: use `| /usr/bin/grep -E '<Classe>|Failed:'`. Roda no `centralvet_test`; não interromper; SUITEs simultâneas podem dar falso FAIL em testes Redis ou deadlock de outros arquivos. Classe nova aparece sem rebuild (PSR-4 do host). Hoje: 627 testes, `Failed: 0`;
  - **PYTEST57** = `python3 scripts/test-prepare-mysql57.py`;
  - **GATE de tela**: o orquestrador roda `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`, faz login como admin no Playwright (autorização do usuário, senha do `.env` nunca registrada) e o validador navega só em `http://127.0.0.1:8081` (nunca `localhost`).
- A cirurgia sempre parte de um atendimento (`surgery.encounter_id NOT NULL`): a conta do atendimento (`encounter_account`, UNIQUE por `encounter_id`) recebe procedimento e materiais na conclusão. A entrada é o plano clínico do `EncounterView` (ação `surgery`).
- Sala é um cadastro novo por unidade (`surgery_room`), como o leito da 6A, porque não existe tabela de sala no banco. O agendamento recusa sala inativa e sobreposição de horário na mesma sala (cirurgias `scheduled`, `pre_op` ou `in_progress`), com trava `SELECT ... FOR UPDATE` na linha da sala.
- Procedimento: qualquer item ativo de `procedure_catalog_item` (Fase 4; a tabela não tem tipo/categoria e a 6B não a altera). Nome e `price_cents` são copiados para a cirurgia no agendamento; a cobrança usa a cópia.
- Equipe: cirurgião responsável obrigatório (entra na equipe como `surgeon`) e, no formulário, até um anestesista, um auxiliar e um volante. A equipe pode ser trocada enquanto a cirurgia está `scheduled` ou `pre_op`.
- Consentimento (MVP): registra quem aceitou (nome do tutor/responsável), quando, quem registrou e o texto integral aceito. Sem assinatura digital nem PDF (Fase 7).
- Checklist com itens fixos no Domain (`SurgeryChecklist`): `sign_in` (antes da indução) e `time_out` (antes da incisão) confirmados em `pre_op`; `sign_out` (antes da saída da sala) confirmado em `in_progress`. Iniciar exige consentimento + `sign_in` + `time_out`; concluir exige `sign_out`. Confirmar uma fase exige todos os itens marcados.
- Materiais: registrados só em `in_progress` (removíveis até a conclusão). Baixa de estoque e cobrança acontecem **na conclusão**, agregadas por produto, motivo `surgery_consumption`, via `StockService::consume` (FEFO). Preço = `product.sale_price_cents` × quantidade; com `NULL` ou 0 o item não é cobrado, mas o estoque é baixado. Estoque insuficiente recusa a conclusão inteira (rollback do `TTransaction` do controller). Procedimento com `price_cents` 0 não é lançado.
- Pós-operatório: concluída a cirurgia, a ficha mostra "Internar no pós-operatório", que abre a tela da 6A `HospitalizationAdmissionForm&encounter_id=&patient_id=` (o `HospitalizationService::admit` já testado), e "Agendar retorno", que chama `AppointmentService::schedule` com o cirurgião como profissional e grava `surgery.followup_appointment_id`.
- Cancelamento só em `scheduled` ou `pre_op`, com motivo obrigatório. Remarcação = cancelar e agendar de novo.
- Concorrência (lições da 6A): toda mudança de status grava com `UPDATE ... WHERE status = <status lido>` e confere o resultado; material, conclusão e retorno travam a linha da cirurgia (`lockStatus`, `SELECT ... FOR UPDATE`) antes de ler e decidir; confirmação dupla de checklist esbarra na UNIQUE e vira mensagem de domínio.
- RBAC (decisão do usuário): 9 programas novos, concedidos ao grupo 1 (`Template - Admin`) e a um grupo novo `Clínico – Cirurgia`, que T-04 cria (18 caracteres / 21 bytes em utf8mb4, localizado sempre por nome). O grupo 4 `Clínico – Internação` e os grupos 2 e 3 não recebem nada. Autorização com a unidade real da cirurgia/sala persistida (`requiresUnitScope: true`, `resourceUnitId`).
- Migration `0011` e DML de `system_program` são preparadas pelas tasks e aplicadas só pelo orquestrador, com backup, `gzip -t`, SHA-256 e aprovação SQL explícita do usuário (skill `sql-write-approval`), no **bloqueio entre a Onda 1 e a Onda 2**: migration em `centralvet` e `centralvet_test`, DML só em `centralvet`. Nenhuma task executa SQL de escrita.
- Registros criados nos gates levam o prefixo `F6B teste` (código/nome de sala, observação da cirurgia, nome do signatário, produto e lote de teste). O SQL de limpeza (T-21) os cobre e só o orquestrador o executa, com aprovação.
- Nada em `src/lib/adianti` nem em arquivo listado em `src/app/config/framework_hashes.php` (`index.php`, `engine.php`, `init.php`, `composer.json`, `app/lib/include|menu|util|validator`, `app/lib/widget/TAccordion.php`, `app/templates/adminbs5/*` exceto `cv-components.css`). Telas novas usam `CvPage::header`, nunca `TXMLBreadCrumb`.
- Texto clínico (consentimento, eventos, motivo de cancelamento) só por POST, nunca em URL nem em `TQuestion` com parâmetros GET (padrão `HospitalizationView::onAskDischarge`/`postedSummary`).

## Escopo

### Incluso
- Migration `0011` (6 tabelas + ampliação dos CHECKs `encounter_account_item_source_type_ck` e `stock_movement_reason_ck`), `.verify.sql` e `provision.sh` do banco de teste → T-01.
- Domain de sala, cirurgia e equipe, exceção de sala, tipos novos de item da conta e motivo novo de estoque → T-02.
- Domain de checklist (catálogo fixo dos 3 momentos), evento e material → T-03.
- Programas RBAC das 9 telas e grupo `Clínico – Cirurgia` (seed + DML, verify e rollback) → T-04.
- Fakes dos 6 repositórios → T-05; repositórios PDO com trava de sala, trava de status e UPDATE condicional → T-06.
- `SurgeryRoomService` → T-07; `SurgeryService` (agendamento com equipe e conflito de sala, consentimento, pré-op, início, cancelamento, eventos clínicos) → T-08; `SurgeryChecklistService` → T-09; `SurgeryMaterialService` → T-10; `SurgeryCompletionService` (conclusão integrada e retorno) + `EncounterAccountService::addSourcedItem` aceitando os tipos de cirurgia → T-11.
- Telas: salas → T-12; agendamento/equipe → T-13; ficha da cirurgia (status, cancelamento, conclusão, retorno, internar) → T-14; consentimento e eventos → T-15; checklist (tablet) e materiais → T-16; agenda do dia → T-17; navegação (menu, abas `CvNav`, ação no `EncounterView`) → T-18.
- i18n pt/en das telas e mensagens de domínio (`translations.json`, `UserMessage`) → T-19; runbook do módulo e seção 5.7 da 0011 → T-20.
- Validação final ponta a ponta e SQL de limpeza dos registros `F6B teste` → T-21.

### Excluído
- Mapa gráfico de salas, escalas anestésicas avançadas (ASA detalhada, monitorização contínua), PDFs e assinatura digital do consentimento, comunicação com o tutor (Fase 7), IA.
- Cirurgia sem atendimento; reagendamento (é cancelar e agendar de novo); conflito de agenda do cirurgião/equipe; itens de checklist configuráveis; honorários de equipe e cobrança separada de anestesia.
- Internar dentro da mesma transação da conclusão (a internação usa a tela existente da 6A, depois de concluir).
- Alterar `procedure_catalog_item`, `procedure_execution`, `sale`/`sale_item`, o PDV, `syncAutomaticItems` ou os serviços/telas da 6A.
- Central de Pendências (PRD §8.23).
- Executar migration, DML ou SQL de limpeza (só o orquestrador, com aprovação).

## Contexto técnico
- Camadas envolvidas: database, backend, frontend, infra, shared (testes), docs, qa.
- Projeto/base analisada: `/var/www/html/centralvet` (repositório único; `git -C <DIR> rev-parse --show-toplevel` = `/var/www/html/centralvet`), branch `feat/fase-6a-internacao` @ `09ce2d5`, Adianti 8.6, PHP 8.4, MySQL 8.0.43 local / 5.7 na hospedagem.
- Integrações: `EncounterAccountService` (Fase 5), `StockService::consume` (Fase 4), `ProcedureCatalogRepositoryInterface` (Fase 4), `AppointmentService::schedule` (agenda), `HospitalizationAdmissionForm` (6A), `RbacAuthorizationService` + `PdoAuditLogWriter`, `TenantContext`, `TenantUserDirectoryInterface::isActiveMember`.

## Baseline
- php-lint: `php -l` (via LINT com `sh -c`) em todo `.php` de `app/Core`, `app/control/clinic`, `app/lib/widget`, `tests/Unit`, `tests/Support`, `tests/Integration` (447 arquivos), só as linhas diferentes de `No syntax errors detected`, em raiz → baseline/php-lint.txt (0 linhas). Critério: nenhum erro novo em relação a `baseline/php-lint.txt`, ou seja, `No syntax errors detected` em cada PHP tocado.
- test-prepare-mysql57: `python3 scripts/test-prepare-mysql57.py` em raiz → baseline/test-prepare-mysql57.txt (5 linhas: `Ran 8 tests`, status final sem falhas).

## Exploração read-only
- Caminhos relevantes:
  - Domain e contratos: `src/app/Core/Domain/*` (factory estática + `reconstitute` + `assignId`; `const STATUS_*`), `Domain/Contract/*RepositoryInterface` (estendem `CentralVet\Persistence\TenantRepositoryInterface`), `Domain/Exception/*` (`InvalidStatusTransitionException`, `CrossTenantReferenceException`, `InsufficientStockException`, `SchedulingConflictException`). Modelos 6A: `Hospitalization.php`, `Bed.php`, `HospitalizationEvent.php`.
  - Application: `EncounterAccountService.php` (`openOrGet(int $encounterId, string $action)`, `addSourcedItem(...)` com lista fixa em `in_array`), `StockService.php::consume(int $tenantId, int $systemUnitId, int $productId, int $quantity, string $reason, ?string $referenceType, ?int $referenceId, int $professionalSystemUserId): void`, `AppointmentService::schedule(array $data, string $action): Appointment`, `HospitalizationDischargeService.php` (modelo da conclusão integrada).
  - Persistence: `AbstractTenantRepository.php` (`tenantQuery()`, `assertEntityTenant()`), `HospitalizationRepository.php::save` (UPDATE condicional + `FOR UPDATE` de reconferência, linhas 113-140).
  - Telas: `control/clinic/HospitalizationView.php` (ações `Classe::método`, `onAskDischarge`/`postedSummary` só POST, 3 catches, `TOUCH`), `HospitalizationAdmissionForm.php` (`make*Service`, `resolveTenantContext`), `BedList.php`/`BedForm.php`, `HospitalizationBoard.php`, `EncounterView.php` (`PLAN_ACTIONS` linha 82; `onScheduleFollowUp` 1767-1830).
  - Helpers: `lib/widget/CvPage|CvCard|CvBadge|CvKpiCard|CvForm|CvDatagrid|CvNav|CvFormat::userError`; `Core/Presentation/UserMessage.php` (`STATIC`/`PATTERNS`, totais travados em `UserMessageTest.php:122-123`: 39/38).
  - Navegação e i18n: `src/menu.xml` (`_t{Surgeries}` linhas 55-58 → `CvShellController#method=onComingSoon#item=surgeries`), `CvNav.php` (grupo `hospitalization` linhas 42-45), `app/config/translations.json` (951 entradas, ordem de `en` sem caixa).
  - Banco e scripts: `app/database/migrations/20261005_0010_phase6a_hospitalization.sql` (modelo), `scripts/test-db/provision.sh` (0010 na linha 55), `scripts/prepare-mysql57.py` (0011 vira `15-` no diretório privado), `app/database/seeds/initial-application-programs.sql` (bloco 6A linhas 422-557), `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs*.sql` e `T-20-cleanup.sql` (modelos), `docs/runbooks/internacao.md`, `shared-hosting-mysql57.md`.
- Padrões identificados:
  - Services recebem repositórios por interface, `AuthorizationPolicyInterface`, `TenantContext` e `?Closure $clock = null` (último), e autorizam com `decide(new AuthorizationRequest(context:, action:, requiresUnitScope: true, resourceUnitId:, entityType:, entityId:))->assertAllowed()`. Services não abrem transação; o controller abre `TTransaction::open('permission')`.
  - Controller: `private const ACTION_X = 'Classe::método'`; catches `AuthorizationDenied` (texto fixo), `MissingTenantContext` ('An authenticated session with an active unit is required') e `Exception` (rollback + `error_log` + `CvFormat::userError($e)`); `ControllerRawExceptionMessageTest` reprova `getMessage()` na tela.
  - Migration: `CONSTRAINT <tabela>_<x>_ck CHECK (...)` nomeado (≤ 61 caracteres), sem `BETWEEN`/`LIKE`/`CASE`/funções; `timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`; `*_cents int unsigned`; `*_system_user_id int`; FKs `ON UPDATE RESTRICT ON DELETE RESTRICT`; ampliação de CHECK em `DROP CHECK` + `ADD CONSTRAINT` separados.
- Scripts úteis: LINT, SUITE, PYTEST57 (Premissas); `python3 scripts/prepare-mysql57.py <privdir> <outdir>`; `./scripts/backup.sh`.
- Riscos identificados:
  - `source_type varchar(30)`: `surgery_procedure` (17) e `surgery_material` (16) cabem; `reason varchar(40)`: `surgery_consumption` (19) cabe.
  - Sobreposição de sala entre dois agendamentos simultâneos: trava da linha da sala (T-06/T-08).
  - Material registrado durante a conclusão ficaria sem baixa nem cobrança (o achado de prescrição × alta da revisão final da 6A): trava da cirurgia antes de ler (T-06/T-10/T-11).
  - A conclusão encadeia estoque e conta: atomicidade depende do `TTransaction` do controller (T-14).
  - `HospitalizationNavigationIntegrationTest` exige `label='_t{Surgeries}'` depois da Internação no `menu.xml`: T-18 troca só a `<action>`.
  - `UserMessageTest` trava os totais de `STATIC`/`PATTERNS` e exige tradução dos `_t` das telas: T-19 atualiza os números e inclui `Surgery*`.
  - Compartilhados com um escritor só: `EncounterView.php`, `menu.xml`, `CvNav.php` (T-18), `translations.json`, `UserMessage.php`, `UserMessageTest.php` (T-19), seed de programas (T-04), `cv-components.css` (T-16), `EncounterAccountService.php` (T-11), `EncounterAccountItem.php`, `StockMovement.php` (T-02), `provision.sh` (T-01).

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20261005_0011_phase6b_surgery.sql` | DDL das 6 tabelas e ampliação de 2 CHECKs | criar | T-01 |
| `src/app/database/migrations/20261005_0011_phase6b_surgery.verify.sql` | Verificação só com SELECT | criar | T-01 |
| `scripts/test-db/provision.sh` | Inclui 0011 na lista do banco de teste | modificar | T-01 |
| `src/app/Core/Domain/SurgeryRoom.php` | Entidade sala | criar | T-02 |
| `src/app/Core/Domain/Surgery.php` | Agregado cirurgia (status, consentimento, conclusão, cancelamento, retorno) | criar | T-02 |
| `src/app/Core/Domain/SurgeryTeamMember.php` | Membro da equipe com papel | criar | T-02 |
| `src/app/Core/Domain/Contract/SurgeryRoomRepositoryInterface.php` | Contrato de sala | criar | T-02 |
| `src/app/Core/Domain/Contract/SurgeryRepositoryInterface.php` | Contrato de cirurgia (sobreposição, trava, dia) | criar | T-02 |
| `src/app/Core/Domain/Contract/SurgeryTeamRepositoryInterface.php` | Contrato de equipe | criar | T-02 |
| `src/app/Core/Domain/Exception/SurgeryRoomUnavailableException.php` | Sala inativa ou ocupada no período | criar | T-02 |
| `src/app/Core/Domain/EncounterAccountItem.php` | Tipos `surgery_procedure` e `surgery_material` | modificar | T-02 |
| `src/app/Core/Domain/StockMovement.php` | Motivo `surgery_consumption` | modificar | T-02 |
| `src/tests/Unit/SurgeryDomainTest.php` | Testes do Domain de sala/cirurgia/equipe | criar | T-02 |
| `src/app/Core/Domain/SurgeryChecklist.php` | Catálogo fixo dos itens por fase | criar | T-03 |
| `src/app/Core/Domain/SurgeryChecklistItem.php` | Item confirmado do checklist | criar | T-03 |
| `src/app/Core/Domain/SurgeryEvent.php` | Evento imutável (clínico e de sistema) | criar | T-03 |
| `src/app/Core/Domain/SurgeryMaterial.php` | Material registrado | criar | T-03 |
| `src/app/Core/Domain/Contract/SurgeryChecklistRepositoryInterface.php` | Contrato de checklist | criar | T-03 |
| `src/app/Core/Domain/Contract/SurgeryEventRepositoryInterface.php` | Contrato de evento | criar | T-03 |
| `src/app/Core/Domain/Contract/SurgeryMaterialRepositoryInterface.php` | Contrato de material | criar | T-03 |
| `src/tests/Unit/SurgeryChecklistDomainTest.php` | Testes de checklist, evento e material | criar | T-03 |
| `src/app/database/seeds/initial-application-programs.sql` | Grupo `Clínico – Cirurgia`, 9 programas e concessões em instalação nova | modificar | T-04 |
| `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.sql` | DML do grupo `Clínico – Cirurgia`, dos 9 programas e das 18 concessões | criar | T-04 |
| `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.verify.sql` | Verificação só com SELECT | criar | T-04 |
| `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.rollback.sql` | Reversão preparada com WHERE por nome | criar | T-04 |
| `src/tests/Support/FakeSurgeryRoomRepository.php` | Dublê de sala (trava) | criar | T-05 |
| `src/tests/Support/FakeSurgeryRepository.php` | Dublê de cirurgia (save condicional, sobreposição, trava) | criar | T-05 |
| `src/tests/Support/FakeSurgeryTeamRepository.php` | Dublê de equipe | criar | T-05 |
| `src/tests/Support/FakeSurgeryChecklistRepository.php` | Dublê de checklist (duplicidade) | criar | T-05 |
| `src/tests/Support/FakeSurgeryEventRepository.php` | Dublê de evento | criar | T-05 |
| `src/tests/Support/FakeSurgeryMaterialRepository.php` | Dublê de material | criar | T-05 |
| `src/tests/Unit/SurgeryFakesTest.php` | Contrato dos dublês | criar | T-05 |
| `src/app/Core/Persistence/SurgeryRoomRepository.php` | PDO de sala (trava `FOR UPDATE`) | criar | T-06 |
| `src/app/Core/Persistence/SurgeryRepository.php` | PDO de cirurgia (UPDATE condicional, sobreposição, trava) | criar | T-06 |
| `src/app/Core/Persistence/SurgeryTeamRepository.php` | PDO de equipe | criar | T-06 |
| `src/app/Core/Persistence/SurgeryChecklistRepository.php` | PDO de checklist (UNIQUE → mensagem) | criar | T-06 |
| `src/app/Core/Persistence/SurgeryEventRepository.php` | PDO de evento (append-only) | criar | T-06 |
| `src/app/Core/Persistence/SurgeryMaterialRepository.php` | PDO de material | criar | T-06 |
| `src/tests/Integration/SurgeryRepositoryIntegrationTest.php` | Integração no `centralvet_test` | criar | T-06 |
| `src/app/Core/Application/SurgeryRoomService.php` | Cadastro de salas por unidade | criar | T-07 |
| `src/tests/Unit/SurgeryRoomServiceTest.php` | Testes do cadastro de salas | criar | T-07 |
| `src/app/Core/Application/SurgeryService.php` | Agendamento, equipe, consentimento, pré-op, início, cancelamento, eventos | criar | T-08 |
| `src/tests/Unit/SurgeryServiceTest.php` | Testes do ciclo da cirurgia | criar | T-08 |
| `src/app/Core/Application/SurgeryChecklistService.php` | Confirmação das fases do checklist | criar | T-09 |
| `src/tests/Unit/SurgeryChecklistServiceTest.php` | Testes do checklist | criar | T-09 |
| `src/app/Core/Application/SurgeryMaterialService.php` | Registro e remoção de materiais | criar | T-10 |
| `src/tests/Unit/SurgeryMaterialServiceTest.php` | Testes de materiais | criar | T-10 |
| `src/app/Core/Application/SurgeryCompletionService.php` | Conclusão integrada e retorno | criar | T-11 |
| `src/app/Core/Application/EncounterAccountService.php` | `addSourcedItem` aceita `surgery_procedure`/`surgery_material` | modificar | T-11 |
| `src/tests/Unit/SurgeryCompletionServiceTest.php` | Testes da conclusão e do retorno | criar | T-11 |
| `src/app/control/clinic/SurgeryRoomList.php` | Lista de salas da unidade | criar | T-12 |
| `src/app/control/clinic/SurgeryRoomForm.php` | Cadastro/edição de sala | criar | T-12 |
| `src/tests/Integration/SurgeryRoomFormIntegrationTest.php` | Campos do formulário de sala | criar | T-12 |
| `src/app/control/clinic/SurgeryScheduleForm.php` | Agendamento a partir do atendimento e troca de equipe | criar | T-13 |
| `src/tests/Integration/SurgeryScheduleFormIntegrationTest.php` | Campos do agendamento | criar | T-13 |
| `src/app/control/clinic/SurgeryView.php` | Ficha: status, cancelamento, conclusão, retorno, internar | criar | T-14 |
| `src/tests/Integration/SurgeryViewIntegrationTest.php` | Estado vazio, POST do texto clínico, botões touch | criar | T-14 |
| `src/app/control/clinic/SurgeryConsentForm.php` | Registro do consentimento | criar | T-15 |
| `src/app/control/clinic/SurgeryEventForm.php` | Eventos pré/intra/pós-operatórios | criar | T-15 |
| `src/tests/Integration/SurgeryClinicalFormsIntegrationTest.php` | Campos dos 2 formulários e ações | criar | T-15 |
| `src/app/control/clinic/SurgeryChecklistForm.php` | Checklist de uma fase (tablet) | criar | T-16 |
| `src/app/control/clinic/SurgeryMaterialForm.php` | Materiais da cirurgia | criar | T-16 |
| `src/app/templates/adminbs5/cv-components.css` | Seção `cv-checklist-*` (touch) | modificar | T-16 |
| `src/tests/Integration/SurgeryChecklistFormIntegrationTest.php` | Itens por fase, materiais e ações | criar | T-16 |
| `src/app/control/clinic/SurgeryList.php` | Agenda cirúrgica do dia | criar | T-17 |
| `src/app/Core/Presentation/SurgeryAgendaView.php` | Contagem por status e ordenação da agenda | criar | T-17 |
| `src/tests/Unit/SurgeryAgendaViewTest.php` | Testes da agenda | criar | T-17 |
| `src/app/control/clinic/EncounterView.php` | Ação `surgery` no plano clínico | modificar | T-18 |
| `src/menu.xml` | Cirurgias → `SurgeryList`; Salas cirúrgicas em Configurações | modificar | T-18 |
| `src/app/lib/widget/CvNav.php` | Grupo de abas `surgery` | modificar | T-18 |
| `src/tests/Integration/SurgeryNavigationIntegrationTest.php` | Navegação registrada | criar | T-18 |
| `src/app/config/translations.json` | Chaves pt/en da cirurgia | modificar | T-19 |
| `src/app/Core/Presentation/UserMessage.php` | Mensagens de domínio da cirurgia | modificar | T-19 |
| `src/tests/Unit/UserMessageTest.php` | Casos e totais das mensagens novas; `_t` das telas `Surgery*` | modificar | T-19 |
| `docs/runbooks/cirurgia.md` | Fluxos, regras, schema, programas e operação | criar | T-20 |
| `docs/runbooks/README.md` | Índice dos runbooks | modificar | T-20 |
| `docs/runbooks/shared-hosting-mysql57.md` | Passo da 0011 na hospedagem 5.7 | modificar | T-20 |
| `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-21-cleanup.sql` | Limpeza dos registros `F6B teste` (preparada) | criar | T-21 |

Nenhum arquivo é tocado por mais de uma task. Os compartilhados entre módulos (`EncounterView.php`, `menu.xml`, `CvNav.php`, `translations.json`, `UserMessage.php`, `UserMessageTest.php`, seed de programas, `cv-components.css`, `EncounterAccountService.php`, `EncounterAccountItem.php`, `StockMovement.php`, `provision.sh`) têm uma task dona cada.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Tabelas `surgery_room`, `surgery`, `surgery_team`, `surgery_checklist`, `surgery_event`, `surgery_material` (entidades do PRD §13 + sala e material) | Sala como texto livre; materiais como eventos; checklist em JSON | Sala cadastrada permite recusar sobreposição e sala inativa; material com produto e quantidade alimenta estoque e conta; checklist por item com UNIQUE impede confirmação dupla e guarda autoria |
| Consentimento como colunas de `surgery` (`consent_signer_name`, `consent_text`, `consent_recorded_at`, `consent_recorded_by_system_user_id`) + evento `consent` | Tabela `surgery_consent` com versões | MVP tem um aceite por cirurgia; a regravação antes do início fica no histórico de eventos; PDF/assinatura é Fase 7 |
| Conflito de sala por `SELECT id FROM surgery_room ... FOR UPDATE` seguido de consulta de sobreposição | UNIQUE por horário; checar sem trava | Intervalos não cabem em UNIQUE; a trava da linha da sala serializa dois agendamentos na mesma sala, igual no MySQL 8 e 5.7 |
| Mudança de status por `UPDATE surgery ... WHERE id=? AND tenant_id=? AND status=<status lido>`, com reconferência `SELECT status ... FOR UPDATE` quando nenhuma linha muda | Checar no service e salvar | Lição da 6A (alta × transferência): o segundo clique concorrente falha com `Surgery <id> changed status concurrently` em vez de sobrescrever |
| `lockStatus(int $surgeryId): ?string` (`SELECT status ... FOR UPDATE`) antes de adicionar/remover material, concluir e agendar retorno | Ler a lista de materiais sem trava | Lição da revisão final da 6A (prescrição × alta): material inserido durante a conclusão espera o commit e então é recusado por `Surgery <id> is not in progress` |
| Baixa de estoque e cobrança na conclusão, agregadas por produto, motivo `surgery_consumption`; procedimento `surgery_procedure` (`source_id` = cirurgia) e materiais `surgery_material` (`source_id` = material) via `EncounterAccountService::addSourcedItem` | Baixar a cada registro de material; `addManualItem` | Mesmo desenho da alta da 6A: o `TTransaction` da conclusão é tudo-ou-nada e a UNIQUE `(account_id, source_type, source_id)` torna a conclusão idempotente |
| Internação pós-operatória pela tela existente `HospitalizationAdmissionForm`, depois da conclusão | Chamar `HospitalizationService::admit` dentro da conclusão | Reaproveita a tela e as regras de leito da 6A sem acoplar duas operações longas numa transação; o motivo da internação não vai por URL |
| Retorno via `AppointmentService::schedule` com o cirurgião como profissional, `followup_appointment_id` gravado na cirurgia sob trava | Abrir `AppointmentForm` (não aceita `patient_id` pela URL) | Mesmo padrão de `EncounterView::onScheduleFollowUp`; a trava impede dois retornos por toque duplo |
| Checklist fixo em `SurgeryChecklist` (13 itens em 3 fases), rótulos em inglês traduzidos por `_t` | Itens configuráveis em tabela | Demanda pede itens fixos no MVP; código estável no banco, texto no catálogo de traduções |
| Grupo novo `Clínico – Cirurgia` com os 9 programas, além do Admin (decisão do usuário); programas com nomes ASCII em inglês | Reaproveitar ou renomear o grupo 4 `Clínico – Internação` | Libera a cirurgia à equipe cirúrgica sem abrir a internação e vice-versa; DML no padrão da T-05 da 6A (id de `MAX(id)+1`, `NOT EXISTS`, `SET NAMES utf8mb4`) |
| Rotas fixas entre telas (abaixo) | Cada tela decide seus parâmetros | As telas da Onda 4 se ligam por URL sem depender umas das outras |

Rotas fixadas (todas `index.php?class=...`):
- `SurgeryRoomList`; `SurgeryRoomForm` (`&id=<room_id>` para editar).
- `SurgeryScheduleForm&encounter_id=<id>&patient_id=<id>` (agendar) e `SurgeryScheduleForm&id=<surgery_id>` (trocar equipe).
- `SurgeryView&id=<surgery_id>`.
- `SurgeryConsentForm&surgery_id=<id>`.
- `SurgeryEventForm&surgery_id=<id>&type=pre_op|anesthesia|intra_op|complication|post_op`.
- `SurgeryChecklistForm&surgery_id=<id>&phase=sign_in|time_out|sign_out`.
- `SurgeryMaterialForm&surgery_id=<id>`.
- `SurgeryList` (`&date=YYYY-MM-DD`, padrão hoje).
- Internar no pós-operatório: `HospitalizationAdmissionForm&encounter_id=<id>&patient_id=<id>` (6A, inalterada).

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04
        [bloqueio: 0011 em centralvet + centralvet_test; sql/T-04-programs.sql em centralvet]
Onda 2: T-05 (T-02,T-03)   T-06 (T-01,T-02,T-03)
Onda 3: T-07 (T-02,T-05)  T-08 (T-02,T-03,T-05)  T-09 (T-02,T-03,T-05)
        T-10 (T-02,T-03,T-05)  T-11 (T-02,T-03,T-05)
Onda 4: T-12 (T-06,T-07)  T-13 (T-06,T-07,T-08)  T-14 (T-06,T-08,T-09,T-10,T-11)
        T-15 (T-06,T-08)  T-16 (T-06,T-09,T-10)  T-17 (T-06,T-08)  T-18 (T-04)
Onda 5: T-19 (T-12..T-18)   T-20 (T-01,T-04,T-11)
Onda 6: T-21 (T-19,T-20)
```

## Estratégia de execução
- Branch de trabalho: `feat/fase-6b-cirurgia`
- Branch base: `feat/fase-6a-internacao`
- Ponto de partida: `feat/fase-6a-internacao` @ `09ce2d5`. O orquestrador cria a branch de trabalho com `repos.py --preparar`; o nome `feat/` foi dado pelo orquestrador em vez de `task/fase-6b-cirurgia`.
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`); com `worktree por agente` eles chegam pelos merges de `wave<N>/T-XX`. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`.
- Isolamento em ondas com edições paralelas: caminho exclusivo — nenhum arquivo é dividido entre tasks; os compartilhados entre módulos têm um escritor só (Mapa de arquivos).
- Bloqueio entre Onda 1 e Onda 2 (orquestrador, aprovação SQL): backup + `gzip -t`, SHA-256 da 0011 numa cópia, aplicação em `centralvet` e `centralvet_test` com `MIGRATION_DB_USER`, `.verify.sql` nos dois; `sql/T-04-programs.sql` + `.verify.sql` em `centralvet` com `mysql --default-character-set=utf8mb4` (grupo novo `Clínico – Cirurgia` + 9 programas + 18 concessões; último conhecido `system_group` 4, `system_program` 117, `system_group_program` 127). Contagens antes/depois de `encounter_account_item`, `stock_movement`, `system_program` e `system_group_program` em `notes.md § Bloqueios`. A Onda 2 não abre sem isso (T-06 roda integração no `centralvet_test`).
- Gates econômicos (limite de turnos do validador):
  - Ondas 1–3: LINT dos arquivos da onda + uma SUITE inteira (+ PYTEST57 e preparador 5.7 sobre a 0011 na Onda 1). Sem navegador.
  - Onda 4: rebuild + login admin pelo orquestrador (autorizado pelo usuário para a 6B, credenciais do `.env` nunca registradas); SUITE + smoke Playwright **só desktop (1366×768)**: abrir cada tela nova uma vez com um registro `F6B teste` mínimo (sala → agendamento → ficha), conferindo render, console sem error e rede sem ≥ 400. Fluxos completos ficam para a T-21.
  - Onda 5: SUITE + fetch autenticado em pt das 9 telas (nenhum `Message not found`).
  - Onda 6 (T-21): E2E em **dois disparos de validador**: roteiro A (fluxo completo em desktop e tablet 820×1180) e roteiro B (os 5 itens de Review Focus + permissão negada).
- i18n: até a Onda 5, as telas usam `_t('<en>')` e anotam `- [T-xx] i18n: <en> → <pt>` no board; "Message not found" no gate da Onda 4 é aceito até T-19.

## Ondas de execução

### Onda 1
- T-01
- T-02
- T-03
- T-04

### Onda 2
- T-05
- T-06

### Onda 3
- T-07
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
- T-18

### Onda 5
- T-19
- T-20

### Onda 6
- T-21

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Darwin | general-purpose | inherit | T-01 |
| Platão | general-purpose | inherit | T-02, T-05 |
| Arquimedes | general-purpose | inherit | T-03, T-09 |
| Jaspion | general-purpose | inherit | T-04, T-07, T-18 |
| Athena | general-purpose | inherit | T-06, T-08 |
| Saitama | general-purpose | inherit | T-10, T-12 |
| Aang | general-purpose | inherit | T-11, T-14 |
| Naruto | general-purpose | inherit | T-13 |
| Kratos | general-purpose | inherit | T-15 |
| Tesla | general-purpose | inherit | T-16 |
| Batman | general-purpose | inherit | T-17 |
| Levi | general-purpose | inherit | T-19 |
| Gandalf | geduc:documentador | sonnet | T-20 |
| Spock | general-purpose | inherit | T-21 |

## Review Focus
- Dois agendamentos na mesma sala com horários sobrepostos (inclusive simultâneos) → o segundo recebe `Surgery room <id> is already booked for this period` e só uma cirurgia fica gravada → T-08
- Material registrado enquanto outra aba conclui a cirurgia → o registro espera a trava e é recusado com `Surgery <id> is not in progress`; nenhuma cirurgia concluída fica com material sem baixa nem cobrança → T-10
- Conclusão com material sem saldo suficiente → conclusão recusada com "Estoque insuficiente", a cirurgia continua "Em andamento", nenhum item entra na conta e nenhum movimento de estoque é gravado (rollback) → T-14
- Toque duplo em "Confirmar" de uma fase do checklist no tablet → uma linha por item; o segundo envio recebe `Checklist phase "<fase>" is already confirmed for surgery <id>` → T-16
- Iniciar a cirurgia sem consentimento ou sem `sign_in`/`time_out` confirmados → recusa com mensagem traduzida e a cirurgia continua em pré-op → T-08

## Critérios gerais de aceite
- SUITE com `Failed: 0` e `Total` maior ou igual ao da BASE da onda somado aos testes novos.
- Nenhum erro novo em relação a `baseline/php-lint.txt`: cada PHP tocado imprime `No syntax errors detected`.
- PYTEST57 com `Ran 8 tests` (ou mais) e nenhuma falha.
- Toda query nova filtra `tenant_id` (`tenantQuery()`), e toda mutação autoriza com `resourceUnitId` da entidade persistida.
- Toda tela nova mostra estado vazio, erro traduzido via `CvFormat::userError` e permissão negada (captura `AuthorizationDenied` e `MissingTenantContext`); botões de ação no tablet com altura ≥ 44 px (`cv-touch-target`); texto clínico só por POST.
- Contagens de `encounter`, `encounter_account_item`, `stock_movement`, `appointment`, `hospitalization` e `system_program` antes e depois do bloqueio e dos gates: as linhas existentes continuam lá (só crescem até a limpeza).
