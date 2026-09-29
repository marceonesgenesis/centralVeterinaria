# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Preparar migration de tutor, paciente, serviço, agenda e fila | — | sim | alta | Athena | [x] |
| T-02 | backend | Criar contratos de Domain das 5 entidades novas | T-01 | não | média | Athena | [x] |
| T-03 | backend | Tenant-scoping de Unidades e Usuários (telas legadas) | — | sim | média | Jaspion | [x] |
| T-04 | backend | Tutor — Domain, Repository e Application service | T-02 | sim | média | Athena | [x] |
| T-05 | backend | Paciente — Domain, Repository e Application service | T-02 | sim | média | Athena | [x] |
| T-06 | backend | Serviço (catálogo) — Domain, Repository e Application service | T-02 | sim | simples | Athena | [x] |
| T-07 | backend | Agendamento (Agenda) — Domain, Repository e Application service | T-05,T-06 | sim | alta | Jaspion | [x] |
| T-08 | backend | Fila de atendimento — Domain, Repository e Application service | T-05 | sim | média | Jaspion | [x] |
| T-09 | backend/frontend | Tela Adianti de Tutor (Form/List) | T-04 | sim | média | Aang | [x] |
| T-10 | backend/frontend | Tela Adianti de Paciente (Form/List) | T-05 | sim | média | Aang | [x] |
| T-11 | backend/frontend | Tela Adianti de Serviço (Form/List) | T-06 | sim | simples | Aang | [x] |
| T-12 | backend/frontend | Tela Adianti de Agenda | T-07 | sim | alta | Tesla | [x] |
| T-13 | backend/frontend | Tela Adianti de Fila de atendimento | T-08 | sim | média | Tesla | [x] |
| T-14 | backend/frontend | Busca inicial (global) | T-04,T-05 | sim | simples | Tesla | [x] |
| T-15 | infra | Registrar as 6 telas novas em `menu.xml` | T-09,T-10,T-11,T-12,T-13,T-14 | não | simples | Naruto | [x] |
| T-16 | shared | Testes unitários e de integração das novas entidades | T-04,T-05,T-06,T-07,T-08 | sim | alta | Levi | [x] |
| T-17 | shared | Revisão de segurança e quality gate da Fase 1 | T-15,T-16 | não | alta | Levi | [x] |

## Detalhamento

### T-01 — Preparar migration de tutor, paciente, serviço, agenda e fila

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim (com T-03)
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/database/migrations/20260921_0002_phase1_clinic_core.sql`
- `src/app/database/migrations/20260921_0002_phase1_clinic_core.verify.sql`

**Interface**
- Produz: `src/app/database/migrations/20260921_0002_phase1_clinic_core.sql` contendo as tabelas `tutor`, `patient`, `service`, `appointment` e `queue_entry`, todas com `tenant_id bigint unsigned NOT NULL` referenciando `tenant(id)`, seguindo a convenção de `20260920_0001_foundation_multitenancy.sql`
- Consome: nada dentro deste plano (segue a convenção já estabelecida na migration da Fase 0, fora deste plano)

**Critério de aceite**
- O arquivo contém o comentário `-- Status: PREPARED ONLY`, igual ao usado na migration da Fase 0.
- O arquivo `.verify.sql` contém apenas instruções `SELECT`.
- Nenhum comando é executado contra o MySQL por esta task.

**Validação**
- `grep -c "Status: PREPARED ONLY" src/app/database/migrations/20260921_0002_phase1_clinic_core.sql` → `1`
- `grep -Ei "^\s*(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)" src/app/database/migrations/20260921_0002_phase1_clinic_core.verify.sql` → nenhuma linha impressa

---

### T-02 — Criar contratos de Domain das 5 entidades novas

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** não
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/TutorRepositoryInterface.php`
- `src/app/Core/Domain/Contract/PatientRepositoryInterface.php`
- `src/app/Core/Domain/Contract/ServiceRepositoryInterface.php`
- `src/app/Core/Domain/Contract/AppointmentRepositoryInterface.php`
- `src/app/Core/Domain/Contract/QueueEntryRepositoryInterface.php`

