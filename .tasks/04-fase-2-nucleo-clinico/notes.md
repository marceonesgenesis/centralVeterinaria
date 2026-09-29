# Notas de execução

## Decisões tomadas
- {data} — {decisão e motivo}

## Bloqueios
- {descrição, condição de desbloqueio}

## Descobertas
- T-01 concluída (Athena). Migration `20260922_0003_phase2_encounter.sql` preparada (não aplicada): tabela `encounter` como agregado único, tenant-scoped, FKs para `system_unit`, `patient`, `appointment` (nullable, ON DELETE SET NULL, suporta walk-in) e `system_users`. CHECK `encounter_status_ck` restringe status a `in_progress`/`finished`. Tipos de FK conferidos contra a migration da Fase 1. `.verify.sql` só com SELECT, sem alias de palavra reservada sem crase.
- Gate Onda 1 (T-01): **aprovado**. Tipos das 4 FKs (`system_unit_id`, `patient_id`, `appointment_id`, `professional_system_user_id`) conferidos via `DESCRIBE` contra as tabelas reais — todos batem. Migration não aplicada, checksum ainda placeholder.
- T-02 concluída (Athena). `EncounterRepositoryInterface` (`Core/Domain/Contract`) estende `TenantRepositoryInterface`; `AiClinicalAssistantInterface` (`Core/Assistant/Contract`, namespace novo, mesmo espírito de `AuditLogWriterInterface`) com `summarizePatientHistory(int): ?string` e `suggestNextSteps(int): array`. Sem TPage/Adianti/TenantContext em código real.
- Onda 3 concluída (Athena + Platão, em paralelo, sem colisão):
  - T-03 (Encounter): `Encounter`, `EncounterRepository extends AbstractTenantRepository implements EncounterRepositoryInterface`, `EncounterService` com os 6 métodos do plano. `start()`/`finish()` integram `AuthorizationPolicyInterface` desde já (não como retrofit, diferente da Fase 1): `start()` usa `resourceUnitId` do payload antes de gravar; `finish()` usa a unidade REAL já persistida no encounter (mesmo padrão de `QueueEntryService::advanceStatus()`), ambos lançando `AuthorizationDenied` via `assertAllowed()` antes de qualquer `save()`. `timeline()` lê `audit_log` direto via PDO (sem Repository formal para essa tabela neste plano — decisão documentada no docblock).
  - T-04 (IA placeholder): `NullAiClinicalAssistant` — `summarizePatientHistory()` retorna `null`, `suggestNextSteps()` retorna `[]`, sem I/O.
  - T-05 (Documentos): `EncounterDocumentService::attach()` prefixa a chave por tenant+encounterId (`tenant/{id}/encounter/{id}/{arquivo}`, seguindo o padrão de segmentos de `ObjectKeyNamespace`) antes de chamar `StorageInterface::put()`. **Pendência conhecida e documentada**: `list()` sempre retorna `[]` — `StorageInterface` não expõe listagem e não existe repositório de `stored_object` neste plano; não é um bug, é uma limitação real de escopo (T-16 da Fase 1 também não implementou isso).
  - Todos os Repositories com PDO injetado e docblock "PENDING / DO NOT WIRE YET" (tabela `encounter` ainda não aplicada). Nenhuma query real executada.
