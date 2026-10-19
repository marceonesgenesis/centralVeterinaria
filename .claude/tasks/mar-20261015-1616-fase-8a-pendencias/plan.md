# Plano: Pendências da Fase 8A (AI Gateway)

## Objetivo
Fechar as pendências abertas na revisão final da Fase 8A (`mar-20261007-1508-fase-8a-ai-gateway/reviews/final.md`, `notes.md § Pendências` e `docs/runbooks/ia-assistente.md`): endurecer as telas de IA (decisão, resumo por POST, locale), o `PiiMinimizer`, o `AiConfig`, o contexto clínico e o termo de uso, cobrir os testes faltantes e corrigir runbooks e o SQL de limpeza, na própria branch `feat/fase-8a-ai-gateway`.

## Premissas
- Continuação da 8A na branch `feat/fase-8a-ai-gateway` (HEAD `641c4f6`, base `main`), sem branch nova e sem push (pedido do desenvolvedor, registrado em `notes.md`).
- Nenhuma escrita em banco além da que a SUITE já faz no `centralvet_test`; nenhum SQL executado (o `T-19-cleanup.sql` só é editado).
- PiiMinimizer conservador com allowlist clínica (decisão do usuário): nomes e contatos continuam saindo; partes de nome que são termos clínicos e faixas de referência de exame ficam.
- Contexto clínico inclui prescrições `draft` e exames `pending_review`, marcados como provisórios/não validados (decisão do usuário).
- A declaração do ditado entra em `AiTerms` e a versão sobe para `2026-10-v2`; quem aceitou `2026-10-v1` reaceita (decisão do usuário). O bloqueio até o reaceite já existe (`AiAssistService` devolve `ai_terms_pending`; `AiSettingsService::enable` exige o aceite).
- Fuso: bug confirmado. A sessão MySQL de dev roda em `-03:00` (`SELECT @@session.time_zone` → `-03:00`) e grava `created_at` em hora local, mas `AiAssistService::monthStart()` e `AiSettingsService::monthStart()` calculam o mês em UTC: das 21:00 às 23:59 do último dia do mês (America/Fortaleza) a cota passa a ler o mês seguinte, quase vazio. Correção: mês no fuso da aplicação (`date_default_timezone_get()`, `APP_TIMEZONE`).
- `ANTHROPIC_BASE_URL` passa a exigir `https` sem exceção: o código não prevê http para localhost/stub (o stub não usa a URL), então nenhuma exceção é criada.

## Escopo

### Incluso
- `ExamResultForm::onAiDecide`: valida `decision`; "Aceitar" com texto editado vira `edited` (SHA-256) → T-08; validação de `decision` antes do metadata de autorização em `AiAssistService::decide` → T-05.
- Resumo de exame por POST (TButton em TForm), recusa de chamada não POST → T-08.
- Locale único das telas de IA (`AiLocale::fromLanguage`) → T-01, T-08, T-09.
- PiiMinimizer conservador com allowlist clínica, faixas de referência, NFD, hífen/apóstrofo, celular sem DDD → T-03.
- `AiConfig`: `https` obrigatório em `ANTHROPIC_BASE_URL` e `#[\SensitiveParameter]` → T-02.
- Contexto clínico com prescrições em rascunho e exames pendentes marcados como provisórios; versões de prompt `.v2` e teste que trava texto × versão → T-06.
- Declaração do ditado em `AiTerms`, `AiTerms::VERSION` = `2026-10-v2`, reaceite → T-04.
- Ditado: live region sem `display:none`, status "ouvindo", mensagens para `no-speech`, `audio-capture`, `network` e erro genérico → T-07 (JS/CSS) e T-09 (rótulos na tela e traduções).
- Testes faltantes: outra unidade nas telas (T-08, T-09); T-02 (T-12); T-05 fake × PDO (T-11); T-07 custo da falha pós-fallback (T-13); T-11/T-12 `forContext` e `find` duplo (T-05, T-10); T-14 `edited` e autosave em voo (T-09); T-15 regressão legada e `Throwable` (T-08); T-17 pt não vazio (T-14).
- Fuso do mês da cota (bug confirmado) → T-05, T-10.
- Onda 4 — correção (revisão final): regressão de PII em telefone com DDD e assinante de 8 dígitos iniciado em 6–9, mais faixas de referência e celular sem DDD só em contexto de contato (itens 6/7 do code-review, mesmo arquivo) → T-16; docblock de `AiClinicalContextQueryInterface` → T-21.
- Onda 5 — correção (code-review): conflito do autosave por SHA-256 da anamnese carregada → T-17; https só com provider `anthropic` → T-18; `AiLocale` em AiSettingsForm e AiPromptCatalog → T-19; ExamResultForm "Aceitar" sem SHA fica `accepted` e com texto vazio é recusado → T-20; escopo da palavra de contato não termina em abreviação com `/` (`p/`, `c/`, `s/`), achado do fechador da onda 4 → T-22.
- Onda 6 — correção (usuário): PiiMinimizer mascara todo número com formato de telefone, com exceção só para unidade de laboratório e marcador de referência sem celular → T-23; autosave com versão no `EncounterService` e UPDATE condicional atômico → T-24; "Aceitar" sem hash na ExamResultForm compara com a sugestão gravada → T-25.
- Onda 7 — correção (usuário): `ia-assistente.md` com a regra de PII e o autosave versionado → T-26.
- Onda 8 — correção (usuário): fixo de 8 dígitos sem prefixo intacto, guarda de decimal em todos os ramos, unidade no fim de frase (PII) → T-27; autosave com `SELECT ... FOR UPDATE` e `draftVersion` dos campos de rascunho, números normalizados, ciclo de vida sem regravar o rascunho, "Aplicar" com tick em voo, `postedDraftVersion()` → T-28.
- Onda 9 — correção (usuário): regra única de "Aceitar" (`AiAssistService::resolveDecision`) nas duas telas → T-29; runbook com fixo sem DDD e atomicidade nova → T-30.
- Onda 10 — correção (usuário, última onda focada): PII com celular nunca isento pela guarda de decimal → T-31; encontro finalizado não aceita autosave nem Aplicar, falha de tick nunca silenciosa, vitais sem negativo, falso conflito do `previous_draft` com número inválido → T-32.
- Onda 11 — correção (usuário): `AiAssistService::normalizeText` público usado pelas telas → T-33; runbook com o encontro encerrado e a guarda de decimal → T-34.
- Docs: `shared-hosting-mysql57.md § Migration 0014`, `ia-assistente.md` (:28, :63 e termo v2), ADR 0006 e `T-19-cleanup.sql` (:39, :135, :151) do plano anterior → T-15.

