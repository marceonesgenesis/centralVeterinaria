# Notas de execução

## Decisões tomadas
- 2026-10-07 · plano · onda 0 — **Escopo.** Fase 8A núcleo = PRD § 9: AI Gateway + resumo do paciente, organização da anamnese (texto livre/ditado) e resumo de exame, todos com revisão humana antes de persistir. Fora: busca natural, próximos passos, rascunho ao tutor (8A.2), MCP (8B), agents (8C). `suggestionsPanel` (próximos passos) sai do `EncounterView` na T-14.
- 2026-10-07 · plano · onda 0 — **Cliente HTTP próprio (curl) em vez do SDK oficial.** A demanda dizia "PHP sem SDK oficial", mas a skill `claude-api` documenta o SDK PHP oficial (`anthropic-ai/sdk`) e o recomenda por padrão. O planejador manteve curl porque `src/composer.json` está em `src/app/config/framework_hashes.php` (as fases anteriores proíbem editá-lo), só `POST /v1/messages` não streaming é usado e o projeto já tem o padrão curl (`S3Client`). Ficou isolado no adaptador `AnthropicMessagesGateway`; trocar pelo SDK muda só esse arquivo. Usuário confirmou: curl próprio, sem SDK, `composer.json` intocado.
- 2026-10-07 · plano · onda 0 — **Modelo padrão (resposta do usuário).** `AI_MODEL=claude-sonnet-5-5` (US$ 2/10 por MTok), troca por env continua possível. Effort `low` (`AI_EFFORT`): o padrão do Sonnet 5.5 é `high`, e a skill `claude-api` indica `low` para chamada única de texto, `medium` como degrau se a qualidade pedir. O Sonnet 5.5 recusa `thinking` `disabled` e sampling fora do padrão (400): o gateway omite `thinking`, `temperature`, `top_p` e `top_k`. `fallbacks: "default"` (beta `server-side-fallback-2026-07-01`, só Claude API) ligado por padrão; recusas `cyber`/`frontier_llm` vão para `claude-sonnet-5` (no catálogo de preços), as demais voltam como `refusal` (`ai_refused`). O custo usa o `model` da resposta.
- 2026-10-07 · plano · onda 0 — **Síncrono.** Uma chamada por requisição, timeout total 30 s e de conexão 5 s, sem retentativa. A fila/worker não é usada: a revisão é na mesma interação e o worker na hospedagem roda por cron `--once`. Falha vira aviso traduzido e a tela segue.
- 2026-10-07 · plano · onda 0 — **Prompt caching** não usado: o prompt de sistema fica abaixo do mínimo cacheável do modelo. As colunas de tokens de cache existem para o custo ficar certo se um dia houver.
- 2026-10-07 · plano · onda 0 — **Dados.** `feature_flag` genérica (flag `ai_assistant`, ausência = desligado; PRD § 13), `ai_tenant_setting` (termo `2026-10-v1` e cota mensal de tokens, padrão 300000) e `ai_interaction` (finalidade, versão de prompt, provedor, modelo devolvido, usuário, unidade, sujeito, tokens, custo em micro-USD, latência, `stop_reason`, id do pedido, código de erro, sugestão, texto final e decisão). Da entrada só o SHA-256. Sem ALTER em tabela existente.
- 2026-10-07 · plano · onda 0 — **Persistência após revisão.** Resumo do paciente → `EncounterService::acceptAiSummary` (colunas `ai_summary_*` já existentes). Anamnese → `EncounterService::autosave` com `anamnesis_text` = texto postado pelo formulário + linha em branco + texto aceito (nunca substitui o que o profissional digitou). Resumo de exame → só `ai_interaction.final_text`; `exam_result` não muda.
- 2026-10-07 · plano · onda 0 — **RBAC.** As ações de IA autorizam pelo programa da tela hospedeira (`EncounterView::onAi*`, `ExamResultForm::onAi*`) com a unidade do sujeito: mesmas permissões da UI, sem DML. `AdiantiProgramPermissionProvider` só entende `Classe::metodo` (ação com ponto seria sempre negada). Programas novos `AiSettingsForm` e `AiInteractionList` só para o grupo 1 (2 concessões), confirmado pelo usuário; os usos clínicos seguem as permissões das telas clínicas.
- 2026-10-07 · plano · onda 0 — **Porta antiga.** `AiClinicalAssistantInterface`/`NullAiClinicalAssistant` (Fase 2) são substituídos por `AiAssistantInterface`/`AiAssistService`; a remoção fica na T-14, junto com o último uso em `EncounterView`, para a tela não quebrar entre as ondas 1 e 4.
- 2026-10-07 · plano · onda 0 — **Gates sem rede.** `AI_PROVIDER=stub` (`StubAiGateway`, recusado com `APP_ENV=production`), ligado por variável de shell no `docker compose up` do orquestrador, sem editar `.env`. E2E com a API real (resposta do usuário): o usuário põe a `ANTHROPIC_API_KEY` no `.env` local antes da Onda 6, a pedido do orquestrador; a T-19 faz no máximo 3 chamadas (uma por caso de uso) e confere que a chave não aparece em log, relatório ou tabela, sem imprimir o valor.
- 2026-10-07 · plano · onda 0 — **Branch e base.** Branch de trabalho `feat/fase-8a-ai-gateway` (nome dado pelo orquestrador; o padrão da skill seria `task/fase-8a-ai-gateway`), base `main` @ `5e4b238` (`branches.py`: produção `main`, fonte `padrão`; teste `dev` informada pela demanda).
- 2026-10-07 · plano · onda 0 — **Baseline.** php-lint 0 linhas (734 PHP sem erro) e test-prepare-mysql57 com `Ran 8 tests` / `OK`, gravados em `baseline/`.
- 2026-10-07 · T-03 · onda 1 — Fix loop rodada 1: a cruzada `grep api.anthropic.com src/tests` reprovou (AiConfigTest:36, host literal); corrigido em 9a582c2 (parse_url + host montado), revalidado e revisado aprovado. O grep do critério é só proxy: tasks futuras não devem contorná-lo com host montado.
- 2026-10-07 · T-01 · onda 1 — Comando PREP57 do plano falha (`No numbered SQL files found`; o preparador lê só `NN-*.sql`): usada cópia `18-` em diretório temporário, como na 0013; repassar à T-18 (runbook). "2 UNIQUEs" = `feature_flag_tenant_key_uq` + PK de `ai_tenant_setting`.
- 2026-10-07 · gate · onda 1 — `AI_PROVIDER=anthropic` é padrão no docker-compose com `ANTHROPIC_API_KEY` vazia; os testes não leem env e a suíte roda sem rede/chave: aceito.
- 2026-10-07 · bloqueio · onda 1 — Aplicar a 0014 (centralvet e centralvet_test) e o DML da T-04 exige aprovação SQL do usuário; pendente entre as ondas 1 e 2 (o orquestrador conduz).
- 2026-10-07 · bloqueio · onda 2 — Bloqueio entre ondas 1 e 2 resolvido com aprovação SQL explícita do usuário: backup `var/backups/centralvet-20261007T184658Z.sql.gz` (gzip -t ok); 0014 aplicada em centralvet e centralvet_test (checksum c24b4dc88f6e2ea897dbf077a5394f9bf955103ac253005fc4ef925d10d15db9, cópia temporária; arquivo commitado mantém o placeholder); verify: 3 tabelas vazias, 8 CHECK, 9 FK, 3 PK, 1 UNIQUE em cada banco; DML T-04 em centralvet: programas 138 AiSettingsForm e 139 AiInteractionList, só grupo 1 (2 concessões), system_program 137→139, system_group_program 189→191; verify T-04 ok (grupos 2..5 = 0).
- 2026-10-07 · T-07 · onda 2 — [plano-mandou] custo/tokens somam `usage.iterations` quando há fallback (o usage de topo cobre só a tentativa final). Modelo por iteração: `model` da entrada se houver; senão `fallback_message` usa o modelo do topo e as demais `AI_MODEL`. Overload não aciona fallback (skill); o teste usa recusa.
- 2026-10-07 · T-10 · onda 2 — [plano-mandou, USUÁRIO] manter o ditado via Web Speech e DECLARAR que o áudio vai ao serviço de voz do fabricante do navegador; aviso junto ao botão (`data-label-notice`/`aria-describedby`). T-13 (termo de IA), T-14/T-17 (aviso traduzido em `data-label-notice`) e T-18 (runbook/ADR) incorporam a declaração (registrado nas notas dessas tasks em tasks.md).
- 2026-10-07 · T-08 · onda 2 — Ajuste de texto de entrada de teste no commit de implementação, sem remover asserção; RED válido (validador).
- 2026-10-07 · gate · onda 3 — Itens "não rodado" do validador (contagens encounter/exam_result/patient/audit_log antes/depois, grep da chave em ai_interaction/audit_log, testes de tela) ficam para os gates das ondas 4–6, onde telas e banco real entram; não são pendência da onda 3.
- 2026-10-07 · plano · onda 3 — Correção de rota: o fechador da onda 2 sugeriu T-16 na onda 3; o plano (plan.md:196-197) põe T-16 na onda 4, mantido.
- 2026-10-07 · T-11 · onda 3 — Desvios aceitos: `latestDecided` restrito à unidade ativa; texto em branco do provedor = falha `ai_provider_error`; falha de auditoria propaga para rollback do controller.
- 2026-10-07 · T-12 · onda 3 — Desvios aceitos: mutação sem efeito não grava nem audita; `month_tokens` via `monthlyTokenUsage`; `recentInteractions` limite 1..200.
- 2026-10-07 · T-14 · onda 4 — Escopo: a0cf20b tocou só o docblock de AiAssistantInterface.php, fora dos Arquivos prováveis; aceito (remoção da porta antiga era da T-14).
- 2026-10-07 · T-14 · onda 4 — [plano-mandou] rodada 1: onAiDecide persiste os DRAFT_FIELDS postados pelo caminho de rascunho antes de aplicar o texto aceito no campo-alvo; encontro fora de estado não grava nem recarrega. Corrigido (acde3a1 RED, ad66b31), revalidado e aprovado na rodada 2.
- 2026-10-07 · T-15 · onda 4 — Visão só leitura para resultado salvo aceita: o formulário legado para resultado salvo sempre falhava ao salvar (InvalidStatusTransition); sem regressão.
- 2026-10-07 · gate · onda 4 — Rebuild feito pelo orquestrador (AI_PROVIDER=stub, app/worker/nginx); smoke Playwright das telas ADIADO para a onda 6 (T-19) por falta de credenciais de admin; novo rebuild lá. Banco centralvet inalterado antes/depois (ai_interaction 0, encounter 7, exam_result 3, patient 7, audit_log 1155); centralvet_test sem resíduo.
- 2026-10-07 · T-18 · onda 5 — Rodada 1 (orquestrador): ADR criada como 0004 (duplicada com 0004-central-intelligence) renumerada para 0006-ai-gateway.md; a T-18 alterou o comentário da migration 0014 já aplicada (8→9 FKs), o que quebraria o checksum c24b4dc8…, e o arquivo foi restaurado byte a byte (validador conferiu sha e diff vazio contra a BASE); a contagem de 9 FKs fica só nos runbooks.
- 2026-10-07 · T-17 · onda 5 — Autorizado ajustar a linha 188 de ExamResultAiIntegrationTest.php (T-15) para o texto traduzido (consequência do i18n). Desvio: 13 motivos de IA como PATTERNS; STATIC ganhou 'Edited AI text must not be empty'. RED 444f2ac sem Co-Authored-By (cosmético).
- 2026-10-07 · gate · onda 5 — Antes da onda 6 o orquestrador pede ao usuário ANTHROPIC_API_KEY no .env local e credenciais de admin, refaz o rebuild (AI_PROVIDER=stub para os roteiros A/B) e faz o smoke de tela adiado da onda 4.
- 2026-10-07 · T-19 · onda 6 — (usuário) telas no navegador PULADAS: Roteiros A/B e login de admin viram verificação manual no deploy em dev; o smoke de tela adiado da onda 4 também fica para o deploy em dev.
- 2026-10-07 · T-19 · onda 6 — (orquestrador) Roteiro C pela tela substituído por smoke de CLI do gateway (`sql/T-19-gateway-smoke.php`: uma chamada patient_summary com texto fictício, sem banco, sem imprimir chave); `.env` sem ANTHROPIC_API_KEY, smoke PENDENTE até o usuário inserir a chave.
- 2026-10-07 · T-19 · onda 6 — (orquestrador) nenhum dado F8A criado no centralvet (encounter 7, exam_result 3, patient 7, audit_log 1155, ai_* 0 = baseline): limpeza não executada; SQL fica preparado.
- 2026-10-07 · T-15 · onda 7 — Revisão final, rodada 1, bloqueante: `ExamResultForm::onAiExamSummary` fazia rollback na falha do provedor e perdia `ai_interaction` failed, auditoria e custo; corrigido para commitar como a EncounterView (inclusive na indisponibilidade antes da chamada); rollback só em exceções inesperadas. SUITE 1323/1323.

