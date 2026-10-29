# Tasks

Comandos: `SUITE` e `LINT` estão definidos em `agents/<persona>.md § Ambiente`. A SUITE não filtra: o grep de cada comando seleciona as linhas `PASS`/`FAIL` da classe e o total `Failed:`.

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | backend | `AiLocale::fromLanguage` — regra única de locale do prompt | — | sim | simples | Platão | [x] |
| T-02 | backend | AiConfig: https obrigatório e `#[\SensitiveParameter]` | — | sim | simples | Jaspion | [x] |
| T-03 | backend | PiiMinimizer conservador com allowlist clínica | — | sim | alta | Arquimedes | [x] |
| T-04 | backend | Termo `2026-10-v2` com a declaração do ditado e reaceite | — | sim | média | Yoda | [x] |
| T-05 | backend | AiAssistService: decisão validada, mês no fuso, forContext e testes T-11 | — | sim | média | Spock | [x] |
| T-06 | backend | Contexto clínico com rascunhos/pendentes marcados e prompts `.v2` | — | sim | alta | Aang | [x] |
| T-07 | frontend | Ditado: live region e mensagens de erro (JS/CSS) | — | sim | média | Naruto | [x] |
| T-08 | frontend | ExamResultForm: decisão, SHA → edited, resumo por POST, locale, Throwable e testes | T-01, T-05 | sim | alta | Sherlock | [x] |
| T-09 | frontend | EncounterView: locale, rótulos do ditado, guarda do autosave e testes T-14 | T-01, T-04, T-07 | sim | alta | Thanos | [x] |
| T-10 | backend | AiSettingsService: find único, mês no fuso e forContext | T-04 | sim | média | Saitama | [x] |
| T-11 | qa | FakeAiInteractionRepository com a semântica do PDO | T-05 | sim | simples | Hegel | [x] |
| T-12 | qa | Testes de domínio T-02 e docblock de `insert` | T-04 | sim | simples | Levi | [x] |
| T-13 | backend | Custo da falha pós-fallback por modelo | T-05 | sim | média | Darwin | [x] |
| T-14 | qa | UserMessageTest exige pt não vazio nas chaves das telas de IA | T-09 | sim | simples | Maquiavel | [x] |
| T-15 | docs | Runbooks, ADR 0006 e `T-19-cleanup.sql` | T-02, T-03, T-04, T-05, T-06, T-10 | sim | média | Gandalf | [x] |
| T-16 | backend | Correção: telefone com assinante 6–9, faixas de referência e celular sem DDD só com contexto | T-03 | sim | alta | Kratos | [x] |
| T-17 | frontend | Correção: conflito do autosave por SHA-256 da anamnese carregada | T-09, T-14 | sim | alta | Athena | [x] |
| T-18 | backend | Correção: https só com provider `anthropic` | T-02 | sim | simples | Batman | [x] |
| T-19 | backend | Correção: `AiLocale` em AiSettingsForm e AiPromptCatalog | T-01, T-04, T-06 | sim | simples | Tesla | [x] |
| T-20 | frontend | Correção: "Aceitar" sem SHA fica `accepted`; texto vazio é recusado | T-08 | sim | média | Goku | [x] |
| T-21 | docs | Correção: docblock de `AiClinicalContextQueryInterface` | T-06 | sim | simples | Gandalf | [x] |
| T-22 | backend | Correção: `/` de abreviação não encerra o escopo da palavra de contato | T-16 | sim | simples | Hipátia | [x] |
| T-23 | backend | Correção (usuário): PII mascarado por padrão; exceção só unidade/marcador | T-22 | sim | alta | Ada | [x] |
| T-24 | backend | Correção (usuário): autosave com versão no serviço e UPDATE condicional | T-17 | sim | alta | Dijkstra | [x] |
| T-25 | frontend | Correção (usuário): "Aceitar" sem hash compara com a sugestão gravada | T-20 | sim | simples | Curie | [x] |
| T-26 | docs | Correção (usuário): runbook com a regra de PII e o autosave versionado | T-23, T-24 | sim | simples | Gandalf | [x] |
| T-27 | backend | Correção (usuário): fixo sem DDD intacto, decimal e unidade no fim de frase (PII) | T-23 | sim | alta | Noether | [x] |
| T-28 | backend | Correção (usuário): autosave com FOR UPDATE, números normalizados, ciclo de vida e Aplicar em voo | T-24 | sim | alta | Lamport | [x] |
| T-29 | backend | Correção (usuário): regra única de "Aceitar" (`AiAssistService::resolveDecision`) | T-25, T-28 | sim | média | Euclides | [x] |
| T-30 | docs | Correção (usuário): runbook com fixo sem DDD e atomicidade do autosave | T-26, T-27, T-28 | sim | simples | Gandalf | [x] |
| T-31 | backend | Correção (usuário): celular nunca isento pela guarda de decimal (PII) | T-27 | sim | média | Gauss | [x] |
| T-32 | backend | Correção (usuário): encontro finalizado, falha do tick, vitais sem negativo e `previous_draft` com número inválido | T-28, T-29 | sim | alta | Hamilton | [x] |
| T-33 | backend | Correção (usuário): `AiAssistService::normalizeText` único | T-29, T-32 | sim | simples | Knuth | [x] |
| T-34 | docs | Correção (usuário): runbook com encontro encerrado e guarda de decimal | T-30, T-31, T-32 | sim | simples | Gandalf | [x] |

## Detalhamento

### T-01 — `AiLocale::fromLanguage` — regra única de locale do prompt

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Platão

Achado (revisão final): a EncounterView usa `en` se o idioma começa com `en`, senão `pt` (`EncounterView.php:1446`); a ExamResultForm usa `pt` se começa com `pt`, senão `en` (`ExamResultForm.php:598`). A regra canônica é a de `AiPromptCatalog::build` (:149): `en` se começa com `en` (sem diferenciar caixa), senão `pt`. As telas passam a usá-la na T-08 e na T-09.

**Arquivos prováveis**
- `src/app/Core/Ai/AiLocale.php`
- `src/tests/Unit/AiLocaleTest.php`

**Interface**
- Produz: `CentralVet\Ai\AiLocale::fromLanguage(?string $language): string` (classe `final`, construtor privado; devolve `'en'` quando `str_starts_with(strtolower((string) $language), 'en')`, senão `'pt'`)
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiLocaleTest.php` — `AiLocale::fromLanguage` com `'pt'`, `'pt_BR'`, `'en'`, `'EN_us'`, `'es'`, `''` e `null` devolve `pt`, `pt`, `en`, `en`, `pt`, `pt`, `pt`; falha antes porque a classe não existe (comando: `SUITE | /usr/bin/grep -E 'AiLocaleTest|Failed:'`)

**Critério de aceite**
- Os 7 casos do teste passam: linhas `PASS  CentralVet\Tests\Unit\AiLocaleTest::...` e nenhuma `FAIL` da classe.
- `LINT` dos dois arquivos imprime `No syntax errors detected` (nenhum erro novo em relação a `baseline/php-lint.txt`).

**Validação**
- `SUITE | /usr/bin/grep -E 'AiLocaleTest|Failed:'` (evidência: linhas `PASS` da classe e `Failed: 0`)
- `LINT app/Core/Ai/AiLocale.php` (evidência: `No syntax errors detected`)

### T-02 — AiConfig: https obrigatório e `#[\SensitiveParameter]`

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

Achado (revisão final T-03 e sugestão): `ANTHROPIC_BASE_URL` não exige https (`AiConfig.php:74`); sem `#[\SensitiveParameter]` em `$apiKey` (:24) e em `$env` de `fromArray`/`string`/`choice`/`integer` (:60, :174, :191, :205); os casos `32001`/`abc` não conferem a ausência do valor na mensagem. O código não prevê http para localhost/stub: https vale para todo provedor.

**Arquivos prováveis**
- `src/app/Core/Ai/AiConfig.php`
- `src/tests/Unit/AiConfigTest.php`

**Interface**
- Produz: `AiConfig::fromArray()` lança `InvalidArgumentException('ANTHROPIC_BASE_URL must be an https URL')` quando `parse_url` não dá `scheme === 'https'` (comparação em minúsculas) e `host` não vazio; a mensagem nunca contém o valor recebido
- Produz: `#[\SensitiveParameter]` no parâmetro `$apiKey` do construtor e no parâmetro `$env` de `fromArray`, `string`, `choice` e `integer`
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiConfigTest.php` — `fromArray` com `ANTHROPIC_BASE_URL` = `http://api.anthropic.com`, `ftp://x` e `api.anthropic.com` lança a exceção sem o valor na mensagem; `https://example.invalid/` continua aceito; por Reflection, os 5 parâmetros têm o atributo `SensitiveParameter`; `AI_TIMEOUT_SECONDS` = `32001` e `abc` falham sem o valor na mensagem — falha antes porque http é aceito e os atributos não existem (comando: `SUITE | /usr/bin/grep -E 'AiConfigTest|Failed:'`)

**Critério de aceite**
- Todos os testes de `AiConfigTest` passam, inclusive os casos novos de https e de atributo.
- `ANTHROPIC_BASE_URL=http://...` → `InvalidArgumentException` com a mensagem `ANTHROPIC_BASE_URL must be an https URL`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiConfigTest|Failed:'` (evidência: `PASS` dos casos novos e `Failed: 0`)
- `/usr/bin/grep -c 'SensitiveParameter' src/app/Core/Ai/AiConfig.php` (evidência: `5`)
- `LINT app/Core/Ai/AiConfig.php` (evidência: `No syntax errors detected`)

### T-03 — PiiMinimizer conservador com allowlist clínica

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Arquimedes

Achado (revisão final T-08 e sugestão): toda parte de nome com 3+ letras é mascarada ("Maria das Dores" apaga "dores"); faixas de exame (`Linfócitos 20 1000-4800`) viram telefone; nome em NFD não casa; partes com hífen/apóstrofo não são isoladas; celular sem DDD não sai. Decisão do usuário: conservador + allowlist clínica — nomes e contatos continuam saindo.

**Arquivos prováveis**
- `src/app/Core/Ai/PiiMinimizer.php`
- `src/tests/Unit/PiiMinimizerTest.php`

**Interface**
- Produz: `PiiMinimizer::CLINICAL_TERMS` (`public const`, lista em minúsculas sem acento) com ao menos `'dor'`, `'dores'`, `'luz'`, `'flor'`, `'clara'`, `'claro'`, `'branca'`, `'branco'`, `'rosa'`, `'febre'`, `'pena'`, `'cruz'`: uma *parte* de nome que está na lista não vira candidata; o nome completo (todas as partes juntas) continua candidato e é mascarado
- Produz: `minimize()` normaliza `$text` e cada nome para NFC (`\Normalizer::normalize($v, \Normalizer::FORM_C)`, com fallback sem mudança se a extensão `intl` faltar) antes de casar
- Produz: partes de nome separadas também por `-`, `'` e `’`; cada parte com 3+ letras fora de `PARTICLES` e de `CLINICAL_TERMS` é candidata, e o nome com o separador original também
- Produz: telefone com DDD só casa com DDD de dois dígitos 1–9 e assinante de 8 dígitos iniciado em 2–5 ou de 9 dígitos iniciado em 9; um casamento seguido (após espaços) de unidade de exame (`mg/dL`, `g/dL`, `/µL`, `/uL`, `/mm³`, `/mm3`, `%`, `U/L`, `mmol/L`, `fL`, `pg`) não é telefone
- Produz: celular sem DDD `9\d{4}[\s.-]?\d{4}` (9 dígitos iniciados em 9, fora de número maior) vira `[TELEFONE]`
- Consome: nada

**Teste RED**
- `src/tests/Unit/PiiMinimizerTest.php` — com nomes `['Maria das Dores Silva', 'Rex']`: "Maria das Dores Silva relatou dores abdominais" → `[NOME] relatou dores abdominais`; "Linfócitos 20 1000-4800 /µL; VR 10–20 mg/dL" fica igual; "(85) 98765-4321", "85 3261-4321" e "98765-4321" viram `[TELEFONE]`; nome `'José'` em NFD (`"Jose\u{0301}"`) casa com "José" em NFC e vice-versa; nomes `'Ana-Clara D\'Ávila'` mascaram "Ana", "D'Ávila" e "Ana-Clara" mas não "clara" isolado em "urina clara"; e-mail, CPF e CNPJ seguem mascarados — falha antes porque "dores" vira `[NOME]` e "20 1000-4800" vira `[TELEFONE]` (comando: `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'`)

**Critério de aceite**
- `PiiMinimizerTest` passa todos os casos do Teste RED.
- `AiPromptCatalogTest` (que cobre o minimizer em :171) continua com todos os casos `PASS`.

**Validação**
- `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'` (evidência: `PASS` de `testClinicalTermInsideNameIsKept` ou equivalente, `testReferenceRangeIsNotPhone` ou equivalente e `Failed: 0`)
- Review Focus: os casos "Maria das Dores"/"dores abdominais" e "Linfócitos 20 1000-4800 /µL"/"(85) 98765-4321" estão no teste (evidência: nomes dos métodos no relatório)
- `LINT app/Core/Ai/PiiMinimizer.php` (evidência: `No syntax errors detected`)

### T-04 — Termo `2026-10-v2` com a declaração do ditado e reaceite

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

Achado (revisão final T-13): a declaração do ditado está fora de `AiTerms::text`/`VERSION` (`AiSettingsForm.php:245`), então o aceite não a versiona. Decisão do usuário: a declaração entra em `AiTerms`, a versão sobe e quem aceitou a anterior reaceita. O reaceite já é imposto: `AiTenantSetting::hasAcceptedTerms(AiTerms::VERSION)` falso → `AiAssistService` devolve `ai_terms_pending` (:261) e `AiSettingsService::enable` exige `accept_terms`.

**Arquivos prováveis**
- `src/app/Core/Domain/AiTerms.php`
- `src/app/control/clinic/AiSettingsForm.php`
- `src/tests/Unit/AiDomainTest.php`
- `src/tests/Unit/AiSettingsServiceTest.php`
- `src/tests/Integration/AiSettingsScreensIntegrationTest.php`
- `src/tests/Integration/EncounterAiIntegrationTest.php`