### Excluído
- Smoke real com `ANTHROPIC_API_KEY` (sem chave ainda) — pendência manual do usuário.
- Verificação visual das telas em dev (inclui alvos ≥ 44 px no navegador) — pendência manual.
- EXPLAIN com volume de `monthlyTokenUsage`/`monthlySummary` — pendência manual.
- Execução de qualquer SQL de limpeza ou DML.
- Chaves de tradução fora da 8A (`Reload`, `System`, `Dark mode`, `Update`).
- Ondas 10–11 (escopo fechado pelo usuário): refatorações e docblocks apontados na revisão final rodada 4 ficam como pendência.
- Coluna de versão em `encounter` (exigiria migration). A regra de "Aceitar" sem hash na EncounterView, fora na onda 6, entrou na T-29 (onda 9).
- Onda de correção: mover allowlist/normalização/rebaixamento por SHA de `ExamResultForm::onAiDecide` e `EncounterView::onAiDecide` para `AiAssistService::decide` (sugestão do code-review); sugestões abertas da triagem da revisão final não citadas no ruling (query/fragment/userinfo em `httpsUrl`, chave órfã da declaração antiga etc.).
- Pendências de produto não pedidas: N+1 dos itens de prescrição, visão só leitura do exame sem anexo/`markReviewed`, `AiInteractionList::userNames` sem teste, "Aceitar" com `ai_final_text` esvaziado na EncounterView, DRAFT_FIELD inválido bloqueando a decisão.

## Contexto técnico
- Camadas envolvidas: backend, frontend, docs, qa
- Projeto/base analisada: `/var/www/html/centralvet` (raiz de `git -C <DIR> rev-parse --show-toplevel`), branch `feat/fase-8a-ai-gateway` @ `641c4f6`
- Integrações: provedor Anthropic só por `StubAiGateway`/`FakeAiGateway` nos testes; Web Speech API do navegador (ditado)