## Bloqueios
- Bloqueio entre a Onda 1 e a Onda 2: migration 0014 em `centralvet` e `centralvet_test` e `sql/T-04-programs.sql` em `centralvet`, só com aprovação SQL do usuário. Desbloqueia quando o verify dos dois bancos e o verify do DML forem anotados aqui, com backup e SHA-256.
- Desbloqueado em 2026-10-07 (onda 2): 0014 em `centralvet` e `centralvet_test` e DML da T-04 em `centralvet` aplicados com aprovação SQL do usuário; backup `var/backups/centralvet-20261007T184658Z.sql.gz`, checksum 0014 c24b4dc8...5db9, verifies ok (ver Decisões).

## Descobertas
- [T-01] PREP57 literal falha; usar cópia `18-` em diretório temporário (T-18 documenta). 0014 commitada em b24d228: 8 FKs, índices auxiliares `ai_interaction_unit_idx`/`_user_idx`/`_decided_by_idx`.
- [T-03] `CentralVet\Ai\AiConfig` (e8deecd): construtor privado, `fromEnvironment()`/`fromArray()`; `apiKey()` null se vazia; `baseUrl()` sem barra final; `toArray()`/`__debugInfo()` mascaram a chave como `*****`; JsonLogger/AuditRedactor mascaram `x-api-key` e `anthropic_api_key`.
- [T-02] Extras além do contrato: `AiUsage::none()`, value objects com `public readonly`, `AiPurpose::assertValid()`, `AiProviderException::httpStatus()`, getters em `AiInteraction`/`AiTenantSetting`; `AiInteraction::start` exige `inputSha256` hex de 64 chars e ids > 0; `provider` fica '' até `recordSuggestion`/`recordFailure` (uma vez só); `AiProviderException::REASONS` = `AiUnavailableException::REASONS` (13 códigos).
- [T-10] `cv-ai-dictation.js` (decdc16, a72d846): `attach` reaproveita o irmão com `role="status"`; `.cv-dictation-label` preserva ícone; aviso em `<small class="cv-ai-dictation-notice">` via `data-label-notice` + `aria-describedby`; T-14 inclui o script e chama `attach`.
- [T-05] Fakes em `CentralVet\Tests\Support` (20668b1); extras `savedBySystemUserId()`, `changes()`, `calls()`.
- [T-07] Gateways (85302ad, 8a14ad4): `providerName()` anthropic/stub/none; sem chave `ai_not_configured`; custo soma `usage.iterations`.
- [T-09] `AiClinicalContextQuery` (9ebe83e): `patientHistory` com `$excludeEncounterId` exclui prescrições e exames do atendimento; `items` = strings.
- [T-06] Repositórios PDO (5ec4d85, 94e083c): `insert` exige desfecho (`ai_interaction_failed_ck`); `saveDecision` false se não `pending_review`; mês no fuso da sessão.
- [T-08] `CentralVet\Ai\AiPromptCatalog`/`PiiMinimizer` estáticos; `build` com 4º parâmetro opcional `$today`.
- [T-12] `AiSettingsService` (5884754): `recentInteractions()` devolve `list<AiInteraction>` (limite 1..200); `overview()['month_tokens']` vem de `monthlyTokenUsage`, custo de `monthlySummary`; mutação sem efeito não grava nem audita; auditoria `entityType` `ai_tenant_setting`, before/after só com `terms_version`/`enabled`/`monthly_token_quota`; constantes `ACTION_OVERVIEW|ACTION_SAVE|ACTION_INTERACTIONS`, `MAX_MONTHLY_TOKEN_QUOTA`.
- [T-11] `AiAssistService` (9ddc919): `forContext` lança `ai_not_configured` se AiConfig inválida; gerações exigem unidade ativa; `decide` de outra unidade → `AuthorizationDenied`; `latestDecided` só da unidade ativa; constantes `AUDIT_GENERATED|AUDIT_FAILED|AUDIT_DECIDED`; falha de auditoria propaga (rollback no controller).
- [gate] Cabeçalho da 0014 diz "8 foreign keys", mas são 9 (feature_flag 2 + ai_tenant_setting 3 + ai_interaction 4); corrigir na T-18.
- [T-13] Telas AiSettingsForm/AiInteractionList (452814f); helpers `purposeLabel()`, `usd()`, `number()`, `statusLabel()`; declaração do ditado fora de `AiTerms::text`. Chaves `_t` novas listadas no board para a T-17.
- [T-14] EncounterView com painel de IA (a0cf20b, ad66b31); porta antiga removida; `suggestionsPanel` fora. Chaves `_t` no board (inclui 'This encounter is finished and can no longer be edited').
- [T-15] ExamResultForm com resumo por IA, original intacto (f16dbfc); `exam_request_id` com resultado mostra visão só leitura + painel.
- [T-16] menu.xml (665cbdd): submenu `_t{AI assistant}` com `_t{AI settings}`/`_t{AI interactions}`; T-17 traduz.
- [T-17] i18n pt/en pronto (444f2ac RED, 76c24a5, bfdc07a): mensagens de domínio da IA e telas traduzidas; 'AI suggestion not found' sai traduzido e o teste da T-15 foi ajustado.
- [T-18] Runbook ia-assistente.md, ADR 0006-ai-gateway.md, seção 0014 na hospedagem 5.7 (87e31eb, db0cc4f); 0014 intacta (9 FKs só nos runbooks).
- [T-19] `sql/T-19-cleanup.sql` (não executado; COMMIT comentado) e `sql/T-19-gateway-smoke.php` (smoke CLI, sem banco) prontos (1793234); telas ficam para verificação manual em dev; ANTHROPIC_API_KEY vazia no .env.

