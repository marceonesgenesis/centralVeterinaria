# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Preparar migration de `encounter` | — | não | alta | Athena | [x] |
| T-02 | backend | Criar contratos de Encounter e do assistente de IA | T-01 | não | média | Athena | [x] |
| T-03 | backend | Encounter — Domain, Repository e Application service | T-02 | sim | alta | Athena | [x] |
| T-04 | backend | Assistente de IA — implementação placeholder | T-02 | sim | simples | Platão | [x] |
| T-05 | backend | Documentos do encounter (reaproveitando storage existente) | T-02 | sim | média | Platão | [x] |
| T-06 | backend/frontend | Tela única de Atendimento (EncounterView) | T-03,T-04,T-05 | não | alta | Aang | [x] |
| T-07 | infra | Registrar EncounterView em menu.xml e RBAC nativo | T-06 | sim | média | Naruto | [x] |
| T-08 | shared | Testes unitários de Encounter e documentos | T-03,T-04 | sim | média | Levi | [x] |
| T-09 | shared | Revisão de segurança e quality gate da Fase 2 | T-07,T-08 | não | alta | Levi | [x] |

## Detalhamento

### T-01 — Preparar migration de `encounter`

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/database/migrations/20260922_0003_phase2_encounter.sql`
- `src/app/database/migrations/20260922_0003_phase2_encounter.verify.sql`

**Interface**
- Produz: `src/app/database/migrations/20260922_0003_phase2_encounter.sql` contendo a tabela `encounter` com `id`, `tenant_id bigint unsigned NOT NULL` (FK para `tenant(id)`), `system_unit_id` (FK para `system_unit`), `patient_id` (FK para `patient`), `appointment_id` NULLABLE (FK para `appointment`), `professional_system_user_id` (FK para `system_users`), `status`, `started_at`, `finished_at` NULLABLE, `anamnesis_text` NULLABLE, `temperature_c`, `heart_rate_bpm`, `respiratory_rate_mpm`, `weight_kg`, `mucous_membranes`, `capillary_refill_seconds` (todos NULLABLE), `physical_exam_text` NULLABLE, `diagnosis_text` NULLABLE, `clinical_plan_text` NULLABLE, `ai_summary_text` NULLABLE, `ai_summary_accepted_at` NULLABLE, `created_at`, `updated_at`
- Consome: nada dentro deste plano (segue a convenção já estabelecida nas migrations das Fases 0 e 1, fora deste plano)

**Critério de aceite**
- O arquivo contém o comentário `Status: PREPARED ONLY`, igual ao padrão das migrations anteriores.
- O arquivo `.verify.sql` contém apenas instruções `SELECT`.
- Nenhum comando é executado contra o MySQL por esta task.

**Validação**
- `grep -c "Status: PREPARED ONLY" src/app/database/migrations/20260922_0003_phase2_encounter.sql` → `1`
- `grep -Ei "^\s*(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)" src/app/database/migrations/20260922_0003_phase2_encounter.verify.sql` → nenhuma linha impressa

---

### T-02 — Criar contratos de Encounter e do assistente de IA

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** não
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/EncounterRepositoryInterface.php`
- `src/app/Core/Assistant/Contract/AiClinicalAssistantInterface.php`

**Interface**
- Produz: `CentralVet\Domain\Contract\EncounterRepositoryInterface` (estendendo `CentralVet\Persistence\TenantRepositoryInterface`, já existente da Fase 0); `CentralVet\Assistant\Contract\AiClinicalAssistantInterface` com os métodos `summarizePatientHistory(int $patientId): ?string` e `suggestNextSteps(int $encounterId): array`
- Consome: nada dentro deste plano (usa `CentralVet\Persistence\TenantRepositoryInterface`, já existente da Fase 0)

**Critério de aceite**
- `EncounterRepositoryInterface` estende `TenantRepositoryInterface` e não referencia `TPage` nem qualquer classe do Adianti.
- `AiClinicalAssistantInterface` declara exatamente os dois métodos acima, sem depender de `TenantContext` nem de qualquer classe do Adianti.

**Validação**
- `php -l src/app/Core/Domain/Contract/EncounterRepositoryInterface.php src/app/Core/Assistant/Contract/AiClinicalAssistantInterface.php` → `No syntax errors detected` nos 2
- `grep -L "extends TenantRepositoryInterface" src/app/Core/Domain/Contract/EncounterRepositoryInterface.php` → nenhuma linha impressa

---

