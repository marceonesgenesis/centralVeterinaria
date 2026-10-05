# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | frontend | Borda de erro no campo focado, foco após "Remover", marcador do wrap e cache-busters | — | sim | média | Jaspion | [x] |
| T-02 | backend | `failurePage` do CSV com `lang` do idioma ativo do Adianti | — | sim | simples | Athena | [x] |
| T-03 | backend | Phone sem `$phoneRaw === null ||`, com caracterização de ausente/`null`/não-string | — | sim | simples | Sherlock | [x] |
| T-04 | database | Comentários pós-execução do SQL de limpeza da T-09 anterior | — | sim | simples | Maquiavel | [-] cancelada (fora do escopo) |
| T-05 | qa | Validação final: LINT, SUITE, curl, Playwright pt/en/es, contagem e escopo | T-01, T-02, T-03 | não | média | Kratos | [x] |

## Detalhamento

### T-01 — Borda de erro no campo focado, foco após "Remover", marcador do wrap e cache-busters

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

Pendências `[aberta]` da T-06 anterior (`reviews/final.md`): `.field input:focus` (0,2,1) com `outline: none` vence `.field [aria-invalid="true"]` (0,2,0) em `src/landing/landing.css:223-225`; `#remove-plan` em `src/landing/landing.js:253` recria o corpo do carrinho e o foco cai em `body`; `testScriptWrapsTabInsideOpenCart` (`src/tests/Unit/LandingA11yTest.php:58-66`) usa `ev.preventDefault()`, que já existe em `submitLead` (`landing.js:191`).
- CSS: acrescentar, logo depois da linha `.field [aria-invalid="true"] { border-color: #B3412E; }` (que fica intacta), a regra exata `.field [aria-invalid="true"]:focus { border-color: #B3412E; box-shadow: 0 0 0 3px rgba(179, 65, 46, .25); }`.
- JS: o ramo de `#remove-plan` passa a ser exatamente `if (t.id === "remove-plan") { state.planId = null; persist(); renderCount(); renderPlans(); renderCart(); $("go-plans").focus(); return; }` (`renderCartContent()` sem plano desenha `a#go-plans`, `landing.js:101`). Nenhum texto visível novo; `translations.js` não muda.
- Teste do wrap: em `testScriptWrapsTabInsideOpenCart`, trocar `Assert::stringContains('ev.preventDefault()', $script);` por três asserções dos literais já existentes em `landing.js:270-275`: `ev.preventDefault(); last.focus();`, `ev.preventDefault(); first.focus();` e `if (ev.key === "Tab") wrapTab(ev);`. Os demais marcadores do método ficam. Esse método passa antes e depois (o JS do wrap não muda); prove no relatório que ele discrimina: com `last.focus()` trocado temporariamente numa cópia em `/tmp` do JS, `str_contains` dá `false` (sem editar `landing.js` para isso).
- Cache-busters: em `src/app/view/landing/landing.html:13,16`, `landing/landing.css?v=20261005-a11y` → `landing/landing.css?v=20261005-a11y2` e `landing/landing.js?v=20261005-a11y` → `landing/landing.js?v=20261005-a11y2`; `translations.js?v=20261005-a11y` e `i18n.js?v=20261002-i18n` ficam. Atualizar `src/tests/Unit/LandingPageTest.php:67-68` e `testTemplateUsesA11yCacheBusters` (`LandingA11yTest.php:35-41`) para os valores novos de css/js (translations continua `20261005-a11y`).
- Testes novos em `LandingA11yTest`: `testRemovePlanFocusesGoPlansInsideCart` (literal `renderCart(); $("go-plans").focus(); return; }`) e `testStyleKeepsInvalidBorderOnFocus` (literal `.field [aria-invalid="true"]:focus { border-color: #B3412E;`).

**Arquivos prováveis**
- `src/landing/landing.css`
- `src/landing/landing.js`
- `src/app/view/landing/landing.html`
- `src/tests/Unit/LandingA11yTest.php`
- `src/tests/Unit/LandingPageTest.php`

