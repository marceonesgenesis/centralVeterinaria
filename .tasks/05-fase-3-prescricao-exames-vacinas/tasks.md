# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Preparar migration de prescrição/exame/vacina (8 tabelas) | — | não | alta | Athena | [x] |
| T-02 | backend | Criar os 7 contratos de Domain | T-01 | não | média | Athena | [x] |
| T-03 | backend | Prescrição — Domain, Repository e Application service | T-02 | sim | média | Athena | [x] |
| T-04 | backend | Exame — Domain, Repository e Application service (catálogo + solicitação + resultado) | T-02 | sim | alta | Jaspion | [x] |
| T-05 | backend | Vacina — Domain, Repository e Application service (catálogo + protocolo + aplicação) | T-02 | sim | alta | Jaspion | [x] |
| T-06 | backend/frontend | Tela de Prescrição (formulário + PDF) | T-03 | sim | média | Aang | [x] |
| T-07 | backend/frontend | Telas de Exame (catálogo, solicitação, resultado) | T-04 | sim | alta | Tesla | [x] |
| T-08 | backend/frontend | Telas de Vacina (catálogo, protocolo, aplicação, carteira) | T-05 | sim | alta | Tesla | [x] |
| T-09 | backend/frontend | Conectar EncounterView às 3 telas novas | T-06,T-07,T-08 | não | média | Aang | [x] |
| T-10 | infra | Registrar as telas novas em menu.xml e RBAC nativo | T-09 | sim | média | Naruto | [x] |
| T-11 | shared | Testes unitários de Prescrição, Exame e Vacinação | T-03,T-04,T-05 | sim | alta | Levi | [x] |
| T-12 | shared | Revisão de segurança e quality gate da Fase 3 | T-10,T-11 | não | alta | Levi | [x] |

## Detalhamento

### T-01 — Preparar migration de prescrição/exame/vacina (8 tabelas)

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql`
- `src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.verify.sql`

**Interface**
- Produz: `src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql` criando `prescription`, `prescription_item`, `exam_catalog_item`, `exam_request`, `exam_result`, `vaccine_catalog_item`, `vaccine_protocol`, `vaccination`, todas com `tenant_id bigint unsigned NOT NULL` e FK para `tenant(id)`, seguindo a convenção já estabelecida nas migrations anteriores
- Consome: nada dentro deste plano (segue a convenção já estabelecida nas migrations das Fases 0-2, fora deste plano)

**Critério de aceite**
- O arquivo contém o comentário `Status: PREPARED ONLY`, igual ao padrão das migrations anteriores.
- `prescription`/`exam_request`/`vaccination` têm FK para `encounter(id)` e para `patient(id)`; `exam_request` tem FK para `exam_catalog_item(id)`; `exam_result` tem FK para `exam_request(id)`; `vaccination` tem FK para `vaccine_catalog_item(id)`; `vaccine_protocol` tem FK para `vaccine_catalog_item(id)`.
- O arquivo `.verify.sql` contém apenas instruções `SELECT`.
- Nenhum comando é executado contra o MySQL por esta task.

**Validação**
- `grep -c "Status: PREPARED ONLY" src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql` → `1`
- `grep -c "^CREATE TABLE" src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql` → `8`
- `grep -Ei "^\s*(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)" src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.verify.sql` → nenhuma linha impressa

---

### T-02 — Criar os 7 contratos de Domain

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** não
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/PrescriptionRepositoryInterface.php`
- `src/app/Core/Domain/Contract/ExamCatalogRepositoryInterface.php`
- `src/app/Core/Domain/Contract/ExamRequestRepositoryInterface.php`
- `src/app/Core/Domain/Contract/ExamResultRepositoryInterface.php`
- `src/app/Core/Domain/Contract/VaccineCatalogRepositoryInterface.php`
- `src/app/Core/Domain/Contract/VaccineProtocolRepositoryInterface.php`
- `src/app/Core/Domain/Contract/VaccinationRepositoryInterface.php`