### T-03 — Encounter — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim (com T-04, T-05)
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Encounter.php`
- `src/app/Core/Persistence/EncounterRepository.php`
- `src/app/Core/Application/EncounterService.php`

**Interface**
- Produz: `CentralVet\Application\EncounterService` com os métodos `start(array $data, string $action): Encounter` (`patient_id`, `professional_system_user_id`, `system_unit_id`, `appointment_id` opcional), `findById(int $id): ?Encounter`, `autosave(int $id, array $draft): Encounter` (atualiza os campos de anamnese/sinais vitais/exame físico/diagnóstico/plano informados em `$draft`, os demais ficam como estavam), `finish(int $id, string $action): Encounter`, `acceptAiSummary(int $id, string $summaryText): Encounter`, `timeline(int $id): array`; `CentralVet\Persistence\EncounterRepository` implementando `EncounterRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\EncounterRepositoryInterface`

**Critério de aceite**
- `EncounterRepository` estende `AbstractTenantRepository` e implementa `EncounterRepositoryInterface`; toda query nasce de `TenantQuery::forTenant(...)`.
- `start()` e `finish()` recusam a operação quando `CentralVet\Authorization\Contract\AuthorizationPolicyInterface::decide(...)` (dependência obrigatória do construtor, mesmo padrão já usado em `AppointmentService`/`QueueEntryService` na Fase 1) devolve uma decisão negada, lançando `AuthorizationDenied` antes de gravar qualquer coisa.
- `timeline(int $id)` lê exclusivamente de `audit_log` filtrado por `entity_type = 'encounter'` e `entity_id = $id`, escopado ao tenant corrente — nenhuma tabela nova é criada para isso.

**Validação**
- `php -l src/app/Core/Domain/Encounter.php src/app/Core/Persistence/EncounterRepository.php src/app/Core/Application/EncounterService.php` → `No syntax errors detected` nos 3
- Teste unitário (T-08) cobrindo a recusa de `start()`/`finish()` quando a política de autorização nega

---

### T-04 — Assistente de IA — implementação placeholder

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim (com T-03, T-05)
**Complexidade:** simples
**Agente:** Platão

**Arquivos prováveis**
- `src/app/Core/Assistant/NullAiClinicalAssistant.php`

**Interface**
- Produz: `CentralVet\Assistant\NullAiClinicalAssistant` implementando `AiClinicalAssistantInterface`, devolvendo `null` em `summarizePatientHistory()` e uma lista vazia em `suggestNextSteps()`
- Consome: T-02 `CentralVet\Assistant\Contract\AiClinicalAssistantInterface`

**Critério de aceite**
- `NullAiClinicalAssistant` não faz nenhuma chamada de rede nem depende de nenhum provedor externo de IA.
- Chamar `summarizePatientHistory()`/`suggestNextSteps()` nunca lança exceção, independentemente do id informado.

**Validação**
- `php -l src/app/Core/Assistant/NullAiClinicalAssistant.php` → `No syntax errors detected`
- Teste unitário (T-08) chamando os dois métodos com um id qualquer e confirmando ausência de exceção

---

### T-05 — Documentos do encounter (reaproveitando storage existente)

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim (com T-03, T-04)
**Complexidade:** média
**Agente:** Platão

**Arquivos prováveis**
- `src/app/Core/Application/EncounterDocumentService.php`

**Interface**
- Produz: `CentralVet\Application\EncounterDocumentService` com os métodos `attach(int $encounterId, string $fileName, string $contents, string $contentType): object` (devolve os metadados do objeto salvo) e `list(int $encounterId): array`
- Consome: nada dentro deste plano (usa `CentralVet\Storage\StorageInterface`, já existente da Fase 0)

**Critério de aceite**
- `attach()` prefixa a chave do objeto por tenant e por `encounterId` (mesmo padrão de namespacing já usado pelo storage da Fase 0), nunca grava um documento sem esse prefixo.
- Nenhuma tabela nova é criada — os metadados persistem via `stored_object`, já existente.

**Validação**
- `php -l src/app/Core/Application/EncounterDocumentService.php` → `No syntax errors detected`
- Teste unitário (T-08) confirmando que a chave gerada contém o `tenantId` e o `encounterId` informados

---

### T-06 — Tela única de Atendimento (EncounterView)

**Camada:** backend/frontend
**Dependências:** T-03, T-04, T-05
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`
- `src/app/model/clinic/Encounter.php`

**Interface**
- Produz: `EncounterView`, consumindo `CentralVet\Application\EncounterService` (T-03), `CentralVet\Assistant\NullAiClinicalAssistant` (T-04), `CentralVet\Application\EncounterDocumentService` (T-05) e os services já existentes da Fase 1 (`CentralVet\Application\AppointmentService` para a ação "Retorno", `CentralVet\Application\ServiceCatalogService` para o preço do resumo financeiro)
- Consome: T-03 `CentralVet\Application\EncounterService`
- Consome: T-04 `CentralVet\Assistant\NullAiClinicalAssistant`
- Consome: T-05 `CentralVet\Application\EncounterDocumentService`

