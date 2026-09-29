# Plano: Navegação e jornada clínica — fim das telas CRUD desconectadas

## Objetivo
Corrigir o problema de arquitetura de navegação identificado pelo usuário após a auditoria Playwright completa: várias telas CRUD já construídas (Fases 1-5) não se conectam ao fluxo correto — algumas são becos sem saída (ID bruto sem seletor, texto exigindo uma coluna que o usuário não tem como preencher), outras são acessíveis duas vezes (uma via jornada correta, outra via item de menu solto que só quebra), e duas funcionalidades inteiras (registro de resultado de exame, recebimento de pagamento de conta) não têm NENHUM caminho de UI real para chegar nelas. Esta fase fecha os 3 problemas: (a) cria os 2 pontos de entrada que faltam, (b) remove/substitui itens de `menu.xml` que só duplicam um caminho contextual já correto, (c) troca campo de ID cru por widget de busca relacional nas telas que são legitimamente standalone.

## Premissas
- **Base de evidência**: catalogada nesta própria sessão por auditoria Playwright sistemática (4 sub-agentes paralelos, grupos Recepção/Prontuário clínico/Estoque/Financeiro) + inspeção manual de código feita nesta rodada de planejamento (grep/leitura de cada tela candidata, dos repositórios/serviços de Application e do `menu.xml`). Nenhuma suposição não verificada entrou nas tasks abaixo.
- **`TDBUniqueSearch`/`TDBCombo` existem no framework e já têm 1 precedente de uso no projeto** (`src/app/control/admin/SystemUserForm.php`, tela nativa do Adianti) — nenhuma das telas customizadas das Fases 1-5 nunca usou. `TDBUniqueSearch::__construct($name, $database, $model, $key, $value, $orderColumn, ?TCriteria $criteria)` aceita um `TCriteria` opcional — todo uso novo desta fase que busca em `Tutor`/`Patient`/`Product` (todos com atributo `tenant_id` confirmado) passa um `TCriteria` com `TFilter('tenant_id', '=', $tenantContext->tenantId())`, para não vazar dados de outro tenant no autocomplete.
- **Campo `professional_system_user_id` (AppointmentForm) é uma exceção documentada**: `SystemUser` é a tabela nativa do Adianti (banco `permission`, sem coluna `tenant_id` própria — o vínculo é via `tenant_user`, uma tabela de junção que `TCriteria` de `TDBUniqueSearch` não alcança sem SQL customizado). Mesmo padrão do único precedente existente (`SystemUserForm.php` busca `SystemProgram` sem filtro de tenant). Esta fase busca em `SystemUser` sem filtro de tenant — simplificação documentada, não bug esquecido; filtrar por profissional-do-tenant fica para uma fase futura se necessário.
- **`ExamRequestRepository::listPending()` já existe e já está implementado** (`src/app/Core/Persistence/ExamRequestRepository.php:79`) — não precisa de migration nem de mudança de schema, só falta o wrapper de `Application\ExamService` e a tela que o consome.
- **`ReceivableRepositoryInterface` NÃO tem hoje nenhum método de listagem** (só `findByEncounterAccountId`) — precisa de um método novo (`listOpen()`, escopado por tenant, sem coluna de unidade porque `Receivable` não tem `system_unit_id` — só `tenant_id`/`encounter_account_id`/`tutor_id`). Mirror direto do padrão já usado em `PayableRepositoryInterface::listOpenBySystemUnit()`, adaptado à ausência da coluna de unidade.
- **`TutorForm`/`TutorList` nunca linkam para `PatientList`/`PatientForm`**, apesar de `PatientList` já aceitar `?tutor_id=...` desde a Fase 1 (`PatientList.php`, docblock próprio confirma) e `PatientForm` já aceitar `?tutor_id=...` também travado como campo não editável. O bug aqui é só a ausência do link — nenhuma tela nova, nenhum widget novo, só uma ação de linha em `TutorList` (mesmo padrão já usado por `ProductList`'s ação "Batch" → `StockBatchForm`).
- **Nenhuma migration, nenhuma mudança de schema.** O único DML desta fase é o registro em `system_program`/`system_group_program` das 2 telas novas — mesma regra de sempre: aprovação de SQL específica na hora, nunca pré-autorizada aqui.
- Projeto não é repositório Git.

## Escopo

### Incluso
- `Application\ExamService::listPending()` (wrapper fino sobre o repositório já existente).
- `Application\PaymentService::listOpenReceivables()` + `ReceivableRepositoryInterface::listOpen()` + implementação em `ReceivableRepository`.
- Tela nova `PendingExamResultList` (espelha `PayableList`): lista solicitações de exame com status `requested`, ação de linha → `ExamResultForm` com `exam_request_id`.
- Tela nova `PendingReceivableList` (espelha `PayableList`): lista contas a receber com status `open`/`partially_paid`, ação de linha → `PaymentForm` com `receivable_id`.
- Ação de linha "Pacientes" em `TutorList` → `PatientList` com `tutor_id`.
- `menu.xml`: substituir os 2 itens soltos que hoje apontam para telas órfãs (`ExamResultForm` → `PendingExamResultList`; `PaymentForm` → `PendingReceivableList`) e remover 9 itens soltos que hoje só duplicam, com pior UX, um caminho contextual já correto e testado (`PrescriptionForm`, `ExamRequestForm`, `VaccinationForm`, `ProcedureExecutionForm`, `EncounterAccountForm`, `StockBatchForm`, `VaccineProtocolForm`, `PatientList`, `PatientForm`).
- Registro das 2 telas novas em `system_program`/`system_group_program` (DML, aprovação na hora).
- Troca de campo de ID cru (`TEntry`) por widget de busca relacional (`TDBUniqueSearch`/`TDBCombo`) em 3 telas genuinamente standalone: `AppointmentForm` (patient_id, service_id, professional_system_user_id), `SaleForm` (tutor_id, patient_id, product_id, procedure_id), `VaccinationCardView` (adiciona seletor de paciente só quando `patient_id` não vem por querystring — hoje a tela fica em branco sem nenhuma forma de escolher um paciente).
- Validação Playwright ponta a ponta de cada fluxo novo/alterado + regressão completa (153 testes).

### Excluído
- Qualquer wizard/reformulação de `EncounterView` (fora de escopo, já tratado na fase anterior).
- Filtro de `professional_system_user_id` por tenant/unidade (ver Premissas — exceção documentada).
- Busca global de paciente sem tutor (não existe hoje, não é pedida aqui — `PatientList` continua exigindo `tutor_id`).
- Qualquer mudança de schema/migration.
- Dashboards, cards de estatística, badges de IA (já excluídos na fase anterior, seguem fora de escopo).

## Contexto técnico
- Camadas envolvidas: backend (Application/Persistence PHP), frontend (telas Adianti `app/control/clinic/*.php`), config (`menu.xml`), banco (DML de registro de programa, não schema).
- Projeto/base analisada: `/var/www/html/centralvet` (Adianti Framework 8.6, Docker Compose, acesso real testado via Playwright em `http://127.0.0.1:8081`).
- Integrações: nenhuma nova.

## Exploração read-only
- Caminhos relevantes: `src/app/Core/Domain/Contract/ExamRequestRepositoryInterface.php`, `src/app/Core/Domain/Contract/ReceivableRepositoryInterface.php`, `src/app/Core/Domain/Contract/PayableRepositoryInterface.php`, `src/app/Core/Persistence/ExamRequestRepository.php`, `src/app/Core/Persistence/ReceivableRepository.php`, `src/app/Core/Persistence/PayableRepository.php`, `src/app/Core/Application/ExamService.php`, `src/app/Core/Application/PaymentService.php`, `src/app/Core/Application/PayableService.php`, `src/app/control/clinic/PayableList.php`, `src/app/control/clinic/ProductList.php`, `src/app/control/clinic/TutorList.php`, `src/app/control/clinic/PatientList.php`, `src/app/control/clinic/PatientForm.php`, `src/app/control/clinic/AppointmentForm.php`, `src/app/control/clinic/SaleForm.php`, `src/app/control/clinic/VaccinationCardView.php`, `src/app/control/clinic/ExamResultForm.php`, `src/app/control/clinic/PaymentForm.php`, `src/lib/adianti/widget/wrapper/TDBUniqueSearch.php`, `src/app/control/admin/SystemUserForm.php` (único precedente de uso), `src/menu.xml`.
- Padrões identificados: toda tela de listagem "pendências para ação" deste projeto segue exatamente a forma de `PayableList` (T-09 da Fase 5): `onReload()` totalmente sobrescrito puxando de um método de Application service, uma `TDataGridAction` de linha chamando outra tela com o ID via querystring, sem `TXMLBreadCrumb` até o registro em `menu.xml` acontecer (mesma ressalva já documentada no docblock de `PayableList`). `ProductList::$action_batch` (linha 97) é o precedente exato de `TDataGridAction` que navega para outra tela passando o id da linha corrente como parâmetro de querystring — é esse precedente que as novas ações de linha replicam.
- Scripts úteis: `docker compose exec app php tests/run.php` (153 testes hoje); Playwright MCP para verificação end-to-end real; `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx` após cada rodada de mudança de código.
- Riscos identificados: `menu.xml` é um arquivo único — toda edição de item passa por T-06, escritor único, depois de T-03/T-04 existirem como classes (senão os 2 itens substituídos apontam para uma classe inexistente e quebram o menu inteiro). DML de `system_program` (T-07) só pode rodar depois que as classes de T-03/T-04 existem de verdade no disco.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/Core/Application/ExamService.php` | `listPending()` | modificar | T-01 |
| `src/app/Core/Domain/Contract/ReceivableRepositoryInterface.php` | contrato `listOpen()` | modificar | T-02 |
| `src/app/Core/Persistence/ReceivableRepository.php` | implementação `listOpen()` | modificar | T-02 |
| `src/app/Core/Application/PaymentService.php` | `listOpenReceivables()` | modificar | T-02 |
| `src/tests/Support/FakeReceivableRepository.php` | acompanhar método novo da interface | modificar | T-02 |
| `src/app/control/clinic/TutorList.php` | ação de linha "Pacientes" | modificar | T-05 |
| `src/app/control/clinic/AppointmentForm.php` | 3 campos → widgets relacionais | modificar | T-08 |
| `src/app/control/clinic/SaleForm.php` | 4 campos → widgets relacionais | modificar | T-09 |
| `src/app/control/clinic/VaccinationCardView.php` | seletor de paciente quando ausente | modificar | T-10 |
| `src/app/control/clinic/PendingExamResultList.php` | tela nova | criar | T-03 |
| `src/app/control/clinic/PendingReceivableList.php` | tela nova | criar | T-04 |
| `src/menu.xml` | substituir 2 itens, remover 9 | modificar | ⚠ T-06 (depende de T-03/T-04 existirem) |
| banco `permission.system_program`/`system_group_program` | registrar 2 telas novas | DML | T-07 (depende de T-03/T-04 existirem) |

Nenhum arquivo é tocado por mais de uma task de implementação simultaneamente — a única colisão em potencial (`menu.xml`/DML dependerem das telas novas) é resolvida por ordem de onda (T-06/T-07 só abrem depois que T-03/T-04 fecham), não por paralelismo dentro da mesma onda.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| `ReceivableRepositoryInterface::listOpen()` sem parâmetro de unidade | Espelhar `PayableRepositoryInterface::listOpenBySystemUnit(int $systemUnitId)` ao pé da letra | `Receivable` não tem coluna `system_unit_id` (só `tenant_id`/`tutor_id`/`encounter_account_id`) — exigir o parâmetro forçaria um join que não existe hoje; escopo por tenant já é suficiente e é o que `AbstractTenantRepository` garante em toda query |
| `professional_system_user_id` sem filtro de tenant no `TDBUniqueSearch` | Fazer join manual via SQL customizado, ou trocar por `TDBCombo` filtrado em PHP após query separada | `TDBUniqueSearch` não suporta join no `TCriteria`; filtrar em PHP exigiria reescrever o widget. Documentado como simplificação aceita, mesmo padrão do único precedente existente no projeto (`SystemUserForm.php`) |
| `menu.xml`: substituir (não só remover) os itens de `ExamResultForm`/`PaymentForm` | Só remover e deixar sem nenhum item de menu para essas 2 funcionalidades | As 2 funcionalidades são reais e usadas (registrar resultado de exame, receber pagamento) — remover sem substituir as tornaria inacessíveis de novo, reproduzindo o problema original com outra cara |
| `menu.xml`: remover (não substituir) os 9 itens de telas contextuais | Manter os itens soltos "para acesso administrativo direto" | Todas as 9 têm um caminho contextual já testado e funcionando (EncounterView, EncounterAccountForm, ProductList, VaccineCatalogList, nova ação de TutorList); o item solto só reproduz o dead-end de ID cru/mensagem de erro que motivou esta fase inteira — não existe caso de uso legítimo para acessá-las fora do contexto |

## Diagrama de dependências

```text
T-01 (ExamService.listPending)         ─┐
T-02 (Receivable.listOpen + Payment)   ─┼─► T-03 (PendingExamResultList)   ─┐
                                        │   T-04 (PendingReceivableList)   ─┼─► T-06 (menu.xml)
T-05 (TutorList → PatientList)         ─┤                                  ├─► T-07 (DML system_program)
T-08 (AppointmentForm widgets)         ─┤                                  │
T-09 (SaleForm widgets)                ─┤                                  │
T-10 (VaccinationCardView picker)      ─┘                                  │
                                                                            ▼
                                                                    T-11 (Playwright + regressão)
                                                                            │
                                                                            ▼
                                                                    T-12 (revisão final)
```

## Estratégia de execução
- Branch de trabalho: não se aplica (projeto sem Git).
- Branch base: não se aplica.
- Sem commit por onda (sem Git) — cada onda fecha com evidência registrada em `notes.md`.
- Isolamento em ondas com edições paralelas: escritor único por arquivo em toda onda (nenhuma onda tem 2 agentes editando o mesmo arquivo).

## Ondas de execução

### Onda 1
- T-01, T-02, T-05, T-08, T-09, T-10 (todos arquivos distintos, nenhuma dependência entre si)

### Onda 2
- T-03 (depende de T-01), T-04 (depende de T-02)

### Onda 3
- T-06 (depende de T-03, T-04), T-07 (depende de T-03, T-04 — DML, aprovação de SQL na hora)

### Onda 4
- T-11 (depende de todas as anteriores)

### Onda 5
- T-12 (depende de T-11)

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Atenea (Application/Persistence) | general-purpose | herdado | T-01, T-02 |
| Hermes (telas de listagem novas) | general-purpose | herdado | T-03, T-04 |
| Iris (navegação/link) | general-purpose | herdado | T-05 |
| Ártemis (widgets relacionais) | general-purpose | herdado | T-08, T-09, T-10 |
| Jano (menu + DML) | general-purpose | herdado | T-06, T-07 |
| Cérbero — validador | geduc:validador | — | T-11 |
| Minerva — revisora final | geduc:revisor | — | T-12 |

## Critérios gerais de aceite
- `docker compose exec app php tests/run.php` reporta 153/153 (ou mais, se alguma task adicionar teste) depois de cada onda.
- Todo item de menu novo/alterado carrega sem erro fatal e sem mensagem citando coluna de banco ausente.
- Todo campo relacional trocado (`AppointmentForm`/`SaleForm`/`VaccinationCardView`) permite buscar e selecionar um registro real por nome, sem digitar ID numérico.
- `PendingExamResultList`/`PendingReceivableList` mostram pelo menos 1 registro pendente real (criado via UI durante a validação) e a ação de linha abre a tela de destino já com o ID certo preenchido.