**Interface**
- Produz: `CentralVet\Domain\Contract\TutorRepositoryInterface`, `CentralVet\Domain\Contract\PatientRepositoryInterface`, `CentralVet\Domain\Contract\ServiceRepositoryInterface`, `CentralVet\Domain\Contract\AppointmentRepositoryInterface`, `CentralVet\Domain\Contract\QueueEntryRepositoryInterface` — cada uma estendendo `CentralVet\Persistence\TenantRepositoryInterface`, já existente da Fase 0
- Consome: nada dentro deste plano (usa `CentralVet\Persistence\TenantRepositoryInterface`, já existente da Fase 0)

**Critério de aceite**
- As 5 interfaces existem em `Domain/Contract`, cada uma estendendo `TenantRepositoryInterface`, e nenhuma delas referencia `TPage` ou qualquer classe do Adianti.

**Validação**
- `php -l src/app/Core/Domain/Contract/TutorRepositoryInterface.php src/app/Core/Domain/Contract/PatientRepositoryInterface.php src/app/Core/Domain/Contract/ServiceRepositoryInterface.php src/app/Core/Domain/Contract/AppointmentRepositoryInterface.php src/app/Core/Domain/Contract/QueueEntryRepositoryInterface.php` → `No syntax errors detected` nos 5
- `grep -L "extends TenantRepositoryInterface" src/app/Core/Domain/Contract/TutorRepositoryInterface.php src/app/Core/Domain/Contract/PatientRepositoryInterface.php src/app/Core/Domain/Contract/ServiceRepositoryInterface.php src/app/Core/Domain/Contract/AppointmentRepositoryInterface.php src/app/Core/Domain/Contract/QueueEntryRepositoryInterface.php` → nenhuma linha impressa

---

### T-03 — Tenant-scoping de Unidades e Usuários (telas legadas)

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim (com T-01)
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/control/admin/SystemUnitForm.php`
- `src/app/control/admin/SystemUnitList.php`
- `src/app/control/admin/SystemUserForm.php`
- `src/app/control/admin/SystemUserList.php`

**Interface**
- Produz: filtro `tenant_id` aplicado à listagem e à abertura por id em `SystemUnitList`, `SystemUnitForm`, `SystemUserList` e `SystemUserForm`, usando `CentralVet\Tenancy\TenantContext`, já existente da Fase 0
- Consome: nada dentro deste plano (usa `CentralVet\Tenancy\TenantContext`, já existente da Fase 0)

**Critério de aceite**
- Um usuário autenticado do tenant A lista e abre apenas os registros de `system_unit`/`system_users` do tenant A; uma tentativa de abrir por id um registro de outro tenant é recusada.
- A quantidade de registros retornados para o tenant A antes e depois desta mudança é idêntica (nenhum registro do próprio tenant deixa de aparecer).

**Validação**
- Teste de integração dedicado (criado em T-16) provando isolamento entre dois tenants fictícios → `docker compose exec app php tests/run.php` → `Failed: 0`
- `php -l src/app/control/admin/SystemUnitForm.php src/app/control/admin/SystemUnitList.php src/app/control/admin/SystemUserForm.php src/app/control/admin/SystemUserList.php` → `No syntax errors detected` nos 4

---

### T-04 — Tutor — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim (com T-05, T-06)
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Tutor.php`
- `src/app/Core/Persistence/TutorRepository.php`
- `src/app/Core/Application/TutorService.php`

**Interface**
- Produz: `CentralVet\Application\TutorService` com os métodos `search(string $query): array` (por nome, CPF ou telefone), `create(array $data): Tutor` e `findById(int $id): ?Tutor`; `CentralVet\Persistence\TutorRepository` implementando `TutorRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\TutorRepositoryInterface`

**Critério de aceite**
- `TutorRepository` estende `AbstractTenantRepository` e implementa `TutorRepositoryInterface`; toda consulta começa por `tenant_id = :tenant_scope_id` via `TenantQuery`.
- `TutorService` não contém nenhuma referência a `TPage` nem a qualquer classe do Adianti.