**Interface**
- Produz: `AiTerms::VERSION = '2026-10-v2'`
- Produz: `AiTerms::text('pt')` termina com `' O ditado por voz usa o reconhecimento de voz do navegador (ex.: Google, Apple, Microsoft): o áudio é enviado a esse serviço, não ao Central Vet; quem preferir pode digitar o texto.'` e `AiTerms::text('en')` com `' Voice dictation uses the speech recognition of the browser (e.g. Google, Apple, Microsoft): the audio is sent to that service, not to Central Vet; anyone who prefers can type the text.'`
- Produz: `AiSettingsForm::termsCard` renderiza só `AiTerms::text(...)` no `.cv-ai-terms` (a linha `_t('Voice dictation uses the speech recognition of your browser ...')` sai; a chave fica em `translations.json`)
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiDomainTest.php`, `src/tests/Unit/AiSettingsServiceTest.php` — `AiTerms::VERSION === '2026-10-v2'`; `AiTerms::text('pt')` contém `o áudio é enviado a esse serviço` e `text('en')` contém `the audio is sent to that service`; um `AiTenantSetting` com `acceptTerms('2026-10-v1', ...)` dá `hasAcceptedTerms(AiTerms::VERSION) === false`; `AiSettingsService::overview()['terms_current']` é `false` para esse tenant e `enable(false)` lança `ai_terms_pending` — falha antes porque a versão é `2026-10-v1` e o texto não cita o áudio (comando: `SUITE | /usr/bin/grep -E 'AiDomainTest|AiSettingsServiceTest|AiSettingsScreensIntegrationTest|EncounterAiIntegrationTest|Failed:'`)

**Critério de aceite**
- `AiTerms::VERSION` é `2026-10-v2` e o texto pt/en traz a frase do ditado da Interface.
- Tenant com termo `2026-10-v1` aceito: `overview()['terms_current'] === false` e a geração lança `AiUnavailableException` com `reason() === 'ai_terms_pending'`.
- A tela AiSettingsForm mostra a frase do ditado uma única vez (`substr_count($html, 'Google, Apple, Microsoft') === 1`).
- Os testes que semeavam `'2026-10-v1'` como termo aceito (`AiSettingsServiceTest:139,145`, `AiSettingsScreensIntegrationTest:154`, `EncounterAiIntegrationTest:373`) usam `AiTerms::VERSION`; `AiRepositoryIntegrationTest` fica intacto.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiDomainTest|AiSettingsServiceTest|AiSettingsScreensIntegrationTest|EncounterAiIntegrationTest|Failed:'` (evidência: `PASS` dos casos novos, nenhuma `FAIL` dessas classes e `Failed: 0`)
- Review Focus: caso "termo v1 aceito → `ai_terms_pending` sem chamada ao provedor" em `AiSettingsServiceTest` ou `AiDomainTest` (evidência: nome do método no relatório)
- `/usr/bin/grep -rn "2026-10-v1" src/app` (evidência: nenhuma linha)
- `LINT app/Core/Domain/AiTerms.php` e `LINT app/control/clinic/AiSettingsForm.php` (evidência: `No syntax errors detected`)

### T-05 — AiAssistService: decisão validada, mês no fuso, forContext e testes T-11

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Spock

Achados: `decide` grava o `$decision` cru no metadata da autorização antes de validar (`AiAssistService.php:189`; revisão final T-11 e sugestão da ExamResultForm). `monthStart()` calcula o mês em UTC, mas `created_at` é gravado no fuso da sessão MySQL (`-03:00` em dev): bug confirmado — das 21:00 às 23:59 do último dia do mês (America/Fortaleza) a cota lê o mês seguinte. Pendências T-11: `forContext` sem teste; `latestDecided` sem caso "outra unidade → null"; autorização negada não cobre `summarizeExamResult`.

**Arquivos prováveis**
- `src/app/Core/Application/AiAssistService.php`
- `src/tests/Unit/AiAssistServiceTest.php`
- `src/tests/Integration/AiAssistServiceForContextIntegrationTest.php`

**Interface**
- Produz: `AiAssistService::decide()` lança `InvalidArgumentException('Unknown AI decision')` quando `$decision` não está em `AiInteraction::DECISIONS`, antes de `find()` e de `$this->authorization->decide(...)` (nenhuma auditoria de autorização com valor desconhecido)
- Produz: `AiAssistService::monthStart()` = primeiro dia do mês às 00:00 em `new DateTimeZone(date_default_timezone_get())`
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiAssistServiceTest.php` — `decide($id, 'hacked', null)` lança `InvalidArgumentException` com a mensagem `Unknown AI decision` (a mesma de `AiInteraction::decide`, `AiInteraction.php:218`) e `FakeAuthorizationPolicy` não recebe pedido nem a auditoria tem evento; com `date_default_timezone_set('America/Fortaleza')` (restaurado no tearDown), relógio `2026-10-31 22:00:00` no fuso e uma interação de `2026-10-31 21:30:00` que esgota a cota, a geração lança `ai_quota_exceeded`; `latestDecided` de interação de outra unidade → `null`; política negando `summarizeExamResult` → `AuthorizationDenied` sem chamada ao gateway — falha antes porque `decide('hacked')` chega à política e o mês lido é novembro (comando: `SUITE | /usr/bin/grep -E 'AiAssistService|Failed:'`)

**Critério de aceite**
- Decisão desconhecida: `InvalidArgumentException`, `FakeAuthorizationPolicy` com 0 pedidos e 0 eventos de auditoria.
- Geração às 22:00 de 31/10 (America/Fortaleza) com a cota de outubro esgotada lança `reason() === 'ai_quota_exceeded'` e o gateway tem 0 chamadas.
- `AiAssistServiceForContextIntegrationTest`: com `AI_PROVIDER=stub` e `APP_ENV=testing`, `forContext($context, $this->pdo, 'pt')` devolve `AiAssistService` e `latestDecided(AiPurpose::PATIENT_SUMMARY, <id inexistente>)` é `null`; com `APP_ENV=production` e `AI_PROVIDER=stub` (env restaurado no tearDown) lança `AiUnavailableException` `ai_not_configured`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiAssistService|Failed:'` (evidência: `PASS` dos casos novos em `AiAssistServiceTest` e `AiAssistServiceForContextIntegrationTest` e `Failed: 0`)
- Review Focus: caso "22:00 de 31/10 com a cota esgotada → `ai_quota_exceeded`" (evidência: nome do método no relatório)
- `LINT app/Core/Application/AiAssistService.php` (evidência: `No syntax errors detected`)

### T-06 — Contexto clínico com rascunhos/pendentes marcados e prompts `.v2`

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

Achado (revisão final T-09): `historyPrescriptions` inclui `draft` e `historyExamResults` inclui `pending_review = 1` sem sinalizar (`AiClinicalContextQuery.php:131,174`). Decisão do usuário: incluir os dois, marcados como provisórios/não validados no prompt. Pendência T-08: falta teste que trave "texto muda, versão muda".

**Arquivos prováveis**
- `src/app/Core/Persistence/AiClinicalContextQuery.php`
- `src/app/Core/Ai/AiPromptCatalog.php`
- `src/tests/Unit/AiPromptCatalogTest.php`
- `src/tests/Integration/AiClinicalContextQueryIntegrationTest.php`
- `src/tests/Support/FakeAiClinicalContextQuery.php`

**Interface**
- Produz: `patientHistory()['prescriptions'][]` = `array{created_at: string, status: string, items: list<string>}` com `status` ∈ (`draft`, `issued`) de `prescription.status`
- Produz: `patientHistory()['exam_results'][]` = `array{received_at: string, exam_name: string, result_text: ?string, pending_review: bool}` e `examResultContext()` com a chave `pending_review: bool`
- Produz: `AiPromptCatalog::VERSIONS` = `patient_summary.v2`, `anamnesis_organize.v2`, `exam_summary.v2`
- Produz: rótulos `LABELS[lang]['provisional_prescription']` = pt `(rascunho, não emitida — provisória)` / en `(draft, not issued — provisional)` e `LABELS[lang]['unvalidated_exam']` = pt `(pendente de revisão — não validado)` / en `(pending review — not validated)`, acrescentados à linha da prescrição `draft` e do exame `pending_review` (no histórico e no resumo de exame)
- Produz: regra nova no `BASE` pt `- Itens marcados como provisórios ou não validados podem mudar: cite-os como tal e não os trate como confirmados.` / en `- Items marked as provisional or not validated may change: mention them as such and do not treat them as confirmed.`
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiPromptCatalogTest.php`, `src/tests/Integration/AiClinicalContextQueryIntegrationTest.php` — o prompt `patient_summary` de um contexto com prescrição `draft` e exame `pending_review: true` contém `(rascunho, não emitida — provisória)` e `(pendente de revisão — não validado)` (pt) e a regra nova no system; `VERSIONS` são `.v2`; `testPromptTextIsPinnedToItsVersion` compara o SHA-256 do system prompt de cada finalidade/idioma com um mapa fixo indexado pela versão; o histórico do banco devolve `status = 'draft'` e `pending_review = true` para os registros semeados — falha antes porque as chaves e os rótulos não existem (comando: `SUITE | /usr/bin/grep -E 'AiPromptCatalogTest|AiClinicalContextQuery|Failed:'`)

**Critério de aceite**
- Prescrição `draft` e exame `pending_review` aparecem no histórico com os rótulos da Interface; prescrição `issued` e exame revisado aparecem sem rótulo.
- `VERSIONS` são `.v2` e o teste de trava falha se o texto mudar sem trocar o mapa de hashes.
- Integração: registros existentes do `centralvet_test` não somem do histórico — o caso conta as prescrições e os exames do paciente semeado antes e depois (mesmo total).

**Validação**
- `SUITE | /usr/bin/grep -E 'AiPromptCatalogTest|AiClinicalContextQuery|Failed:'` (evidência: `PASS` dos casos novos e `Failed: 0`)
- `LINT app/Core/Persistence/AiClinicalContextQuery.php` e `LINT app/Core/Ai/AiPromptCatalog.php` (evidência: `No syntax errors detected`)

### T-07 — Ditado: live region e mensagens de erro (JS/CSS)

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

Achado (revisão final T-10): `.cv-ai-status:empty{display:none}` numa live region (`cv-components.css:942`); o status anuncia o rótulo da ação ("Parar ditado"); `onerror` (`cv-ai-dictation.js:143`) só trata `not-allowed`/`service-not-allowed` — `no-speech`, `audio-capture` e `network` ficam mudos.

**Arquivos prováveis**
- `src/app/templates/adminbs5/js/cv-ai-dictation.js`
- `src/app/templates/adminbs5/cv-components.css`
- `src/tests/Unit/AiDictationAssetTest.php`

**Interface**
- Produz: atributos lidos do botão `data-label-listening`, `data-label-no-speech`, `data-label-audio-capture`, `data-label-network` e `data-label-error` (documentados no cabeçalho do JS ao lado de `data-label-start`/`stop`/`denied`/`notice`)
- Produz: ao iniciar, `status.textContent` = `data-label-listening` (cai para `data-label-stop` se vazio); `onerror`: `no-speech` → `data-label-no-speech`, `audio-capture` → `data-label-audio-capture`, `network` → `data-label-network`, `not-allowed`/`service-not-allowed` → `data-label-denied`, `aborted` → nada, qualquer outro → `data-label-error`; o botão volta a `data-label-start`
- Produz: `.cv-ai-status:empty` sem `display:none` (oculto visualmente com `position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap`), mantendo a live region na árvore de acessibilidade; rótulos seguem à esquerda e alvos com `cv-touch-target`
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiDictationAssetTest.php` — o JS contém `'no-speech'`, `'audio-capture'`, `'network'`, `'aborted'` e os cinco `data-label-*` novos; o CSS não tem `display: none` (nem `display:none`) no bloco `.cv-ai-status:empty` e tem `clip` nele — falha antes porque o JS não trata esses códigos e o CSS esconde a região (comando: `SUITE | /usr/bin/grep -E 'AiDictationAssetTest|Failed:'`)

**Critério de aceite**
- `AiDictationAssetTest` passa, inclusive os testes já existentes (alvos de toque, rótulos à esquerda, aviso do serviço de voz).
- `node --check` do JS sai com status 0 (sintaxe válida), se `node` existir no host; senão o relatório registra a ausência.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiDictationAssetTest|Failed:'` (evidência: `PASS` de todos os casos e `Failed: 0`)
- `command -v node && node --check src/app/templates/adminbs5/js/cv-ai-dictation.js; echo exit=$?` (evidência: `exit=0` ou ausência do node registrada)

### T-08 — ExamResultForm: decisão, SHA → edited, resumo por POST, locale, Throwable e testes

**Camada:** frontend
**Dependências:** T-01, T-05
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Sherlock

Achados (revisão final): `onAiDecide` não valida `decision` e "Aceitar" com texto editado grava `accepted` com a sugestão original (`ExamResultForm.php:330-354`); o resumo é disparado por link GET (`:507`); locale divergente (`:598`); `catch (Exception)` deixa `\Error` derrubar a tela (`:315`, `:373`, `:422`, `:435`). Testes faltantes (T-15): regressão dos caminhos legados (`exam_request_id` sem e com resultado), controle positivo de `testDisabledAiHidesThePanel`, interação de outra unidade (sugestão em `ExamResultAiIntegrationTest:173`).

**Arquivos prováveis**
- `src/app/control/clinic/ExamResultForm.php`
- `src/tests/Integration/ExamResultAiIntegrationTest.php`

**Interface**
- Produz: `ExamResultForm::onAiDecide` aceita só `decision` ∈ (`accepted`, `edited`, `discarded`) (string); outro valor → `TMessage('warning', _t('Invalid action'))` sem abrir transação nem chamar `decide`
- Produz: `suggestionForm` renderiza `<input type="hidden" name="ai_suggestion_sha256">` com `hash('sha256', <texto da sugestão normalizado>)`; em `onAiDecide`, `ai_final_text` é normalizado (CRLF/CR → LF, `trim`) e `accepted` com texto não vazio cujo SHA difere do postado vira `edited` com esse texto; `decide` recebe o texto só em `edited`
- Produz: o botão "Summarize exam" é `TButton` `ai_summarize` com `TAction([$this, 'onAiExamSummary'])` dentro de `TForm` `form_ExamResultAiSummary_<resultId>` com `THidden` `exam_result_id` (nenhum `href` com `method=onAiExamSummary`); `onAiExamSummary` só chama o assistente se `($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'`, senão `TMessage('warning', _t('Invalid action'))`
- Produz: `makeAiAssistant` usa `AiLocale::fromLanguage(ApplicationTranslator::getLanguage())`; os `catch (Exception $e)` de `loadSavedResult`, `onAiExamSummary` e `onAiDecide` passam a `catch (\Throwable $e)`
- Consome: T-01 `CentralVet\Ai\AiLocale::fromLanguage(?string $language): string`
- Consome: T-05 `AiAssistService::decide()` lança `InvalidArgumentException('Unknown AI decision')`
- Só chaves `_t` já traduzidas (`Invalid action`, `Summarize exam`, ...): nenhuma chave nova

**Teste RED**
- `src/tests/Integration/ExamResultAiIntegrationTest.php` — "Accept" com `ai_final_text` diferente da sugestão grava `status = 'edited'` e `final_text` = texto postado; `decision = 'hacked'` não muda a linha (`pending_review`) e não grava auditoria de autorização; `onAiExamSummary` com `REQUEST_METHOD=GET` não cria linha em `ai_interaction`; o painel não contém `method=onAiExamSummary` e contém `name="ai_summarize"`; interação de outra unidade (segunda `system_unit` de `SELECT id FROM system_unit ORDER BY id LIMIT 2`, ou `createUnit` copiado de `ReminderSourceQueryUnitTenantIntegrationTest:174`) mostra "Sugestão de IA não encontrada." (pt de `AI suggestion not found` em `translations.json`, afirmado em `UserMessageTest`) e não muda a linha; `exam_request_id` sem resultado abre o formulário de lançamento e com resultado abre a visão só leitura; IA ligada mostra o painel (controle positivo de `testDisabledAiHidesThePanel`) — falha antes porque "Accept" grava `accepted` e o GET gera a chamada (comando: `SUITE | /usr/bin/grep -E 'ExamResultAiIntegrationTest|Failed:'`)