## Baseline
- php-lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app sh -c 'find app/Core app/control app/lib/widget bin tests -name "*.php" -exec php -l {} \; 2>&1 | grep -v "^No syntax errors detected"'` em raiz → baseline/php-lint.txt (0 linhas). Critério das tasks: nenhum erro novo em relação a `baseline/php-lint.txt` (cada PHP tocado imprime `No syntax errors detected`).
- Cópia de referência (não é lint): `baseline/T-19-cleanup.orig.sql`, o `sql/T-19-cleanup.sql` do plano anterior antes da T-15 (arquivo não rastreado pelo git; a T-15 prova a edição com `diff`).

## Exploração read-only
- Caminhos relevantes:
  - **Config/PII/prompt.** `src/app/Core/Ai/AiConfig.php` (ctor :22, `$apiKey` :24, `fromArray(array $env)` :60, `string()` :174, `choice()` :191, `integer()` :205; base URL só com `rtrim` :74; stub vetado em produção :65). `src/app/Core/Ai/PiiMinimizer.php` (`minimize(string $text, array $knownNames)` :53; telefone :63; dígitos soltos :68; partes de nome ≥ 3 letras :131; `PARTICLES` :23). `src/app/Core/Ai/AiPromptCatalog.php` (`VERSIONS` :23, todas `.v1`; `build(..., string $locale, ...)` :146 com regra `en` se começa com `en`, senão `pt`; `patientSummary` :164).
  - **Contexto clínico.** `src/app/Core/Persistence/AiClinicalContextQuery.php` (`patientHistory` :62; `historyPrescriptions` :131 sem filtro de `status`; `historyExamResults` :174 sem `pending_review`; `examResultContext`). `prescription.status` ∈ (`draft`, `issued`) e `exam_result.pending_review` tinyint (migration 0004 :89, :165).
  - **Termo.** `src/app/Core/Domain/AiTerms.php` (`VERSION = '2026-10-v1'` :13, `text(string $locale)` :33); `AiTenantSetting::hasAcceptedTerms` :66; `AiAssistService` :261 (`ai_terms_pending`); `AiSettingsService::enable` :124; declaração do ditado como `_t(...)` separado em `src/app/control/clinic/AiSettingsForm.php:245`. Literal `2026-10-v1` em `AiDomainTest:189-195`, `AiSettingsServiceTest:139,145`, `AiSettingsScreensIntegrationTest:154`, `EncounterAiIntegrationTest:373`, `AiRepositoryIntegrationTest:253-259` (este só faz ida e volta de string; fica).
  - **Serviços.** `src/app/Core/Application/AiAssistService.php` (`forContext` :93, `decide` :177 com `$decision` cru no metadata :189, `fail` :323, `monthStart` em UTC); `src/app/Core/Application/AiSettingsService.php` (`forContext` :69, `find()` duplo em `changeMonthlyQuota` :177, `monthStart` em UTC :204). `AiInteractionRepository::monthRange` :186 formata sem conversão de fuso; `src/app/config/application.php:12` → `America/Fortaleza`.
  - **Falha pós-fallback.** `AiInteraction::recordFailure` :194 calcula custo com um só modelo; `AiProviderException` (ctor com `usage`, `model`); `AiAssistService` :295 `catch (AiProviderException $e)`.
  - **Telas.** `ExamResultForm.php`: `onAiExamSummary` :285 (link GET `<a ... method=onAiExamSummary>` :507), `onAiDecide` :330 (`decision` cru :336, `ai_final_text` :337), `suggestionForm` (TForm `form_ExamResultAi_<id>`, textarea `ai_final_text_<id>`), `makeAiAssistant` com locale `pt` se começa com `pt`, senão `en` (:598), `catch (Exception)` em :315, :373, :422, :435. `EncounterView.php`: `aiLocale()` :1444, botão de ditado e `data-label-*` :1525, hidden `ai_suggestion_sha256` :1615, `onAiDecide` :1856 (SHA → `edited` :1877), autosave JS `setInterval` 20 s :1145-1164, `onAutosave` :2084.
  - **Ditado.** `src/app/templates/adminbs5/js/cv-ai-dictation.js` (`onerror` :143 trata só `not-allowed`/`service-not-allowed`; status por `textContent` :102-146; `statusFor` :32); `cv-components.css:942` `.cv-ai-status:empty{display:none}`.
  - **Testes.** Runner `src/tests/run.php` (sem filtro); `src/tests/Unit/AiDictationAssetTest.php` lê o JS/CSS; `UserMessageTest::assertScreenKeysTranslated` :522 (só confere chave en); `AnthropicMessagesGatewayTest:116,148,179` (fallback em `content` já coberto); `FakeAiInteractionRepository` `latestDecidedFor` :121 (ordena por `decided_at`; o PDO por `created_at`), `monthRows` :164 (sem limite superior); segunda unidade: `SELECT id FROM system_unit ORDER BY id LIMIT 2` (`AiClinicalContextQueryIntegrationTest:51`) ou `createUnit` privado de `ReminderSourceQueryUnitTenantIntegrationTest:174`.
  - **Docs.** `docs/runbooks/shared-hosting-mysql57.md:335-336` cita DML "descrita em ia-assistente.md" (a DML real é `src/app/database/seeds/initial-application-programs.sql:1011-1034`); `docs/runbooks/ia-assistente.md:28` ("o termo de uso o declara", falso em v1), `:37`, `:63` (`APP_ENV` "(da aplicação)"; o padrão é `development`, `AiConfig.php:63`), `:119`; `docs/architecture/adr/0006-ai-gateway.md:25-26,33-34`; `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-19-cleanup.sql:39,:135,:151` (`surgery.followup_appointment_id` existe, migration 0011 :111,:141).
- Padrões identificados: services recebem repositórios por interface, `AuthorizationPolicyInterface`, `TenantContext` e `?Closure $clock` por último; controller abre a transação (`TTransaction::open('permission')`); ações de tela por `TButton` + `TAction` com `setFormName` (POST, `EncounterView::aiButton` :1566 → `headerButton` :518); controllers só carregam em subprocesso com `require "init.php"`; i18n em `src/app/config/translations.json` (`_t('en')` → pt); mensagens ao usuário por `CvFormat::userError`.
- Scripts úteis: LINT, SUITE (em `agents/*.md § Ambiente`); sem build JS (não há `package.json`).
- Riscos identificados:
  - **Versão do termo** quebra todo teste que semeia `2026-10-v1` como aceito (EncounterAiIntegrationTest :373): a T-04 troca por `AiTerms::VERSION`.
  - **Allowlist frouxa** vaza nome: a allowlist só isenta *partes* de nome; o nome completo continua mascarado (Review Focus).
  - **Autosave em voo** regrava a anamnese antiga depois de "Aplicar à anamnese"; páginas recarregadas acumulam `setInterval` (T-09).
  - **Arquivos compartilhados**, serializados em ondas: `EncounterAiIntegrationTest.php` (T-04 → T-09), `AiDomainTest.php` (T-04 → T-12), `AiSettingsServiceTest.php` (T-04 → T-10), `AiAssistService.php` e `AiAssistServiceTest.php` (T-05 → T-13), `translations.json` (T-09 → T-14).

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| src/app/Core/Ai/AiLocale.php | regra única de locale do prompt (`en` se começa com `en`, senão `pt`) | criar | T-01 |
| src/tests/Unit/AiLocaleTest.php | ⚠ testes do locale (T-01); varredura das regras de locale (T-19) | criar | T-01, T-19 |
| src/app/Core/Ai/AiConfig.php | ⚠ https obrigatório e `#[\SensitiveParameter]` (T-02); https só com provider `anthropic` (T-18) | modificar | T-02, T-18 |
| src/tests/Unit/AiConfigTest.php | ⚠ testes de https, atributos e mensagens sem valor (T-02); stub com http (T-18) | modificar | T-02, T-18 |
| src/app/Core/Ai/PiiMinimizer.php | ⚠ minimização conservadora com allowlist clínica (T-03); telefone 6–9, faixas, celular só com contexto (T-16); `/` só separa itens com espaço dos dois lados (T-22); mascarar por padrão, exceção só unidade/marcador (T-23); fixo sem prefixo intacto, decimal, unidade no fim de frase (T-27); celular nunca isento pela guarda de decimal (T-31) | modificar | T-03, T-16, T-22, T-23, T-27, T-31 |
| src/tests/Unit/PiiMinimizerTest.php | ⚠ testes de nomes, faixas, NFD, hífen/apóstrofo, celular (T-03); casos da correção (T-16); abreviações `p/`, `c/`, `s/` (T-22); expectativas da regra "mascarar por padrão" (T-23); fixo sem DDD, decimal, unidade (T-27); vazamentos após `\d[.,]` (T-31) | criar | T-03, T-16, T-22, T-23, T-27, T-31 |
| src/app/Core/Domain/AiTerms.php | termo v2 com a declaração do ditado | modificar | T-04 |
| src/app/control/clinic/AiSettingsForm.php | ⚠ termsCard sem a declaração duplicada (T-04); locale via `AiLocale` (T-19) | modificar | T-04, T-19 |
| src/tests/Unit/AiDomainTest.php | ⚠ versão do termo (T-04); REASONS e AiSubjectNotFoundException (T-12) | modificar | T-04, T-12 |
| src/tests/Unit/AiSettingsServiceTest.php | ⚠ versão do termo e reaceite (T-04); find único, no-op, fuso (T-10) | modificar | T-04, T-10 |
| src/tests/Integration/AiSettingsScreensIntegrationTest.php | termo v2 na tela, declaração única | modificar | T-04 |
| src/tests/Integration/EncounterAiIntegrationTest.php | ⚠ semente do termo (T-04); edited, descarte, autosave em voo, outra unidade, rótulos do ditado (T-09); conflito do autosave por SHA (T-17); autosave versionado (T-24); FOR UPDATE, números, ciclo de vida, Aplicar em voo (T-28); Aceitar sem hash (T-29); finish na aba B com tick/Aplicar na aba A (T-32) | modificar | T-04, T-09, T-17, T-24, T-28, T-29, T-32 |
| src/app/Core/Application/AiAssistService.php | ⚠ decisão validada e mês no fuso (T-05); custo da falha pós-fallback (T-13) ; `resolveDecision` (T-29); `normalizeText` público (T-33) | modificar | T-05, T-13, T-29, T-33 |
| src/tests/Unit/AiAssistServiceTest.php | ⚠ decisão inválida, fuso, latestDecided outra unidade, autorização do resumo de exame (T-05); custo pós-fallback (T-13) ; casos de `resolveDecision` (T-29); `normalizeText` público (T-33) | modificar | T-05, T-13, T-29, T-33 |
| src/tests/Integration/AiAssistServiceForContextIntegrationTest.php | `AiAssistService::forContext` com config válida e inválida | criar | T-05 |
| src/app/Core/Persistence/AiClinicalContextQuery.php | status da prescrição e pending_review no contexto | modificar | T-06 |
| src/app/Core/Ai/AiPromptCatalog.php | ⚠ marcadores de provisório, regra no BASE, versões `.v2` (T-06); locale via `AiLocale` (T-19) | modificar | T-06, T-19 |
| src/tests/Unit/AiPromptCatalogTest.php | marcadores e trava texto × versão | modificar | T-06 |
| src/tests/Integration/AiClinicalContextQueryIntegrationTest.php | draft e pending_review no histórico | modificar | T-06 |
| src/tests/Support/FakeAiClinicalContextQuery.php | fake com as chaves novas | modificar | T-06 |
| src/app/templates/adminbs5/js/cv-ai-dictation.js | erros do ditado e status "ouvindo" | modificar | T-07 |
| src/app/templates/adminbs5/cv-components.css | live region vazia sem `display:none` | modificar | T-07 |
| src/tests/Unit/AiDictationAssetTest.php | asserções do JS/CSS | modificar | T-07 |
| src/app/control/clinic/ExamResultForm.php | ⚠ decisão validada, SHA → edited, resumo por POST, locale, `Throwable` (T-08); Aceitar sem SHA e com texto vazio (T-20); sem hash compara com a sugestão gravada (T-25); usa `AiAssistService::resolveDecision` (T-29); `AiAssistService::normalizeText` (T-33) | modificar | T-08, T-20, T-25, T-29, T-33 |
| src/tests/Integration/ExamResultAiIntegrationTest.php | ⚠ testes da tela de exame (T-08); Aceitar sem SHA e com texto vazio (T-20); sem hash × sugestão gravada (T-25); regressão com a regra única (T-29) | modificar | T-08, T-20, T-25, T-29 |
| src/app/control/clinic/EncounterView.php | ⚠ locale, rótulos do ditado, guarda do autosave (T-09); guarda por SHA-256 da anamnese carregada (T-17); autosave versionado pelo serviço, ordem das respostas, manter/recarregar (T-24); `previous_draft` no Aplicar, `postedDraftVersion()` (T-28); Aceitar sem hash pela regra única (T-29); resposta `closed`, falha nunca silenciosa (T-32); `AiAssistService::normalizeText` (T-33) | modificar | T-09, T-17, T-24, T-28, T-29, T-32, T-33 |
| src/app/config/translations.json | ⚠ rótulos do ditado (T-09); pt vazio, se houver (T-14); aviso de conflito do autosave (T-17); botões do conflito (T-24) | modificar | T-09, T-14, T-17, T-24 |
| src/app/Core/Application/AiSettingsService.php | find único, mês no fuso | modificar | T-10 |
| src/tests/Integration/AiSettingsServiceForContextIntegrationTest.php | `AiSettingsService::forContext` | criar | T-10 |
| src/tests/Support/FakeAiInteractionRepository.php | paridade com o PDO (ordem e fim do mês) | modificar | T-11 |
| src/tests/Unit/FakeAiInteractionRepositoryTest.php | testes da paridade | criar | T-11 |
| src/app/Core/Domain/Contract/AiInteractionRepositoryInterface.php | docblock de `insert` (exige desfecho) | modificar | T-12 |
| src/app/Core/Domain/Exception/AiProviderException.php | custo da falha (`costMicroUsd`) | modificar | T-13 |
| src/app/Core/Ai/AnthropicMessagesGateway.php | custo por iteração na falha | modificar | T-13 |
| src/app/Core/Domain/AiInteraction.php | `recordFailure` aceita custo calculado | modificar | T-13 |
| src/tests/Unit/AnthropicMessagesGatewayTest.php | custo da recusa pós-fallback | modificar | T-13 |
| src/tests/Unit/UserMessageTest.php | pt não vazio nas chaves das telas de IA | modificar | T-14 |
| src/app/Core/Domain/Contract/AiClinicalContextQueryInterface.php | docblock com `status` e `pending_review` | modificar | T-21 |
| src/app/Core/Domain/Encounter.php | ⚠ `draftVersion()` (T-24); números do rascunho normalizados antes de hashear e gravar (T-28); `isFinished()`, vitais com mínimo 0 (T-32) | modificar | T-24, T-28, T-32 |
| src/app/Core/Application/EncounterService.php | ⚠ `autosave` com versão esperada e `DraftSaveResult` (T-24); `lockForDraft` + `updateDraft`, ciclo de vida sem rascunho (T-28); status sob o lock, `previous_draft` com número inválido (T-32) | modificar | T-24, T-28, T-32 |
| src/app/Core/Application/DraftSaveResult.php | ⚠ resultado do autosave (`saved`, `version`); `closed` (T-32) | criar | T-24, T-32 |
| src/app/Core/Domain/Contract/EncounterRepositoryInterface.php | ⚠ `saveDraftIfUnchanged` (T-24); `lockForDraft`, `updateDraft` (T-28) | modificar | T-24, T-28 |
| src/app/Core/Persistence/EncounterRepository.php | ⚠ UPDATE condicional por `updated_at` (T-24); `SELECT ... FOR UPDATE`, `updateDraft`, `save()` sem colunas de rascunho no UPDATE (T-28) | modificar | T-24, T-28 |
| src/tests/Support/FakeEncounterRepository.php | ⚠ fake do UPDATE condicional (T-24); fake de `lockForDraft`/`updateDraft` (T-28) | modificar | T-24, T-28 |
| src/tests/Unit/EncounterServiceTest.php | ⚠ versão, conflito, resposta perdida, cliente sem versão (T-24); vírgula decimal, pause/finish sem falso conflito, ciclo de vida não regrava o rascunho (T-28); encerrado, negativos, inválido no tick em voo (T-32) | modificar | T-24, T-28, T-32 |
| docs/runbooks/shared-hosting-mysql57.md | § Migration 0014 aponta a DML real | modificar | T-15 |
| docs/runbooks/ia-assistente.md | ⚠ termo v2, ditado, APP_ENV, https, fuso (T-15); regra de PII e autosave versionado (T-26); fixo sem DDD, FOR UPDATE, só campos do rascunho (T-30); encontro encerrado e guarda de decimal (T-34) | modificar | T-15, T-26, T-30, T-34 |
| docs/architecture/adr/0006-ai-gateway.md | termo v2 e declaração do ditado | modificar | T-15 |
| .claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-19-cleanup.sql | `@gate_start`, literais, `surgery.followup_appointment_id` | modificar | T-15 |

Arquivo tocado por mais de uma task leva ⚠ na linha e a resolução (serializar em ondas diferentes, worktree por agente ou escritor único) fica em "Estratégia de execução"; caminho exclusivo não resolve arquivo compartilhado na mesma onda.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| `CentralVet\Ai\AiLocale::fromLanguage()` em arquivo novo, usado pelas duas telas | helper em `AiPromptCatalog`; corrigir só a linha da ExamResultForm | arquivo novo não colide com a T-06 e dá um ponto testável; a regra é a mesma do `AiPromptCatalog::build` (`en` se começa com `en`, senão `pt`) — produto brasileiro, idioma vazio vira `pt` |
| Resumo de exame por `TButton` em `TForm` e recusa de método não POST no servidor | só trocar o link por botão | o servidor também precisa recusar GET (prefetch, link externo) |
| Allowlist isenta só *partes* de nome; nome completo segue mascarado | remover só nomes completos (menos conservador) | decisão do usuário: conservador |
| Faixa de referência: DDD com dois dígitos 1–9 e assinante de 8 dígitos começando em 2–5 ou de 9 dígitos começando em 9; casamento seguido de unidade (`mg/dL`, `/µL`, `%`...) não é telefone | heurística por palavra "VR"/"ref" | regras do plano de numeração brasileiro; cobre `20 1000-4800` sem afrouxar telefones reais |
| Termo `2026-10-v2` com a frase do ditado; testes usam `AiTerms::VERSION` | manter declaração separada | decisão do usuário; o reaceite já é imposto por `hasAcceptedTerms` |
| Todas as versões de prompt sobem para `.v2` (regra nova no `BASE` compartilhado) | subir só `patient_summary` | `BASE` é comum aos três prompts |
| Mês da cota em `date_default_timezone_get()` | `CONVERT_TZ` no SQL; `SET time_zone` na conexão | menor mudança; dev grava `created_at` em `-03:00` = `APP_TIMEZONE`. Exige sessão MySQL no mesmo fuso de `APP_TIMEZONE` (documentado na T-15) |
| Guarda do autosave por `draft_loaded_at` comparado ao `decided_at` da última `anamnesis_organize` decidida | token de revisão no `encounter`; só documentar | sem migration; o texto aplicado não é regravado por um autosave montado antes da decisão |
| Custo da falha vem do gateway (`AiProviderException::costMicroUsd()`) | recalcular no domínio | o gateway já precifica cada iteração pelo seu modelo |
| Correção de PII (T-16): DDD entre parênteses ou `+55` é sempre contato; DDD solto só deixa de ser telefone diante de unidade de exame, par de metades terminadas em `00` ou marcador de referência; celular sem DDD só com palavra de contato antes | só ampliar a lista de unidades | `(85) 8765-4321` e `85 8765-4321` voltam a sair sem perder `Leucócitos 12 3500-4500` nem `95000-1200` |
| Conflito do autosave por SHA-256 da anamnese carregada/salva por esta aba (T-17), com aviso visível | manter `draft_loaded_at` × `decided_at` | a guarda antiga descartava em silêncio todo tick depois de uma decisão em outra aba e consultava `latestDecidedFor` a cada 20 s |
| (usuário) PII mascarado por padrão: todo número com formato de telefone vira `[TELEFONE]`; exceção só para unidade de laboratório logo depois ou marcador `VR`/`ref`/`referência` logo antes sem celular de 9 dígitos (T-23) | escopo por palavra de contato; metades em `00` | decisão do usuário; revoga o item 7 das ondas 4/5 e a regra das metades `00`; aceita mascarar valor de exame sem unidade |
| (usuário) Versão do rascunho = SHA-256 dos campos de rascunho do `Encounter`; atomicidade por `UPDATE ... WHERE updated_at = :lido`; resposta perdida resolvida com o rascunho anterior enviado pela aba (T-24) | coluna `draft_version` (migration); SHA só da anamnese | não há coluna de versão e migration exige aprovação SQL; `updated_at timestamp(6)` já existe e muda a cada UPDATE |
| (usuário) "Aceitar" sem hash compara o texto com `suggestion_text` gravado (T-25) | tratar sem hash como `accepted` (T-20) | nunca descartar texto editado |
| (usuário) Fixo de 8 dígitos sem DDD, `+55`, tronco `0` ou parênteses fica intacto; celular de 9 dígitos sempre mascarado; DDD + fixo segue mascarado salvo VR/ref ou unidade (T-27) | manter "mascarar por padrão" também para o fixo sem DDD | revoga parcialmente a decisão da onda 6 só nesse ponto: ano, lote, CRMV e faixas sem DDD deixam de virar `[TELEFONE]` |
| Guarda do rascunho por `SELECT ... FOR UPDATE` + `draftVersion()` (T-28) | `updated_at` da linha (T-24) | `updated_at` muda em pause/resume/finish/acceptAiSummary e dá falso conflito; a releitura em REPEATABLE READ devolvia snapshot velho |
| `save()` de encontro existente deixa de escrever as colunas de rascunho; rascunho só por `updateDraft` (T-28) | passar pause/finish pela guarda | mínimo seguro: ciclo de vida não sobrescreve rascunho com valores velhos e não precisa de versão |
| "Aplicar" com tick em voo envia `previous_draft` pelo formulário (T-28) | serializar o Aplicar no JS | reaproveita a regra já testada do autosave; sem esperar rede no clique |
| Regra única de "Aceitar" em `AiAssistService::resolveDecision` (T-29) | duplicar a regra da T-25 na EncounterView | as duas telas divergiram duas vezes |
| Guarda de decimal: trecho com celular depois de `\d[.,]` só é isento quando a sequência de dígitos R imediatamente antes do `[.,]` não é ela mesma precedida de `\d[.,]` e R tem 1 dígito ou é um par que não é DDD (T-31) | isentar todo número depois de vírgula | fecha `123,987654321`, `123,85987654321`, `38,5,987654321` e, de graça, `085,987654321`; mantém `0,987654321`, `10,987654321`, `1,23456789` |
| Encontro finalizado: checado sob o `FOR UPDATE` com `Encounter::isFinished()`; resultado `closed` sem gravar (T-32) | checar só no controller (`findById` sem lock) | uma aba aberta não regrava rascunho de encontro finalizado em outra aba |
| `T-19-cleanup.sql` editado sem commit (pasta do plano anterior não é rastreada) | `git add -f` da pasta | não versionar artefato de plano; a prova é `diff` contra `baseline/T-19-cleanup.orig.sql` |

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04  T-05  T-06  T-07
Onda 2: T-01,T-05 → T-08 ; T-01,T-04,T-07 → T-09 ; T-04 → T-10 ; T-05 → T-11 ; T-04 → T-12 ; T-05 → T-13
Onda 3: T-09 → T-14 ; T-02,T-03,T-04,T-05,T-06,T-10 → T-15
Onda 4 (correção, revisão final): T-03 → T-16 ; T-06 → T-21
Onda 5 (correção, code-review): T-09,T-14 → T-17 ; T-02 → T-18 ; T-01,T-04,T-06 → T-19 ; T-08 → T-20 ; T-16 → T-22
Onda 6 (correção, usuário): T-22 → T-23 ; T-17 → T-24 ; T-20 → T-25
Onda 7 (correção, usuário): T-23,T-24 → T-26
Onda 8 (correção, usuário): T-23 → T-27 ; T-24 → T-28
Onda 9 (correção, usuário): T-25,T-28 → T-29 ; T-26,T-27,T-28 → T-30
Onda 10 (correção, usuário): T-27 → T-31 ; T-28,T-29 → T-32
Onda 11 (correção, usuário): T-29,T-32 → T-33 ; T-30,T-31,T-32 → T-34
```

## Estratégia de execução
- Branch de trabalho: `feat/fase-8a-ai-gateway`
- Observação: continuação pedida pelo desenvolvedor; já em checkout em `/var/www/html/centralvet`.
- Branch base: `main`
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`); com `worktree por agente` eles chegam pelos merges de `wave<N>/T-XX`. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Esses commits são internos: a entrega da Fase 5 (`entrega.py`) os desfaz e deixa o código staged para o desenvolvedor revisar; commit final e push só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo (checkout compartilhado; arquivos com ⚠ serializados em ondas diferentes; SUITE única por agente, falha de arquivo alheio é ruído)

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04, T-05, T-06, T-07 — contratos (`AiLocale`, decisão validada, rótulos do ditado) e núcleo independente

### Onda 2
- T-08, T-09, T-10, T-11, T-12, T-13 — telas e testes que dependem dos contratos

### Onda 3
- T-14, T-15 — traduções e documentação; depois, validação cruzada e revisão final (Fase 5)

### Onda 4 — correção (revisão final)
- T-16, T-21 — bloqueante de PII (com os itens 6/7 do code-review, mesmo arquivo) e docblock do contrato de contexto

### Onda 5 — correção (code-review)
- T-17, T-18, T-19, T-20 — autosave por SHA, https só no provider `anthropic`, locale único, "Aceitar" na ExamResultForm
- T-22 — escopo da palavra de contato com `/` de abreviação (achado do fechador da onda 4; roda junto, arquivos disjuntos)

### Onda 6 — correção (usuário)
- T-23, T-24, T-25 — PII mascarado por padrão, autosave versionado no serviço, "Aceitar" sem hash contra a sugestão gravada (arquivos disjuntos)

### Onda 7 — correção (usuário)
- T-26 — runbook `ia-assistente.md` com as regras das T-23 e T-24

### Onda 8 — correção (usuário)
- T-27, T-28 — PII (fixo sem DDD, decimal, unidade no fim de frase) e autosave (FOR UPDATE, números, ciclo de vida, Aplicar em voo); arquivos disjuntos

### Onda 9 — correção (usuário)
- T-29, T-30 — regra única de "Aceitar" (depois da T-28, que divide a EncounterView) e runbook

### Onda 10 — correção (usuário)
- T-31, T-32 — PII (guarda de decimal) e encontro encerrado/falha do tick/vitais/`previous_draft`; arquivos disjuntos

### Onda 11 — correção (usuário)
- T-33, T-34 — `normalizeText` único (divide EncounterView com a T-32) e runbook

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Platão | general-purpose | inherit | T-01 |
| Jaspion | general-purpose | inherit | T-02 |
| Arquimedes | general-purpose | inherit | T-03 |
| Yoda | general-purpose | inherit | T-04 |
| Spock | general-purpose | inherit | T-05 |
| Aang | general-purpose | inherit | T-06 |
| Naruto | general-purpose | inherit | T-07 |
| Sherlock | general-purpose | inherit | T-08 |
| Thanos | general-purpose | inherit | T-09 |
| Saitama | general-purpose | inherit | T-10 |
| Hegel | general-purpose | inherit | T-11 |
| Levi | general-purpose | inherit | T-12 |
| Darwin | general-purpose | inherit | T-13 |
| Maquiavel | general-purpose | inherit | T-14 |
| Gandalf | geduc:documentador | sonnet | T-15, T-21, T-26, T-30, T-34 |
| Kratos | general-purpose | inherit | T-16 |
| Athena | general-purpose | inherit | T-17 |
| Batman | general-purpose | inherit | T-18 |
| Tesla | general-purpose | inherit | T-19 |
| Goku | general-purpose | inherit | T-20 |
| Hipátia | general-purpose | inherit | T-22 |
| Ada | general-purpose | inherit | T-23 |
| Dijkstra | general-purpose | inherit | T-24 |
| Curie | general-purpose | inherit | T-25 |
| Noether | general-purpose | inherit | T-27 |
| Lamport | general-purpose | inherit | T-28 |
| Euclides | general-purpose | inherit | T-29 |
| Gauss | general-purpose | inherit | T-31 |
| Hamilton | general-purpose | inherit | T-32 |
| Knuth | general-purpose | inherit | T-33 |

## Review Focus
- Tenant com `terms_version = '2026-10-v1'` e IA ligada pede uma sugestão → aviso `ai_terms_pending`, nenhuma chamada ao provedor, AiSettingsForm mostra "termo atual não aceito" até o reaceite → T-04
- Tutor "Maria das Dores" e anamnese "dores abdominais há 2 dias" → "Maria das Dores" vira `[NOME]`, "dores abdominais" permanece → T-03
- "Tutor ligou do 98765-4321 ontem" e resultado "Leucócitos 12 3500-4500 x10³/µL" → número vira `[TELEFONE]` sem palavra de contato, faixa com unidade intacta → T-23
- Aba aberta antes de "Aplicar à anamnese" em outra aba envia um tick → nenhum campo do rascunho é gravado, a aba avisa o conflito e mantém o texto local; resposta perdida de um tick não trava a aba → T-24
- Geração às 22:00 de 31/10 (America/Fortaleza) com a cota de outubro esgotada → `ai_quota_exceeded`, não o mês de novembro vazio → T-05

## Critérios gerais de aceite
- SUITE inteira com `Failed: 0` ao fim de cada onda.
- `php -l` sem erro novo em relação a `baseline/php-lint.txt` em todo PHP tocado.
- `/usr/bin/grep -rn "method=onAiExamSummary" src/app/control` → nenhuma linha.
- `/usr/bin/grep -rn "2026-10-v1" src/app` → nenhuma linha.
- Nenhum SQL executado e nenhum push: `git -C /var/www/html/centralvet log origin/feat/fase-8a-ai-gateway..HEAD` (se o remoto existir) só com commits locais.
