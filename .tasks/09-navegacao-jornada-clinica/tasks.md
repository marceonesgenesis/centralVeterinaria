# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | backend | `ExamService::listPending()` | — | sim | simples | Atenea | [x] |
| T-02 | backend | `ReceivableRepositoryInterface::listOpen()` + impl + `PaymentService::listOpenReceivables()` | — | sim | média | Atenea | [x] |
| T-03 | frontend | Tela `PendingExamResultList` | T-01 | sim | média | Hermes | [x] |
| T-04 | frontend | Tela `PendingReceivableList` | T-02 | sim | média | Hermes | [x] |
| T-05 | frontend | Ação de linha "Pacientes" em `TutorList` | — | sim | simples | Iris | [x] |
| T-06 | config | `menu.xml`: substituir 2, remover 9 | T-03, T-04 | não | simples | Jano | [x] |
| T-07 | banco | DML `system_program`/`system_group_program` para as 2 telas novas | T-03, T-04 | sim (com T-06) | simples | Jano | [x] |
| T-08 | frontend | `AppointmentForm`: 3 campos → widgets relacionais | — | sim | média | Ártemis | [x] |
| T-09 | frontend | `SaleForm`: 4 campos → widgets relacionais | — | sim | média | Ártemis | [x] |
| T-10 | frontend | `VaccinationCardView`: seletor de paciente quando ausente | — | sim | simples | Ártemis | [x] |
| T-11 | qa | Validação Playwright + regressão completa | T-01..T-10 | não | alta | Cérbero | [x] |
| T-12 | qa | Revisão final | T-11 | não | simples | Minerva | [x] |

## Detalhamento

### T-01 — `ExamService::listPending()`

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Atenea

**Arquivos prováveis**
- `src/app/Core/Application/ExamService.php`

**Interface**
- Produz: `class CentralVet\Application\ExamService` ganha `public function listPending(): array` — retorna a lista de `CentralVet\Domain\ExamRequest` com `status() === ExamRequest::STATUS_REQUESTED`, delegando 100% para `ExamRequestRepositoryInterface::listPending()` (já implementado em `src/app/Core/Persistence/ExamRequestRepository.php:79`), passando pelo mesmo padrão de autorização (`RbacAuthorizationService`) já usado nos outros métodos públicos da classe (`requestExam`, `recordResult`, `findById`).
- Consome: nada (método de repositório já existe e já está implementado).

**Critério de aceite**
- `php -l src/app/Core/Application/ExamService.php` retorna a linha `No syntax errors detected`.
- Chamada real (via teste ou script) de `listPending()` com pelo menos 1 `exam_request` em status `requested` no fixture/DB de teste retorna um array não vazio contendo esse registro.

**Validação**
- `docker compose exec app php -l app/Core/Application/ExamService.php` (evidência: linha `No syntax errors detected`).
- `docker compose exec app php tests/run.php` (evidência: contagem de testes passando igual ou maior que antes da mudança).