- Gate Onda 3 (T-03, T-04, T-05): **aprovado**. Ordem "autoriza antes de gravar" confirmada linha a linha em `start()`/`finish()` (não só a presença da chamada); `EncounterRepository` confirmado como subclasse real de `AbstractTenantRepository`; `EncounterDocumentService::attach()` sempre passa pela função de prefixo de chave; suíte 117/117 sem regressão.
- T-06 concluída (Aang). `EncounterView`/`Encounter` (model). Ditado por voz via `TText::enableSpeechRecognition()` nativo. "Retorno" chama `AppointmentService::schedule()` real; Prescrição/Exame/Procedimento/Vacina abrem painel e gravam só um evento em `audit_log` via `AuditEvent`+`PdoAuditLogWriter` direto (decisão documentada, sem tabela própria). Autosave real: `setInterval` (20s) client-side + `__adianti_ajax_exec()` (mesmo mecanismo já usado por `TCalendar` no framework) chamando `onAutosave` → `EncounterService::autosave()`. `AuthorizationDenied`/`CrossTenantReferenceException`/`SchedulingConflictException`/`InvalidStatusTransitionException` tratadas como `TMessage`, nunca fatal.
  - `TXMLBreadCrumb` removida do construtor (classe ainda não registrada em `menu.xml` — normal nesta etapa, mesma solução já usada em `AppointmentForm.php`; T-07 decide se reativa).
  - **Limitação documentada a revisar no gate**: `service_id` do agendamento de origem (usado no resumo financeiro) é guardado em `TSession` em vez de derivado de `Encounter`/`AppointmentService`, porque nem `Encounter` guarda `service_id` nem `AppointmentService` expõe `findById()`. Funciona, mas é um desvio do padrão "nunca acessar Persistence/Domain direto do controller" — vale conferir no gate se isso é aceitável ou merece ajuste.
  - `docker compose exec app php -r "require 'engine.php'; new EncounterView();"` → OK; suíte 117/117, sem regressão.
- Gate Onda 4 (T-06): **aprovado**. Nenhuma regra de negócio duplicada confirmada por leitura integral (1257 linhas); `enableSpeechRecognition()` real; autosave com assinatura de `__adianti_ajax_exec` conferida contra `adianti.js:469` e o uso real em `TCalendar.php:213`; as 4 exceções de negócio sempre capturadas. Veredito sobre `service_id`/`TSession`: aceitável — `AppointmentService` de fato não expõe `findById()`, não é dado sensível, não afeta autorização, pior caso é preço desatualizado com duas abas abertas. **Débito técnico registrado**: expor `AppointmentService::findById()` ou persistir `service_id` no agregado `Encounter`, para remover essa dependência de `TSession` numa rodada futura.

## Onda 5 — T-07 e T-08 (2026-09-22)
- T-07 (Naruto): `src/menu.xml` atualizado — item "Care" (ícone `fas:stethoscope`) dentro do grupo Reception, logo após "Queue"/`QueueEntryView`, seguindo o fluxo Agenda→Fila→Atendimento. `menu.xml` validado como XML. SQL de registro no RBAC **redigido mas não executado** (restrição do agente): `INSERT INTO system_program (id, name, controller) VALUES (75, 'Encounter view', 'EncounterView');` + `INSERT INTO system_group_program (id, system_group_id, system_program_id) VALUES (75, 1, 75);` — aguardando autorização do usuário pro orquestrador rodar, mesmo procedimento da Fase 1.
- T-08 (Levi): `EncounterServiceTest`, `EncounterDocumentServiceTest` + dublês novos (`FakeEncounterRepository`, `FakeStorage`, `NullPdo`). Destaque: `testFinishThrowsAuthorizationDeniedWhenPolicyDeniesUsingEncounterRealUnit` prova de forma automatizada (não só por leitura de código, como no gate da Onda 4) que `finish()` usa a unidade REAL do encounter (`system_unit_id=5`) e não a da sessão ativa (`unit=1`) — assert direto no `resourceUnitId` recebido pela `FakeAuthorizationPolicy`. `docker compose exec app php tests/run.php` → **125/125, 0 falhas** (117 anteriores + 8 novos).
- **Lacuna conhecida, não bloqueante**: `EncounterService::timeline()` não tem teste automatizado — lê `audit_log` via PDO concreto (não uma interface), então testar de verdade exigiria um teste de integração com dados reais de auditoria pra um encounter sintético, fora do escopo de T-08 (testes unitários). Vale considerar para uma rodada futura ou para T-09 avaliar se é aceitável fechar a fase assim.
- T-07 finalizada (orquestrador, com autorização explícita do usuário "sim pode seguir"): os 2 `INSERT`s executados com `centralvet_migrator`. Verificado: `system_group_program` vincula `system_program_id=75` (`EncounterView`) ao grupo 1; `SystemUser::find(1)->getPrograms()` (mesma fonte que `loadSessionVars()` usa no login real) já retorna `EncounterView` como concedido — confirma acesso real, não só a linha no banco. `docker compose exec app php tests/run.php` → 125/125, sem regressão.

