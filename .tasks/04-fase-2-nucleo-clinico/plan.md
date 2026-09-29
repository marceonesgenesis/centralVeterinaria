# Plano: Fase 2 — Núcleo clínico (Central de Atendimento)

## Objetivo
Entregar o critério de saída do PRD para a Fase 2: "consulta completa no mesmo
fluxo". Uma tela única de atendimento (encounter, anamnese, sinais vitais,
exame físico, diagnóstico, plano clínico, timeline, autosave e documentos),
sobre a fundação multi-tenant e o catálogo de tutores/pacientes/serviços/
agenda já entregues nas Fases 0 e 1.

## Premissas
- Escopo desta fase segue o critério de saída literal da seção 24 do PRD
  ("Encounter, anamnese, sinais, diagnóstico, plano, timeline, autosave e
  documentos"), não a lista completa de ações inline da seção 8.8. Decisão
  do usuário: prescrição, exame, procedimento e vacina ganham só a casca de
  UI nesta fase (painel abre, sem tabela própria) — persistência real fica
  para a Fase 3 do roadmap, que já é dedicada a isso.
- "Retorno" (ação inline) reaproveita `CentralVet\Application\AppointmentService`
  já existente da Fase 1 (agenda um novo `Appointment` de verdade) — não é
  casca de UI, é a única ação inline com efeito real além de documento.
- "Documento" (ação inline) reaproveita `stored_object`/`CentralVet\Storage\StorageInterface`
  já existentes da Fase 0 — não precisa de tabela nova.
- Timeline reaproveita `audit_log` (Fase 0, ADR 0003) filtrado por
  `entity_type = 'encounter'` e `entity_id` — não precisa de tabela nova.
- Ditado por voz: decisão do usuário — usar `TText::enableSpeechRecognition()`,
  já nativo do Adianti 8.6 (confirmado em `src/lib/adianti/widget/form/TText.php`),
  em vez de integração com IA real. Funciona de verdade (Web Speech API do
  navegador), sem depender de MCP/AI Gateway (Fase 8+).
- "Resumo IA"/"sugestões de próximos passos": nenhuma chamada real a
  provedor de IA nesta fase (isso é Fase 8+). Modelados atrás de
  `CentralVet\Assistant\Contract\AiClinicalAssistantInterface`, com uma
  implementação `NullAiClinicalAssistant` que devolve resultado vazio/
  determinístico — pronta para trocar por um adapter real depois sem mudar
  a tela.
- Autosave não tem helper nativo no Adianti (confirmado por exploração) —
  implementado com `setInterval()` client-side chamando uma `TAction`
  (`onAutosave`) via `__adianti_ajax_exec()`, mecanismo já usado em outros
  widgets do próprio framework (`TCalendar`, `TTreeView`, `TNotebook`).
- `encounter` é modelado como agregado único (anamnese, sinais vitais, exame
  físico, diagnóstico e plano clínico como colunas diretas da mesma tabela,
  não tabelas separadas) — coerente com "uma tela única" e simplifica o
  autosave para um único UPDATE por vez.
- Resumo financeiro em tempo real mostra o preço do serviço do agendamento
  de origem (`Service.priceCents`, já existente); as ações inline com casca
  de UI (prescrição/exame/procedimento/vacina) não têm preço próprio ainda
  e não alteram o total nesta fase — simplificação documentada, não uma
  omissão.
- Lição herdada da Fase 1: registrar uma tela em `menu.xml` não basta para
  torná-la acessível — precisa também de `system_program` e
  `system_group_program` (RBAC nativo do Adianti, fail-closed via
  `SystemPermission::checkPermission()` em `engine.php`). A task de
  registro desta fase já nasce com esse critério de aceite completo.
- O projeto não é repositório Git; não inicializar git.

## Escopo

### Incluso
- Migration preparada (não aplicada) para `encounter`.
- Contratos de Domain para `Encounter` e para o assistente de IA.
- `EncounterService` (Application) com fail-closed por tenant/unidade,
  incluindo `RbacAuthorizationService` (mesmo padrão já usado em
  `AppointmentService`/`QueueEntryService` na Fase 1) em `start()`/`finish()`.
- `NullAiClinicalAssistant` (implementação placeholder, sem IA real).
- `EncounterDocumentService`, reaproveitando o storage já existente.
- Tela única `EncounterView`: contexto do atendimento (paciente/tutor/
  profissional/unidade sem repetir dado já conhecido), alerta do paciente,
  resumo IA com aceitar/editar, anamnese com ditado por voz nativo, sinais
  vitais, exame físico, diagnóstico, plano clínico com sugestões (cada uma
  com "Aplicar" individual, nunca automático), timeline (via `audit_log`),
  ações inline (documento e retorno reais; prescrição/exame/procedimento/
  vacina como casca de UI com rastro em `audit_log`), resumo financeiro,
  autosave.
- Registro de `EncounterView` em `menu.xml` + `system_program` +
  `system_group_program`, com verificação de acesso ponta a ponta.
- Testes unitários e de integração.
- Revisão de segurança e quality gate de fechamento da fase.

### Excluído
- Persistência real de prescrição, exame, procedimento e vacina (Fase 3).
- Integração real com provedor de IA/LLM, MCP, AI Gateway (Fase 8+).
- Transcrição de voz via servidor/provedor externo (usa-se a Web Speech API
  nativa do navegador, sem servidor).
- Estoque, cobrança/faturamento detalhado além do resumo de preço do
  serviço já agendado.
- Aplicação real da migration no MySQL (checkpoint de autorização SQL do
  usuário, como nas Fases 0 e 1).
- Redução de privilégio do usuário de runtime do MySQL (pendência antiga,
  não desta fase).

## Contexto técnico
- Camadas envolvidas: database, backend (Core PHP), backend/frontend
  (Presentation Adianti), infra (`menu.xml`/RBAC nativo), shared (testes).
- Projeto/base: `/var/www/html/centralvet` (Adianti 8.6, PHP 8.4, MySQL 8,
  Redis, Docker Compose, sem Git).
- Integrações: nenhuma externa nova. Reaproveita Redis/MySQL/S3-MinIO já
  provisionados nas Fases 0-1.

## Exploração read-only
- Caminhos relevantes: `src/app/Core/Storage/StorageInterface.php`
  (`put`/`get`/`exists`/`delete`/`presignedUrl`), `src/app/Core/Audit/*`
  (audit_log, ADR 0003), `src/app/Core/Authorization/*` (RbacAuthorizationService,
  já usado em T-07/T-08 da Fase 1), `src/lib/adianti/widget/form/TText.php`
  (`enableSpeechRecognition()`), `src/lib/adianti/include/components/tentry/tentry.js`
  (implementação real da Web Speech API), `src/app/control/clinic/AppointmentForm.php`
  e `QueueEntryView.php` (padrão de wiring de services + RBAC + tratamento
  de exceção já estabelecido na Fase 1, a replicar aqui).
- Padrões identificados: `TScript::create()` injeta `<script>` customizado;
  `TButton::addFunction()` associa JS puro client-side sem round-trip;
  seletor de campo por `[name="campo"]`, não por id gerado; `__adianti_ajax_exec()`
  chama uma `TAction` via AJAX (usado por `TCalendar`/`TTreeView`/`TNotebook`,
  sem helper nativo de polling — precisa de `setInterval()` manual).
- Scripts úteis: `docker compose exec app php tests/run.php` (suíte real,
  117 testes ao fim da Fase 1); `docker compose build app worker` após
  qualquer mudança em `src/` (containers não usam bind-mount).
- Riscos identificados: `EncounterView` é um arquivo único e grande — não
  paralelizar sua escrita entre agentes (uma task, um agente, sem worktree).
  Esquecer `system_group_program` deixa a tela inacessível mesmo com tudo
  funcionando (já aconteceu na Fase 1) — a task de registro inclui
  verificação de acesso real como critério de aceite, não só existência da
  linha em `system_program`.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20260922_0003_phase2_encounter.sql` | Schema de `encounter` | criar | T-01 |
| `src/app/database/migrations/20260922_0003_phase2_encounter.verify.sql` | Verificação somente leitura | criar | T-01 |
| `src/app/Core/Domain/Contract/EncounterRepositoryInterface.php` | Contrato de repositório de Encounter | criar | T-02 |
| `src/app/Core/Assistant/Contract/AiClinicalAssistantInterface.php` | Contrato do assistente de IA | criar | T-02 |
| `src/app/Core/Domain/Encounter.php` | Entidade de domínio Encounter | criar | T-03 |
| `src/app/Core/Persistence/EncounterRepository.php` | Persistência tenant-aware de Encounter | criar | T-03 |
| `src/app/Core/Application/EncounterService.php` | Casos de uso de Encounter | criar | T-03 |
| `src/app/Core/Assistant/NullAiClinicalAssistant.php` | Implementação placeholder do assistente de IA | criar | T-04 |
| `src/app/Core/Application/EncounterDocumentService.php` | Anexar/listar documentos de um encounter | criar | T-05 |
| `src/app/control/clinic/EncounterView.php` | Tela única de atendimento | criar | T-06 |
| `src/app/model/clinic/Encounter.php` | Model Adianti de Encounter | criar | T-06 |
| `src/menu.xml` | Registro da tela no menu | modificar | T-07 |
| `src/tests/Unit/EncounterServiceTest.php` | Testes unitários de Encounter | criar | T-08 |
| `src/tests/Unit/EncounterDocumentServiceTest.php` | Testes unitários de documentos | criar | T-08 |
| `.tasks/04-fase-2-nucleo-clinico/notes.md` | Parecer final da fase | modificar | T-09 |

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| `encounter` como agregado único (colunas diretas) | Tabelas separadas para anamnese/sinais/exame/diagnóstico/plano | Coerente com "uma tela única"; simplifica autosave a um UPDATE por vez |
| Timeline via `audit_log` existente | Tabela `encounter_timeline` própria | Reaproveita infraestrutura já auditável da Fase 0 (ADR 0003), evita duplicar histórico |
| Documento via `stored_object`/`StorageInterface` existentes | Tabela `encounter_document` própria | Já resolvido na Fase 0; nenhuma responsabilidade nova de storage |
| Retorno via `AppointmentService::schedule()` existente | Tabela `encounter_followup` própria | Um retorno é só outro agendamento; reaproveita regra de conflito de horário já testada |
| Prescrição/exame/procedimento/vacina só como casca de UI | Persistência completa nesta fase | Decisão do usuário; alinhado ao roadmap do PRD (Fase 3 é dedicada a isso) |
| `AiClinicalAssistantInterface` com `NullAiClinicalAssistant` | Adiar toda a UI de "resumo IA"/sugestões para a Fase 8 | PRD pede a UI/UX da IA embutida já na Fase 2 (seção 8.8); só a geração real fica pra depois |
| Ditado por voz via `TText::enableSpeechRecognition()` nativo | Implementar Web Speech API na mão via `TScript::create()` | Já existe pronto no framework, confirmado por exploração — menos código, mesmo resultado |

## Diagrama de dependências

```text
T-01 → T-02 → T-03 → T-06 → T-07 → T-09
              T-04 → T-06
              T-05 → T-06
T-03 → T-08
T-04 → T-08
T-07 → T-09
T-08 → T-09
```

## Estratégia de execução
- Branch de trabalho: não aplicável — projeto não é repositório Git.
- Isolamento em ondas com edições paralelas: escritor único por arquivo em
  todas as ondas (T-03/T-04/T-05 criam arquivos próprios, sem sobreposição);
  `EncounterView.php` (T-06) é escrito por um único agente, em uma única
  task, sem paralelismo.

## Ondas de execução

### Onda 1
- T-01

### Onda 2
- T-02

### Onda 3
- T-03, T-04, T-05

### Onda 4
- T-06

### Onda 5
- T-07, T-08

### Onda 6
- T-09

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Athena | general-purpose | herdado | T-01, T-02, T-03 |
| Platão | general-purpose | herdado | T-04, T-05 |
| Aang | general-purpose | herdado | T-06 |
| Naruto | general-purpose | herdado | T-07 |
| Levi | general-purpose | herdado | T-08, T-09 |

## Critérios gerais de aceite
- `docker compose exec app php tests/run.php` permanece com 0 falhas após
  cada onda que toque Core/testes.
- Nenhuma DDL/DML aplicada sem autorização SQL explícita apresentada com
  efeito, risco e backup, como nas Fases 0 e 1 (inclui tanto a migration de
  T-01 quanto os `INSERT`s de `system_program`/`system_group_program` de
  T-07).
- Um usuário do grupo "Template - Admin" consegue abrir `EncounterView`
  pela aplicação real (não só via `php -r`) depois de T-07.
- Nenhum dado de outro tenant nem de outra unidade visível ou editável em
  `EncounterView`.
