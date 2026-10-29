# Plano: Landing a11y/i18n — pendências abertas da revisão final de ajustes de deploy

## Objetivo
Fechar, numa rodada curta, as pendências `[aberta]` de `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/reviews/final.md` sobre a landing: borda de erro em campo inválido focado, foco após "Remover" no carrinho, `lang` da página de falha do CSV conforme o idioma ativo, marcador específico do wrap de Tab no teste, remoção do `$phoneRaw === null ||` redundante sem mudar comportamento e comentários atualizados do SQL de limpeza já executado.

## Premissas
- Repositório único `/var/www/html/centralvet`, base `task/landing-publica-carrinho` @ `5b632bf`, árvore limpa; caminho exclusivo no checkout compartilhado; RED antes da implementação; trailers `Task: T-xx` / `Task: T-xx (RED)`.
- Convenções da rodada anterior (`.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/plan.md § Premissas`), comandos rodados de `/var/www/html/centralvet`:
  - **LINT** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>`;
  - **SUITE** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (sem filtro: `| grep -E '<Classe>|Failed:'`; testes sem PHPUnit, `test*` + `Assert::*`; roda no `centralvet_test`; SUITEs simultâneas dão falso FAIL em testes Redis);
  - **GATE**: o orquestrador reconstrói antes do gate (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`); só então o validador usa `curl` e o Playwright MCP em `http://127.0.0.1:8081` (nunca `localhost`).
- Sem migration. Nada em `src/lib/adianti` nem em arquivo listado em `src/app/config/framework_hashes.php` (`src/index.php`, `engine.php`, `init.php`, `app/templates/`).
- Cache-buster `?v=` de `landing.css` e `landing.js` muda junto com esses arquivos (novo valor `20261005-a11y2`); `translations.js` e `i18n.js` não mudam e mantêm o `?v=` atual.
- Nenhum texto visível novo nesta rodada (o foco após "Remover" vai para o link já existente "Ver planos"); se surgir, exige pt/en/es em `src/landing/translations.js`.
- Gate: se o bucket `lead-throttle` do gateway `192.168.16.1` estiver esgotado, `X-Forwarded-For: 203.0.113.NN`; o gate desta rodada não precisa criar lead — se criar, nome com prefixo `LP teste`; limpeza por SQL preparada e executada só pelo orquestrador com aprovação do usuário.
- `src/app/Core` não depende de Adianti: o idioma ativo (`ApplicationTranslator::getLanguage()`, `'pt'|'en'|'es'`) é lido no controller e passado ao Core.

## Escopo

### Incluso
- `.field [aria-invalid="true"]:focus` mantém a borda vermelha no campo inválido focado → T-01.
- "Remover" (`#remove-plan`) move o foco para `#go-plans`, dentro de `#cart` → T-01.
- `testScriptWrapsTabInsideOpenCart` com marcadores específicos do wrap (`ev.preventDefault(); last.focus();`, `ev.preventDefault(); first.focus();`, `if (ev.key === "Tab") wrapTab(ev);`) → T-01.
- Cache-busters de `landing.css`/`landing.js` para `?v=20261005-a11y2` e testes de marcador atualizados → T-01.
- `LeadCsvExport::failurePage` com `lang` derivado do idioma ativo do Adianti, passado por `LandingLeadList::onExport` → T-02.
- Remoção do `$phoneRaw === null ||` em `LeadSubmission.php` por reordenação, com teste de caracterização (phone ausente, `null`, não-string recusados) → T-03.
- Comentários atualizados de `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql` (executado em 2026-10-05, 13 linhas, COMMIT após guarda `ROW_COUNT()`; buckets `lead-throttle` dos gates), sem mudar nenhuma instrução SQL → T-04.
- Validação final (LINT, SUITE, curl, Playwright pt/en/es, contagem, escopo) → T-05.