**Interface**
- Produz: `.field [aria-invalid="true"]:focus { border-color: #B3412E; box-shadow: 0 0 0 3px rgba(179, 65, 46, .25); }`; `renderCart(); $("go-plans").focus(); return; }`; `landing/landing.css?v=20261005-a11y2`; `landing/landing.js?v=20261005-a11y2`
- Consome: nada

**Teste RED**
- `src/tests/Unit/LandingA11yTest.php`, `src/tests/Unit/LandingPageTest.php` — `testRemovePlanFocusesGoPlansInsideCart`, `testStyleKeepsInvalidBorderOnFocus`, `testTemplateUsesA11yCacheBusters` e o teste de marcadores de `LandingPageTest` falham porque `$("go-plans").focus()`, `.field [aria-invalid="true"]:focus` e `?v=20261005-a11y2` ainda não existem em `landing.js`, `landing.css` e `landing.html` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'LandingA11yTest|LandingPageTest|Failed:'`)

**Critério de aceite**
- SUITE com `Failed: 0` e as linhas de `LandingA11yTest` (9 métodos, 2 novos) e `LandingPageTest` sem `FAIL`; `landing.html` com `landing/landing.css?v=20261005-a11y2` e `landing/landing.js?v=20261005-a11y2`; no gate (T-05), campo inválido focado com `border-top-color` `rgb(179, 65, 46)` e, após "Remover", `document.activeElement.id === "go-plans"` dentro de `#cart`.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'LandingA11yTest|LandingPageTest|Failed:'` (evidência: `Failed: 0` e nenhuma linha `FAIL` dessas classes)
- `/usr/bin/grep -c 'v=20261005-a11y2' /var/www/html/centralvet/src/app/view/landing/landing.html` (evidência: `2`) e `/usr/bin/grep -c 'translations.js?v=20261005-a11y"' /var/www/html/centralvet/src/app/view/landing/landing.html` (evidência: `1`)
- `git -C /var/www/html/centralvet diff --stat <BASE da onda 1>..HEAD -- src/landing/translations.js src/landing/i18n.js` (evidência: saída vazia)
- Review Focus, no Playwright do gate (T-05): foco em `#remove-plan` + Enter → `document.activeElement.id === "go-plans"`, depois 10 Tab e 10 Shift+Tab sempre dentro de `#cart`; envio vazio e Tab até `#lead-vets` → `borderTopColor` `rgb(179, 65, 46)` (evidência: `go-plans`, 20 checagens `true`, `rgb(179, 65, 46)` no `select`)

### T-02 — `failurePage` do CSV com `lang` do idioma ativo do Adianti

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Athena

Pendência `[aberta]` da T-08 anterior: `src/app/Core/Landing/LeadCsvExport.php:90` fixa `<html lang="pt-BR">`, mas a mensagem vem de `_t()` em `src/app/control/admin/LandingLeadList.php:180` e pode sair em inglês ou espanhol. O Core não pode depender de Adianti: o idioma entra por parâmetro.
- `LeadCsvExport::failurePage(string $message, string $language = 'pt'): string` mapeia `'pt'` → `pt-BR`, `'en'` → `en-US`, `'es'` → `es` (mesmo mapa de `src/landing/i18n.js:5`) e qualquer outro valor → `pt-BR`; o valor do `lang` sai só do mapa (nunca o argumento cru). O resto da página (doctype, charset, viewport, escape) não muda; `testFailurePageEscapesTheMessage` (chamada com 1 argumento, espera `pt-BR`) continua passando sem edição.
- `LandingLeadList::onExport` (linha 180): `echo \CentralVet\Landing\LeadCsvExport::failurePage(_t('Could not export the leads. Close this tab and try again.'), (string) ApplicationTranslator::getLanguage());` (`ApplicationTranslator::getLanguage()` em `src/app/lib/util/ApplicationTranslator.php:119` devolve `'pt'|'en'|'es'`; o arquivo não tem `namespace`).
- Teste novo em `LeadCsvExportTest`: `testFailurePageLangFollowsActiveLanguage` com `'pt'` → `<html lang="pt-BR">`, `'en'` → `<html lang="en-US">`, `'es'` → `<html lang="es">`, `'fr'` → `<html lang="pt-BR">`, e `'"><script>'` → `<html lang="pt-BR">` sem `<script>` no HTML.