**Critério de aceite**
- "Accept" com texto editado → linha `ai_interaction` com `status = 'edited'` e `final_text` igual ao texto postado normalizado; `exam_result` igual antes e depois (HEX das colunas de texto).
- `decision` inválida → linha continua `pending_review`, 0 eventos novos em `audit_log` para a interação.
- Chamada não POST → 0 linhas novas em `ai_interaction`.
- `/usr/bin/grep -c "method=onAiExamSummary" src/app/control/clinic/ExamResultForm.php` → `0`.

**Validação**
- `SUITE | /usr/bin/grep -E 'ExamResultAiIntegrationTest|UserMessageTest|Failed:'` (evidência: `PASS` dos casos novos e antigos, `testEveryAiScreenKeyHasATranslation` em `PASS` e `Failed: 0`)
- `LINT app/control/clinic/ExamResultForm.php` (evidência: `No syntax errors detected`)

### T-09 — EncounterView: locale, rótulos do ditado, guarda do autosave e testes T-14

**Camada:** frontend
**Dependências:** T-01, T-04, T-07
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Thanos

Achados: locale divergente (`EncounterView.php:1446`); o botão de ditado (:1525) não passa rótulos de erro nem de "ouvindo"; o autosave de 20 s (`:1145-1164`) montado antes de "Aplicar à anamnese" pode chegar depois da decisão e regravar a anamnese antiga, e cada recarga acrescenta um `setInterval`. Testes faltantes (T-14): caminho `edited`, descarte com rascunho postado, autosave em voo, interação de outra unidade (`EncounterAiIntegrationTest:297`).

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`
- `src/tests/Integration/EncounterAiIntegrationTest.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `aiLocale()` delega a `AiLocale::fromLanguage(ApplicationTranslator::getLanguage())`
- Produz: o botão de ditado leva `data-label-listening` = `_t('Listening... speak now')`, `data-label-no-speech` = `_t('No speech was detected. Try again.')`, `data-label-audio-capture` = `_t('No microphone was found. Check the device.')`, `data-label-network` = `_t('The voice service is unavailable. Type the text instead.')` e `data-label-error` = `_t('Dictation failed. Type the text instead.')`; `translations.json` ganha as 5 chaves com pt `Ouvindo... pode falar`, `Nenhuma fala foi detectada. Tente novamente.`, `Nenhum microfone foi encontrado. Verifique o dispositivo.`, `O serviço de voz está indisponível. Digite o texto.` e `O ditado falhou. Digite o texto.`
- Produz: a ação serializada do autosave leva `draft_loaded_at` = instante da renderização em `Y-m-d H:i:s.u` no fuso `date_default_timezone_get()` (mesmo formato e fuso em que `decided_at` é gravado); `onAutosave` descarta `anamnesis_text` do rascunho quando `AiInteractionRepository::latestDecidedFor(AiPurpose::ANAMNESIS_ORGANIZE, $id)` tem `decidedAt()` posterior a `draft_loaded_at` e status `accepted` ou `edited`; sem `draft_loaded_at` o comportamento é o atual
- Produz: o script do autosave guarda o timer em `window.cvAutosaveTimers['form_EncounterView_<id>']` e faz `clearInterval` do anterior antes de criar outro
- Consome: T-01 `CentralVet\Ai\AiLocale::fromLanguage(?string $language): string`
- Consome: T-07 `data-label-listening`, `data-label-no-speech`, `data-label-audio-capture`, `data-label-network`, `data-label-error`
- Consome: T-04 `AiTerms::VERSION = '2026-10-v2'`

**Teste RED**
- `src/tests/Integration/EncounterAiIntegrationTest.php` — "Accept" do resumo com `ai_final_text` diferente e `ai_suggestion_sha256` da sugestão grava `edited` com o texto postado; "Discard" com `anamnesis_text` postado grava o rascunho e `discarded`; após "Apply to anamnesis", um `onAutosave` com `draft_loaded_at` anterior à decisão e a anamnese antiga deixa `encounter.anamnesis_text` com o texto aplicado; interação de outra unidade mostra "Sugestão de IA não encontrada." (pt de `AI suggestion not found`) e a linha segue `pending_review`; o HTML da tela tem `data-label-no-speech` e `cvAutosaveTimers` — falha antes porque o autosave atrasado regrava a anamnese antiga e os atributos não existem (comando: `SUITE | /usr/bin/grep -E 'EncounterAiIntegrationTest|UserMessageTest|Failed:'`)

**Critério de aceite**
- Autosave atrasado (montado antes da decisão) → `encounter.anamnesis_text` termina com o texto aplicado; autosave sem `draft_loaded_at` ou montado depois da decisão grava a anamnese postada.
- "Accept" com texto editado → `ai_interaction.status = 'edited'`, `final_text` = texto postado normalizado.
- Outra unidade → mensagem "Sugestão de IA não encontrada." e linha `pending_review`.
- `UserMessageTest::testEveryAiScreenKeyHasATranslation` em `PASS` (as 5 chaves novas traduzidas).
- Registros existentes não somem: o caso do autosave confere que os demais `DRAFT_FIELDS` do encontro têm o mesmo valor antes e depois.

**Validação**
- `SUITE | /usr/bin/grep -E 'EncounterAiIntegrationTest|UserMessageTest|Failed:'` (evidência: `PASS` dos casos novos e antigos e `Failed: 0`)
- Review Focus: caso "autosave em voo após Aplicar à anamnese" (evidência: nome do método no relatório)
- `python3 -c "import json;json.load(open('/var/www/html/centralvet/src/app/config/translations.json'))" && echo json-ok` (evidência: `json-ok`)
- `LINT app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected`)

### T-10 — AiSettingsService: find único, mês no fuso e forContext

**Camada:** backend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Saitama

Achados (revisão final T-12): `forContext` sem teste; `changeMonthlyQuota` chama `settings->find()` duas vezes (`AiSettingsService.php:177`); mutação sem efeito sem teste. `monthStart()` em UTC (:204): mesmo bug de fuso da T-05 no resumo mensal da tela.

**Arquivos prováveis**
- `src/app/Core/Application/AiSettingsService.php`
- `src/tests/Unit/AiSettingsServiceTest.php`
- `src/tests/Integration/AiSettingsServiceForContextIntegrationTest.php`

**Interface**
- Produz: `changeMonthlyQuota()` faz uma única leitura do repositório (`find()` uma vez por chamada) e não grava nem audita quando a cota não muda e o registro existe
- Produz: `AiSettingsService::monthStart()` = primeiro dia do mês às 00:00 em `new DateTimeZone(date_default_timezone_get())`
- Consome: T-04 `AiTerms::VERSION = '2026-10-v2'`

**Teste RED**
- `src/tests/Unit/AiSettingsServiceTest.php` — `changeMonthlyQuota` com o mesmo valor faz 1 `find()` (contador no `FakeAiTenantSettingRepository` ou fake local do teste), 0 `save` e 0 eventos; com `date_default_timezone_set('America/Fortaleza')` e relógio `2026-10-31 22:00:00` no fuso, `overview()` pede `monthlyTokenUsage`/`monthlySummary` com início `2026-10-01 00:00:00` — falha antes porque há 2 `find()` e o início é `2026-11-01` (comando: `SUITE | /usr/bin/grep -E 'AiSettingsService|Failed:'`)

**Critério de aceite**
- Cota igual: 1 leitura, 0 gravações, 0 auditorias; cota diferente: 1 gravação e 1 evento `ai.settings.quota_changed`.
- Mês do overview às 22:00 de 31/10 (America/Fortaleza) começa em `2026-10-01 00:00:00`.
- `AiSettingsServiceForContextIntegrationTest`: `forContext($context, $this->pdo)` devolve `AiSettingsService` e `overview()` traz `terms_current` booleano para o tenant de teste.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiSettingsService|Failed:'` (evidência: `PASS` dos casos novos e `Failed: 0`)
- `LINT app/Core/Application/AiSettingsService.php` (evidência: `No syntax errors detected`)

### T-11 — FakeAiInteractionRepository com a semântica do PDO

**Camada:** qa
**Dependências:** T-05
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Hegel

Achado (revisão final T-05): no fake, `latestDecidedFor` ordena por `decided_at` (`FakeAiInteractionRepository.php:121`) e o PDO por `created_at DESC, id DESC` (`AiInteractionRepository.php:156`); `monthRows` (:164) não limita o fim do mês, ao contrário do `monthRange` do PDO (`created_at >= :month_start AND < :next_month`).

**Arquivos prováveis**
- `src/tests/Support/FakeAiInteractionRepository.php`
- `src/tests/Unit/FakeAiInteractionRepositoryTest.php`

**Interface**
- Produz: `FakeAiInteractionRepository::latestDecidedFor()` ordena por `[created_at, id]` decrescente; `monthRows($monthStart)` filtra `created_at >= início do mês` e `< início do mês seguinte` (mesmo cálculo de `AiInteractionRepository::monthRange`)
- Consome: nada (a dependência só evita mudar o fake sob os testes novos da onda 1)

**Teste RED**
- `src/tests/Unit/FakeAiInteractionRepositoryTest.php` — duas interações decididas, a criada antes decidida depois: `latestDecidedFor` devolve a criada por último; interação de novembro não soma em `monthlyTokenUsage(2026-10-01)` — falha antes porque o fake devolve a decidida por último e soma novembro (comando: `SUITE | /usr/bin/grep -E 'FakeAiInteractionRepositoryTest|AiAssistServiceTest|Failed:'`)

**Critério de aceite**
- `FakeAiInteractionRepositoryTest` passa; `AiAssistServiceTest` (que usa o fake) continua sem `FAIL`.

**Validação**
- `SUITE | /usr/bin/grep -E 'FakeAiInteractionRepositoryTest|AiAssistServiceTest|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)

### T-12 — Testes de domínio T-02 e docblock de `insert`

**Camada:** qa
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

Achado (revisão final T-02): `AiDomainTest` não afirma `AiSubjectNotFoundException` nem a igualdade de `REASONS` (só a contagem 13, :217); falta no docblock de `insert` que `pending_review` sem `suggestion_text` viola `ai_interaction_failed_ck` e que só se insere após `recordSuggestion`/`recordFailure`.

**Arquivos prováveis**
- `src/tests/Unit/AiDomainTest.php`
- `src/app/Core/Domain/Contract/AiInteractionRepositoryInterface.php`

**Interface**
- Produz: docblock de `AiInteractionRepositoryInterface::insert` citando `ai_interaction_failed_ck` e a exigência de `recordSuggestion`/`recordFailure` antes do insert (sem mudança de assinatura)
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- sem teste: testes de caracterização de comportamento já implementado (`AiProviderException::REASONS === AiUnavailableException::REASONS`, `AiSubjectNotFoundException` com código traduzível) e docblock; passam já na primeira execução

**Critério de aceite**
- `AiDomainTest` tem casos que afirmam `Assert::same(AiUnavailableException::REASONS, AiProviderException::REASONS)` e o código/classe de `AiSubjectNotFoundException`, todos `PASS`.
- `/usr/bin/grep -c "ai_interaction_failed_ck" src/app/Core/Domain/Contract/AiInteractionRepositoryInterface.php` → `1` ou mais.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiDomainTest|Failed:'` (evidência: `PASS` dos casos novos e `Failed: 0`)
- `LINT app/Core/Domain/Contract/AiInteractionRepositoryInterface.php` (evidência: `No syntax errors detected`)

### T-13 — Custo da falha pós-fallback por modelo

**Camada:** backend
**Dependências:** T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

Achado (revisão final T-07): na falha depois do fallback (recusa ou `max_tokens` com `usage.iterations` de dois modelos), `AiInteraction::recordFailure` (:194-207) recalcula o custo com um só modelo; no sucesso o gateway já precifica cada iteração pelo próprio modelo (`AnthropicMessagesGatewayTest:148`). O bloco `fallback` em `content` já é coberto (`AnthropicMessagesGatewayTest:116`).

**Arquivos prováveis**
- `src/app/Core/Domain/Exception/AiProviderException.php`
- `src/app/Core/Ai/AnthropicMessagesGateway.php`
- `src/app/Core/Domain/AiInteraction.php`
- `src/app/Core/Application/AiAssistService.php`
- `src/tests/Unit/AnthropicMessagesGatewayTest.php`
- `src/tests/Unit/AiAssistServiceTest.php`

**Interface**
- Produz: `AiProviderException::__construct(..., ?int $costMicroUsd = null)` (último parâmetro) e `AiProviderException::costMicroUsd(): ?int`; o gateway preenche com a soma por iteração/modelo sempre que há `usage`
- Produz: `AiInteraction::recordFailure(string $errorCode, int $latencyMs, ?AiUsage $usage, ?string $model, string $provider, ?int $costMicroUsd = null)` usa `$costMicroUsd` quando não nulo; senão o cálculo atual
- Produz: `AiAssistService` repassa `$e->costMicroUsd()` ao `recordFailure` no `catch (AiProviderException $e)`
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/AnthropicMessagesGatewayTest.php`, `src/tests/Unit/AiAssistServiceTest.php` — recusa com `iterations` de `claude-sonnet-5-5` e `claude-sonnet-5` (preços diferentes, como em :148): `exception->costMicroUsd()` = soma por modelo; a interação `failed` gravada pelo serviço tem `costMicroUsd()` igual a esse valor — falha antes porque o método não existe e o custo vem de um só modelo (comando: `SUITE | /usr/bin/grep -E 'AnthropicMessagesGatewayTest|AiAssistServiceTest|AiDomainTest|Failed:'`)

**Critério de aceite**
- Recusa pós-fallback: `cost_micro_usd` da interação `failed` = soma por modelo das iterações; falha sem `usage` continua com custo `null`.
- `AiDomainTest` e os testes antigos do gateway seguem `PASS`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AnthropicMessagesGatewayTest|AiAssistServiceTest|AiDomainTest|Failed:'` (evidência: `PASS` dos casos novos e `Failed: 0`)
- `LINT` de `app/Core/Domain/Exception/AiProviderException.php`, `app/Core/Ai/AnthropicMessagesGateway.php`, `app/Core/Domain/AiInteraction.php` e `app/Core/Application/AiAssistService.php` (evidência: `No syntax errors detected` nos quatro)

### T-14 — UserMessageTest exige pt não vazio nas chaves das telas de IA

**Camada:** qa
**Dependências:** T-09
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Maquiavel

Achado (revisão final T-17): `assertScreenKeysTranslated` (`UserMessageTest.php:522-540`) só confere que a chave en existe, não que o pt é não vazio.