### Excluído
- Demais `[aberta]` de `reviews/final.md`: BASE numérica de contagens, 422 no navegador, 403 sem XFF, gate de navegador do caminho de falha do CSV, sugestões de runbook (`set_real_ip_from` no login/logs, 405 do Apache).
- Tornar o `COMMIT` do SQL da T-09 condicional ou reexecutar o SQL; apagar chaves Redis.
- Novos textos visíveis, mudanças em `translations.js`, `i18n.js` ou `translations.json`.
- Migration de schema; `docker-compose.yml`; `docker/nginx/default.conf`; `src/lib/adianti`.

## Contexto técnico
- Camadas envolvidas: frontend (`src/landing/landing.css`, `src/landing/landing.js`, `src/app/view/landing/landing.html`), backend (`src/app/Core/Landing/LeadCsvExport.php`, `src/app/Core/Landing/LeadSubmission.php`, `src/app/control/admin/LandingLeadList.php`), database (comentários do SQL da T-09 anterior), qa (`src/tests/Unit`, gate Playwright).
- Projeto/base analisada: `/var/www/html/centralvet` (repositório único; `git -C <DIR> rev-parse --show-toplevel` = `/var/www/html/centralvet`), branch `task/landing-publica-carrinho` @ `5b632bf`.
- Integrações: nenhuma nova (Adianti `ApplicationTranslator`, MySQL `centralvet` só leitura no gate).

## Baseline
- php-lint: `php -l` (via LINT com `sh -c`) em `app/Core/Landing/LeadCsvExport.php`, `app/Core/Landing/LeadSubmission.php`, `app/control/admin/LandingLeadList.php`, `tests/Unit/LeadCsvExportTest.php`, `tests/Unit/LandingCatalogTest.php`, `tests/Unit/LandingA11yTest.php`, `tests/Unit/LandingPageTest.php`, só as linhas diferentes de `No syntax errors detected`, em raiz → baseline/php-lint.txt (0 linhas). Critério: nenhum erro novo em relação a `baseline/php-lint.txt` = `No syntax errors detected` em cada PHP tocado.

## Exploração read-only
- Caminhos relevantes:
  - `src/landing/landing.css:222-225` (`.field input:focus, .field select:focus` (0,2,1) com `outline: none` vence `.field [aria-invalid="true"]` (0,2,0));
  - `src/landing/landing.js:91-104` (`renderCartContent`, estado vazio com `a#go-plans` em :101), `:149-157` (`openCart` foca `#close-cart`), `:253` (`#remove-plan` sem foco), `:257-277` (`cartFocusables`, `wrapTab`, keydown), `:190-191` (`submitLead` com `ev.preventDefault()`);
  - `src/app/view/landing/landing.html:13-16` (`?v=20261005-a11y` em css, translations, js; `i18n.js?v=20261002-i18n`), `:306-316` (`#cart-language`, `#close-cart`);
  - `src/tests/Unit/LandingA11yTest.php:35-41` (cache-busters), `:58-66` (`testScriptWrapsTabInsideOpenCart`), `testStyleHidesClosedDrawerAndMarksInvalidFields` (exige o literal `.field [aria-invalid="true"]`); `src/tests/Unit/LandingPageTest.php:67-68`;
  - `src/app/Core/Landing/LeadCsvExport.php:86-100` (`failurePage(string $message)`, `lang="pt-BR"` em :90); único chamador `src/app/control/admin/LandingLeadList.php:180`; `src/app/lib/util/ApplicationTranslator.php:119` (`getLanguage()` → `'pt'|'en'|'es'`, padrão `'pt'` em `app/config/application.php:13`); mapa JS `src/landing/i18n.js:5` `{pt:"pt-BR",en:"en-US",es:"es"}`; `src/tests/Unit/LeadCsvExportTest.php:72` (`testFailurePageEscapesTheMessage` exige `pt-BR` com 1 argumento);
  - `src/app/Core/Landing/LeadSubmission.php:57-66` (`strict_types=1`, PHP 8.4; `text()` em :117 devolve `null` para ausente/não-string); `src/tests/Unit/LandingCatalogTest.php:132-160` (testes de phone; nenhum com `null`);
  - `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql:1-14,19,24,35,41-44` (comentários com "3 linhas", guarda só em comentário, "nenhum encontrado em 2026-10-05").