**Interface**
- Produz: `CentralVet\Domain\Contract\PrescriptionRepositoryInterface`, `CentralVet\Domain\Contract\ExamCatalogRepositoryInterface`, `CentralVet\Domain\Contract\ExamRequestRepositoryInterface`, `CentralVet\Domain\Contract\ExamResultRepositoryInterface`, `CentralVet\Domain\Contract\VaccineCatalogRepositoryInterface`, `CentralVet\Domain\Contract\VaccineProtocolRepositoryInterface`, `CentralVet\Domain\Contract\VaccinationRepositoryInterface`, todas estendendo `CentralVet\Persistence\TenantRepositoryInterface`, já existente da Fase 0
- Consome: nada dentro deste plano (usa `CentralVet\Persistence\TenantRepositoryInterface`, já existente da Fase 0)

**Critério de aceite**
- As 7 interfaces existem em `Domain/Contract`, cada uma estendendo `TenantRepositoryInterface`, e nenhuma delas referencia `TPage` ou qualquer classe do Adianti.

**Validação**
- `php -l` nos 7 arquivos → `No syntax errors detected` em todos
- `grep -L "extends TenantRepositoryInterface" src/app/Core/Domain/Contract/PrescriptionRepositoryInterface.php src/app/Core/Domain/Contract/ExamCatalogRepositoryInterface.php src/app/Core/Domain/Contract/ExamRequestRepositoryInterface.php src/app/Core/Domain/Contract/ExamResultRepositoryInterface.php src/app/Core/Domain/Contract/VaccineCatalogRepositoryInterface.php src/app/Core/Domain/Contract/VaccineProtocolRepositoryInterface.php src/app/Core/Domain/Contract/VaccinationRepositoryInterface.php` → nenhuma linha impressa

---

### T-03 — Prescrição — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim (com T-04, T-05)
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Prescription.php`
- `src/app/Core/Domain/PrescriptionItem.php`
- `src/app/Core/Persistence/PrescriptionRepository.php`
- `src/app/Core/Application/PrescriptionService.php`

**Interface**
- Produz: `CentralVet\Application\PrescriptionService` com `create(array $data, string $action): Prescription` (`encounter_id`, `patient_id`, `professional_system_user_id`, `orientation`, lista de itens: `medication_name`, `dose`, `dose_unit`, `route`, `frequency`, `duration`) e `findById(int $id): ?Prescription`; `CentralVet\Persistence\PrescriptionRepository` implementando `PrescriptionRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\PrescriptionRepositoryInterface`

**Critério de aceite**
- `PrescriptionRepository` estende `AbstractTenantRepository` e implementa `PrescriptionRepositoryInterface`; toda query nasce de `TenantQuery::forTenant(...)`.
- `create()` chama `AuthorizationPolicyInterface::decide(...)` com `requiresUnitScope: true` e `resourceUnitId` vindo da unidade do `encounter` de origem, antes de gravar qualquer coisa; uma decisão negada lança `AuthorizationDenied` sem persistir nada.

**Validação**
- `php -l src/app/Core/Domain/Prescription.php src/app/Core/Domain/PrescriptionItem.php src/app/Core/Persistence/PrescriptionRepository.php src/app/Core/Application/PrescriptionService.php` → `No syntax errors detected` nos 4
- Teste unitário (T-11) cobrindo a recusa de `create()` quando a política de autorização nega

---

### T-04 — Exame — Domain, Repository e Application service (catálogo + solicitação + resultado)

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim (com T-03, T-05)
**Complexidade:** alta
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Domain/ExamCatalogItem.php`
- `src/app/Core/Domain/ExamRequest.php`
- `src/app/Core/Domain/ExamResult.php`
- `src/app/Core/Persistence/ExamCatalogRepository.php`
- `src/app/Core/Persistence/ExamRequestRepository.php`
- `src/app/Core/Persistence/ExamResultRepository.php`
- `src/app/Core/Application/ExamCatalogService.php`
- `src/app/Core/Application/ExamService.php`

**Interface**
- Produz: `CentralVet\Application\ExamCatalogService` com `create(array $data): ExamCatalogItem` (nome, parceiro, preço) e `listActive(): array`; `CentralVet\Application\ExamService` com `requestExam(array $data, string $action): ExamRequest` (`encounter_id`, `patient_id`, `exam_catalog_item_id`, `professional_system_user_id`), `recordResult(int $requestId, array $data, string $action): ExamResult` (`structured_result`, `stored_object_key` opcional, `pending_review`) e `findById(int $id): ?ExamRequest`; os 3 Repositories implementando as interfaces correspondentes
- Consome: T-02 `CentralVet\Domain\Contract\ExamCatalogRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\ExamRequestRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\ExamResultRepositoryInterface`