**Arquivos prováveis**
- `src/app/Core/Landing/LeadCsvExport.php`
- `src/app/control/admin/LandingLeadList.php`
- `src/tests/Unit/LeadCsvExportTest.php`

**Interface**
- Produz: `LeadCsvExport::failurePage(string $message, string $language = 'pt'): string`; `failurePage(_t('Could not export the leads. Close this tab and try again.'), (string) ApplicationTranslator::getLanguage())`
- Consome: nada

**Teste RED**
- `src/tests/Unit/LeadCsvExportTest.php` — `testFailurePageLangFollowsActiveLanguage` falha porque `failurePage('…', 'en')` ainda devolve `<html lang="pt-BR">` (o 2º argumento é ignorado) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'LeadCsvExportTest|Failed:'`)

**Critério de aceite**
- SUITE com `Failed: 0` e `LeadCsvExportTest` sem `FAIL` (inclui `testFailurePageEscapesTheMessage` inalterado); `LandingLeadList.php` com 1 ocorrência de `(string) ApplicationTranslator::getLanguage())`; LINT dos 3 PHP com `No syntax errors detected`.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'LeadCsvExportTest|Failed:'` (evidência: `Failed: 0`, nenhuma linha `FAIL` de `LeadCsvExportTest`)
- `/usr/bin/grep -c 'ApplicationTranslator::getLanguage())' /var/www/html/centralvet/src/app/control/admin/LandingLeadList.php` (evidência: `1`) e `/usr/bin/grep -c 'ApplicationTranslator' /var/www/html/centralvet/src/app/Core/Landing/LeadCsvExport.php` (evidência: `0`); Review Focus (admin `es`): o caso `'es'` → `<html lang="es">` de `testFailurePageLangFollowsActiveLanguage` mais o grep do controller acima
- LINT de `app/Core/Landing/LeadCsvExport.php`, `app/control/admin/LandingLeadList.php`, `tests/Unit/LeadCsvExportTest.php` (evidência: `No syntax errors detected` nos 3; nenhum erro novo em relação a `baseline/php-lint.txt`)

### T-03 — Phone sem `$phoneRaw === null ||`, com caracterização de ausente/`null`/não-string

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Sherlock

Pendência `[aberta]` da T-01 anterior: `$phoneRaw === null ||` em `src/app/Core/Landing/LeadSubmission.php:60`. Ele só é redundante se `strlen($phone) < 10` vier antes de `mb_strlen($phoneRaw)`: o arquivo tem `strict_types=1` e `mb_strlen(null)` lança `TypeError` (PHP 8.4). `text()` (:117) devolve `null` para campo ausente ou não-string, e `$phone` vira `''`.
1. Primeiro, o teste de caracterização `testMissingNullOrNonStringPhoneIsRejected` em `src/tests/Unit/LandingCatalogTest.php`: payload válido (`validPayload()`) sem a chave `phone`, com `'phone' => null` e com `'phone' => 11912345678` (int) → `errorsFor(...)` devolve exatamente `['phone']` nos 3 casos. Rode a SUITE (passa com o código atual), commite só o teste com `-m "Task: T-03"` e cole a saída em `## RED` do relatório como "caracterização: passa antes".
2. Depois, a condição passa a ser exatamente:
   `if (strlen($phone) < 10 || strlen($phone) > 13 || mb_strlen($phoneRaw) > self::PHONE_RAW_MAX) {` (multilinha no estilo atual), mantendo `$errors['phone'] = 'Informe o WhatsApp com DDD.';` e a linha do ternário `$phone = $phoneRaw === null ? '' : …`. Rode a SUITE de novo e commite com `-m "Task: T-03"`.

**Arquivos prováveis**
- `src/app/Core/Landing/LeadSubmission.php`
- `src/tests/Unit/LandingCatalogTest.php`

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: refatoração sem mudança de comportamento; o teste de caracterização `testMissingNullOrNonStringPhoneIsRejected` passa antes e depois e é commitado antes da refatoração

