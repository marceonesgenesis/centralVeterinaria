# Plano: Fase 1 — Cadastros e agenda

## Objetivo
Entregar o critério de saída do PRD para a Fase 1: "recepção opera dados reais".
Cobre unidades/usuários tenant-aware, tutores, pacientes, catálogo de serviços,
agenda por profissional e fila de atendimento, sobre a fundação multi-tenant
já concluída e testada na Fase 0.

## Premissas
- Conexões `permission`, `unit_a`, `unit_b`, `sample` apontam todas para o
  mesmo banco `centralvet` (`multi_database => '0'` em `application.php`) —
  não há sharding real por unidade; novas entidades usam
  `TTransaction::open('permission')`, mesma conexão de `SystemUser`/`SystemUnit`.
- O RBAC novo (`CentralVet\Authorization\RbacAuthorizationService`, Fase 0)
  ainda não está integrado a nenhum controller. Esta fase integra o RBAC novo
  **apenas** nas telas novas (Tutor, Paciente, Serviço, Agenda, Fila) e no
  tenant-scoping de Unidades/Usuários (T-03). Não migra RBAC de outras telas
  legadas do Adianti (fora de escopo).
- "Plano" (plano comercial/billing) citado no épico E01 do PRD não é
  necessário para o critério de saída desta fase ("recepção opera dados
  reais") e fica fora de escopo — ver Excluído.
- Nomenclatura de tabelas em inglês/snake_case, seguindo a convenção já usada
  em `tenant`, `audit_log`, `stored_object`, `system_unit`, `system_users`.
- Fluxo de UI segue os 4 mocks aprovados pelo usuário
  (https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR, artboards 01–04):
  busca/cadastro rápido de tutor → cadastro de paciente → agenda por
  profissional → fila com status aguardando/em atendimento/atendido/atrasado.
- `queue_entry.appointment_id` é opcional (nullable): a fila aceita tanto
  check-in a partir de um agendamento quanto entrada avulsa (walk-in), como
  o próprio fluxo clínico do PRD (seção 7) permite.
- A "Central de Atendimento" (tela única de consulta clínica com IA, PRD
  seção 8.8) é Fase 2 — não implementada aqui.

## Escopo

### Incluso
- Migration preparada (não aplicada) para `tutor`, `patient`, `service`,
  `appointment`, `queue_entry`.
- Tenant-scoping das telas legadas de Unidades e Usuários.
- Domain, Application e Persistence (Core, sem dependência de `TPage`) para
  as 5 entidades novas.
- Telas Adianti (Form/List) para Tutor, Paciente, Serviço, Agenda e Fila,
  seguindo o padrão `TStandardForm`/`TStandardList`/`TRecord` já usado em
  `SystemUnitForm`.
- Busca inicial (global, por nome/CPF/telefone) entre tutores e pacientes.
- Registro das novas telas em `menu.xml`.
- Testes unitários e de integração das novas entidades, incluindo isolamento
  por tenant (mesmo padrão da suíte real criada na Fase 0).
- Revisão de segurança e quality gate de fechamento da fase.

### Excluído
- Central de Atendimento / prontuário clínico / IA embutida (Fase 2).
- Prescrição, exames, procedimentos, internação, estoque, financeiro
  detalhado (fases posteriores).
- "Plano" comercial/billing (épico E01, não necessário ao critério de saída
  desta fase).
- Migração de RBAC novo para telas legadas fora de Unidades/Usuários.
- Aplicação real da migration no MySQL (fica para checkpoint de autorização
  SQL explícito do usuário, como na Fase 0).
- Domínio/DNS/TLS/publicação externa (mesma exclusão da Fase 0).

## Contexto técnico
- Camadas envolvidas: database, backend (Core PHP), backend/frontend
  (Presentation Adianti), infra (`menu.xml`), shared (testes).
- Projeto/base analisada: `/var/www/html/centralvet` (Adianti Framework 8.6,
  PHP 8.4, MySQL 8, Redis, Docker Compose, sem Git).
- Integrações: nenhuma externa nova; reaproveita Redis/MySQL já provisionados
  na Fase 0.

## Exploração read-only
- Caminhos relevantes: `src/app/control/admin/SystemUnitForm.php`,
  `src/app/control/admin/SystemUnitList.php`,
  `src/app/control/admin/SystemUserForm.php`,
  `src/app/control/admin/SystemUserList.php`,
  `src/app/model/admin/SystemUnit.php`, `src/app/model/admin/SystemUser.php`,
  `src/app/Core/Tenancy`, `src/app/Core/Persistence`,
  `src/app/Core/Authorization`, `src/app/Core/Audit`,
  `src/app/database/migrations/20260920_0001_foundation_multitenancy.sql`,
  `src/menu.xml`, `.docs/design-system.md`.
- Padrões identificados: Form estende `TStandardForm` (ex. `SystemUnitForm`)
  ou `TPage` direto (ex. `SystemUserForm`, inconsistente); List usa
  `TStandardList` + trait `AdiantiStandardListTrait`; Model estende `TRecord`
  com atributos via `addAttribute()` no `__construct()`; tudo montado
  programaticamente em PHP (sem templates separados); conexão de banco via
  `TTransaction::open('permission')`.
- Scripts úteis: `docker compose exec app php tests/run.php` (suíte real,
  78 testes na Fase 0); `docker compose exec mysql mysql -u... centralvet`
  para leitura; `docker compose build app worker` após qualquer mudança em
  `src/` (containers não usam bind-mount).
- Riscos identificados: `menu.xml` é arquivo único compartilhado — qualquer
  task que registre uma tela nova colide nele; resolvido concentrando o
  registro numa única task (T-15) no fim. RBAC novo não integrado em telas
  legadas — mudar `SystemUnitForm`/`SystemUserForm` é edição de código já em
  produção interna, exige cautela para não quebrar administração existente.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20260921_0002_phase1_clinic_core.sql` | Schema de tutor/paciente/serviço/agenda/fila | criar | T-01 |
| `src/app/database/migrations/20260921_0002_phase1_clinic_core.verify.sql` | Verificação somente leitura da migration | criar | T-01 |
| `src/app/Core/Domain/Contract/TutorRepositoryInterface.php` | Contrato de repositório de Tutor | criar | T-02 |
| `src/app/Core/Domain/Contract/PatientRepositoryInterface.php` | Contrato de repositório de Paciente | criar | T-02 |
| `src/app/Core/Domain/Contract/ServiceRepositoryInterface.php` | Contrato de repositório de Serviço | criar | T-02 |
| `src/app/Core/Domain/Contract/AppointmentRepositoryInterface.php` | Contrato de repositório de Agendamento | criar | T-02 |
| `src/app/Core/Domain/Contract/QueueEntryRepositoryInterface.php` | Contrato de repositório de Fila | criar | T-02 |
| `src/app/control/admin/SystemUnitForm.php` | Tenant-scoping de Unidade | modificar | T-03 |
| `src/app/control/admin/SystemUnitList.php` | Tenant-scoping de Unidade | modificar | T-03 |
| `src/app/control/admin/SystemUserForm.php` | Tenant-scoping de Usuário | modificar | T-03 |
| `src/app/control/admin/SystemUserList.php` | Tenant-scoping de Usuário | modificar | T-03 |
| `src/app/Core/Domain/Tutor.php` | Entidade de domínio Tutor | criar | T-04 |
| `src/app/Core/Persistence/TutorRepository.php` | Persistência tenant-aware de Tutor | criar | T-04 |
| `src/app/Core/Application/TutorService.php` | Casos de uso de Tutor | criar | T-04 |
| `src/app/Core/Domain/Patient.php` | Entidade de domínio Paciente | criar | T-05 |
| `src/app/Core/Persistence/PatientRepository.php` | Persistência tenant-aware de Paciente | criar | T-05 |
| `src/app/Core/Application/PatientService.php` | Casos de uso de Paciente | criar | T-05 |
| `src/app/Core/Domain/Service.php` | Entidade de domínio Serviço | criar | T-06 |
| `src/app/Core/Persistence/ServiceRepository.php` | Persistência tenant-aware de Serviço | criar | T-06 |
| `src/app/Core/Application/ServiceCatalogService.php` | Casos de uso de Serviço | criar | T-06 |
| `src/app/Core/Domain/Appointment.php` | Entidade de domínio Agendamento | criar | T-07 |
| `src/app/Core/Persistence/AppointmentRepository.php` | Persistência tenant-aware de Agendamento | criar | T-07 |
| `src/app/Core/Application/AppointmentService.php` | Casos de uso de Agendamento | criar | T-07 |
| `src/app/Core/Domain/QueueEntry.php` | Entidade de domínio Fila | criar | T-08 |
| `src/app/Core/Persistence/QueueEntryRepository.php` | Persistência tenant-aware de Fila | criar | T-08 |
| `src/app/Core/Application/QueueEntryService.php` | Casos de uso de Fila | criar | T-08 |
| `src/app/control/clinic/TutorForm.php` | Tela Adianti de Tutor | criar | T-09 |
| `src/app/control/clinic/TutorList.php` | Tela Adianti de Tutor | criar | T-09 |
| `src/app/model/clinic/Tutor.php` | Model Adianti de Tutor | criar | T-09 |
| `src/app/control/clinic/PatientForm.php` | Tela Adianti de Paciente | criar | T-10 |
| `src/app/control/clinic/PatientList.php` | Tela Adianti de Paciente | criar | T-10 |
| `src/app/model/clinic/Patient.php` | Model Adianti de Paciente | criar | T-10 |
| `src/app/control/clinic/ServiceForm.php` | Tela Adianti de Serviço | criar | T-11 |
| `src/app/control/clinic/ServiceList.php` | Tela Adianti de Serviço | criar | T-11 |
| `src/app/model/clinic/Service.php` | Model Adianti de Serviço | criar | T-11 |
| `src/app/control/clinic/AgendaView.php` | Tela Adianti de Agenda | criar | T-12 |
| `src/app/control/clinic/AppointmentForm.php` | Tela Adianti de Agendamento | criar | T-12 |
| `src/app/control/clinic/QueueEntryView.php` | Tela Adianti de Fila | criar | T-13 |
| `src/app/control/clinic/GlobalSearchController.php` | Busca inicial | criar | T-14 |
| `src/menu.xml` | Registro de telas no menu | modificar | T-15 |
| `src/tests/Unit/TutorServiceTest.php` | Teste unitário de Tutor | criar | T-16 |
| `src/tests/Unit/PatientServiceTest.php` | Teste unitário de Paciente | criar | T-16 |
| `src/tests/Unit/ServiceCatalogServiceTest.php` | Teste unitário de Serviço | criar | T-16 |
| `src/tests/Unit/AppointmentServiceTest.php` | Teste unitário de Agendamento | criar | T-16 |
| `src/tests/Unit/QueueEntryServiceTest.php` | Teste unitário de Fila | criar | T-16 |
| `src/tests/Integration/Phase1TenantIsolationIntegrationTest.php` | Teste de isolamento de tenant | criar | T-16 |
| `.tasks/03-fase-1-cadastros-agenda/notes.md` | Parecer final da fase | modificar | T-17 |

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Novas entidades em `src/app/control/clinic/` e `src/app/model/clinic/` | Reaproveitar pasta `admin/` | Segue o padrão Adianti de agrupar por domínio (`admin/`, `communication/`, `log/`); mantém separação clara entre administração do sistema e domínio clínico |
| `queue_entry.appointment_id` nullable | Exigir agendamento prévio sempre | Fila aceita walk-in, conforme fluxo do PRD (seção 7) e mock 04 |
| `menu.xml` só editado por uma task no fim (T-15) | Cada tela registra sua própria entrada | Arquivo único e compartilhado; edição concorrente quebraria XML ou geraria conflito de merge sem Git |
| RBAC novo só nas telas novas + Unidades/Usuários | Migrar todas as telas legadas agora | Mantém escopo gerenciável; telas legadas fora do fluxo de recepção não bloqueiam o critério de saída da fase |
| Migration preparada, não aplicada | Aplicar direto como na Fase 0 fez depois | Mesmo checkpoint de autorização SQL do plano da Fase 0; usuário decide quando aplicar |

## Diagrama de dependências

```text
T-01 → T-02 → T-04 → T-09
                 → T-05 → T-10
                       → T-07 → T-12
                       → T-08 → T-13
                 → T-06 → T-11
                       → T-07
T-04 → T-14
T-05 → T-14
T-03 (independente, onda 1)
T-09,T-10,T-11,T-12,T-13,T-14 → T-15
T-04..T-08 → T-16
T-15,T-16 → T-17
```

## Estratégia de execução
- Branch de trabalho: não aplicável — projeto não é repositório Git
  (`.git` inexistente, confirmado na retomada da Fase 0). Sem branch/commit
  por onda; progresso rastreado só em `tasks.md`/`notes.md`.
- Isolamento em ondas com edições paralelas: escritor único por arquivo em
  todas as ondas (cada task cria seus próprios arquivos); nenhuma onda exige
  worktree.

## Ondas de execução

### Onda 1
- T-01, T-03

### Onda 2
- T-02

### Onda 3
- T-04, T-05, T-06

### Onda 4
- T-07, T-08

### Onda 5
- T-09, T-10, T-11, T-12, T-13, T-14

### Onda 6
- T-15, T-16

### Onda 7
- T-17

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Athena | general-purpose | herdado | T-01, T-02, T-04, T-05, T-06 |
| Jaspion | general-purpose | herdado | T-03, T-07, T-08 |
| Aang | general-purpose | herdado | T-09, T-10, T-11 |
| Tesla | general-purpose | herdado | T-12, T-13, T-14 |
| Naruto | general-purpose | herdado | T-15 |
| Levi | general-purpose | herdado | T-16, T-17 |

## Critérios gerais de aceite
- `docker compose exec app php tests/run.php` permanece com 0 falhas após
  cada onda que toque Core/testes.
- Nenhuma DDL/DML aplicada sem autorização SQL explícita apresentada com
  efeito, risco e backup, como na Fase 0.
- Nenhum dado de outro tenant visível ou editável nas novas telas nem nas
  telas de Unidades/Usuários após T-03.
- `menu.xml` continua XML válido e todas as 6 telas novas navegáveis a
  partir do menu logado.