**Critério de aceite**
- Os 3 Repositories estendem `AbstractTenantRepository` e implementam a interface correspondente.
- `requestExam()` e `recordResult()` chamam `AuthorizationPolicyInterface::decide(...)` com `requiresUnitScope: true` (unidade do `encounter`/da solicitação de origem) antes de gravar; decisão negada lança `AuthorizationDenied` sem persistir.
- `requestExam()` marca o status inicial como pendente de resultado; `recordResult()` muda o status para indicar resultado disponível.

**Validação**
- `php -l` nos 8 arquivos → `No syntax errors detected` em todos
- Teste unitário (T-11) cobrindo a recusa de `requestExam()`/`recordResult()` quando a política de autorização nega

---

### T-05 — Vacina — Domain, Repository e Application service (catálogo + protocolo + aplicação)

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim (com T-03, T-04)
**Complexidade:** alta
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Domain/VaccineCatalogItem.php`
- `src/app/Core/Domain/VaccineProtocol.php`
- `src/app/Core/Domain/Vaccination.php`
- `src/app/Core/Persistence/VaccineCatalogRepository.php`
- `src/app/Core/Persistence/VaccineProtocolRepository.php`
- `src/app/Core/Persistence/VaccinationRepository.php`
- `src/app/Core/Application/VaccineCatalogService.php`
- `src/app/Core/Application/VaccinationService.php`

**Interface**
- Produz: `CentralVet\Application\VaccineCatalogService` com `create(array $data): VaccineCatalogItem` (nome, fabricante, `stock_quantity`), `listActive(): array` e `findById(int $id): ?VaccineCatalogItem`; `CentralVet\Application\VaccinationService` com `apply(array $data, string $action): Vaccination` (`encounter_id`, `patient_id`, `vaccine_catalog_item_id`, `lot`, `expiry_date`, `dose_number`, `professional_system_user_id`) e `historyByPatient(int $patientId): array` (para a carteira); os 3 Repositories implementando as interfaces correspondentes
- Consome: T-02 `CentralVet\Domain\Contract\VaccineCatalogRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\VaccineProtocolRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\VaccinationRepositoryInterface`

**Critério de aceite**
- Os 3 Repositories estendem `AbstractTenantRepository` e implementam a interface correspondente.
- `apply()` chama `AuthorizationPolicyInterface::decide(...)` com `requiresUnitScope: true` (unidade do `encounter` de origem) antes de gravar; decisão negada lança `AuthorizationDenied` sem persistir.
- `apply()` decrementa `vaccine_catalog_item.stock_quantity` em 1 e calcula `next_dose_at` a partir do intervalo do `VaccineProtocol` correspondente ao `dose_number` seguinte, quando existir um protocolo configurado.

**Validação**
- `php -l` nos 8 arquivos → `No syntax errors detected` em todos
- Teste unitário (T-11) cobrindo a recusa de `apply()` quando a política de autorização nega, e a baixa de estoque em uma aplicação aceita

---

### T-06 — Tela de Prescrição (formulário + PDF)

**Camada:** backend/frontend
**Dependências:** T-03
**Paralelizável:** sim (com T-07, T-08)
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/PrescriptionForm.php`
- `src/app/model/clinic/Prescription.php`

**Interface**
- Produz: `PrescriptionForm` (formulário de prescrição com itens repetíveis, consumindo `CentralVet\Application\PrescriptionService`, com exportação em PDF via `dompdf`, já disponível no `composer.json`)
- Consome: T-03 `CentralVet\Application\PrescriptionService`

**Critério de aceite**
- `PrescriptionForm` não contém nenhuma regra de negócio própria — toda validação/decisão fica em `PrescriptionService`.
- `AuthorizationDenied` é capturada e vira `TMessage` tratado, nunca erro fatal.

**Validação**
- `php -l src/app/control/clinic/PrescriptionForm.php src/app/model/clinic/Prescription.php` → `No syntax errors detected` nos 2
- `docker compose exec app php -r "require 'engine.php'; new PrescriptionForm(); echo 'OK';"` → conclui sem erro fatal (containers já reconstruídos)

---