- Padrões identificados: testes de front por marcador estático em PHP (sem harness de JS); comportamento provado pelo Playwright MCP no gate; Core em `CentralVet\` sem Adianti; i18n da landing com chave = texto pt.
- Scripts úteis: LINT, SUITE e GATE (§ Premissas); leitura de dados `docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" centralvet -N -e "<SELECT>"'`; neste zsh `grep` é função de shell — usar `/usr/bin/grep` em validações.
- Riscos identificados:
  - remover só o `$phoneRaw === null ||` sem reordenar dá `TypeError` em `mb_strlen(null)` (`strict_types`) para phone ausente/não-string — a T-03 reordena para `strlen($phone) < 10` vir primeiro;
  - trocar o `?v=` exige atualizar `LandingA11yTest.php` e `LandingPageTest.php` na mesma task;
  - `#go-plans` fecha o carrinho ao ser ativado; o foco só fica nele, não o ativa.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/landing/landing.css` | borda vermelha em campo inválido focado | modificar | T-01 |
| `src/landing/landing.js` | foco em `#go-plans` após "Remover" | modificar | T-01 |
| `src/app/view/landing/landing.html` | cache-busters de css/js | modificar | T-01 |
| `src/tests/Unit/LandingA11yTest.php` | marcadores de foco, borda, wrap e cache-buster | modificar | T-01 |
| `src/tests/Unit/LandingPageTest.php` | marcadores de cache-buster | modificar | T-01 |
| `src/app/Core/Landing/LeadCsvExport.php` | `failurePage` com `lang` por idioma | modificar | T-02 |
| `src/app/control/admin/LandingLeadList.php` | passa o idioma ativo ao `failurePage` | modificar | T-02 |
| `src/tests/Unit/LeadCsvExportTest.php` | teste do `lang` por idioma | modificar | T-02 |
| `src/app/Core/Landing/LeadSubmission.php` | condição do phone sem `$phoneRaw === null ||` | modificar | T-03 |
| `src/tests/Unit/LandingCatalogTest.php` | caracterização do phone ausente/`null`/não-string | modificar | T-03 |
| `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql` | comentários pós-execução | modificar | T-04 |
| `.claude/tasks/mar-20261005-1457-landing-a11y-i18n/reports/T-05.md` | relatório da validação final | criar | T-05 |