**Critério de aceite**
- SUITE com `Failed: 0` e `LandingCatalogTest` sem `FAIL` (inclui `testPhoneRawTextAboveLimitIsRejected`, `testUncoveredRulesAreEnforced` e o teste novo); `LeadSubmission.php` sem `$phoneRaw === null ||` e com 1 ocorrência de `$phoneRaw === null` (o ternário); o commit do teste é anterior ao da refatoração.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'LandingCatalogTest|Failed:'` (evidência: `Failed: 0`, nenhuma linha `FAIL` de `LandingCatalogTest`)
- `/usr/bin/grep -c '\$phoneRaw === null ||' /var/www/html/centralvet/src/app/Core/Landing/LeadSubmission.php` (evidência: `0`) e `/usr/bin/grep -c '\$phoneRaw === null' /var/www/html/centralvet/src/app/Core/Landing/LeadSubmission.php` (evidência: `1`)
- `git -C /var/www/html/centralvet log --format='%h %s' <BASE da onda 1>..HEAD -- src/tests/Unit/LandingCatalogTest.php src/app/Core/Landing/LeadSubmission.php` (evidência: o commit de `LandingCatalogTest.php` aparece abaixo, isto é, antes, do de `LeadSubmission.php`)
- LINT de `app/Core/Landing/LeadSubmission.php` e `tests/Unit/LandingCatalogTest.php` (evidência: `No syntax errors detected` nos 2)

### T-04 — Comentários pós-execução do SQL de limpeza da T-09 anterior

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Maquiavel

Pendências `[aberta]` da T-09 anterior em `/var/www/html/centralvet/.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql` (versionado; última versão em `5b632bf`). Fato (`.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/notes.md § Decisões tomadas`, T-09 · pós-revisão): executado em 2026-10-05 pelo orquestrador com aprovação SQL do usuário, backup `var/backups/centralvet-20261005T174645Z.sql.gz` (`gzip -t` ok), DELETE em transação com guarda condicional `ROW_COUNT() = 13` → 13 linhas afetadas, COMMIT, `total_depois = 0`; nenhuma chave Redis removida.
- Cabeçalho (linhas 1-14): registrar a execução acima numa linha que comece com `-- EXECUTADO em 2026-10-05`, com `13 linhas`, `ROW_COUNT() = 13` e o backup; dizer que o arquivo não deve ser reexecutado e que o `COMMIT;` final é incondicional — rodar com `mysql < arquivo` não tem guarda; reuso só interativo, conferindo `ROW_COUNT()` antes do `COMMIT`. O "Estado lido" passa a dizer "antes dos gates" (3 linhas) e que a execução encontrou 13.
- Comentários de linha `-- esperado em 2026-10-05: 3` (:19) e `-- esperado: N (3 em 2026-10-05)` (:24): trocar o número por `13 na execução de 2026-10-05`; `-- esperado: total_antes - N` pode ficar.
- Redis (:41-44): `lead-throttle` "nenhum encontrado em 2026-10-05" → "nenhum na leitura antes dos gates; os gates da T-10 criaram buckets (ex.: gateway 192.168.16.1 e 203.0.113.NN), que expiram por TTL ≤ 3600 s".
- Nenhuma instrução SQL muda (só texto após `--` e linhas de comentário).

**Arquivos prováveis**
- `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql`

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: só comentários de um SQL já executado; conferido por diff das instruções sem comentários e por grep

**Critério de aceite**
- O diff das instruções SQL sem comentários entre `5b632bf` e o arquivo atual é vazio (imprime `SQL-INALTERADO`); o arquivo tem `-- EXECUTADO em 2026-10-05`, `ROW_COUNT() = 13` e `192.168.16.1`, e nenhuma ocorrência de `nenhum` + `encontrado em 2026-10-05`.

**Validação**
- `bash -c 'F=.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql; strip(){ sed "s/--.*\$//;s/[[:space:]]*\$//;/^\$/d"; }; diff <(git -C /var/www/html/centralvet show 5b632bf:$F | strip) <(strip < /var/www/html/centralvet/$F) && echo SQL-INALTERADO'` (evidência: `SQL-INALTERADO`, sem linhas de diff)
- `/usr/bin/grep -oF -e '-- EXECUTADO em 2026-10-05' -e 'ROW_COUNT() = 13' -e '192.168.16.1' /var/www/html/centralvet/.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql | sort -u | wc -l` (evidência: `3`) e `/usr/bin/grep -c 'encontrado em 2026-10-05' /var/www/html/centralvet/.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql` (evidência: `0`)

### T-05 — Validação final: LINT, SUITE, curl, Playwright pt/en/es, contagem e escopo

**Camada:** qa
**Dependências:** T-01, T-02, T-03
**Paralelizável:** não
**Complexidade:** média
**Agente:** Kratos

Depois do rebuild + restart do nginx pelo orquestrador (§ Premissas de `plan.md`): SUITE uma vez (árvore parada), LINT dos 7 PHP da baseline, `curl` dos cache-busters, Playwright MCP anônimo em `http://127.0.0.1:8081` (um contexto novo por língua pt, en, es), contagem de `landing_lead` antes e depois e escopo por commit. O gate não envia lead (só envio vazio, barrado no cliente); se precisar enviar, nome com prefixo `LP teste` e, com o bucket do gateway `192.168.16.1` esgotado, `X-Forwarded-For: 203.0.113.NN`. Grava `reports/T-05.md`.
- Playwright, por língua: abrir `/`, ativar um `[data-add]`; no carrinho, envio vazio do `#lead-form` → `document.activeElement` com `aria-invalid="true"` e `getComputedStyle(document.activeElement).borderTopColor === "rgb(179, 65, 46)"`; Tab até `#lead-vets` → mesma cor de borda; foco em `#remove-plan` + Enter → `document.activeElement.id === "go-plans"` e `$("cart").contains(document.activeElement)`; 10 Tab e 10 Shift+Tab → foco sempre dentro de `#cart`, nunca `body`; `browser_console_messages` nível `error` = 0.
- Falha do CSV (`failurePage`) segue coberta só pelo unitário de T-02 (sem gate de navegador, por Excluído).