**Validação**
- `php -l src/app/Core/Domain/Tutor.php src/app/Core/Persistence/TutorRepository.php src/app/Core/Application/TutorService.php` → `No syntax errors detected` nos 3
- `grep -c "TPage" src/app/Core/Application/TutorService.php` → `0`

---

### T-05 — Paciente — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim (com T-04, T-06)
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Patient.php`
- `src/app/Core/Persistence/PatientRepository.php`
- `src/app/Core/Application/PatientService.php`

**Interface**
- Produz: `CentralVet\Application\PatientService` com os métodos `create(array $data): Patient` (nome, espécie, raça, sexo, data de nascimento, peso, cor, observações, `tutor_id`), `findById(int $id): ?Patient` e `findByTutor(int $tutorId): array`; `CentralVet\Persistence\PatientRepository` implementando `PatientRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\PatientRepositoryInterface`

**Critério de aceite**
- `PatientRepository` estende `AbstractTenantRepository` e implementa `PatientRepositoryInterface`.
- `create()` rejeita um `tutor_id` que pertence a outro tenant, lançando uma exceção específica em vez de gravar o registro.

**Validação**
- `php -l src/app/Core/Domain/Patient.php src/app/Core/Persistence/PatientRepository.php src/app/Core/Application/PatientService.php` → `No syntax errors detected` nos 3
- Teste unitário (T-16) cobrindo a rejeição de `tutor_id` de outro tenant → `docker compose exec app php tests/run.php` → `Failed: 0`

---

### T-06 — Serviço (catálogo) — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim (com T-04, T-05)
**Complexidade:** simples
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Service.php`
- `src/app/Core/Persistence/ServiceRepository.php`
- `src/app/Core/Application/ServiceCatalogService.php`

**Interface**
- Produz: `CentralVet\Application\ServiceCatalogService` com os métodos `create(array $data): Service` (nome, categoria, duração em minutos, preço em centavos) e `listActive(): array`; `CentralVet\Persistence\ServiceRepository` implementando `ServiceRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\ServiceRepositoryInterface`

**Critério de aceite**
- `ServiceRepository` estende `AbstractTenantRepository` e implementa `ServiceRepositoryInterface`.
- `listActive()` retorna somente registros do tenant corrente com `active = true`.

**Validação**
- `php -l src/app/Core/Domain/Service.php src/app/Core/Persistence/ServiceRepository.php src/app/Core/Application/ServiceCatalogService.php` → `No syntax errors detected` nos 3
- Teste unitário (T-16) cobrindo o filtro `active` → `docker compose exec app php tests/run.php` → `Failed: 0`

---

### T-07 — Agendamento (Agenda) — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-05, T-06
**Paralelizável:** sim (com T-08)
**Complexidade:** alta
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Domain/Appointment.php`
- `src/app/Core/Persistence/AppointmentRepository.php`
- `src/app/Core/Application/AppointmentService.php`

**Interface**
- Produz: `CentralVet\Application\AppointmentService` com os métodos `schedule(array $data): Appointment` (`patient_id`, `service_id`, `professional_system_user_id`, `scheduled_at`, `system_unit_id`) e `listByProfessionalAndDate(int $professionalId, \DateTimeImmutable $date): array`; `CentralVet\Persistence\AppointmentRepository` implementando `AppointmentRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\AppointmentRepositoryInterface`
- Consome: T-05 `CentralVet\Application\PatientService`
- Consome: T-06 `CentralVet\Application\ServiceCatalogService`

**Critério de aceite**
- `schedule()` recusa um novo agendamento quando o intervalo `scheduled_at` até `scheduled_at + duração do serviço` sobrepõe outro agendamento já existente do mesmo `professional_system_user_id`.
- `AppointmentRepository` estende `AbstractTenantRepository` e implementa `AppointmentRepositoryInterface`.

**Validação**
- `php -l src/app/Core/Domain/Appointment.php src/app/Core/Persistence/AppointmentRepository.php src/app/Core/Application/AppointmentService.php` → `No syntax errors detected` nos 3
- Teste unitário (T-16) cobrindo a recusa de conflito de horário → `docker compose exec app php tests/run.php` → `Failed: 0`

---

### T-08 — Fila de atendimento — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-05
**Paralelizável:** sim (com T-07)
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Domain/QueueEntry.php`
- `src/app/Core/Persistence/QueueEntryRepository.php`
- `src/app/Core/Application/QueueEntryService.php`