**Arquivos prováveis**
- `src/tests/Unit/UserMessageTest.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `assertScreenKeysTranslated` falha com `Empty pt translation for screen key: <chave>` quando `trim(pt) === ''`
- Consome: T-09 `Listening... speak now`

**Teste RED**
- sem teste: a task endurece uma asserção de teste existente; sem comportamento de produto ausente, ela passa no primeiro run se todo pt estiver preenchido (se algum pt vazio aparecer, a task o preenche em `translations.json` e registra no relatório)

**Critério de aceite**
- `UserMessageTest::testEveryAiScreenKeyHasATranslation` em `PASS` com a asserção nova de pt não vazio.

**Validação**
- `SUITE | /usr/bin/grep -E 'UserMessageTest|Failed:'` (evidência: `PASS` de todos os casos e `Failed: 0`)
- `/usr/bin/grep -n "Empty pt translation" src/tests/Unit/UserMessageTest.php` (evidência: 1 linha)

### T-15 — Runbooks, ADR 0006 e `T-19-cleanup.sql`

**Camada:** docs
**Dependências:** T-02, T-03, T-04, T-05, T-06, T-10
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Gandalf

Achados (revisão final T-18 2ª e T-19): `shared-hosting-mysql57.md:335-336` manda para uma DML "descrita em ia-assistente.md" que não existe ali (a DML real é `src/app/database/seeds/initial-application-programs.sql:1011-1034`); `ia-assistente.md:28` diz que "o termo de uso o declara" (falso em v1); `:63` omite que `APP_ENV` vale `development` por padrão (`AiConfig.php:63`); `T-19-cleanup.sql:39` define `@gate_start` sem uso, `:151` manda "preencher os literais" inexistentes e `:135` não cobre `surgery.followup_appointment_id` (migration 0011 :111, :141). Sem executar SQL.

**Arquivos prováveis**
- `docs/runbooks/shared-hosting-mysql57.md`
- `docs/runbooks/ia-assistente.md`
- `docs/architecture/adr/0006-ai-gateway.md`
- `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-19-cleanup.sql`

**Interface**
- Produz: `shared-hosting-mysql57.md § Migration 0014` aponta `src/app/database/seeds/initial-application-programs.sql` (linhas dos programas `AiSettingsForm`/`AiInteractionList`, grupo 1)
- Produz: `ia-assistente.md` e ADR 0006 com termo `2026-10-v2` (`AiTerms::VERSION`), frase do ditado dentro do termo, reaceite obrigatório de quem aceitou `2026-10-v1` (aviso `ai_terms_pending` até o administrador aceitar em AiSettingsForm), `APP_ENV` padrão `development`, `ANTHROPIC_BASE_URL` só `https`, mês da cota no fuso `APP_TIMEZONE` (a sessão MySQL precisa estar no mesmo fuso), contexto com prescrições em rascunho e exames pendentes marcados como provisórios, PiiMinimizer com allowlist clínica
- Produz: `T-19-cleanup.sql` sem `@gate_start` órfão (usado no filtro ou removido), comentário da seção 4 coerente com o que o script faz, verificação de `surgery.followup_appointment_id` junto da de `patient_id`; `COMMIT` continua comentado
- Consome: T-04 `AiTerms::VERSION = '2026-10-v2'`
- Consome: T-02 `ANTHROPIC_BASE_URL must be an https URL`

**Teste RED**
- sem teste: documentação e script SQL não executado

**Critério de aceite**
- `/usr/bin/grep -c "2026-10-v1" docs/runbooks/ia-assistente.md docs/architecture/adr/0006-ai-gateway.md` só conta menções históricas marcadas como versão anterior; `/usr/bin/grep -n "2026-10-v2" docs/runbooks/ia-assistente.md` → ao menos 1 linha.
- `/usr/bin/grep -n "descrita em ia-assistente.md" docs/runbooks/shared-hosting-mysql57.md` → nenhuma linha; `/usr/bin/grep -n "initial-application-programs.sql" docs/runbooks/shared-hosting-mysql57.md` → ao menos 1 linha.
- `diff baseline/T-19-cleanup.orig.sql <arquivo>` mostra só as três correções; `/usr/bin/grep -n "followup_appointment_id" <arquivo>` → ao menos 1 linha; `/usr/bin/grep -nE "^\s*COMMIT" <arquivo>` → nenhuma linha.

**Validação**
- `/usr/bin/grep -n "2026-10-v2\|development\|https" /var/www/html/centralvet/docs/runbooks/ia-assistente.md` (evidência: linhas do termo v2, do `APP_ENV` padrão e do https)
- `diff /var/www/html/centralvet/.claude/tasks/mar-20261015-1616-fase-8a-pendencias/baseline/T-19-cleanup.orig.sql /var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-19-cleanup.sql` (evidência: hunks só em `@gate_start`, comentário da seção 4 e `surgery`)
- `git -C /var/www/html/centralvet status --short docs` (evidência: só os três arquivos de docs, commitados com `Task: T-15`)


### T-16 — Correção: telefone com assinante 6–9, faixas de referência e celular sem DDD só com contexto

**Camada:** backend
**Dependências:** T-03
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Kratos

Origem: onda de correção (revisão final + code-review). Arquivo da T-03.

Achado (revisão final, bloqueante `plano-mandou`, ruling do orquestrador: CORRIGIR): regressão de PII. Em `641c4f6`, telefone com DDD e assinante de 8 dígitos iniciado em 6–9 era mascarado; hoje passa sem máscara (`PiiMinimizer.php:79-80`, regra `[2-5]\d{3}`), divergindo de `classifyDigits`, que mascara `8587654321`. Reprodução exigida: `php -r 'require "app/Core/Ai/PiiMinimizer.php"; echo CentralVet\Ai\PiiMinimizer::minimize("Contato (85) 8765-4321", []);'` → `Contato (85) 8765-4321` (o mesmo com `85 8765-4321`).
Achado (code-review, item 6): a isenção de faixa exige unidade listada em `UNIT_AHEAD` (`PiiMinimizer.php:38`, usada em :79-80): "Leucócitos 12 3500-4500", "20 1000-4800 mm³", `x10³/µL` e `μL` (mu grego, U+03BC) viram `[TELEFONE]`.
Achado (code-review, item 7): o celular sem DDD `9\d{4}[\s.-]?\d{4}` (`PiiMinimizer.php:89-91`) casa contagens clínicas ("Plaquetas 95000 1200", "95000-1200"); a decisão do usuário (mascarar celular sem DDD) vale em contexto de contato.
Veredito exigido: os quatro cenários reproduzidos no teste antes da correção, falhando.

**Arquivos prováveis**
- `src/app/Core/Ai/PiiMinimizer.php`
- `src/tests/Unit/PiiMinimizerTest.php`

**Interface**
- Produz: telefone com DDD entre parênteses (`\(\s*[1-9][1-9]\s*\)`) ou com `+55`: assinante `9\d{4}` ou `[2-9]\d{3}`, separador opcional `[\s.-]`, `\d{4}` → sempre `[TELEFONE]` (sem isenção por unidade)
- Produz: telefone com DDD solto (`[1-9][1-9][\s.-]+`) e assinante `9\d{4}` ou `[2-9]\d{3}` + `\d{4}` → `[TELEFONE]`, exceto quando (a) seguido de unidade de `PiiMinimizer::LAB_UNITS`, (b) as duas metades de 4 dígitos do par terminam em `00` (faixa redonda, ex.: `3500-4500`, `1000-4800`) ou (c) a mesma linha traz antes `VR`, `ref`, `referência` ou `valor de referência`; com palavra de contato antes, é sempre `[TELEFONE]`
- Produz: `PiiMinimizer::LAB_UNITS` (`public const`, lista) com ao menos `mg/dL`, `g/dL`, `g/L`, `/µL` (U+00B5), `/μL` (U+03BC), `/uL`, `µL`, `μL`, `/mm³`, `/mm3`, `mm³`, `mm3`, `x10³/µL`, `x10³/μL`, `x10^3/µL`, `x10^3/uL`, `mil/mm³`, `%`, `U/L`, `UI/L`, `mmol/L`, `mEq/L`, `µmol/L`, `ng/mL`, `fL`, `pg`
- Produz: `PiiMinimizer::CONTACT_WORDS` (`public const`) = `['tel', 'telefone', 'fone', 'cel', 'celular', 'whatsapp', 'whats', 'zap', 'contato', 'ligar']`; celular sem DDD (`9\d{4}[\s.-]?\d{4}`, fora de número maior) só vira `[TELEFONE]` quando uma dessas palavras (sem diferenciar caixa e acento) aparece antes, separada por até 3 caracteres de `[\s:.\-]`
- Produz: `classifyDigits` sem mudança (10 dígitos com DDD válido segue `[TELEFONE]`)
- Consome: nada

**Teste RED**
- `src/tests/Unit/PiiMinimizerTest.php` — `Contato (85) 8765-4321` → `Contato [TELEFONE]`; `85 8765-4321` → `[TELEFONE]`; `Leucócitos 12 3500-4500`, `Leucócitos 12 3500-4500 x10³/µL`, `20 1000-4800 mm³`, `Leucócitos 8 6000-17000 /μL` (mu grego), `Plaquetas 95000 1200` e `Plaquetas 95000-1200` ficam iguais; `Cel: 98765-4321` → `Cel: [TELEFONE]`; `ligar 987654321 hoje` → `ligar [TELEFONE] hoje` (caso já existente, :47); o caso `98765-4321` sem contexto (:46) passa a esperar o texto intacto (mudança deliberada pelo item 7) — falha antes porque `(85) 8765-4321` passa sem máscara e `12 3500-4500` vira `[TELEFONE]` (comando: `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'`)

**Critério de aceite**
- Os casos do Teste RED passam; os demais casos de `PiiMinimizerTest` (nomes, NFD, hífen/apóstrofo, e-mail, CPF, CNPJ, `(85) 98765-4321`, `85 3261-4321`) seguem `PASS`.
- A reprodução da revisão final imprime `Contato [TELEFONE]`.