**Arquivos prováveis**
- `.claude/tasks/mar-20261005-1457-landing-a11y-i18n/reports/T-05.md`

**Interface**
- Produz: nada
- Consome: T-01 `landing/landing.css?v=20261005-a11y2`, T-01 `landing/landing.js?v=20261005-a11y2`, T-01 `renderCart(); $("go-plans").focus(); return; }`, T-02 `LeadCsvExport::failurePage(string $message, string $language = 'pt'): string`

**Teste RED**
- sem teste: task de validação; não implementa comportamento

**Critério de aceite**
- SUITE `Failed: 0` com `Total` = BASE da Onda 1 + 4; LINT dos 7 PHP com `No syntax errors detected`; `curl` com os 2 cache-busters `?v=20261005-a11y2`; Playwright pt/en/es com `rgb(179, 65, 46)` no campo inválido focado (input e select), `go-plans` como `activeElement` após "Remover" e 0 erros de console; `COUNT(*)` de `landing_lead` igual antes e depois; diff sem caminhos proibidos nem `src/landing/translations.js`.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'FAIL|Total|Failed:'` (evidência: `Failed: 0` e `Total` = BASE + 4)
- `curl -s http://127.0.0.1:8081/ | /usr/bin/grep -oE 'landing/landing\.(css|js)\?v=[^"]+'` (evidência: `landing/landing.css?v=20261005-a11y2` e `landing/landing.js?v=20261005-a11y2`)
- `curl -s 'http://127.0.0.1:8081/landing/landing.css?v=20261005-a11y2' | /usr/bin/grep -c 'aria-invalid="true"\]:focus'` (evidência: `1`)
- Playwright MCP (`browser_evaluate`) por língua (evidência: `rgb(179, 65, 46)` duas vezes, `go-plans`, `true` para `#cart` contém o foco, 20 checagens de Tab dentro de `#cart`, console error 0)
- `docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" centralvet -N -e "SELECT COUNT(*) FROM landing_lead"'` antes e depois do Playwright (evidência: mesmo número nas duas leituras; nenhum registro existente perdido)
- `git -C /var/www/html/centralvet diff --stat <BASE da onda 1>..HEAD` (evidência: só os caminhos de "Arquivos prováveis" de T-01..T-04 e `.claude/tasks/`; nenhum de `src/lib/adianti`, `framework_hashes.php`, `src/landing/translations.js`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
- [-] cancelada / fora do escopo por decisão do usuário