**Interface**
- Produz: `CentralVet\Application\QueueEntryService` com os métodos `checkIn(array $data): QueueEntry` (`patient_id`, `professional_system_user_id`, `system_unit_id`, `appointment_id` opcional), `advanceStatus(int $id): QueueEntry` (transição `aguardando` → `em_atendimento` → `atendido`) e `listToday(int $systemUnitId): array`; `CentralVet\Persistence\QueueEntryRepository` implementando `QueueEntryRepositoryInterface`
- Consome: T-02 `CentralVet\Domain\Contract\QueueEntryRepositoryInterface`
- Consome: T-05 `CentralVet\Application\PatientService`

**Critério de aceite**
- `advanceStatus()` só aceita a sequência `aguardando` → `em_atendimento` → `atendido`; uma chamada que tente pular uma etapa ou retroceder é recusada.
- Um `queue_entry` criado com `appointment_id` nulo (entrada avulsa) é aceito normalmente.

**Validação**
- `php -l src/app/Core/Domain/QueueEntry.php src/app/Core/Persistence/QueueEntryRepository.php src/app/Core/Application/QueueEntryService.php` → `No syntax errors detected` nos 3
- Teste unitário (T-16) cobrindo a recusa de transição de status inválida → `docker compose exec app php tests/run.php` → `Failed: 0`

---

### T-09 — Tela Adianti de Tutor (Form/List)

**Camada:** backend/frontend
**Dependências:** T-04
**Paralelizável:** sim (com T-10, T-11, T-12, T-13, T-14)
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/TutorForm.php`
- `src/app/control/clinic/TutorList.php`
- `src/app/model/clinic/Tutor.php`

**Interface**
- Produz: `TutorForm` (busca por nome/CPF/telefone e cadastro rápido, refletindo o mock 01) e `TutorList`, ambos chamando `CentralVet\Application\TutorService`
- Consome: T-04 `CentralVet\Application\TutorService`

**Critério de aceite**
- `TutorForm` e `TutorList` não contêm nenhuma regra de negócio própria (validação de dado, cálculo, decisão) — toda regra fica em `TutorService`.
- A classe `TutorForm` é instanciável dentro do container `app` sem lançar exceção.

**Validação**
- `php -l src/app/control/clinic/TutorForm.php src/app/control/clinic/TutorList.php src/app/model/clinic/Tutor.php` → `No syntax errors detected` nos 3
- `docker compose build app && docker compose exec app php -r "require 'engine.php'; new TutorForm();"` (ou equivalente ao padrão de smoke test já usado nas ondas da Fase 0) → sem exceção lançada

---

### T-10 — Tela Adianti de Paciente (Form/List)

**Camada:** backend/frontend
**Dependências:** T-05
**Paralelizável:** sim (com T-09, T-11, T-12, T-13, T-14)
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/PatientForm.php`
- `src/app/control/clinic/PatientList.php`
- `src/app/model/clinic/Patient.php`

**Interface**
- Produz: `PatientForm` (campos do mock 02: espécie, raça, sexo, nascimento, peso, cor, observações, vinculado a um `tutor_id` recebido por parâmetro) e `PatientList`, ambos chamando `CentralVet\Application\PatientService`
- Consome: T-05 `CentralVet\Application\PatientService`

**Critério de aceite**
- Ao receber um `tutor_id` de outro tenant, `PatientForm` recusa a abertura da tela com uma mensagem tratada, sem lançar um erro HTTP 500.

**Validação**
- `php -l src/app/control/clinic/PatientForm.php src/app/control/clinic/PatientList.php src/app/model/clinic/Patient.php` → `No syntax errors detected` nos 3
- Teste manual documentado em `notes.md`: abrir `PatientForm` com `tutor_id` de outro tenant retorna mensagem tratada, não HTTP 500