**Critério de aceite**
- O campo de anamnese usa `TText::enableSpeechRecognition()` (não uma implementação própria de reconhecimento de voz).
- A ação "Retorno" chama `AppointmentService::schedule()` de verdade (cria um `Appointment`); as ações "Prescrição"/"Exame"/"Procedimento"/"Vacina" abrem um painel e, no máximo, geram um evento em `audit_log` — nenhuma delas grava em uma tabela própria.
- Uma chamada periódica de autosave (`setInterval` client-side + `TAction` `onAutosave` server-side) persiste o rascunho corrente sem exigir que o usuário clique em salvar.
- `AuthorizationDenied`, `CrossTenantReferenceException` e `SchedulingConflictException` (esta última vinda da ação "Retorno") são capturadas e viram `TMessage` tratado, nunca erro fatal.

**Validação**
- `php -l src/app/control/clinic/EncounterView.php src/app/model/clinic/Encounter.php` → `No syntax errors detected` nos 2
- `docker compose exec app php -r "require 'engine.php'; new EncounterView(); echo 'OK';"` → conclui sem erro fatal (containers já reconstruídos)

---

### T-07 — Registrar EncounterView em menu.xml e RBAC nativo

**Camada:** infra
**Dependências:** T-06
**Paralelizável:** sim (com T-08)
**Complexidade:** média
**Agente:** Naruto

**Arquivos prováveis**
- `src/menu.xml`

**Interface**
- Produz: entrada de menu para `EncounterView` em `src/menu.xml`, mais o `INSERT` exato (com ids calculados por `SELECT MAX(id)+1`) para registrar `EncounterView` em `system_program` e vinculá-la ao grupo "Template - Admin" via `system_group_program` — redigido e pronto, mas **não executado** pelo agente (a execução, com autorização SQL do usuário, é feita pelo orquestrador logo em seguida, mesmo procedimento da Fase 1)
- Consome: T-06 `EncounterView`

**Critério de aceite**
- `menu.xml` continua um XML válido e a entrada de `EncounterView` aparece nele.
- Um usuário do grupo "Template - Admin" consegue instanciar `EncounterView` a partir de uma sessão autenticada real (não só via `php -r` fora do fluxo HTTP) — este critério só é considerado cumprido com essa verificação, não apenas com a existência da linha em `system_program`.

**Validação**
- `php -r "simplexml_load_file('src/menu.xml') !== false or exit(1); echo 'menu.xml OK';"` → `menu.xml OK`
- Consulta somente leitura confirmando as linhas em `system_program`/`system_group_program` para `EncounterView`, e um teste de acesso real (mesmo padrão usado para validar `ServiceList`/`PatientList`/`QueueEntryView` na Fase 1) confirmando que a classe não recusa mais acesso por ausência de registro

---

### T-08 — Testes unitários de Encounter e documentos

**Camada:** shared
**Dependências:** T-03, T-04
**Paralelizável:** sim (com T-07)
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `src/tests/Unit/EncounterServiceTest.php`
- `src/tests/Unit/EncounterDocumentServiceTest.php`

**Interface**
- Produz: cobertura dos critérios de aceite de T-03 (recusa de `start()`/`finish()` por autorização negada, `timeline()` lendo só de `audit_log`), T-04 (ausência de exceção no placeholder de IA) e T-05 (prefixo de chave por tenant/encounter), com fakes em memória das interfaces envolvidas, sem depender do banco real
- Consome: T-03 `CentralVet\Application\EncounterService`
- Consome: T-04 `CentralVet\Assistant\NullAiClinicalAssistant`

**Critério de aceite**
- A suíte completa (testes já existentes mais os novos) termina com zero falhas.

**Validação**
- `docker compose exec app php tests/run.php` → `Failed: 0`, com `Total` maior que 117

---

### T-09 — Revisão de segurança e quality gate da Fase 2

**Camada:** shared
**Dependências:** T-07, T-08
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `.tasks/04-fase-2-nucleo-clinico/notes.md`

**Interface**
- Produz: parecer final registrado em `notes.md` cobrindo fail-closed por tenant/unidade em `EncounterService`, ausência de chamada real a IA, ausência de segredo real em qualquer arquivo criado, e confirmação de que a migration de T-01 segue não aplicada (a menos que o usuário já a tenha autorizado)
- Consome: nada formalmente (revisão consome as evidências já reportadas por T-01 a T-08)

**Critério de aceite**
- Nenhum bloqueante em aberto; toda pendência conhecida é listada explicitamente em `notes.md`.

**Validação**
- `docker compose exec app php tests/run.php` → `Failed: 0`
- Checagem somente leitura confirmando que um usuário do grupo "Template - Admin" acessa `EncounterView` de verdade (evidência de T-07 revalidada)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