### T-02 — `ReceivableRepositoryInterface::listOpen()` + impl + `PaymentService::listOpenReceivables()`

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Atenea

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/ReceivableRepositoryInterface.php`
- `src/app/Core/Persistence/ReceivableRepository.php`
- `src/app/Core/Application/PaymentService.php`
- `src/tests/Support/FakeReceivableRepository.php`

**Interface**
- Produz: `class CentralVet\Application\PaymentService` ganha `public function listOpenReceivables(): array`, delegando para `ReceivableRepositoryInterface::listOpen(): array` (método novo da interface e da implementação de `ReceivableRepository` — retorna todo `Receivable` do tenant atual com `status()` em `[STATUS_OPEN, STATUS_PARTIALLY_PAID]`, escopado só por `tenant_id`, ver Decisões de arquitetura em `plan.md`), com o mesmo padrão de autorização já usado em `register()`. `FakeReceivableRepository` recebe a mesma assinatura `listOpen(): array`.
- Consome: nada.

**Critério de aceite**
- `php -l` retorna a linha `No syntax errors detected` nos 4 arquivos modificados.
- Chamada real de `listOpenReceivables()` com pelo menos 1 `receivable` em status `open` no fixture/DB de teste retorna um array não vazio contendo esse registro; um `receivable` com status `paid` não aparece no retorno.
- Toda suíte de testes que hoje instancia `FakeReceivableRepository` continua executando até o fim, sem interromper por método abstrato ausente.

**Validação**
- `docker compose exec app php -l app/Core/Domain/Contract/ReceivableRepositoryInterface.php app/Core/Persistence/ReceivableRepository.php app/Core/Application/PaymentService.php tests/Support/FakeReceivableRepository.php` (evidência: linha `No syntax errors detected` nos 4).
- `docker compose exec app php tests/run.php` (evidência: contagem final de testes passando, igual ou maior que 153).

### T-03 — Tela `PendingExamResultList`

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim (com T-04, T-05, T-08, T-09, T-10)
**Complexidade:** média
**Agente:** Hermes

**Arquivos prováveis**
- `src/app/control/clinic/PendingExamResultList.php`

**Interface**
- Produz: `class PendingExamResultList` (arquivo novo, padrão `TStandardList`, espelha `src/app/control/clinic/PayableList.php` na estrutura: `onReload()` sobrescrito puxando de `ExamService::listPending()` produzido por T-01, colunas paciente/exame/data da solicitação, sem `TXMLBreadCrumb` até T-06 registrar em `menu.xml` — mesma ressalva do docblock de `PayableList`). Ação de linha (`TDataGridAction`) rotulada `_t('Registrar resultado')` navegando para `ExamResultForm::onEdit` passando o id da linha corrente como `exam_request_id` na querystring (mesmo padrão de `ProductList.php:97`, `$action_batch`).
- Consome: T-01 `public function listPending(): array` em `CentralVet\Application\ExamService`.

**Critério de aceite**
- Tela carrega e renderiza a grade com pelo menos 1 exame pendente real criado via UI.
- Clique na ação de linha abre `ExamResultForm` com o campo `exam_request_id` já preenchido com o id correto (não em branco).

**Validação**
- `docker compose exec app php -l app/control/clinic/PendingExamResultList.php` (evidência: linha `No syntax errors detected`).
- Screenshot Playwright da tela carregada com pelo menos 1 linha + clique na ação de linha confirmando `exam_request_id` correto em `ExamResultForm`.

### T-04 — Tela `PendingReceivableList`

**Camada:** frontend
**Dependências:** T-02
**Paralelizável:** sim (com T-03, T-05, T-08, T-09, T-10)
**Complexidade:** média
**Agente:** Hermes

**Arquivos prováveis**
- `src/app/control/clinic/PendingReceivableList.php`

**Interface**
- Produz: `class PendingReceivableList` (arquivo novo, mesmo espelho de `PayableList.php` que T-03), colunas tutor/valor total/valor pago/status, ação de linha rotulada `_t('Receber pagamento')` navegando para `PaymentForm::onEdit` passando o id da linha corrente como `receivable_id` na querystring.
- Consome: T-02 `public function listOpenReceivables(): array` em `CentralVet\Application\PaymentService`.

**Critério de aceite**
- Tela carrega e renderiza a grade com pelo menos 1 conta a receber aberta real (criada via fechamento de conta de atendimento na validação).
- Clique na ação de linha abre `PaymentForm` com o campo `receivable_id` já preenchido com o id correto.

**Validação**
- `docker compose exec app php -l app/control/clinic/PendingReceivableList.php` (evidência: linha `No syntax errors detected`).
- Screenshot Playwright da tela carregada com pelo menos 1 linha + clique na ação de linha confirmando `receivable_id` correto em `PaymentForm`.

### T-05 — Ação de linha "Pacientes" em `TutorList`

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Iris

**Arquivos prováveis**
- `src/app/control/clinic/TutorList.php`

**Interface**
- Produz: nova `TDataGridAction` em `TutorList` rotulada `_t('Patients')`, navegando para `PatientList::onReload` passando o id da linha corrente como `tutor_id` na querystring (mesmo padrão de `ProductList.php:97`). `PatientList` já aceita `tutor_id` por querystring (confirmado, nenhuma mudança nela necessária).
- Consome: nada (usa `PatientList` como já existe hoje).

**Critério de aceite**
- Clique na ação de linha, a partir de um tutor real com pelo menos 1 paciente cadastrado, abre `PatientList` mostrando na grade exatamente os pacientes daquele tutor.

**Validação**
- `docker compose exec app php -l app/control/clinic/TutorList.php` (evidência: linha `No syntax errors detected`).
- Screenshot Playwright confirmando a navegação com `tutor_id` correto e a grade de `PatientList` populada com os pacientes esperados.

### T-06 — `menu.xml`: substituir 2 itens, remover 9

**Camada:** config
**Dependências:** T-03, T-04
**Paralelizável:** não (arquivo único, escritor único desta onda)
**Complexidade:** simples
**Agente:** Jano

**Arquivos prováveis**
- `src/menu.xml`

**Interface**
- Produz: item de menu que hoje aponta para a action `ExamResultForm` (linha ~159) passa a apontar para a action `PendingExamResultList`; item que hoje aponta para a action `PaymentForm` (linha ~261) passa a apontar para a action `PendingReceivableList`; remoção completa dos 9 `<menuitem>` (label + action) que hoje apontam para as actions `PrescriptionForm` (~143), `ExamRequestForm` (~155), `VaccinationForm` (~175), `ProcedureExecutionForm` (~219), `EncounterAccountForm` (~233), `StockBatchForm` (~203), `VaccineProtocolForm` (~171), `PatientList` (~112), `PatientForm` (~116). XML permanece bem formado (mesma quantidade de tags de abertura/fechamento).
- Consome: T-03 `class PendingExamResultList`
- Consome: T-04 `class PendingReceivableList`

**Critério de aceite**
- O parse de `menu.xml` via `DOMDocument::load()` retorna `bool(true)`.
- Login e carregamento do menu principal retornam a página normalmente, com os 9 itens removidos ausentes da árvore de menu e os 2 itens substituídos presentes apontando para as classes novas.

**Validação**
- `docker compose exec app php -r "var_dump((new DOMDocument())->load('menu.xml'));"` a partir de `app/` (evidência: `bool(true)`).
- Screenshot Playwright do menu principal expandido, mostrando a ausência dos 9 itens removidos e a presença dos 2 substituídos com o rótulo original.

### T-07 — DML `system_program`/`system_group_program` para as 2 telas novas

**Camada:** banco
**Dependências:** T-03, T-04
**Paralelizável:** sim (com T-06, arquivos/alvos diferentes)
**Complexidade:** simples
**Agente:** Jano

**Arquivos prováveis**

Nenhum arquivo — DML direto, mesmo procedimento de aprovação de SQL de toda fase anterior (backup, checksum, usuário `centralvet_migrator`, autorização explícita do usuário imediatamente antes de executar).

**Interface**
- Produz: 2 linhas novas em `system_program` (`name = 'PendingExamResultList'` e `name = 'PendingReceivableList'`, mesmos campos usados nos registros existentes de `PayableList`/`ExamResultForm` como referência de formato) + 2 linhas correspondentes em `system_group_program` associando ao grupo `Template - Admin` (id=1), mesmo padrão de toda fase anterior.
- Consome: T-03 `class PendingExamResultList`
- Consome: T-04 `class PendingReceivableList`

**Critério de aceite**
- `SELECT * FROM system_program WHERE name IN ('PendingExamResultList','PendingReceivableList')` retorna 2 linhas.
- `SELECT * FROM system_group_program WHERE program_id` das 2 linhas acima retorna 2 linhas associadas ao grupo id=1.
- Usuário logado como `admin` abre as 2 telas e recebe o conteúdo da tela, não a mensagem "Acesso negado".

**Validação**
- `SELECT` de conferência acima (somente leitura, sem necessidade de aprovação) rodado depois do DML aprovado e executado.
- Screenshot Playwright de cada tela nova abrindo sem mensagem de permissão negada.

### T-08 — `AppointmentForm`: 3 campos → widgets relacionais

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Ártemis

**Arquivos prováveis**
- `src/app/control/clinic/AppointmentForm.php`

**Interface**
- Produz: `patient_id` (`TEntry`, linha 48) substituído por `new TDBUniqueSearch('patient_id', 'permission', 'Patient', 'id', 'name', 'name', $tenantCriteria)`; `professional_system_user_id` (`TEntry`, linha 50) substituído por `new TDBUniqueSearch('professional_system_user_id', 'permission', 'SystemUser', 'id', 'name', 'name')` (sem `TCriteria`, ver Decisões de arquitetura em `plan.md`); `service_id` (`TEntry`, linha 49) substituído por `new TDBCombo('service_id', 'permission', 'Service', 'id', 'name')` com `TCriteria` de tenant (catálogo pequeno, mesmo padrão de população direta já usado em `ProcedureInputForm::loadProductOptions()`). `$tenantCriteria` construído a partir do `TenantContext` já resolvido em `resolveTenantContext()` (método já existente na classe), com `TFilter('tenant_id', '=', $tenant_context->tenantId())`.
- Consome: nada (widgets do framework, modelos já existentes).

**Critério de aceite**
- Digitar parte do nome de um paciente/serviço/profissional reais no campo correspondente mostra sugestões e permite selecionar, sem digitar ID numérico.
- Salvar um agendamento com os 3 campos preenchidos via busca funciona (mesmo critério de aceite que já existia antes, agora sem exigir ID cru).
- Buscar por um tutor/paciente de outro tenant (se houver massa de teste multi-tenant) não aparece nas sugestões de `patient_id`.

**Validação**
- `docker compose exec app php -l app/control/clinic/AppointmentForm.php` (evidência: `No syntax errors detected`).
- Screenshot Playwright do formulário com autocomplete funcionando + salvamento bem-sucedido de um agendamento real.
- `docker compose exec app php tests/run.php` (evidência: 153+/153, nenhuma regressão nas regras de `AppointmentService`).

### T-09 — `SaleForm`: 4 campos → widgets relacionais

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Ártemis

**Arquivos prováveis**
- `src/app/control/clinic/SaleForm.php`

**Interface**
- Produz: `tutor_id` (linha 91) → `TDBUniqueSearch` em `Tutor` com `TCriteria` de tenant; `patient_id` (linha 92) → `TDBUniqueSearch` em `Patient` com `TCriteria` de tenant (mantém `setValue($this->patientId)` quando vier por querystring, comportamento hoje existente linha 110); `product_id` (linha 116) → `TDBUniqueSearch` em `Product` com `TCriteria` de tenant; `procedure_id` (linha 132) → `TDBUniqueSearch` em `ProcedureCatalogItem` com `TCriteria` de tenant. Todos usando o `TenantContext` já resolvido pela classe (`resolveTenantContext()`, já existente).
- Consome: nada.

**Critério de aceite**
- Digitar parte do nome real em cada um dos 4 campos mostra sugestões e permite selecionar, sem digitar ID numérico.
- Fluxo de venda completo (tutor + paciente opcional + produto OU procedimento) continua fechando com sucesso, idêntico ao comportamento anterior (mesmo critério de aceite da Fase 4/5), agora sem exigir ID cru.

**Validação**
- `docker compose exec app php -l app/control/clinic/SaleForm.php` (evidência: `No syntax errors detected`).
- Screenshot Playwright de uma venda completa fechada via UI com os 4 campos preenchidos por busca.
- `docker compose exec app php tests/run.php` (evidência: 153+/153, nenhuma regressão em `SaleService`).

### T-10 — `VaccinationCardView`: seletor de paciente quando ausente

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Ártemis

**Arquivos prováveis**
- `src/app/control/clinic/VaccinationCardView.php`

**Interface**
- Produz: quando `$this->patient_id === null` (hoje a tela só retorna em branco, ver `onReload()` linha ~111), a tela mostra um pequeno formulário com `TDBUniqueSearch('patient_id_picker', 'permission', 'Patient', 'id', 'name', 'name', $tenantCriteria)` + botão `_t('View card')` que recarrega a própria tela via `TScript`/`AdiantiCoreApplication::loadPage` passando `patient_id` na querystring (mesma classe, `onReload` reaproveitado). Quando `$this->patient_id` já vem preenchido (fluxo contextual existente vindo de `VaccinationForm`), comportamento idêntico ao atual — nenhuma mudança nesse caminho.
- Consome: nada.

**Critério de aceite**
- Acessar a tela sem `patient_id` mostra o seletor de paciente (não mais uma tela em branco).
- Selecionar um paciente real e confirmar recarrega a mesma tela agora mostrando o histórico de vacinação desse paciente.
- Acesso já contextual (vindo de `VaccinationForm` com `patient_id` na querystring) carrega o histórico de vacinação diretamente, sem mostrar o seletor de paciente.

**Validação**
- `docker compose exec app php -l app/control/clinic/VaccinationCardView.php` (evidência: `No syntax errors detected`).
- Screenshot Playwright dos 2 caminhos: acesso bare (mostra seletor → seleciona → mostra carteira) e acesso contextual (mostra carteira direto).

### T-11 — Validação Playwright + regressão completa

**Camada:** qa
**Dependências:** T-01 a T-10
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Cérbero

**Arquivos prováveis**

Nenhum arquivo de produto — só evidência.

**Interface**
- Produz: relatório de evidência cobrindo cada critério de aceite de T-01 a T-10 + rodada completa da suíte de testes.
- Consome: todas as interfaces produzidas por T-01 a T-10.

**Critério de aceite**
- `docker compose exec app php tests/run.php` reporta 153/153 (ou mais) sem falha.
- Cada um dos 12 fluxos alterados/criados (2 telas novas + ação TutorList + 9 itens de menu removidos confirmados ausentes + 2 itens substituídos confirmados redirecionando certo + 3 telas com widgets relacionais) tem uma evidência Playwright própria (screenshot ou trecho de snapshot de acessibilidade).
- Nenhum arquivo `.png`/`.playwright-mcp/` temporário sobra no repositório ao final.

**Validação**
- `docker compose exec app php tests/run.php` (evidência: contagem final de testes).
- Lista de screenshots/evidências por fluxo, anexada em `notes.md`.

### T-12 — Revisão final

**Camada:** qa
**Dependências:** T-11
**Paralelizável:** não
**Complexidade:** simples
**Agente:** Minerva

**Arquivos prováveis**

Nenhum — revisão read-only.

**Interface**
- Produz: relatório de revisão (escopo respeitado, nenhum arquivo fora do mapa tocado, nenhuma regressão, documentação/`notes.md` atualizada).
- Consome: evidência de T-11.

**Critério de aceite**
- Nenhum bloqueante encontrado, ou bloqueantes resolvidos em onda curta adicional.

**Validação**
- Relatório de Minerva anexado em `notes.md`, com veredito final.

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