**Validação**
- `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'` (evidência: `PASS` dos casos novos e antigos e `Failed: 0`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'require "app/Core/Ai/PiiMinimizer.php"; echo CentralVet\Ai\PiiMinimizer::minimize("Contato (85) 8765-4321 / 85 8765-4321 / Leucócitos 12 3500-4500", []), PHP_EOL;'` (evidência: `Contato [TELEFONE] / [TELEFONE] / Leucócitos 12 3500-4500`)
- Review Focus: caso "(85) 8765-4321" e "Leucócitos 12 3500-4500 x10³/µL" no teste (evidência: nomes dos métodos no relatório)
- `LINT app/Core/Ai/PiiMinimizer.php` (evidência: `No syntax errors detected`)

### T-17 — Correção: conflito do autosave por SHA-256 da anamnese carregada

**Camada:** frontend
**Dependências:** T-09, T-14
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

Origem: onda de correção (code-review, item 1). Arquivos da T-09 (e `translations.json` da T-09/T-14).

Achado (code-review): `EncounterView::anamnesisDecidedSince` (`EncounterView.php:2150`, chamado em `:2127`) compara o instante de renderização da página com a última decisão `anamnesis_organize` de qualquer aba ou usuário: depois de um "Aplicar à anamnese" em outra aba, todo tick de autosave desta aba descarta `anamnesis_text` em silêncio, e o indicador continua mostrando "Saved at". Cada tick de 20 s ainda faz uma consulta extra a `latestDecidedFor` (`:2162`). Reprodução exigida: renderizar a tela (aba A), aplicar uma organização pela `onAiDecide` (aba B), enviar um tick da aba A com texto novo → hoje `anamnesis_text` fica com o texto aplicado e a resposta não sinaliza nada.
Veredito exigido: o conflito é detectado pela versão da anamnese que esta aba carregou ou salvou por último (SHA-256) e é sinalizado na tela.

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`
- `src/tests/Integration/EncounterAiIntegrationTest.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `EncounterView::anamnesisSha(?string $text): string` = `hash('sha256', self::normalizeText((string) $text))` (privado e estático)
- Produz: o script do autosave guarda `var anamnesisBase = '<anamnesisSha do anamnesis_text armazenado na renderização>'` e envia `&anamnesis_base_sha256=<anamnesisBase>` em cada tick; o parâmetro `draft_loaded_at` e o método `anamnesisDecidedSince` saem (nenhum `latestDecidedFor` no `onAutosave`)
- Produz: `onAutosave`: com `anamnesis_base_sha256` postado e diferente de `anamnesisSha(<anamnesis_text armazenado>)`, tira `anamnesis_text` do rascunho, grava os demais `DRAFT_FIELDS` e imprime `<span class="cv-autosave-conflict"></span>`; senão grava e imprime `<span class="cv-autosave-base" data-sha256="<anamnesisSha do anamnesis_text gravado>"></span>`; sem o parâmetro, o comportamento é o anterior à guarda (grava)
- Produz: o callback do tick, se a resposta contém `cv-autosave-conflict`, escreve em `#encounter_autosave_indicator` o texto de `_t('The anamnesis was changed in another window. Reload the page to keep editing it.')` (sem "Saved at"); senão atualiza `anamnesisBase` com o `data-sha256` da resposta e escreve "Saved at"
- Produz: `translations.json` com a chave acima e pt `A anamnese foi alterada em outra janela. Recarregue a página para continuar editando.`
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Integration/EncounterAiIntegrationTest.php` — `testAutosaveFromAStaleTabIsFlaggedAsConflict`: com `anamnesis_base_sha256` = SHA de "Texto antigo" (o que a aba carregou), depois de "Apply to anamnesis" por `onAiDecide`, um tick com `anamnesis_text` = "Texto novo da aba A" e outro `DRAFT_FIELD` alterado deixa `encounter.anamnesis_text` com o texto aplicado, grava o outro campo e devolve `cv-autosave-conflict`; um tick com o SHA da anamnese atual grava o texto e devolve `data-sha256` igual a `hash('sha256', 'Texto novo')`; o HTML da tela traz `anamnesis_base_sha256` e não traz `draft_loaded_at`; os testes de `draft_loaded_at` (:402, :438 e a regex de :506) passam a usar `anamnesis_base_sha256` — falha antes porque a resposta não tem `cv-autosave-conflict` e a tela ainda envia `draft_loaded_at` (comando: `SUITE | /usr/bin/grep -E 'EncounterAiIntegrationTest|UserMessageTest|Failed:'`)

**Critério de aceite**
- Tick de aba desatualizada: `encounter.anamnesis_text` igual ao texto aplicado, o outro `DRAFT_FIELD` gravado, resposta com `cv-autosave-conflict`.
- Tick com o SHA atual: `anamnesis_text` gravado e resposta com `data-sha256` do texto gravado.
- `/usr/bin/grep -c "latestDecidedFor\|draft_loaded_at\|anamnesisDecidedSince" src/app/control/clinic/EncounterView.php` → `0`.
- `UserMessageTest::testEveryAiScreenKeyHasATranslation` em `PASS` (chave nova com pt não vazio).

**Validação**
- `SUITE | /usr/bin/grep -E 'EncounterAiIntegrationTest|UserMessageTest|Failed:'` (evidência: `PASS` de `testAutosaveFromAStaleTabIsFlaggedAsConflict`, dos casos antigos e `Failed: 0`)
- Review Focus: caso "aba aberta antes de Aplicar à anamnese em outra aba" (evidência: nome do método no relatório)
- `python3 -c "import json;json.load(open('/var/www/html/centralvet/src/app/config/translations.json'))" && echo json-ok` (evidência: `json-ok`)
- `LINT app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected`)

### T-18 — Correção: https só com provider `anthropic`

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Batman

Origem: onda de correção (code-review, item 2). Arquivos da T-02.

Achado (code-review): `AiConfig::httpsUrl` (`AiConfig.php:74`) valida `ANTHROPIC_BASE_URL` também com `AI_PROVIDER=stub`; um stub com `http://localhost` quebra como `ai_not_configured`, embora o stub não use a URL. Reprodução exigida: `AiConfig::fromArray(['AI_PROVIDER' => 'stub', 'APP_ENV' => 'testing', 'ANTHROPIC_BASE_URL' => 'http://localhost:8080'])` lança `InvalidArgumentException`.
Veredito exigido: https exigido só quando o provider usa a URL (`anthropic`).

**Arquivos prováveis**
- `src/app/Core/Ai/AiConfig.php`
- `src/tests/Unit/AiConfigTest.php`

**Interface**
- Produz: `AiConfig::fromArray()` só lança `InvalidArgumentException('ANTHROPIC_BASE_URL must be an https URL')` com `AI_PROVIDER` = `anthropic`; com `stub`, guarda a URL recebida (só `rtrim('/')`) sem validar o esquema
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/AiConfigTest.php` — `stub` + `http://localhost:8080` → instância criada com `baseUrl() === 'http://localhost:8080'`; `anthropic` + `http://api.anthropic.com` segue lançando a exceção sem o valor na mensagem — falha antes porque o stub também lança (comando: `SUITE | /usr/bin/grep -E 'AiConfigTest|Failed:'`)

**Critério de aceite**
- `stub` com URL http: instância criada; `anthropic` com URL http: `InvalidArgumentException` `ANTHROPIC_BASE_URL must be an https URL`.
- Os testes de `#[\SensitiveParameter]` e de mensagens sem valor seguem `PASS`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiConfigTest|Failed:'` (evidência: `PASS` do caso novo e `Failed: 0`)
- `LINT app/Core/Ai/AiConfig.php` (evidência: `No syntax errors detected`)

### T-19 — Correção: `AiLocale` em AiSettingsForm e AiPromptCatalog

**Camada:** backend
**Dependências:** T-01, T-04, T-06
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Tesla

Origem: onda de correção (code-review, item 3). Arquivos da T-01, T-04 e T-06.

Achado (code-review): a ExamResultForm (:672) e a EncounterView (:1457) usam `AiLocale::fromLanguage`, mas `AiSettingsForm::locale()` (:339) usa `pt` se começa com `pt`, senão `en` (um idioma `es` mostra o termo em inglês), e `AiPromptCatalog::build` (:159) repete a regra em linha. Reprodução exigida: `/usr/bin/grep -n "str_starts_with" src/app/control/clinic/AiSettingsForm.php src/app/Core/Ai/AiPromptCatalog.php` → duas regras próprias.
Veredito exigido: uma única regra, `AiLocale::fromLanguage`, nos três pontos.

**Arquivos prováveis**
- `src/app/control/clinic/AiSettingsForm.php`
- `src/app/Core/Ai/AiPromptCatalog.php`
- `src/tests/Unit/AiLocaleTest.php`

**Interface**
- Produz: `AiSettingsForm::locale()` devolve `\CentralVet\Ai\AiLocale::fromLanguage(ApplicationTranslator::getLanguage())`; `AiPromptCatalog::build` usa `$lang = AiLocale::fromLanguage($locale)`
- Consome: T-01 `CentralVet\Ai\AiLocale::fromLanguage(?string $language): string`

**Teste RED**
- `src/tests/Unit/AiLocaleTest.php` — `testEveryAiLocaleRuleDelegatesToAiLocale`: em `AiSettingsForm.php`, `AiPromptCatalog.php`, `ExamResultForm.php` e `EncounterView.php`, nenhuma expressão `str_starts_with(...)` com `'pt'` ou `'en'` e cada arquivo contém `AiLocale::fromLanguage(` — falha antes porque `AiSettingsForm.php` e `AiPromptCatalog.php` têm regra própria (comando: `SUITE | /usr/bin/grep -E 'AiLocaleTest|AiPromptCatalogTest|AiSettingsScreensIntegrationTest|Failed:'`)

**Critério de aceite**
- `AiLocaleTest` passa, inclusive o caso novo; `AiPromptCatalogTest` (inclusive a trava texto × versão) e `AiSettingsScreensIntegrationTest` seguem `PASS`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiLocaleTest|AiPromptCatalogTest|AiSettingsScreensIntegrationTest|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- `LINT app/control/clinic/AiSettingsForm.php` e `LINT app/Core/Ai/AiPromptCatalog.php` (evidência: `No syntax errors detected`)

### T-20 — Correção: "Aceitar" sem SHA fica `accepted`; texto vazio é recusado

**Camada:** frontend
**Dependências:** T-08
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Goku

Origem: onda de correção (code-review, itens 4 e 5). Arquivos da T-08. Revoga o ruling `T-08 · onda 2` ("Aceitar sem ai_suggestion_sha256 vira edited").

Achado (code-review, item 4): em `ExamResultForm::onAiDecide` (`ExamResultForm.php:365-373`), "Aceitar" com `ai_suggestion_sha256` ausente ou vazio vira sempre `edited`; a EncounterView só rebaixa com `$postedSha !== ''` (`EncounterView.php:1893`). Reprodução exigida: postar `decision=accepted` com `ai_final_text` igual à sugestão e sem `ai_suggestion_sha256` → hoje `status = 'edited'`.
Achado (code-review, item 5): "Aceitar" com a textarea vazia pula a checagem e grava a sugestão original como `accepted` (`:369-376`). Reprodução exigida: postar `decision=accepted` com `ai_final_text=''` → hoje `status = 'accepted'` com `final_text` = sugestão original.
Veredito exigido: alinhar o item 4 à EncounterView; no item 5, recusar com mensagem sem gravar.

**Arquivos prováveis**
- `src/app/control/clinic/ExamResultForm.php`
- `src/tests/Integration/ExamResultAiIntegrationTest.php`

**Interface**
- Produz: `accepted` só vira `edited` quando o SHA postado não é vazio e `!hash_equals($postedSha, hash('sha256', $finalText))` (mesma guarda de `EncounterView.php:1893`)
- Produz: `accepted` ou `edited` com `ai_final_text` normalizado vazio → `TMessage('warning', _t('Edited AI text must not be empty'))` (chave existente, pt `O texto editado não pode ficar vazio.`), sem abrir transação nem chamar `decide`; a interação segue `pending_review`
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Integration/ExamResultAiIntegrationTest.php` — "Accept" sem `ai_suggestion_sha256` e texto igual à sugestão grava `accepted` (o teste do ruling revogado passa a esperar isso); "Accept" com `ai_final_text` vazio deixa a linha `pending_review`, `final_text` nulo e mostra "O texto editado não pode ficar vazio." — falha antes porque grava `edited` e `accepted` respectivamente (comando: `SUITE | /usr/bin/grep -E 'ExamResultAiIntegrationTest|Failed:'`)

**Critério de aceite**
- Sem SHA: `ai_interaction.status = 'accepted'`; com SHA divergente: `edited` (caso existente `testAcceptWithEditedTextIsRecordedAsEdited` segue `PASS`).
- Texto vazio: linha `pending_review`, 0 eventos novos em `audit_log` para a interação, mensagem "O texto editado não pode ficar vazio.".
- `exam_result` igual antes e depois (HEX das colunas de texto).

**Validação**
- `SUITE | /usr/bin/grep -E 'ExamResultAiIntegrationTest|UserMessageTest|Failed:'` (evidência: `PASS` dos casos novos e antigos e `Failed: 0`)
- `LINT app/control/clinic/ExamResultForm.php` (evidência: `No syntax errors detected`)

### T-21 — Correção: docblock de `AiClinicalContextQueryInterface`

**Camada:** docs
**Dependências:** T-06
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

Origem: onda de correção (revisão final, triagem; também item 8 do code-review).

Achado: o docblock de `AiClinicalContextQueryInterface` (`:22`, `:27`) não traz `status` nas prescrições nem `pending_review` nos exames de `patientHistory`, nem `pending_review` em `examResultContext`. Reprodução exigida: `/usr/bin/grep -c "pending_review" src/app/Core/Domain/Contract/AiClinicalContextQueryInterface.php` → `0`.
Veredito exigido: docblock alinhado ao retorno da T-06, sem mudança de assinatura.

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/AiClinicalContextQueryInterface.php`

**Interface**
- Produz: `@return` de `patientHistory` com `prescriptions: list<array{created_at: string, status: string, items: list<string>}>` e `exam_results: list<array{received_at: string, exam_name: string, result_text: ?string, pending_review: bool}>`; `@return` de `examResultContext` com `pending_review: bool`
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- sem teste: só docblock, sem mudança de comportamento

**Critério de aceite**
- `/usr/bin/grep -c "pending_review" src/app/Core/Domain/Contract/AiClinicalContextQueryInterface.php` → `2` ou mais; `/usr/bin/grep -c "status: string" src/app/Core/Domain/Contract/AiClinicalContextQueryInterface.php` → `1` ou mais.

**Validação**
- `/usr/bin/grep -n "pending_review\|status: string" src/app/Core/Domain/Contract/AiClinicalContextQueryInterface.php` (evidência: linhas dos dois `@return`)
- `LINT app/Core/Domain/Contract/AiClinicalContextQueryInterface.php` (evidência: `No syntax errors detected`)


### T-22 — Correção: `/` de abreviação não encerra o escopo da palavra de contato

**Camada:** backend
**Dependências:** T-16
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Hipátia

Origem: onda de correção (code-review; achado do fechador da onda 4 em `notes.md § Pendências` e nota de `reviews/T-16.md` Rodada 3). Arquivos da T-03 e da T-16.

Achado: desde `2b4945c`, `PiiMinimizer::hasContactWordBefore` (`PiiMinimizer.php:151-165`) corta a frase em todo `/` que abre trecho com letras; em abreviações coladas ("p/", "c/", "s/") o corte separa a palavra de contato do número. Em `26175c0` saía `Ligar p/ tutor [TELEFONE]`. Reprodução exigida: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'require "app/Core/Ai/PiiMinimizer.php"; echo CentralVet\Ai\PiiMinimizer::minimize("Ligar p/ tutor 98765-4321", []), PHP_EOL;'` → `Ligar p/ tutor 98765-4321`.
Ruling do orquestrador: o `/` só encerra o escopo quando separa itens, com espaço em branco dos dois lados (`" / "`, como em "Contato X / Y / Leucócitos ..."); nunca em abreviações coladas como "p/", "c/", "s/".
Veredito exigido: RED com os dois casos de abreviação, mantendo a reprodução da T-16 e os casos intactos atuais.

**Arquivos prováveis**
- `src/app/Core/Ai/PiiMinimizer.php`
- `src/tests/Unit/PiiMinimizerTest.php`

**Interface**
- Produz: `hasContactWordBefore()` divide a última frase (após `\R` ou `;`) só em `/` com espaço em branco dos dois lados (`(?<=\s)/(?=\s)`); a regra "o trecho após o último separador que contém letras define o escopo" vale só para esses separadores; `/` colado a letra ou dígito (`p/`, `c/`, `s/`, `mg/dL`) não corta
- Produz: `labUnitAheadPattern()` e `contactPattern()` seguem compiladas uma vez (`static $pattern`), sem mudança de comportamento (a sugestão da T-16 já está em `82d77d6`; a task só confere)
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/PiiMinimizerTest.php` — `Ligar p/ tutor 98765-4321` → `Ligar p/ tutor [TELEFONE]`; `Falar c/ responsável (85) 98765-4321` → `Falar c/ responsável [TELEFONE]`; `Contato s/ resposta, tentar 98765-4321` → `Contato s/ resposta, tentar [TELEFONE]`; `Contato 98765-4321 / Plaquetas 95000-1200` → `Contato [TELEFONE] / Plaquetas 95000-1200`; a reprodução da T-16 `Contato (85) 8765-4321 / 85 8765-4321 / Leucócitos 12 3500-4500` → `Contato [TELEFONE] / [TELEFONE] / Leucócitos 12 3500-4500`; `Plaquetas 95000 1200`, `VR 12 3500-4500`, `Leucócitos 8,5 (VR: 12 3512-4587)` e `Contato com tutor feito.\nPlaquetas 95000 1200` intactos — falha antes porque `Ligar p/ tutor 98765-4321` sai sem máscara (comando: `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'`)

**Critério de aceite**
- Os casos do Teste RED passam e os casos já existentes de `PiiMinimizerTest` seguem `PASS` (nenhuma asserção removida ou afrouxada: `git diff 154328d -- src/tests/Unit/PiiMinimizerTest.php` só acrescenta linhas).
- A reprodução imprime `Ligar p/ tutor [TELEFONE]`.

**Validação**
- `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'` (evidência: `PASS` dos casos novos e antigos e `Failed: 0`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'require "app/Core/Ai/PiiMinimizer.php"; echo CentralVet\Ai\PiiMinimizer::minimize("Ligar p/ tutor 98765-4321", []), PHP_EOL;'` (evidência: `Ligar p/ tutor [TELEFONE]`)
- `/usr/bin/grep -c 'static $pattern' src/app/Core/Ai/PiiMinimizer.php` (evidência: `2` ou mais)
- `LINT app/Core/Ai/PiiMinimizer.php` (evidência: `No syntax errors detected`)


### T-23 — Correção (usuário): PII mascarado por padrão; exceção só unidade/marcador

**Camada:** backend
**Dependências:** T-22
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Ada

Origem: onda de correção (usuário; code-review de `26175c0..HEAD`). Arquivos da T-03, T-16 e T-22.

Achado: a regra atual só mascara celular sem DDD com palavra de contato na mesma frase (`PiiMinimizer::hasContactWordBefore`, `PiiMinimizer.php:153`, chamada em `:124`, com custo quadrático por recortar o prefixo a cada casamento) e isenta DDD solto por faixa de metades terminadas em `00`. Isso contraria a decisão original do usuário ("mascarar celular sem DDD"). Reprodução exigida: `minimize("Tutor ligou do 98765-4321 ontem", [])` → hoje `Tutor ligou do 98765-4321 ontem`; o mesmo vale para `Tutor: 11 3400-5600` (metades em `00`) e `Ref 11 98765-4321` (marcador).
Decisão do usuário (revoga o item 7 das ondas 4/5 e a regra das metades `00`): todo número com formato de telefone vira `[TELEFONE]`, com ou sem palavra de contato; exceção única para o que é claramente clínico. Valor de exame sem unidade pode virar `[TELEFONE]` (aceito).
Veredito exigido: RED com as reproduções mascaradas e os intactos abaixo; cada expectativa antiga alterada vai ao relatório com o motivo.

**Arquivos prováveis**
- `src/app/Core/Ai/PiiMinimizer.php`
- `src/tests/Unit/PiiMinimizerTest.php`

**Interface**
- Produz: formato de telefone = `(?:\+\s?55[\s.-]*)?` + DDD opcional (`\(\s*[1-9][1-9]\s*\)\s*`, `[1-9][1-9][\s.-]+` ou `[1-9][1-9]` colado ao celular) + assinante `9[\s.]?\d{4}[\s.-]?\d{4}` (celular, aceita `9 8765-4321`) ou `[2-9]\d{3}[\s.-]?\d{4}` (fixo), fora de número maior; todo casamento vira `[TELEFONE]`
- Produz: exceção (a) — casamento sem parênteses e sem `+55` seguido (após espaços) de unidade de `PiiMinimizer::LAB_UNITS` fica intacto; exceção (b) — casamento logo depois de `VR`, `ref`, `referência` ou `valor de referência` (com `:`/espaços opcionais entre o marcador e o número) fica intacto, salvo se contém celular de 9 dígitos iniciado em 9
- Produz: sem escopo por palavra de contato nem regra de metades `00`: `hasContactWordBefore`, `contactPattern` e `CONTACT_WORDS` saem; `LAB_UNITS` e `labUnitAheadPattern()` (compilada uma vez, `static $pattern`) ficam; `classifyDigits` (10–14 dígitos colados) sem mudança
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/PiiMinimizerTest.php` — mascarados: `Tutor ligou do 98765-4321 ontem` → `Tutor ligou do [TELEFONE] ontem`, `Ref 11 98765-4321` → `Ref [TELEFONE]`, `Tutor: 11 3400-5600` → `Tutor: [TELEFONE]`, `Tel: 3456-7890` → `Tel: [TELEFONE]`, `telefone: 1198765-4321` → `telefone: [TELEFONE]`, `Contato 9 8765-4321` → `Contato [TELEFONE]`, além de todos os mascarados atuais (`(85) 8765-4321`, `85 8765-4321`, `(85) 9 8765-4321`, `Ligar p/ tutor 98765-4321`, `+55 85 8765-4321` e os demais já em `PiiMinimizerTest`); intactos: `Leucócitos 12 3500-4500 x10³/µL`, `VR: 12 3512-4587`, `20 1000-4800 mm³`; os casos que hoje esperam intacto sem unidade nem marcador (`Plaquetas 95000 1200`, `Plaquetas 95000-1200`, `Leucócitos 12 3500-4500`, `98765-4321` sem contexto, e o trecho final de `Contato (85) 8765-4321 / 85 8765-4321 / Leucócitos 12 3500-4500` em :120 e :141, que vira `Leucócitos [TELEFONE]`) passam a esperar `[TELEFONE]`; `VR 3500-4500; whatsapp do tutor 85 8765-4321` (:108) mantém `VR 3500-4500` intacto (marcador sem celular); o teste que lista `PiiMinimizer::CONTACT_WORDS` (:160) sai — falha antes porque `Tutor ligou do 98765-4321 ontem` sai sem máscara (comando: `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'`)

**Critério de aceite**
- Os casos do Teste RED passam; nomes, NFD, hífen/apóstrofo, e-mail, CPF e CNPJ seguem `PASS`.
- `/usr/bin/grep -c "hasContactWordBefore\|CONTACT_WORDS" src/app/Core/Ai/PiiMinimizer.php` → `0`.
- O relatório lista cada expectativa antiga alterada (caso, antes, depois, motivo: decisão do usuário).

**Validação**
- `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'require "app/Core/Ai/PiiMinimizer.php"; echo CentralVet\Ai\PiiMinimizer::minimize("Tutor ligou do 98765-4321 ontem / Leucócitos 12 3500-4500 x10³/µL", []), PHP_EOL;'` (evidência: `Tutor ligou do [TELEFONE] ontem / Leucócitos 12 3500-4500 x10³/µL`)
- Review Focus: casos "Tutor ligou do 98765-4321 ontem" e "Leucócitos 12 3500-4500 x10³/µL" no teste (evidência: nomes dos métodos no relatório)
- `LINT app/Core/Ai/PiiMinimizer.php` (evidência: `No syntax errors detected`)

### T-24 — Correção (usuário): autosave com versão no serviço e UPDATE condicional

**Camada:** backend
**Dependências:** T-17
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Dijkstra

Origem: onda de correção (usuário; code-review de `26175c0..HEAD`). Arquivos da T-09 e T-17 (e `translations.json` da T-09/T-14/T-17).

Achado: a guarda da T-17 vive no controller. `EncounterView::onAutosave` lê o encontro com `findById` (`EncounterView.php:2163`) e o `EncounterService::autosave` lê de novo antes do `save` (`EncounterService.php:142-152`), sem condição no UPDATE. Um "Aplicar à anamnese" entre a leitura e a gravação é sobrescrito. No conflito, só `anamnesis_text` é descartado: diagnóstico, plano e exame físico da aba desatualizada sobrescrevem os da outra aba. Se a resposta de um tick se perde, a base da aba não avança e todo tick seguinte entra em conflito. Um post sem `anamnesis_base_sha256` (aba antiga depois do deploy) grava às cegas. Reproduções exigidas: (1) a resposta do tick 1 se perde e o tick 2 sai com a base antiga → hoje é conflito; (2) uma aba em conflito envia `diagnosis_text`, que hoje sobrescreve o da outra aba; (3) Apply entre a leitura e a gravação; (4) post sem versão → hoje grava.
Decisão do usuário: a versão fica no serviço, com UPDATE condicional atômico. No conflito nada do rascunho é gravado, a tela avisa e o texto local fica. A aba avança pela versão que o servidor devolve, ignorando respostas mais velhas. Cliente sem versão é tratado como conflito.
Veredito exigido: as quatro reproduções viram testes RED.

**Arquivos prováveis**
- `src/app/Core/Domain/Encounter.php`
- `src/app/Core/Application/EncounterService.php`
- `src/app/Core/Application/DraftSaveResult.php`
- `src/app/Core/Domain/Contract/EncounterRepositoryInterface.php`
- `src/app/Core/Persistence/EncounterRepository.php`
- `src/tests/Support/FakeEncounterRepository.php`
- `src/tests/Unit/EncounterServiceTest.php`
- `src/app/control/clinic/EncounterView.php`
- `src/tests/Integration/EncounterAiIntegrationTest.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `Encounter::draftVersion(): string` = `hash('sha256', json_encode(<mapa campo → valor de Encounter::DRAFT_FIELDS na ordem da constante; texto com CRLF/CR → LF e trim, número como string, vazio como null>, JSON_UNESCAPED_UNICODE))`
- Produz: `CentralVet\Application\DraftSaveResult` (`final`, `public readonly bool $saved`, `public readonly string $version`) — `version` é sempre a versão gravada no fim da chamada (a nova quando `saved`, a atual quando conflito)
- Produz: `EncounterService::autosave(int $id, array $draft, ?string $expectedVersion, ?array $previousDraft = null): DraftSaveResult`. Com `$expectedVersion === null`, é conflito sem gravar. Lê o encontro uma vez e aceita a base quando `$expectedVersion === draftVersion()` ou quando `$previousDraft` aplicado a um clone do encontro lido mantém `draftVersion()` (o tick anterior desta aba já está gravado). Sem mudança de versão, devolve `saved` sem UPDATE. Senão chama `saveDraftIfUnchanged`; `false` é conflito. No conflito, nenhum campo é gravado.
- Produz: `EncounterService::saveDraft(int $id, array $draft): Encounter` com o comportamento antigo de `autosave`, usado só pelo `onAiDecide`
- Produz: `EncounterRepositoryInterface::saveDraftIfUnchanged(Encounter $encounter, DateTimeImmutable $expectedUpdatedAt): bool`: `UPDATE encounter SET <colunas de rascunho> WHERE id = :id AND tenant_id = :tenant_id AND updated_at = :expected_updated_at` (`Y-m-d H:i:s.u`), com `true` se `rowCount() === 1`. O fake compara o `updatedAt()` guardado e avança um relógio próprio.
- Produz: `onAutosave` lê `draft_version`, `draft_seq` (inteiro) e `previous_draft` (JSON com os mesmos campos, opcional) e chama só o serviço, sem `findById`. A resposta é `<span class="cv-autosave-result" data-status="saved|conflict" data-version="<sha>" data-seq="<seq>"></span>`; `anamnesis_base_sha256`, `anamnesisSha` e os marcadores `cv-autosave-conflict`/`cv-autosave-base` saem.
- Produz: o script guarda `draftVersion` (renderizado com `Encounter::draftVersion()`), `seq` e `lastSent`, e envia `previous_draft` enquanto a resposta do tick anterior não chegou. A resposta com `data-seq` menor que a última aplicada é ignorada. Em `saved`, `draftVersion = data-version` e o indicador mostra "Saved at". Em `conflict`, o texto local fica, o indicador mostra `_t('The anamnesis was changed in another window. Reload the page to keep editing it.')` e dois botões `cv-touch-target`, `_t('Keep my text')` (adota `data-version` como base, e o próximo tick grava o texto local) e `_t('Reload the page')`. O `translations.json` ganha as duas chaves, com pt `Manter meu texto` e `Recarregar a página`.
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/EncounterServiceTest.php`, `src/tests/Integration/EncounterAiIntegrationTest.php` — (1) tick 1 gravado com resposta descartada; tick 2 com a versão antiga e `previous_draft` = tick 1 → `saved` e texto do tick 2 gravado; (2) aba com versão antiga após outra aba gravar `diagnosis_text` → `conflict` e `diagnosis_text`, `clinical_plan_text`, `physical_exam_text` e `anamnesis_text` iguais aos da outra aba; (3) `saveDraftIfUnchanged` com `updated_at` alterado entre leitura e gravação (Apply simulado no fake e, na integração, `onAiDecide` entre render e tick) → `conflict` e texto aplicado mantido; (4) post sem `draft_version` → `conflict`, nada gravado, resposta `data-status="conflict"` — falha antes porque `autosave` não recebe versão, o diagnóstico é sobrescrito e o post sem versão grava (comando: `SUITE | /usr/bin/grep -E 'EncounterServiceTest|EncounterAiIntegrationTest|UserMessageTest|Failed:'`)

**Critério de aceite**
- Os quatro cenários passam; o encontro em conflito fica com os valores da outra aba em todos os `DRAFT_FIELDS` (conferido por SELECT no teste).
- `/usr/bin/grep -c "anamnesis_base_sha256\|cv-autosave-conflict\|anamnesisSha" src/app/control/clinic/EncounterView.php` → `0`; `onAutosave` não chama `findById` (o relatório cola o trecho do método); `EncounterServiceTest` antigo (:116-121) migra para `saveDraft`/`autosave` com versão e segue `PASS`.
- `UserMessageTest::testEveryAiScreenKeyHasATranslation` em `PASS`.
- Registros existentes não somem: o caso (2) confere por SELECT que os demais campos do encontro (status, `started_at`, `ai_summary_text`) seguem iguais.

**Validação**
- `SUITE | /usr/bin/grep -E 'EncounterServiceTest|EncounterAiIntegrationTest|UserMessageTest|Failed:'` (evidência: `PASS` dos quatro cenários e `Failed: 0`)
- Review Focus: casos "aba desatualizada não grava nenhum campo" e "resposta perdida não trava a aba" (evidência: nomes dos métodos no relatório)
- `python3 -c "import json;json.load(open('/var/www/html/centralvet/src/app/config/translations.json'))" && echo json-ok` (evidência: `json-ok`)
- `LINT` de `app/Core/Domain/Encounter.php`, `app/Core/Application/EncounterService.php`, `app/Core/Application/DraftSaveResult.php`, `app/Core/Persistence/EncounterRepository.php` e `app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected` em todos)

### T-25 — Correção (usuário): "Aceitar" sem hash compara com a sugestão gravada

**Camada:** frontend
**Dependências:** T-20
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Curie

Origem: onda de correção (usuário; code-review de `26175c0..HEAD`). Arquivos da T-08 e T-20.

Achado: depois da T-20, "Aceitar" sem `ai_suggestion_sha256` fica `accepted` com a sugestão original mesmo quando o texto postado foi editado (`ExamResultForm.php:369-385`), e a edição se perde. Reprodução exigida: `decision=accepted`, sem hash, `ai_final_text` diferente da sugestão → hoje `status = 'accepted'` e `final_text` = sugestão.
Decisão do usuário: sem hash, o texto enviado é comparado com a sugestão gravada na interação. Se é igual, fica `accepted`; se é diferente, vira `edited` com o texto do usuário. Texto editado nunca é descartado, e texto vazio continua recusado.
Veredito exigido: RED com os dois casos sem hash.

**Arquivos prováveis**
- `src/app/control/clinic/ExamResultForm.php`
- `src/tests/Integration/ExamResultAiIntegrationTest.php`

**Interface**
- Produz: em `onAiDecide`, `accepted` com hash não vazio segue a regra atual. Sem hash, compara `normalizeText($postedText)` com `normalizeText($interaction->suggestionText())` da interação já lida na transação: igual mantém `accepted`, diferente vira `edited` com `$postedText`. O vazio continua recusado com `Edited AI text must not be empty`.
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Integration/ExamResultAiIntegrationTest.php` — sem `ai_suggestion_sha256` e `ai_final_text` editado → `status = 'edited'` e `final_text` = texto postado; sem hash e texto igual à sugestão → `accepted` — falha antes porque o caso editado grava `accepted` (comando: `SUITE | /usr/bin/grep -E 'ExamResultAiIntegrationTest|Failed:'`)

**Critério de aceite**
- Sem hash + texto editado: `ai_interaction.status = 'edited'`, `final_text` = texto postado normalizado; sem hash + texto igual: `accepted`; texto vazio: `pending_review` e mensagem "O texto editado não pode ficar vazio.".
- `exam_result` igual antes e depois (HEX das colunas de texto).

**Validação**
- `SUITE | /usr/bin/grep -E 'ExamResultAiIntegrationTest|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- `LINT app/control/clinic/ExamResultForm.php` (evidência: `No syntax errors detected`)

### T-26 — Correção (usuário): runbook com a regra de PII e o autosave versionado

**Camada:** docs
**Dependências:** T-23, T-24
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

Origem: onda de correção (usuário). Arquivo da T-15.

Achado: `docs/runbooks/ia-assistente.md` descreve o PiiMinimizer com allowlist e faixas pelas regras da T-03/T-16 e não fala do autosave. As T-23 e T-24 mudam as duas coisas. Reprodução exigida: `/usr/bin/grep -n "metades\|palavra de contato" docs/runbooks/ia-assistente.md` mostra a regra antiga (ou nenhuma menção ao "mascarar por padrão").
Veredito exigido: o runbook descreve a regra nova de PII (mascarar por padrão; exceções por unidade e por marcador sem celular; valor de exame sem unidade pode virar `[TELEFONE]`) e o autosave versionado (conflito não grava, aviso, "Manter meu texto"/"Recarregar a página", aba antiga pós-deploy não grava).

**Arquivos prováveis**
- `docs/runbooks/ia-assistente.md`

**Interface**
- Produz: seções do runbook sobre PII e autosave alinhadas à Interface das T-23 e T-24
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `/usr/bin/grep -n "TELEFONE" docs/runbooks/ia-assistente.md` → ao menos 1 linha que cita a regra de mascarar por padrão; `/usr/bin/grep -n "Manter meu texto" docs/runbooks/ia-assistente.md` → ao menos 1 linha; nenhuma menção à regra das metades `00` nem ao escopo por palavra de contato.

**Validação**
- `/usr/bin/grep -n "TELEFONE\|Manter meu texto\|conflito" /var/www/html/centralvet/docs/runbooks/ia-assistente.md` (evidência: linhas das duas regras)


### T-27 — Correção (usuário): fixo sem DDD intacto, decimal e unidade no fim de frase (PII)

**Camada:** backend
**Dependências:** T-23
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Noether

Origem: onda de correção (usuário; /code-review de `004d11c..HEAD` e revisão final rodada 3). Arquivos da T-03, T-16, T-22 e T-23.

Decisão do usuário, que revoga em parte o "mascarar por padrão" da onda 6 só neste ponto: fixo de 8 dígitos (4+4) sem DDD, sem `+55`, sem tronco `0` e sem parênteses não é mascarado. O celular de 9 dígitos é sempre mascarado. Reprodução exigida: `minimize("Plaquetas 2000-5000", [])` → hoje `Plaquetas [TELEFONE]`; o mesmo acontece com `Ano 2025/2026`, `Lote 2024-0001` e `CRMV 3456 7890`.
Achado (a): a guarda de decimal `(?<!\d[.,])` só existe no ramo do tronco `0` (`PiiMinimizer.php:59`). Reprodução: `Densidade 1,23456789` → `Densidade 1,[TELEFONE]` e `Fator 0,987654321` → `Fator 0,[TELEFONE]`. A lista `98765-4321,98765-1111` precisa continuar `[TELEFONE],[TELEFONE]`.
Achado (b): o lookahead da unidade `(?!\s*\.?\s*\d)` (`PiiMinimizer.php:175`) tira a exceção de unidade em fim de frase seguida de número. Reprodução: `Hematócrito 37 3500-4500 /µL. 2 doses` → hoje `Hematócrito [TELEFONE] /µL. 2 doses`. O caso `pg 2` não pode reabrir.
Veredito exigido: RED com todas as reproduções acima e as expectativas do Teste RED.

**Arquivos prováveis**
- `src/app/Core/Ai/PiiMinimizer.php`
- `src/tests/Unit/PiiMinimizerTest.php`

**Interface**
- Produz: fixo (`[2-9]\d{3}` + separador + `\d{4}`) só vira `[TELEFONE]` com prefixo (DDD solto ou colado, DDD entre parênteses, `+55` ou tronco `0`); sem prefixo, fica intacto. Celular (`9[\s.]?\d{4}` + separador + `\d{4}`) é sempre `[TELEFONE]`, com ou sem prefixo, e nunca é isento. As exceções por VR/ref e `LAB_UNITS`, os prefixos e os separadores não mudam.
- Produz: um casamento precedido de `\d[.,]` é parte decimal e fica intacto em todos os ramos. A exceção é quando o trecho imediatamente antes do `[.,]` já é um telefone completo (lista): `98765-4321,98765-1111` → `[TELEFONE],[TELEFONE]`.
- Produz: a unidade de `LAB_UNITS` mantém a exceção quando vem seguida de fim de frase (`.` + espaço em branco + qualquer coisa). Ela perde a exceção só quando um dígito vem colado ou só com espaços depois dela (`pg 2`, `pg2`, `pg.2`), o que equivale ao lookahead `(?!\s*\d)(?!\.\d)` no lugar de `(?!\s*\.?\s*\d)`.
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/PiiMinimizerTest.php` — intactos: `Plaquetas 2000-5000`, `Ano 2025/2026`, `Lote 2024-0001`, `CRMV 3456 7890`, `Densidade 1,23456789`, `Fator 0,987654321`, `Hematócrito 37 3500-4500 /µL. 2 doses`, `VR: 12 3512-4587`, `Leucócitos 12 3500-4500 x10³/µL`; mascarados: `Tel 85 3456-7890` → `Tel [TELEFONE]`, `(85) 3456-7890` → `[TELEFONE]`, `98765-4321` → `[TELEFONE]`, `Tutor ligou do 98765-4321` → `Tutor ligou do [TELEFONE]`, `98765-4321,98765-1111` → `[TELEFONE],[TELEFONE]`, `Ver 85 3456-7890 pg 2` → `Ver [TELEFONE] pg 2`; expectativa registrada: `Leucócitos 12 3500-4500` (sem unidade, formato DDD + fixo) → `Leucócitos [TELEFONE]`. As expectativas antigas de fixo sem DDD mascarado (ex.: `Tel: 3456-7890` da T-23) passam a intacto e vão listadas no relatório. Falha antes porque `Plaquetas 2000-5000` e `Densidade 1,23456789` são mascarados e `/µL. 2 doses` perde a exceção (comando: `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'`)

**Critério de aceite**
- Todos os casos do Teste RED passam; nomes, NFD, hífen/apóstrofo, e-mail, CPF, CNPJ, `+55`, tronco `0` e `(85) 9 8765-4321` seguem `PASS`.
- O relatório lista cada expectativa antiga alterada (caso, antes, depois, motivo).

**Validação**
- `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'require "app/Core/Ai/PiiMinimizer.php"; echo CentralVet\Ai\PiiMinimizer::minimize("Plaquetas 2000-5000 / Tel 85 3456-7890 / Densidade 1,23456789 / 98765-4321", []), PHP_EOL;'` (evidência: `Plaquetas 2000-5000 / Tel [TELEFONE] / Densidade 1,23456789 / [TELEFONE]`)
- `LINT app/Core/Ai/PiiMinimizer.php` (evidência: `No syntax errors detected`)

### T-28 — Correção (usuário): autosave com FOR UPDATE, números normalizados, ciclo de vida e Aplicar em voo

**Camada:** backend
**Dependências:** T-24
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Lamport

Origem: onda de correção (usuário; /code-review de `004d11c..HEAD`). Arquivos da T-09, T-17 e T-24.

Achados e reproduções exigidas:
- (c) `Encounter::draftVersion()` (`Encounter.php:320-336`, `versionValue`) hasheia o número cru quando ele não é `is_numeric` (`"38,5"` com vírgula). O banco grava `decimal(4,1)` = `38.5`, e o conflito fica perpétuo. Reprodução: autosave com `temperature_c = "38,5"`, depois um tick com a versão devolvida → hoje `conflict`.
- (d) A guarda por `updated_at` da linha inteira (`EncounterRepository::saveDraftIfUnchanged`, :139) dá falso conflito depois de pause, resume, finish, `acceptAiSummary` e ações do plano. A releitura em `EncounterService.php:177` (REPEATABLE READ) devolve o snapshot velho. Reprodução: tick com a versão atual depois de `pause()` → hoje `conflict`.
- (e) `pause`, `resume`, `finish` e `acceptAiSummary` gravam por `save()`, que reescreve todas as colunas, inclusive as de rascunho, com os valores lidos antes. Reprodução: ler o encontro, uma aba grava `diagnosis_text` e o `pause()` com a leitura antiga sobrescreve → hoje o diagnóstico volta ao valor antigo (no fake: `save` de um clone antigo).
- (f) "Aplicar à anamnese" com tick em voo (`EncounterView.php:2083`) dá falso conflito porque não envia `previous_draft`. Reprodução: tick gravado sem resposta, depois `onAiDecide` com a versão antiga → hoje `conflict`.
- (g) `onAutosave` reimplementa a validação de `draft_version` em vez de usar `postedDraftVersion()` (`:2192`).
Veredito exigido: um RED por achado (c–f); o (g) é refatoração coberta pelos testes existentes.

**Arquivos prováveis**
- `src/app/Core/Domain/Encounter.php`
- `src/app/Core/Application/EncounterService.php`
- `src/app/Core/Domain/Contract/EncounterRepositoryInterface.php`
- `src/app/Core/Persistence/EncounterRepository.php`
- `src/tests/Support/FakeEncounterRepository.php`
- `src/tests/Unit/EncounterServiceTest.php`
- `src/app/control/clinic/EncounterView.php`
- `src/tests/Integration/EncounterAiIntegrationTest.php`

**Interface**
- Produz: `Encounter::applyDraft()` normaliza `temperature_c` (escala 1), `weight_kg` (escala 2) e `capillary_refill_seconds` (escala 1): vírgula vira ponto, valor numérico vira `number_format(..., escala, '.', '')`, não numérico lança `InvalidArgumentException`. `heart_rate_bpm` e `respiratory_rate_mpm` viram `int`. A versão é calculada sobre os valores já normalizados, então a versão logo depois de gravar é igual à recomputada do banco.
- Produz: `EncounterRepositoryInterface::lockForDraft(int $id): ?Encounter` (`SELECT ... FOR UPDATE` filtrado por tenant; exige transação aberta) e `EncounterRepositoryInterface::updateDraft(Encounter $encounter): void` (`UPDATE` só das colunas de `DRAFT_FIELDS`). `saveDraftIfUnchanged` sai.
- Produz: o `UPDATE` de `EncounterRepository::save()` para encontro existente não escreve mais as colunas de `DRAFT_FIELDS`. O `INSERT` não muda. O rascunho só é gravado por `updateDraft`.
- Produz: `EncounterService::autosave(int $id, array $draft, ?string $expectedVersion, ?array $previousDraft = null): DraftSaveResult` lê com `lockForDraft`, compara `draftVersion()` (mesma regra de base e `previous_draft` da T-24), grava com `updateDraft` e devolve a nova versão. No conflito, devolve a versão real da leitura com `FOR UPDATE`, sem segunda leitura. `saveDraft(int $id, array $draft)` também usa `updateDraft`.
- Produz: o formulário `form_EncounterView_<id>` tem `<input type="hidden" name="previous_draft">`. O script do autosave o preenche com o JSON do tick em voo e o limpa na resposta. `onAiDecide` passa `self::postedPreviousDraft($param)` ao `autosave`. `onAutosave` usa `self::postedDraftVersion($param)`.
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/EncounterServiceTest.php`, `src/tests/Integration/EncounterAiIntegrationTest.php` — (c) `temperature_c = "38,5"` gravado como `38.5` e o tick seguinte com a versão devolvida → `saved`; (d) `pause()` (e `acceptAiSummary`) entre dois ticks não muda `draftVersion()` e o tick com a versão anterior → `saved`; (e) `pause()` com leitura anterior a uma gravação de `diagnosis_text` mantém o diagnóstico novo (SELECT); (f) tick gravado sem resposta seguido de `onAiDecide` "Apply to anamnesis" com a versão antiga e `previous_draft` = tick → texto aplicado gravado, sem aviso de conflito. Falha antes porque (c), (d) e (f) dão `conflict` e (e) devolve o diagnóstico antigo (comando: `SUITE | /usr/bin/grep -E 'EncounterServiceTest|EncounterAiIntegrationTest|Failed:'`)

**Critério de aceite**
- Os quatro cenários passam; os testes da T-24 (resposta perdida, aba desatualizada não grava nenhum campo, post sem versão) seguem `PASS`.
- `/usr/bin/grep -c "saveDraftIfUnchanged\|updated_at = :expected" src/app/Core/Persistence/EncounterRepository.php` → `0`; `/usr/bin/grep -c "FOR UPDATE" src/app/Core/Persistence/EncounterRepository.php` → `1` ou mais.
- Registros existentes não somem: o caso (e) confere por SELECT que `status`, `paused_at` e os `DRAFT_FIELDS` ficam com os valores gravados pela última escrita de cada campo (rascunho da aba, `paused_at` do pause).

**Validação**
- `SUITE | /usr/bin/grep -E 'EncounterServiceTest|EncounterAiIntegrationTest|UserMessageTest|Failed:'` (evidência: `PASS` dos cenários e `Failed: 0`)
- `LINT` de `app/Core/Domain/Encounter.php`, `app/Core/Application/EncounterService.php`, `app/Core/Persistence/EncounterRepository.php` e `app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected` em todos)

### T-29 — Correção (usuário): regra única de "Aceitar" (`AiAssistService::resolveDecision`)

**Camada:** backend
**Dependências:** T-25, T-28
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Euclides

Origem: onda de correção (usuário; /code-review de `004d11c..HEAD`). Arquivos da T-05, T-08, T-13, T-20, T-25, T-28 (EncounterView na onda 8).

Achado (h): `EncounterView::onAiDecide` (`EncounterView.php:2016-2045`) mantém `accepted` sem `ai_suggestion_sha256` mesmo com texto editado, e a edição do usuário se perde. É a regra que a T-25 corrigiu só na ExamResultForm. Reprodução exigida: "Accept" do resumo do paciente sem hash e com texto editado → hoje `status = 'accepted'` com a sugestão original.
Veredito exigido: uma regra só, usada pelas duas telas: igual à sugestão gravada → `accepted`; diferente → `edited` com o texto do usuário; vazio → recusado.

**Arquivos prováveis**
- `src/app/Core/Application/AiAssistService.php`
- `src/tests/Unit/AiAssistServiceTest.php`
- `src/app/control/clinic/ExamResultForm.php`
- `src/tests/Integration/ExamResultAiIntegrationTest.php`
- `src/app/control/clinic/EncounterView.php`
- `src/tests/Integration/EncounterAiIntegrationTest.php`

**Interface**
- Produz: `AiAssistService::resolveDecision(string $decision, string $postedText, string $postedSha, string $suggestionText): array` (público, estático, puro), que devolve `['decision' => string, 'final_text' => ?string]`:
  - `$decision` fora de `AiInteraction::DECISIONS` lança `InvalidArgumentException('Unknown AI decision')`.
  - `discarded` devolve `final_text` `null`.
  - Se `accepted` ou `edited` vem com o texto normalizado vazio (CRLF/CR → LF, `trim`), lança `InvalidArgumentException('Edited AI text must not be empty')`.
  - `edited` devolve `edited` com o texto postado.
  - `accepted` com hash não vazio fica `accepted` (`final_text` `null`) se `hash_equals($postedSha, hash('sha256', <texto normalizado>))`; senão vira `edited`.
  - `accepted` sem hash fica `accepted` se o texto normalizado é igual a `normalizeText($suggestionText)`; senão vira `edited`.
- Produz: `ExamResultForm::onAiDecide` e `EncounterView::onAiDecide` chamam `resolveDecision` com a `suggestionText()` da interação lida e não têm regra própria. O `InvalidArgumentException` vira `TMessage('warning', ...)` com a mensagem traduzida. Na EncounterView, "Aceitar" com o texto vazio passa a ser recusado.
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/AiAssistServiceTest.php`, `src/tests/Integration/EncounterAiIntegrationTest.php` — tabela de `resolveDecision`: accepted + hash igual → `accepted`; accepted + hash diferente → `edited`; accepted sem hash + texto igual → `accepted`; accepted sem hash + texto editado → `edited` com o texto; texto vazio → exceção; decisão desconhecida → exceção. Integração: "Accept" na EncounterView sem `ai_suggestion_sha256` e com texto editado grava `edited` com o texto postado. Falha antes porque `resolveDecision` não existe e a EncounterView grava `accepted` (comando: `SUITE | /usr/bin/grep -E 'AiAssistServiceTest|EncounterAiIntegrationTest|ExamResultAiIntegrationTest|Failed:'`)

**Critério de aceite**
- Os casos da tabela passam; na EncounterView, "Accept" sem hash com texto editado → `ai_interaction.status = 'edited'` e `final_text` = texto postado normalizado.
- `ExamResultAiIntegrationTest` (sem hash igual/editado, vazio, SHA) segue `PASS` sem mudar expectativas.
- `/usr/bin/grep -c "hash_equals" src/app/control/clinic/ExamResultForm.php src/app/control/clinic/EncounterView.php` → `0` nos dois (a regra vive só no serviço).

**Validação**
- `SUITE | /usr/bin/grep -E 'AiAssistServiceTest|EncounterAiIntegrationTest|ExamResultAiIntegrationTest|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- `LINT` de `app/Core/Application/AiAssistService.php`, `app/control/clinic/ExamResultForm.php` e `app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected` nos três)

### T-30 — Correção (usuário): runbook com fixo sem DDD e atomicidade do autosave

**Camada:** docs
**Dependências:** T-26, T-27, T-28
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

Origem: onda de correção (usuário; /code-review de `004d11c..HEAD`, item i). Arquivo da T-15 e T-26.

Achado: `docs/runbooks/ia-assistente.md` descreve "mascarar por padrão" também para o fixo sem DDD e a atomicidade do autosave por `updated_at`. Também não diz que `draft_version` cobre só os campos do rascunho, nem contrasta faixa "com unidade" × sem unidade. Reprodução exigida: `/usr/bin/grep -n "updated_at\|3456-7890" docs/runbooks/ia-assistente.md` → regra antiga.
Veredito exigido: o runbook descreve a regra da T-27 (fixo sem prefixo intacto; celular sempre mascarado; `Leucócitos 12 3500-4500` sem unidade mascarado × `... x10³/µL` intacto; risco aceito do VR/ref) e a da T-28 (`SELECT ... FOR UPDATE`, `draft_version` só dos campos de rascunho, ciclo de vida não regrava rascunho, "Aplicar" com `previous_draft`).

**Arquivos prováveis**
- `docs/runbooks/ia-assistente.md`

**Interface**
- Produz: seções de PII e autosave do runbook alinhadas à Interface das T-27 e T-28
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `/usr/bin/grep -n "FOR UPDATE" docs/runbooks/ia-assistente.md` → ao menos 1 linha; `/usr/bin/grep -n "updated_at" docs/runbooks/ia-assistente.md` → nenhuma linha como guarda do autosave; `/usr/bin/grep -n "sem DDD" docs/runbooks/ia-assistente.md` → ao menos 1 linha com a regra do fixo.

**Validação**
- `/usr/bin/grep -n "FOR UPDATE\|sem DDD\|x10³/µL\|draft_version" /var/www/html/centralvet/docs/runbooks/ia-assistente.md` (evidência: linhas das regras novas)


### T-31 — Correção (usuário): celular nunca isento pela guarda de decimal (PII)

**Camada:** backend
**Dependências:** T-27
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Gauss

Origem: onda de correção (usuário, última onda focada; /code-review de `d5e657c..HEAD`, item 2). Arquivos da T-03, T-16, T-22, T-23 e T-27.

Achado: a guarda de decimal (`PiiMinimizer.php:180-190`) mantém intacto todo casamento só de dígitos logo depois de `\d[.,]`. Reprodução exigida: `Rua das Flores 123,987654321`, `123,85987654321` e `temp 38,5,987654321` saem sem máscara.
Veredito exigido: a regra exata abaixo. Os três vazamentos passam a ser mascarados e os intactos da T-27 continuam intactos. `085,987654321` também é mascarado pela regra nova, sem custo extra.

**Arquivos prováveis**
- `src/app/Core/Ai/PiiMinimizer.php`
- `src/tests/Unit/PiiMinimizerTest.php`

**Interface**
- Produz: um casamento que contém celular (9 dígitos começando em 9, com ou sem DDD solto ou colado) logo depois de `\d[.,]` só fica intacto quando a sequência de dígitos R imediatamente antes do `[.,]` (a) não é ela mesma precedida de `\d[.,]` e (b) tem 1 dígito (inclui `0`) ou 2 dígitos que não estão em `DDDS`. Fora disso, vira `[TELEFONE]`. Fixo depois de `\d[.,]` e listas (`98765-4321,98765-1111`) seguem a regra da T-27.
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/PiiMinimizerTest.php` — mascarados: `Rua das Flores 123,987654321` → `Rua das Flores 123,[TELEFONE]`, `123,85987654321` → `123,[TELEFONE]`, `temp 38,5,987654321` → `temp 38,5,[TELEFONE]`, `085,987654321` → `085,[TELEFONE]`, `85,987654321` → `[TELEFONE]` (comportamento atual, DDD + celular, inalterado); intactos: `Fator 0,987654321`, `Fator 10,987654321`, `Densidade 1,23456789` e os intactos da T-27 (`Plaquetas 2000-5000`, `Ano 2025/2026`, `Hematócrito 37 3500-4500 /µL. 2 doses`, `VR: 12 3512-4587`); `98765-4321,98765-1111` → `[TELEFONE],[TELEFONE]` — falha antes porque os três vazamentos saem sem máscara (comando: `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'`)

**Critério de aceite**
- Todos os casos do Teste RED passam e os demais casos de `PiiMinimizerTest` seguem `PASS`.
- O relatório lista toda expectativa antiga alterada (caso, antes, depois); só `085,987654321` pode mudar de intacto para mascarado.

**Validação**
- `SUITE | /usr/bin/grep -E 'PiiMinimizerTest|AiPromptCatalogTest|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'require "app/Core/Ai/PiiMinimizer.php"; echo CentralVet\Ai\PiiMinimizer::minimize("Rua das Flores 123,987654321 / temp 38,5,987654321 / Fator 10,987654321", []), PHP_EOL;'` (evidência: `Rua das Flores 123,[TELEFONE] / temp 38,5,[TELEFONE] / Fator 10,987654321`)
- `LINT app/Core/Ai/PiiMinimizer.php` (evidência: `No syntax errors detected`)

### T-32 — Correção (usuário): encontro finalizado, falha do tick, vitais sem negativo e `previous_draft` com número inválido

**Camada:** backend
**Dependências:** T-28, T-29
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Hamilton

Origem: onda de correção (usuário, última onda focada; /code-review de `d5e657c..HEAD`, itens 1, 3, 4 e 5). Arquivos da T-09, T-17, T-24, T-28 e T-29.

Achados e reproduções exigidas:
- (1, bloqueante) `EncounterService::autosave()` (`EncounterService.php:178`) não checa o status sob o `FOR UPDATE`, e o `onAiDecide` usa `isFinished` com `findById` sem lock (`EncounterView.php:2121`). Reprodução: `finish` na aba B, depois um tick ou "Apply to anamnesis" na aba A → hoje anamnese, vitais e diagnóstico do encontro finalizado são regravados.
- (3) No callback do tick (`EncounterView.php:1330-1360`), uma resposta sem marca (erro HTTP, HTML de login, fatal) só mostra erro se trouxer `cv-autosave-error` do mesmo `seq`. Sem isso o indicador continua em "Saved at", e um erro HTTP que não chama o callback fica mudo. Reprodução: tick cuja resposta é o HTML da tela de login → hoje nenhum aviso.
- (4) `Encounter::NUMERIC_DRAFT_RANGE` (`Encounter.php:83-89`) aceita negativo em `temperature_c`, `weight_kg` e `capillary_refill_seconds`. Reprodução: autosave com `weight_kg = "-3"` → hoje gravado.
- (5) Em `EncounterService.php:170-176` o número inválido é tirado do `previousDraft` antes de `isCurrentBase`, e o `array_diff_key($draft, $previousDraft)` (`:217`) declara conflito quando o tick atual corrige esse campo. Reprodução: tick 1 com `temperature_c = "abc"` sem resposta, depois tick 2 com `temperature_c = "38,5"`, a versão antiga e `previous_draft` = tick 1 → hoje `conflict`.
Veredito exigido: um RED por item.

**Arquivos prováveis**
- `src/app/Core/Domain/Encounter.php`
- `src/app/Core/Application/EncounterService.php`
- `src/app/Core/Application/DraftSaveResult.php`
- `src/tests/Unit/EncounterServiceTest.php`
- `src/app/control/clinic/EncounterView.php`
- `src/tests/Integration/EncounterAiIntegrationTest.php`

**Interface**
- Produz: `Encounter::isFinished(): bool` (`status === self::STATUS_FINISHED`), que é a regra de domínio existente: o domínio só tem `in_progress` e `finished`.
- Produz: `DraftSaveResult` ganha `public readonly bool $closed = false` (último parâmetro). `EncounterService::autosave()` checa `isFinished()` no encontro lido com `lockForDraft` antes de qualquer comparação. Encontro finalizado devolve `new DraftSaveResult(false, <versão lida>, [], closed: true)` sem gravar. `saveDraft()` segue a mesma regra e lança `InvalidStatusTransitionException`.
- Produz: `onAutosave` responde `data-status="closed"` (o regex do script passa a `saved|conflict|closed`). O script mostra no indicador, alinhado à esquerda, `_t('This encounter is finished and can no longer be edited')` (chave já traduzida), para de enviar ticks e não mexe nos campos. `onAiDecide` decide pelo resultado `closed` do `autosave` sob o lock e não pelo `findById` sem lock: rollback, sem registrar a decisão, e `TMessage('error', _t('This encounter is finished and can no longer be edited'))`.
- Produz: quando a resposta do tick mais recente (`sent === seq`) não traz `cv-autosave-result`, o script mostra o rótulo de erro já existente (`{$errorLabel}`), nunca "Saved at", e mantém `lastSent` com os valores desse tick e `awaiting = true` para que o próximo tick envie `previous_draft`. Uma resposta sem marca de tick mais antigo é ignorada. Um `setTimeout` de 25 s sem resposta do tick mais recente também mostra o erro.
- Produz: `NUMERIC_DRAFT_RANGE` com mínimo 0 em todos os campos (`temperature_c` [0, 999.9], `weight_kg` [0, 9999.99], `capillary_refill_seconds` [0, 999.9], `heart_rate_bpm` e `respiratory_rate_mpm` [0, 4294967295]). Negativo entra em `invalidDraftNumbers`.
- Produz: `isCurrentBase` usa o conjunto de chaves do `previous_draft` postado, antes de tirar os inválidos, para a cobertura (`array_diff_key`), e aplica no clone só os valores válidos.
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/EncounterServiceTest.php`, `src/tests/Integration/EncounterAiIntegrationTest.php` — (1) `finish` (aba B) e depois um tick com a versão atual → `closed`, `DRAFT_FIELDS` iguais (SELECT), resposta `data-status="closed"`; "Apply to anamnesis" depois do `finish` → `ai_interaction` segue `pending_review` e a mensagem "Este atendimento foi finalizado..." (pt da chave existente) aparece; (3) o script renderizado trata resposta sem `cv-autosave-result` do tick mais recente como erro sem depender de `cv-autosave-error` e tem o `setTimeout` de 25 s (asserções no HTML); (4) `weight_kg = "-3"`, `temperature_c = "-1"` e `capillary_refill_seconds = "-0,5"` → `invalidFields` com os três e nada gravado; (5) tick 1 com `temperature_c = "abc"` + tick 2 com `"38,5"` e `previous_draft` = tick 1 → `saved` com `38.5`. Falha antes porque (1) grava, (4) aceita negativo, (5) dá `conflict` e o script não tem o ramo de erro (comando: `SUITE | /usr/bin/grep -E 'EncounterServiceTest|EncounterAiIntegrationTest|UserMessageTest|Failed:'`)

**Critério de aceite**
- (1) Encontro finalizado: nenhum `DRAFT_FIELD` muda (SELECT antes e depois), resposta `closed`, decisão de IA não registrada.
- (3) O HTML do script tem o ramo "sem marca no tick mais recente → erro" e o `setTimeout`.
- (4) Os negativos vão para `invalidFields` e não são gravados; zero é aceito.
- (5) O tick que corrige o número inválido do tick em voo grava (`saved`).
- Os testes das T-24, T-28 e T-29 seguem `PASS`.

**Validação**
- `SUITE | /usr/bin/grep -E 'EncounterServiceTest|EncounterAiIntegrationTest|UserMessageTest|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- `LINT` de `app/Core/Domain/Encounter.php`, `app/Core/Application/EncounterService.php`, `app/Core/Application/DraftSaveResult.php` e `app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected` em todos)

### T-33 — Correção (usuário): `AiAssistService::normalizeText` único

**Camada:** backend
**Dependências:** T-29, T-32
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Knuth

Origem: onda de correção (usuário, última onda focada; /code-review de `d5e657c..HEAD`, item 6). Arquivos da T-05, T-08, T-13, T-29 e T-32.

Achado: `normalizeText` existe três vezes, em `AiAssistService.php:217` (privado), `EncounterView.php:1853` e `ExamResultForm.php:642`. O SHA renderizado nas telas e o comparado no serviço podem divergir. Reprodução exigida: `/usr/bin/grep -n "function normalizeText" src/app/Core/Application/AiAssistService.php src/app/control/clinic/EncounterView.php src/app/control/clinic/ExamResultForm.php` → três definições.
Veredito exigido: uma função pública, usada pelas telas para normalizar e para o SHA renderizado.

**Arquivos prováveis**
- `src/app/Core/Application/AiAssistService.php`
- `src/tests/Unit/AiAssistServiceTest.php`
- `src/app/control/clinic/EncounterView.php`
- `src/app/control/clinic/ExamResultForm.php`

**Interface**
- Produz: `AiAssistService::normalizeText(string $text): string` (público, estático; CRLF/CR → LF e `trim`). EncounterView e ExamResultForm removem a versão própria e chamam `\CentralVet\Application\AiAssistService::normalizeText` também no SHA de `ai_suggestion_sha256`.
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- `src/tests/Unit/AiAssistServiceTest.php` — `AiAssistService::normalizeText(" a\r\nb\r ")` === `"a\nb"` (chamada pública), e uma varredura dos fontes `EncounterView.php` e `ExamResultForm.php` sem `function normalizeText` — falha antes porque o método é privado e as telas têm cópia própria (comando: `SUITE | /usr/bin/grep -E 'AiAssistServiceTest|EncounterAiIntegrationTest|ExamResultAiIntegrationTest|Failed:'`)

**Critério de aceite**
- `/usr/bin/grep -c "function normalizeText" src/app/control/clinic/EncounterView.php src/app/control/clinic/ExamResultForm.php` → `0` nos dois; `AiAssistService.php` tem `public static function normalizeText`.
- Os testes de SHA, "Aceitar" e `resolveDecision` (T-25, T-29) seguem `PASS`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiAssistServiceTest|EncounterAiIntegrationTest|ExamResultAiIntegrationTest|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- `LINT` de `app/Core/Application/AiAssistService.php`, `app/control/clinic/EncounterView.php` e `app/control/clinic/ExamResultForm.php` (evidência: `No syntax errors detected` nos três)

### T-34 — Correção (usuário): runbook com encontro encerrado e guarda de decimal

**Camada:** docs
**Dependências:** T-30, T-31, T-32
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

Origem: onda de correção (usuário, última onda focada). Arquivo da T-15, T-26 e T-30.

Achado: `docs/runbooks/ia-assistente.md` não diz que encontro finalizado recusa autosave e "Aplicar" sob o lock, nem que tick sem resposta válida mostra erro. Também não descreve a regra de celular depois de vírgula. Reprodução exigida: `/usr/bin/grep -n "finalizado\|vírgula" docs/runbooks/ia-assistente.md` → sem as regras novas.
Veredito exigido: o runbook descreve o resultado `closed`, a falha visível do tick, o mínimo 0 dos vitais e a regra de decimal da T-31 (com exemplos mascarados e intactos).

**Arquivos prováveis**
- `docs/runbooks/ia-assistente.md`

**Interface**
- Produz: seções de PII e autosave do runbook alinhadas à Interface das T-31 e T-32
- Consome: nada (a dependência é só de arquivo compartilhado; ver ⚠ no Mapa)

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `/usr/bin/grep -n "123,987654321\|10,987654321" docs/runbooks/ia-assistente.md` → ao menos 1 linha; `/usr/bin/grep -n "finalizado" docs/runbooks/ia-assistente.md` → ao menos 1 linha sobre o autosave recusado.

**Validação**
- `/usr/bin/grep -n "finalizado\|123,987654321\|closed" /var/www/html/centralvet/docs/runbooks/ia-assistente.md` (evidência: linhas das regras novas)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