## Pendências
- T-01: T-18 deve levar o passo PREP57 (cópia `18-` em diretório temporário) ao runbook 5.7.
- T-02: documentar no docblock de `AiInteraction::start()`/`AiInteractionRepositoryInterface::insert` que `pending_review` sem `suggestion_text` viola `ai_interaction_failed_ck`; só inserir após `recordSuggestion`/`recordFailure` (T-06/T-11). Falta asserção de `AiSubjectNotFoundException` e de `REASONS` igual em AiDomainTest.
- T-03: `#[\SensitiveParameter]` ausente em `$apiKey` do construtor e `$env` de `fromArray`/`string`/`choice`/`integer` (AiConfig.php:24, :60); casos `32001`/`abc` não conferem ausência do valor na mensagem.
- Validador: critérios dependentes da aplicação da 0014/DML não avaliados (bloqueio entre ondas 1 e 2); aplicar 0014 em centralvet e centralvet_test e o DML da T-04 com aprovação SQL, e rodar os verifies.
- T-05: fake `latestDecidedFor` ordena por decided_at e o PDO por created_at; `monthRows` do fake não limita o fim do mês como o PDO (T-11/T-14 podem divergir).
- T-06: confirmar com volume (EXPLAIN) o índice de `monthlyTokenUsage`/`monthlySummary` no gate final; fuso da sessão MySQL sem conversão explícita.
- T-07: sem teste com bloco `fallback` real em `content`; na falha pós-fallback o custo é recalculado com um só modelo (`recordFailure`, T-02) — pendência menor.
- T-08: telefone mascara faixas de exame (`Linfócitos 20 1000-4800`); nome NFD não casa; partes de nome com apóstrofo/hífen; celular sem DDD; falta teste que trave "texto muda, versão muda".
- T-09: `historyPrescriptions` inclui `draft`; `historyExamResults` inclui `pending_review=1` sem sinalizar; N+1 limitado nos itens de prescrição.
- T-10: `.cv-ai-status:empty{display:none}` em live region; status anuncia rótulo da ação; erros `no-speech`/`audio-capture`/`network` sem retorno; asserções fracas no teste; aviso só aparece se a tela passar `data-label-notice` (T-14 e teste).
- T-18: levar a declaração do ditado (Web Speech) e corrigir "8 foreign keys" para 9 no cabeçalho da 0014/runbook.
- Validador: critérios de contagem encounter/exam_result/patient/audit_log e grep da chave no banco não exercitados na onda 2.
- T-11: `forContext` sem teste (config inválida e montagem dos repositórios); `latestDecided` sem caso "outra unidade → null"; metadata do pedido de autorização em `decide` leva `$decision` cru antes da validação (AiAssistService.php:189); teste de autorização negada não cobre `summarizeExamResult`.
- T-12: `forContext` sem teste; `changeMonthlyQuota` chama `settings->find()` duas vezes (AiSettingsService.php:177); caminho de mutação sem efeito sem teste.
- T-13: declaração do ditado fora de AiTerms::text/VERSION (aceite não a versiona; avaliar na T-18/próxima VERSION); asserção do ditado `/navegador|browser/i` frouxa; `AiInteractionList::userNames` sem cobertura (só a T-19 pela tela).
- T-14: sem teste do caminho `edited` e do descarte com rascunho postado; teste de recarga posta `$expected` em onAutosave em vez de ler o textarea; autosave de 20 s em voo durante a decisão pode regravar a anamnese antiga; "Aceitar" com `ai_final_text` esvaziado aplica a sugestão original; valor inválido em DRAFT_FIELD bloqueia a decisão (onAutosave engole); envio de `ai_free_text`/`ai_final_text` e ditado não vistos no navegador.
- T-15: falta teste de regressão dos caminhos legados (`exam_request_id` sem/com resultado); `testDisabledAiHidesThePanel` sem controle positivo; visão só leitura sem link do anexo nem ação `markReviewed` (pendência de produto); catch interno só `Exception` (considerar `Throwable`); chaves i18n até a T-17.
- T-16: rótulos `AI assistant`, `AI settings`, `AI interactions` precisam de tradução pt na T-17.
- Validador (onda 4): smoke de tela (AiSettingsForm, AiInteractionList, EncounterView, ExamResultForm, menu) adiado para a onda 6 (T-19), sem credenciais de admin.
- T-18: shared-hosting-mysql57.md:336 cita DML "descrita em ia-assistente.md" que o runbook não descreve (apontar o seed initial-application-programs.sql ou descrever); "o termo de uso o declara" impreciso (AiTerms::text() não cita áudio; só a AiSettingsForm) em ia-assistente.md e ADR 0006; ia-assistente.md § Banco com frase longa/fora de lugar e APP_ENV omite o padrão `development`.
- T-17: assertScreenKeysTranslated só confere chave en existente, não pt não vazio/diferente (UserMessageTest.php:538-540); RED 444f2ac sem Co-Authored-By.
- Fora da 8A: chaves 'Reload', 'System', 'Dark mode', 'Update' sem pt (templates gerais).
- T-19: smoke real da API pendente até o usuário inserir ANTHROPIC_API_KEY (orquestrador roda e registra).
- T-19: verificação manual no deploy em dev: menu IA, AiSettingsForm (termo/aviso de ditado/cota), EncounterView (resumo/anamnese/ditado), ExamResultForm só leitura com resumo, AiInteractionList, alvos >= 44 px (inclui o smoke de tela adiado da onda 4).
- T-19: `@gate_start` sem uso (cleanup.sql:39); comentário da seção 4 manda "preencher literais" que não existem (:151); `surgery_vazio` não cobre `surgery.followup_appointment_id` (:135); rodar o smoke com `AI_FALLBACKS=off` para medir só o modelo primário (smoke.php:46).
- T-15: `loadSavedResult`/`onAiExamSummary`/`onAiDecide` capturam só `Exception` (um `\Error` derruba a tela; considerar `Throwable`); chaves i18n novas e `AI subject not found` sem tradução até a T-17.
- Revisão final (rodadas 1 e 2): sugestões abertas listadas em `reviews/final.md`.
- eficiencia.py: aviso "onda 7 sem tasks em plan.md" (onda de correção; sem linha medida).