## Migration da Fase 2 aplicada (2026-09-22)
- Usuário autorizou explicitamente ("sim confirmo") a aplicação de `20260922_0003_phase2_encounter.sql` contra o MySQL de desenvolvimento, após apresentação de efeito, risco, banco/ambiente e tabela afetada.
- Backup gerado e testado antes: `var/backups/centralvet-20260922T175602Z.sql.gz` (gzip íntegro, 52 tabelas, batendo com a contagem real do banco).
- Checksum SHA-256 calculado (`eacc567ba71c16e994458b2d89d8974e572b4a3abde000339d065e41610caec0`) e substituído no placeholder antes da execução única.
- Aplicada com o usuário dedicado `centralvet_migrator` — não root. Exit code 0.
- `.verify.sql`: `schema_migrations` registrado com checksum correto; tabela `encounter` existe com as 23 colunas esperadas, 0 linhas (nenhum dado semeado); **0 violações** nos 6 checks de integridade referencial/tenant/status.
- `docker compose exec app php tests/run.php` → **125/125, 0 falhas** (sem regressão; `timeline()` continua sem teste automatizado, gap já conhecido e documentado, não afetado pela aplicação da migration).
- Pós-suíte: `SELECT COUNT(*) FROM encounter` → `0` — sem resíduo.
- A partir de agora, `EncounterView` opera com dados reais: o critério de saída do PRD para a Fase 2 ("consulta completa no mesmo fluxo") está tecnicamente cumprido.

## Follow-up pós-Fase 2: as duas pendências fechadas (2026-09-22)
- **`service_id` fora do `TSession`**: `AppointmentService` ganhou `findById(int $id): ?Appointment` (passthrough tenant-scoped pro Repository, que já tinha `findById()` herdado, só não era exposto na Application layer). `EncounterView` agora deriva `service_id` de `Encounter::appointmentId()` → `AppointmentService::findById()` → `Appointment::serviceId`; atendimento sem agendamento prévio (walk-in) trata sem erro, resumo financeiro fica vazio. `TSession` não é mais usado pra isso.
- **`timeline()` sem teste**: novo `src/tests/Integration/EncounterTimelineIntegrationTest.php`, mesmo padrão `MysqlIntegrationTestCase` (transação + `ROLLBACK` garantido) já usado na Fase 0/1. Dentro da transação: cria `patient`+`encounter` reais, grava 2-3 linhas de `audit_log` (`entity_type='encounter'`) mais uma de outro tipo/encounter pra provar que o filtro funciona, chama `EncounterService::timeline()` real (com `FakeAuthorizationPolicy` que sempre permite) e confirma ordem/escopo exatos.
- Container precisou de rebuild pra pegar o arquivo de teste novo (não usa bind-mount) — mesmo detalhe que já pegou outros agentes nesta sessão.
- `docker compose exec app php tests/run.php` → **130/130, 0 falhas** (125 anteriores + 3 novos de timeline + 2 novos de `AppointmentService::findById`). Pós-suíte: `patient`/`encounter`/`audit_log` com `COUNT(*) = 0` — sem resíduo, `ROLLBACK` confirmado.
- Nenhuma pendência conhecida restante na Fase 2.

## Riscos
- {risco e mitigação}