### T-07 — Telas de Exame (catálogo, solicitação, resultado)

**Camada:** backend/frontend
**Dependências:** T-04
**Paralelizável:** sim (com T-06, T-08)
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/ExamCatalogForm.php`
- `src/app/control/clinic/ExamCatalogList.php`
- `src/app/control/clinic/ExamRequestForm.php`
- `src/app/control/clinic/ExamResultForm.php`
- `src/app/model/clinic/ExamCatalogItem.php`

**Interface**
- Produz: `ExamCatalogForm` e `ExamCatalogList` (CRUD simples de catálogo, consumindo `ExamCatalogService`); `ExamRequestForm` (solicitação vinculada a um encounter, consumindo `ExamService::requestExam`); `ExamResultForm` (registro de resultado, com anexo de arquivo reaproveitando o mesmo padrão de `StorageInterface` já usado por `EncounterDocumentService` na Fase 2, consumindo `ExamService::recordResult`)
- Consome: T-04 `CentralVet\Application\ExamCatalogService`
- Consome: T-04 `CentralVet\Application\ExamService`

**Critério de aceite**
- Nenhuma das 4 telas contém regra de negócio própria — tudo delega para `ExamCatalogService`/`ExamService`.
- `AuthorizationDenied` é capturada e vira `TMessage` tratado, nunca erro fatal.

**Validação**
- `php -l` nos 5 arquivos → `No syntax errors detected` em todos
- `docker compose exec app php -r "require 'engine.php'; new ExamCatalogForm(); new ExamCatalogList(); new ExamRequestForm(); new ExamResultForm(); echo 'OK';"` → conclui sem erro fatal

---

### T-08 — Telas de Vacina (catálogo, protocolo, aplicação, carteira)

**Camada:** backend/frontend
**Dependências:** T-05
**Paralelizável:** sim (com T-06, T-07)
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/VaccineCatalogForm.php`
- `src/app/control/clinic/VaccineCatalogList.php`
- `src/app/control/clinic/VaccineProtocolForm.php`
- `src/app/control/clinic/VaccinationForm.php`
- `src/app/control/clinic/VaccinationCardView.php`
- `src/app/model/clinic/VaccineCatalogItem.php`

**Interface**
- Produz: `VaccineCatalogForm` e `VaccineCatalogList` (CRUD de catálogo, consumindo `VaccineCatalogService`); `VaccineProtocolForm` (CRUD de protocolo, vinculado a um item de catálogo); `VaccinationForm` (aplicação de vacina vinculada a um encounter, consumindo `VaccinationService::apply`); `VaccinationCardView` (carteira — listagem do histórico via `VaccinationService::historyByPatient`)
- Consome: T-05 `CentralVet\Application\VaccineCatalogService`
- Consome: T-05 `CentralVet\Application\VaccinationService`

**Critério de aceite**
- Nenhuma das 5 telas contém regra de negócio própria — tudo delega para `VaccineCatalogService`/`VaccinationService`.
- `AuthorizationDenied` é capturada e vira `TMessage` tratado, nunca erro fatal.

**Validação**
- `php -l` nos 6 arquivos → `No syntax errors detected` em todos
- `docker compose exec app php -r "require 'engine.php'; new VaccineCatalogForm(); new VaccineCatalogList(); new VaccineProtocolForm(); new VaccinationForm(); new VaccinationCardView(); echo 'OK';"` → conclui sem erro fatal

---

### T-09 — Conectar EncounterView às 3 telas novas

**Camada:** backend/frontend
**Dependências:** T-06, T-07, T-08
**Paralelizável:** não
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: os botões de ação inline "Prescrição", "Exame" e "Vacina" em `EncounterView`, hoje só gravando um evento em `audit_log`, passam a abrir/redirecionar para `PrescriptionForm`, `ExamRequestForm` e `VaccinationForm` respectivamente, levando `encounter_id`/`patient_id` como parâmetro
- Consome: T-06 `PrescriptionForm`
- Consome: T-07 `ExamRequestForm`
- Consome: T-08 `VaccinationForm`

**Critério de aceite**
- Nenhuma regra de negócio nova é adicionada a `EncounterView` — a edição só troca o destino dos 3 botões já existentes.
- As demais ações inline (Procedimento) continuam exatamente como estavam (casca de UI + `audit_log`).