---

### T-11 — Tela Adianti de Serviço (Form/List)

**Camada:** backend/frontend
**Dependências:** T-06
**Paralelizável:** sim (com T-09, T-10, T-12, T-13, T-14)
**Complexidade:** simples
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/ServiceForm.php`
- `src/app/control/clinic/ServiceList.php`
- `src/app/model/clinic/Service.php`

**Interface**
- Produz: `ServiceForm` e `ServiceList` (CRUD de nome, categoria, duração, preço e ativo/inativo), ambos chamando `CentralVet\Application\ServiceCatalogService`
- Consome: T-06 `CentralVet\Application\ServiceCatalogService`

**Critério de aceite**
- `ServiceList` exibe apenas os serviços do tenant corrente, na mesma contagem retornada por `ServiceCatalogService::listActive()` para esse tenant.

**Validação**
- `php -l src/app/control/clinic/ServiceForm.php src/app/control/clinic/ServiceList.php src/app/model/clinic/Service.php` → `No syntax errors detected` nos 3

---

### T-12 — Tela Adianti de Agenda

**Camada:** backend/frontend
**Dependências:** T-07
**Paralelizável:** sim (com T-09, T-10, T-11, T-13, T-14)
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/AgendaView.php`
- `src/app/control/clinic/AppointmentForm.php`

**Interface**
- Produz: `AgendaView` (grade por profissional/dia, como no mock 03, consumindo `AppointmentService::listByProfessionalAndDate`) e `AppointmentForm` (consumindo `AppointmentService::schedule`)
- Consome: T-07 `CentralVet\Application\AppointmentService`

**Critério de aceite**
- Quando `AppointmentService::schedule()` recusa um conflito de horário, `AppointmentForm` exibe a mensagem de recusa na tela em vez de propagar uma exceção não tratada.

**Validação**
- `php -l src/app/control/clinic/AgendaView.php src/app/control/clinic/AppointmentForm.php` → `No syntax errors detected` nos 2

---

### T-13 — Tela Adianti de Fila de atendimento

**Camada:** backend/frontend
**Dependências:** T-08
**Paralelizável:** sim (com T-09, T-10, T-11, T-12, T-14)
**Complexidade:** média
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/QueueEntryView.php`

**Interface**
- Produz: `QueueEntryView` (lista da fila do dia por unidade, como no mock 04, com ação de avançar status), consumindo `QueueEntryService::listToday` e `QueueEntryService::advanceStatus`
- Consome: T-08 `CentralVet\Application\QueueEntryService`

**Critério de aceite**
- Após acionar o avanço de status de uma entrada da fila, o novo status é exibido na tela sem exigir recarregar manualmente a página inteira, ou, quando isso não for possível no template atual, o recarregamento automático fica documentado em `notes.md`.

**Validação**
- `php -l src/app/control/clinic/QueueEntryView.php` → `No syntax errors detected`

---

### T-14 — Busca inicial (global)

**Camada:** backend/frontend
**Dependências:** T-04, T-05
**Paralelizável:** sim (com T-09, T-10, T-11, T-12, T-13)
**Complexidade:** simples
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/GlobalSearchController.php`

**Interface**
- Produz: `GlobalSearchController`, que combina `TutorService::search` e `PatientService::findByTutor`/busca equivalente de paciente, retornando resultados tipados (tutor ou paciente) com atalho para a tela correspondente
- Consome: T-04 `CentralVet\Application\TutorService`
- Consome: T-05 `CentralVet\Application\PatientService`

**Critério de aceite**
- Uma busca com termo de 1 caractere retorna uma lista vazia; uma busca com 2 ou mais caracteres retorna os registros correspondentes do tenant corrente.

**Validação**
- `php -l src/app/control/clinic/GlobalSearchController.php` → `No syntax errors detected`
- Teste unitário/manual documentado em `notes.md`: busca com termo de 1 caractere retorna lista vazia

---

### T-15 — Registrar as 6 telas novas em `menu.xml`