## Retomada
- Pasta: `.tasks/04-fase-2-nucleo-clinico/`
- Branch de trabalho: não aplicável — projeto não é repositório Git.
- Commits por onda: {onda → hash}
- Último status conhecido: {resumo}
- Próxima onda recomendada: {onda}

## Onda 6 — T-09 (2026-09-22)
- T-09 (Levi): revisão de segurança e quality gate de fechamento da Fase 2. `docker compose exec app php tests/run.php` → **125/125, 0 falhas, 0 pulados**.
- Fail-closed confirmado por leitura direta do código (não só pelo teste): `EncounterService::start()` autoriza via `AuthorizationPolicyInterface::decide()->assertAllowed()` ANTES de `save()`, usando `system_unit_id` do payload; `finish()` autoriza usando `$encounter->systemUnitId()` (a unidade REAL já persistida, lida via `requireEncounter()`), não a unidade da sessão — em ambos os casos nada é gravado antes da checagem.
- `EncounterView.php` (1257 linhas): nenhuma regra de negócio duplicada — todas as ações chamam `EncounterService`/`EncounterDocumentService`/`AppointmentService`; a única escrita direta fora de Application service é o evento de `audit_log` para prescrição/exame/procedimento/vacina (`PdoAuditLogWriter`+`AuditEvent`), decisão documentada no plano e no docblock (T-06), não regra de negócio. `AuthorizationDenied`, `CrossTenantReferenceException`, `SchedulingConflictException`, `InvalidStatusTransitionException` e `MissingTenantContext` sempre capturadas e viram `TMessage`, nunca erro fatal — confirmado em todos os handlers (start, load, finish, acceptAiSummary, ações inline, retorno, documento).
- `EncounterDocumentService::attach()` sempre prefixa a chave via `key()` (`tenant/{tenantId}/encounter/{encounterId}/{arquivo sanitizado}`) antes de chamar `StorageInterface::put()` — nenhum caminho grava sem esse prefixo.
- Nenhuma chamada real a provedor de IA: `NullAiClinicalAssistant` não faz I/O, `grep` por `http/https` externo em `EncounterView.php` só retorna a licença do cabeçalho do Adianti. Ditado por voz confirmado via `TText::enableSpeechRecognition()` nativo (linha 523), não reimplementação.
- Nenhuma prescrição/exame/procedimento/vacina persistida em tabela própria — confirmado: sem `INSERT`/`->save()` para essas entidades, só o evento em `audit_log`.
- Migration `20260922_0003_phase2_encounter.sql` segue **NÃO aplicada** (`Status: PREPARED ONLY` presente); `.verify.sql` contém só `SELECT`. Isso é decisão explícita do usuário (checkpoint de autorização SQL), não falha de execução — `EncounterView` ainda não opera contra dados reais de `encounter` até essa migration ser autorizada e aplicada.
- Critério de saída do PRD ("consulta completa no mesmo fluxo"): Core, UI, RBAC real (`system_program`/`system_group_program` já aplicados e verificados na Onda 5) e suíte de testes estão prontos e íntegros. Falta apenas a aplicação da migration de T-01 para a tela operar contra dados reais em produção — bloqueio consciente, aguardando autorização SQL do usuário.
- Pendências não bloqueantes reavaliadas e mantidas para rodada futura (não impedem o fechamento desta fase):
  1. `service_id` guardado em `TSession` em vez de derivado de `Encounter`/`AppointmentService` (já avaliado e aceito no gate da Onda 4 — sem impacto de autorização ou dado sensível).
  2. Ausência de teste automatizado para `EncounterService::timeline()` (lê `audit_log` via PDO concreto; cobertura exigiria teste de integração fora do escopo unitário de T-08).
- **Parecer final da Fase 2: aceito com pendências.** Nenhum bloqueante encontrado. Fase 2 pronta para produção condicionada à aplicação autorizada da migration de T-01 (e do já preparado registro RBAC, que já foi aplicado). T-09 concluída.