**Validação**
- `php -l src/app/control/clinic/EncounterView.php` → `No syntax errors detected`
- `docker compose exec app php -r "require 'engine.php'; new EncounterView(); echo 'OK';"` → conclui sem erro fatal

---

### T-10 — Registrar as telas novas em menu.xml e RBAC nativo

**Camada:** infra
**Dependências:** T-09
**Paralelizável:** sim (com T-11)
**Complexidade:** média
**Agente:** Naruto

**Arquivos prováveis**
- `src/menu.xml`

**Interface**
- Produz: entradas de menu em `src/menu.xml` para as 10 telas novas, mais o `INSERT` exato (com ids calculados por `SELECT MAX(id)+1`) para registrar cada uma em `system_program` e vinculá-la ao grupo "Template - Admin" via `system_group_program` — redigido e pronto, mas **não executado** pelo agente (a execução, com autorização SQL do usuário, é feita pelo orquestrador, mesmo procedimento das Fases 1 e 2)
- Consome: T-06 `PrescriptionForm`
- Consome: T-07 `ExamCatalogForm`
- Consome: T-07 `ExamCatalogList`
- Consome: T-07 `ExamRequestForm`
- Consome: T-07 `ExamResultForm`
- Consome: T-08 `VaccineCatalogForm`
- Consome: T-08 `VaccineCatalogList`
- Consome: T-08 `VaccineProtocolForm`
- Consome: T-08 `VaccinationForm`
- Consome: T-08 `VaccinationCardView`

**Critério de aceite**
- `menu.xml` continua um XML válido e as 10 entradas aparecem nele.
- O SQL redigido cobre exatamente as 10 classes, sem faltar nenhuma.

**Validação**
- `php -r "simplexml_load_file('src/menu.xml') !== false or exit(1); echo 'menu.xml OK';"` → `menu.xml OK`
- `grep -c "PrescriptionForm\|ExamCatalogForm\|ExamCatalogList\|ExamRequestForm\|ExamResultForm\|VaccineCatalogForm\|VaccineCatalogList\|VaccineProtocolForm\|VaccinationForm\|VaccinationCardView" src/menu.xml` → `10` ou mais

---

### T-11 — Testes unitários de Prescrição, Exame e Vacinação

**Camada:** shared
**Dependências:** T-03, T-04, T-05
**Paralelizável:** sim (com T-10)
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `src/tests/Unit/PrescriptionServiceTest.php`
- `src/tests/Unit/ExamServiceTest.php`
- `src/tests/Unit/VaccinationServiceTest.php`

**Interface**
- Produz: cobertura dos critérios de aceite de T-03, T-04 e T-05 (recusa de autorização por unidade antes de gravar, transição de status de exame, baixa de estoque e cálculo de próxima dose na vacinação), com fakes em memória das interfaces envolvidas, sem depender do banco real
- Consome: T-03 `CentralVet\Application\PrescriptionService`
- Consome: T-04 `CentralVet\Application\ExamService`
- Consome: T-05 `CentralVet\Application\VaccinationService`

**Critério de aceite**
- A suíte completa (testes já existentes mais os novos) termina com zero falhas.

**Validação**
- `docker compose exec app php tests/run.php` → `Failed: 0`, com `Total` maior que 130

---

### T-12 — Revisão de segurança e quality gate da Fase 3

**Camada:** shared
**Dependências:** T-10, T-11
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `.tasks/05-fase-3-prescricao-exames-vacinas/notes.md`

**Interface**
- Produz: parecer final registrado em `notes.md` cobrindo fail-closed por tenant/unidade nas 3 novas Application services, ausência de módulo de estoque completo (só o contador simples), ausência de segredo real em qualquer arquivo criado, e confirmação de que a migration de T-01 segue não aplicada (a menos que o usuário já a tenha autorizado)
- Consome: nada formalmente (revisão consome as evidências já reportadas por T-01 a T-11)

**Critério de aceite**
- Nenhum bloqueante em aberto; toda pendência conhecida é listada explicitamente em `notes.md`.

**Validação**
- `docker compose exec app php tests/run.php` → `Failed: 0`
- Checagem somente leitura confirmando que um usuário do grupo "Template - Admin" acessa as telas novas de verdade (evidência de T-10 revalidada)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