## Riscos
- Corrida na cota: duas gerações simultâneas podem ultrapassar a cota em uma chamada (soma lida antes da chamada). Aceito; o excesso fica visível em `AiSettingsForm`.
- Recusa por classificador (`stop_reason` `refusal`) em texto clínico comum: mitigado por `fallbacks: "default"`; se persistir, a tela mostra o aviso e o profissional segue manualmente.
- PHP-FPM ocupado até 30 s por chamada lenta: timeout curto, sem retentativa; reavaliar a fila se o uso crescer.
- `suggestion_text` de sugestões descartadas fica no banco (auditoria); expurgo fora do escopo.
- Texto livre pode conter dado pessoal não reconhecido pelo `PiiMinimizer` (ex.: endereço): o termo avisa o admin; o system prompt manda não reproduzir dados pessoais.

## Retomada
- Pasta: `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/`
- Sessões: c2da22ef-8644-4fa0-aa7c-cb2020291aaf
- Branch de trabalho: feat/fase-8a-ai-gateway (base: main)
- BASE da onda 1: 5e4b238
- Commits por onda:
- Entrega: . HEAD e570ef6 → BASE 5e4b238 (commits internos desfeitos; alterações staged)
  - Onda 1: BASE 5e4b238 → HEAD 9a582c2 (a245542, 03e89f4, 8893a83, 8103633, b24d228, e8deecd, 7c9a838, 9a582c2)
  - Onda 2: BASE 5765f54 → HEAD a72d846 (508d02a, 20668b1, cc90e02, 5ec4d85, 94e083c, a798441, 85302ad, afa884e, 8a14ad4, bbf3bc6, e4b30ae, 4c47dd6, 9ebe83e, 646a401, decdc16, 70073f5, a72d846)
  - Onda 3: BASE 3a7aa9f → HEAD 9ddc919 (02148e6, 5884754, b1301e6, 9ddc919)
  - Onda 4: BASE 43f35b6 → HEAD ad66b31 (0410f64, f872801, 74661d0, 665cbdd, 452814f, 659d232, f16dbfc, a0cf20b, acde3a1, ad66b31)
  - Onda 5: BASE 4ee59f4 → HEAD bfdc07a (444f2ac, 76c24a5, 87e31eb, db0cc4f, bfdc07a)
  - Onda 6: BASE 94604c0 → HEAD 1793234 (1793234)
  - Onda 7: BASE 0452a69 → HEAD 1565241 (ee56a46, 1565241)
- Último status conhecido: onda 7 (correção pós-revisão final) concluída (T-15 [x], SUITE 1323/1323); smoke real da API e telas pendentes (chave e deploy em dev)
- Próxima onda recomendada: nenhuma (revisão final)