Nenhum arquivo é tocado por mais de uma task: não há ⚠.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Regra nova `.field [aria-invalid="true"]:focus { border-color: #B3412E; box-shadow: 0 0 0 3px rgba(179, 65, 46, .25); }` (0,3,0) depois da linha 225 | trocar o seletor de `[aria-invalid]`; `!important` | mantém o literal exigido por `testStyleHidesClosedDrawerAndMarksInvalidFields`; a sombra devolve um indicador de foco que o `outline: none` tirava |
| Após "Remover", `$("go-plans").focus()` logo depois de `renderCart()` | focar `#close-cart`; `tabindex="-1"` no `.empty` | `#go-plans` está no lugar do conteúdo removido e já existe em pt/en/es; sem texto novo nem elemento novo |
| Marcadores do wrap: `ev.preventDefault(); last.focus();`, `ev.preventDefault(); first.focus();`, `if (ev.key === "Tab") wrapTab(ev);` | comentário marcador novo no JS | literais que só existem no wrap; nenhuma mudança no JS para o teste |
| `failurePage(string $message, string $language = 'pt')` com mapa `pt→pt-BR`, `en→en-US`, `es→es`, outro→`pt-BR`; controller passa `(string) ApplicationTranslator::getLanguage()` | Core chamar `ApplicationTranslator`; `lang` cru `pt` | Core sem Adianti; mesmo mapa de `src/landing/i18n.js:5`; parâmetro com padrão preserva o teste existente |
| Phone: `strlen($phone) < 10 || strlen($phone) > 13 || mb_strlen($phoneRaw) > self::PHONE_RAW_MAX` | só apagar o `$phoneRaw === null ||`; `(string)` cast | `null` vira `''`, `strlen('') < 10` curto-circuita antes de `mb_strlen`; mesmo erro `phone`, sem `TypeError` |
| SQL da T-09: só comentários; instruções SQL idênticas (conferido por diff sem comentários) | tornar o COMMIT condicional | já executado; o cabeçalho passa a proibir reexecução por `mysql < arquivo` |

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04
T-01..T-04 → T-05 (Onda 2)
```

## Estratégia de execução
- Branch de trabalho: `task/landing-a11y-i18n`
- Branch base: `task/landing-publica-carrinho`
- Commits da onda: cada implementador commita os próprios caminhos (`git -C /var/www/html/centralvet add <caminhos>` + `git -C /var/www/html/centralvet commit -m "<assunto>" -m "Task: T-xx" -- <caminhos>`); o commit do teste falhando leva `Task: T-xx (RED)` e vem antes da implementação. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task no checkout compartilhado `/var/www/html/centralvet`; nenhum arquivo dividido; rebuild, restart do nginx, Playwright e SQL de escrita são estado global — só o orquestrador reconstrói e executa SQL, só o validador navega)
- Gate: o validador confere trailer, escopo e ordem RED por `git -C /var/www/html/centralvet log <BASE da onda>..HEAD`, roda LINT e SUITE sozinha e, depois do rebuild + restart pelo orquestrador, o `curl` e o Playwright.

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04

### Onda 2
- T-05

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Jaspion | general-purpose | inherit | T-01 |
| Athena | general-purpose | inherit | T-02 |
| Sherlock | general-purpose | inherit | T-03 |
| Maquiavel | general-purpose | inherit | T-04 |
| Kratos — validador | geduc:validador | sonnet | T-05, gate da onda 1 |

## Review Focus
- "Remover" ativado pelo teclado (foco em `#remove-plan` + Enter) e depois Tab/Shift+Tab → foco em `#go-plans` e o wrap continua dentro de `#cart`, nunca em `body` → T-01
- `select#lead-vets` inválido focado (envio vazio, Tab até ele) → `border-top-color` `rgb(179, 65, 46)` como no `input` → T-01
- Admin com idioma `es` e falha no `onExport` → `<html lang="es">`, não `pt-BR` → T-02

## Critérios gerais de aceite
- SUITE termina com `Failed: 0` e `Total` = o da BASE da Onda 1 + os testes novos (T-01: 2, T-02: 1, T-03: 1).
- LINT de todo PHP tocado imprime `No syntax errors detected` (nenhum erro novo em relação a `baseline/php-lint.txt`, 0 linhas).
- `curl -s http://127.0.0.1:8081/` contém `landing/landing.css?v=20261005-a11y2` e `landing/landing.js?v=20261005-a11y2`.
- Dados: `SELECT COUNT(*) FROM landing_lead` em `centralvet` igual antes e depois do gate (nenhum registro existente perdido; gate sem envio de lead).
- Commits: `git -C /var/www/html/centralvet log --format='%h %s%n%b' <BASE da onda>..HEAD` só com trailer `Task: T-xx` de tasks da onda (ou `chore(tasks)` restrito a `.claude/tasks/`); `git show --stat` de cada um só com caminhos de "Arquivos prováveis"; RED antes da implementação.
- `git -C /var/www/html/centralvet diff --stat <BASE da onda 1>..HEAD` sem `src/lib/adianti`, `src/app/config/framework_hashes.php`, `src/index.php`, `src/engine.php`, `src/init.php`, `src/app/templates/`, `src/app/database/migrations/` nem `src/landing/translations.js`.