**Camada:** infra
**Dependências:** T-09, T-10, T-11, T-12, T-13, T-14
**Paralelizável:** não (escritor único do arquivo compartilhado)
**Complexidade:** simples
**Agente:** Naruto

**Arquivos prováveis**
- `src/menu.xml`

**Interface**
- Produz: entradas de menu para as 6 telas, agrupadas sob um item pai de recepção/clínica
- Consome: T-09 `TutorForm`
- Consome: T-10 `PatientForm`
- Consome: T-11 `ServiceForm`
- Consome: T-12 `AgendaView`
- Consome: T-13 `QueueEntryView`
- Consome: T-14 `GlobalSearchController`

**Critério de aceite**
- `menu.xml` permanece um XML válido.
- As 6 entradas aparecem no menu de um usuário autenticado do tenant, e as entradas já existentes continuam presentes e inalteradas.

**Validação**
- `php -r "simplexml_load_file('src/menu.xml') !== false or exit(1); echo 'menu.xml OK';"` → `menu.xml OK`
- `grep -c "TutorForm\|PatientForm\|ServiceForm\|AgendaView\|QueueEntryView\|GlobalSearchController" src/menu.xml` → `6` ou mais

---

### T-16 — Testes unitários e de integração das novas entidades

**Camada:** shared
**Dependências:** T-04, T-05, T-06, T-07, T-08
**Paralelizável:** sim (com T-15)
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `src/tests/Unit/TutorServiceTest.php`
- `src/tests/Unit/PatientServiceTest.php`
- `src/tests/Unit/ServiceCatalogServiceTest.php`
- `src/tests/Unit/AppointmentServiceTest.php`
- `src/tests/Unit/QueueEntryServiceTest.php`
- `src/tests/Integration/Phase1TenantIsolationIntegrationTest.php`

**Interface**
- Produz: cobertura de teste para os critérios de aceite de T-03, T-05, T-07 e T-08 (isolamento de tenant em Unidades/Usuários, rejeição de `tutor_id` de outro tenant, conflito de horário, transição de status inválida), usando o mesmo padrão de transação com `ROLLBACK` garantido já existente da Fase 0
- Consome: T-04 `CentralVet\Application\TutorService`
- Consome: T-05 `CentralVet\Application\PatientService`
- Consome: T-06 `CentralVet\Application\ServiceCatalogService`
- Consome: T-07 `CentralVet\Application\AppointmentService`
- Consome: T-08 `CentralVet\Application\QueueEntryService`

**Critério de aceite**
- A suíte completa (testes já existentes da Fase 0 mais os novos) termina com zero falhas e não deixa nenhuma linha residual nas tabelas novas depois de rodar.

**Validação**
- `docker compose exec app php tests/run.php` → `Failed: 0`, com `Total` maior que 78
- `docker compose exec mysql mysql -u... centralvet -e "SELECT COUNT(*) FROM tutor"` (só se a migration já estiver aplicada; caso contrário a tabela não existe e o teste correspondente reporta skip) → `0`

---

### T-17 — Revisão de segurança e quality gate da Fase 1

**Camada:** shared
**Dependências:** T-15, T-16
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `.tasks/03-fase-1-cadastros-agenda/notes.md`

**Interface**
- Produz: parecer final registrado em `notes.md` cobrindo isolamento por tenant nas 5 entidades novas e nas telas de Unidades/Usuários, ausência de segredo real em qualquer arquivo criado, e confirmação de que a migration de T-01 segue não aplicada
- Consome: nada formalmente (revisão consome as evidências já reportadas por T-01 a T-16)

**Critério de aceite**
- Nenhum bloqueante em aberto; toda pendência conhecida é listada explicitamente em `notes.md`, incluindo a aplicação da migration pendente de autorização do usuário.

**Validação**
- `docker compose exec app php tests/run.php` → `Failed: 0`
- Checagem somente leitura no MySQL confirmando que `tutor`, `patient`, `service`, `appointment` e `queue_entry` não existem (migration não aplicada) ou, se o usuário já tiver autorizado nesse meio-tempo, que existem com o schema esperado

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
