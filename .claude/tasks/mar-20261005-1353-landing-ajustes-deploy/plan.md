# Plano: Ajustes de deploy da landing pública (IP real, ordem do lead.php, HEAD, a11y, testes e limpeza)

## Objetivo
Fechar as pendências `[aberta]` de deploy da landing pública registradas em `.claude/tasks/mar-20261001-2231-landing-publica-carrinho/reviews/final.md`: IP real no nginx local e documentação do equivalente na hospedagem compartilhada, `lead.php` respondendo 405/413/400/403 sem abrir MySQL/Redis, HEAD sem token, a11y do carrinho e do formulário (pt/en/es), testes que faltavam no lead, `FakeRedis` com TTL int, erro legível no CSV e SQL de limpeza dos leads de teste (preparado, não executado).

## Premissas
- Branch de trabalho: a atual `task/landing-publica-carrinho` (continua a landing), HEAD `939b541`, árvore limpa; checkout compartilhado em `/var/www/html/centralvet`, caminho exclusivo, RED antes da implementação, trailers `Task: T-xx` / `Task: T-xx (RED)`.
- nginx (`docker/nginx/default.conf`) é só do ambiente docker local; produção é hospedagem compartilhada (Apache + `.htaccess`, MySQL 5.7), onde o PHP lê só `REMOTE_ADDR`. O PHP nunca confia em `X-Forwarded-For`.
- Mesmas convenções da rodada anterior (`.claude/tasks/mar-20261001-2231-landing-publica-carrinho/plan.md § Premissas`), comandos rodados de `/var/www/html/centralvet`:
  - **LINT** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>`;
  - **SUITE** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (não filtra: `| grep -E '<Classe>|Failed:'`; roda no `centralvet_test`; não interromper; SUITEs simultâneas podem dar falso FAIL em testes Redis de outros arquivos);
  - **NGINX-T** = `docker compose exec -T nginx nginx -t`;
  - **REDIS** = `docker compose exec -T redis redis-cli <comando só leitura>` (sem senha);
  - **GATE**: o orquestrador reconstrói antes de cada gate (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`): o PHP entra copiado na imagem; os estáticos de `src/` e o `default.conf` vêm de bind-mount `:ro`. Só então o validador usa `curl` e o Playwright MCP, sempre em `http://127.0.0.1:8081` (nunca `localhost`).
- Sem migration de schema. Nada em `src/lib/adianti` nem em arquivo listado em `src/app/config/framework_hashes.php` (inclui `src/index.php`, `engine.php`, `init.php`, `app/templates/`).
- Leads criados nos gates desta rodada levam nome com prefixo `LP teste`; o SQL de limpeza (T-09) os cobre. A execução do SQL é do orquestrador, depois da Onda 3, com aprovação SQL do usuário (skill `sql-write-approval`).
- Estado lido em 2026-10-05 (só SELECT/leitura Redis): `landing_lead` tem 3 linhas, todas de teste (id 1 `R3 landing Ana`, id 2 `LP teste Ana`, id 3 `LP teste Replay`); `centralvet:session:deadbeef` já expirou (EXISTS 0); nenhuma chave `centralvet:lead-throttle:*`; 8 chaves `centralvet:lead-token:*` com TTL ≤ 7200 s.

## Escopo

### Incluso
- nginx local: `set_real_ip_from` das faixas privadas da rede docker + `real_ip_header X-Forwarded-For` + `real_ip_recursive on`, `absolute_redirect off`, `/lead.php` só com `POST` (405 JSON) no nginx; `src/.htaccess` com limite de método do `lead.php` → T-04.
- Documentação do IP real e do limite por IP na hospedagem compartilhada (`docs/runbooks/shared-hosting-mysql57.md`, `docs/runbooks/landing-leads.md`), sem confiar em `X-Forwarded-For` no PHP → T-05.
- `src/lead.php`: checagens que não dependem de estado (método, tamanho, content-type, origem) antes de abrir Redis/PDO; PDO aberto só na gravação → T-02.
- HEAD em `landing.php` sem emitir nem gravar token → T-03.
- a11y do drawer do carrinho (`inert`, sem `aria-hidden` incoerente, Esc, foco preso e devolvido) e do formulário (`aria-invalid`, `aria-describedby`, foco após 201), com textos em pt/en/es → T-06.
- Testes de preço forjado (`price_cents`/`plan_price_cents`) e das regras sem teste; `name`/`clinic` só com NBSP/ZWSP/`\p{Cf}` recusados; `phone` com limite no texto bruto → T-01.
- `FakeRedis::set` com TTL int no 3º argumento → T-07.
- `LandingLeadList::onExport` em falha com página de erro legível → T-08.
- SQL de limpeza de `landing_lead` (DELETE com WHERE explícito + SELECT antes/depois) e roteiro dos resíduos Redis, preparado sem executar → T-09.
- Validação final (SUITE, LINT, NGINX-T, curl, navegador anônimo, contagens) → T-10.

### Excluído
- Retenção/expurgo LGPD dos leads e edição de status do lead na tela interna.
- Auto-hospedar as fontes (Google Fonts continua).
- Demais pendências de outras rodadas e as `[aberta]` de `reviews/final.md` fora desta lista (verify.sql de `system_group_program`, guarda do `T-02-programs.sql`, testes de período do `LeadRepositoryIntegrationTest`, `plan` com espaços, `ControllerRawExceptionMessageTest` em `app/control/admin`, Redis fora do ar no gate, `limit_req`/`error_page 413` no nginx, `sameOrigin` atrás de proxy TLS).
- Migration de schema; `docker-compose.yml` (rede com `ipam` fixa e `LEAD_RATE_LIMIT_*` ficam como estão).
- Executar o SQL de limpeza ou apagar chaves Redis (só o orquestrador, com aprovação).

## Contexto técnico
- Camadas envolvidas: backend (`src/lead.php`, `src/landing.php`, `src/app/Core/Landing`), frontend (`src/landing/*.js|css`, `src/app/view/landing/landing.html`, `src/app/control/admin/LandingLeadList.php`, `src/app/config/translations.json`), infra (`docker/nginx/default.conf`, `src/.htaccess`), database (SQL de limpeza em `<DIR>/sql/`), docs (`docs/runbooks/`), qa (`src/tests`, gate Playwright).
- Projeto/base analisada: `/var/www/html/centralvet` (repositório único; `git -C <DIR> rev-parse --show-toplevel` = `/var/www/html/centralvet`), branch `task/landing-publica-carrinho` @ `939b541`.
- Integrações: MySQL `centralvet`/`centralvet_test`, Redis (token `centralvet:lead-token:`, limitador `centralvet:lead-throttle:` + sha256 do identificador `lead|<ip>`), Google Fonts.

## Baseline
- php-lint: `php -l` (via LINT com `sh -c`) em `lead.php`, `landing.php` e todo `.php` de `app/Core/Landing`, `app/control/admin`, `tests/Support`, `tests/Unit` (144 arquivos), só as linhas diferentes de `No syntax errors detected`, em raiz → baseline/php-lint.txt (0 linhas). Critério: nenhum erro novo em relação a `baseline/php-lint.txt` = `No syntax errors detected` em cada arquivo PHP tocado.
- nginx-t: `docker compose exec -T nginx nginx -t` em raiz → baseline/nginx-t.txt (2 linhas: `syntax is ok` e `test is successful`).

## Exploração read-only
- Caminhos relevantes:
  - `src/lead.php:37-51` (PDO e Redis abertos antes de `handle`), `:67-73` (handler, `REMOTE_ADDR` em :71), `:76-81` (503);
  - `src/app/Core/Landing/LeadSubmissionHandler.php:24-28` (construtor), `:34-50` (método → tamanho → content-type → origem), `:52-58` (limitador), `:60-63` (token);
  - `src/landing.php:48-60` (token emitido em :50 antes da saída do HEAD em :58); `src/app/Core/Landing/LandingPage.php:23-35` (`entryFor`);
  - `src/app/Core/Landing/LeadSubmission.php:30-83` (regras), `:106-132` (`text()` com `trim()`, `plainText()` com `<>` e `\p{Cc}`);
  - `src/tests/Support/FakeRedis.php:45-77` (`set` só lê `EX` de array);
  - `src/app/control/admin/LandingLeadList.php:163-178` (`onExport` em falha: `http_response_code(500); exit` sem corpo); `src/app/Core/Landing/LeadCsvExport.php`;
  - `src/landing/landing.js:146-157` (`openCart`/`closeCart`), `:130-137` (`field`/`selectField` com `<id>-err`), `:159-170` (`setErr`, `errIdFor`, `focusFirstError`, `showServerErrors`), `:212-215` (201/200), `:94-97` (`.done-box`), `:243` (Esc); `src/app/view/landing/landing.html:302-318` (drawer `aria-hidden="true"` sem `inert`), `:12-16` (cache-busters); `src/landing/landing.css:199-201` (`.drawer`/`.drawer.open`), `:223`;
  - `src/landing/translations.js` (`window.CvLandingTranslations = {en, es}` com chave = texto pt; pt é o fallback) e `src/landing/i18n.js:20-45` (`translate` percorre texto, `aria-label`, `placeholder`, `title`);
  - `src/tests/Unit/LandingPageTest.php:64-72` (marcadores exatos `landing.css?v=20261002-i18n` e `landing.js?v=20261002-cart`);
  - `docker/nginx/default.conf` (`listen 8080`, server :1-13, `= /lead.php` :43-58, `^~ /landing/` :60-76, `location /` :84-86); `docker-compose.yml:61` (`nginx:1.27.5-alpine`, com `--with-http_realip_module`), `:70` (`127.0.0.1:${HTTP_PORT:-8081}:8080`), `:212-215` (redes sem `ipam`);
  - `src/.htaccess` (2 linhas, `DirectoryIndex landing.php index.php`), `docs/runbooks/shared-hosting-mysql57.md:44-57`, `docs/runbooks/landing-leads.md:66-72`.
- Padrões identificados: Core em `CentralVet\` sem Adianti (`CoreLayerDependencyTest`); testes sem PHPUnit (`test*` + `Assert::*`, descoberta `*Test.php` em `tests/Unit`/`Integration`); controllers fora da suíte; mensagens do lead em pt no Core; i18n da landing por texto pt → `en`/`es`; `translations.json` do Adianti é lista `{en, pt}` em ordem alfabética do `en`.
- Scripts úteis: LINT, SUITE, NGINX-T, REDIS e GATE (§ Premissas); leitura de dados `docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" centralvet -N -e "<SELECT>"'`; `node` v22 existe, mas o módulo Playwright não (o `scripts/test-landing-i18n.cjs` não roda aqui; o gate usa o Playwright MCP).
- Riscos identificados:
  - não há proxy na frente do nginx local: o cliente chega pelo docker-proxy com o IP do gateway e sem `X-Forwarded-For`; confiar nas faixas privadas permite a quem fala com o gateway forjar o header — aceitável só porque a porta está presa em `127.0.0.1` (`docker-compose.yml:70`); a rede não tem subnet fixa, por isso as faixas RFC1918 inteiras;
  - `add_header` numa `location`/`if` anula a herança: o 405 do nginx precisa sair com os 5 headers já repetidos em `= /lead.php`;
  - `renderPlans()` recria os botões `[data-add]`: o `lastFocus` do carrinho pode ficar desconectado do DOM;
  - trocar o cache-buster de `landing.css`/`landing.js` exige atualizar `LandingPageTest.php:67-68` (mesma task, T-06);
  - SUITEs simultâneas de agentes da mesma onda geram falso FAIL em testes Redis alheios.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/Core/Landing/LeadSubmission.php` | regras do lead (trim unicode, `\p{Cf}`, phone bruto) | modificar | T-01 |
| `src/tests/Unit/LandingCatalogTest.php` | regras e preço forjado no value object | modificar | T-01 |
| `src/tests/Unit/LeadSubmissionHandlerTest.php` | preço forjado no endpoint | modificar | T-01 |
| `src/app/Core/Landing/LeadSubmissionHandler.php` | `precheck` estático (passos 1–4) | modificar | T-02 |
| `src/app/Core/Landing/LazyLeadStore.php` | store que abre a conexão só no `insert` | criar | T-02 |
| `src/lead.php` | precheck antes de Redis/PDO; PDO preguiçoso | modificar | T-02 |
| `src/tests/Unit/LeadEndpointPrecheckTest.php` | teste do precheck e do store preguiçoso | criar | T-02 |
| `src/app/Core/Landing/LandingPage.php` | `issuesToken()` | modificar | T-03 |
| `src/landing.php` | HEAD sem token | modificar | T-03 |
| `src/tests/Unit/LandingPageHeadTest.php` | teste de `issuesToken` | criar | T-03 |
| `docker/nginx/default.conf` | real_ip, `absolute_redirect off`, 405 do `/lead.php` | modificar | T-04 |
| `src/.htaccess` | limite de método do `lead.php` no Apache | modificar | T-04 |
| `docs/runbooks/shared-hosting-mysql57.md` | IP real e limite por IP na hospedagem | modificar | T-05 |
| `docs/runbooks/landing-leads.md` | operação: real_ip local e hospedagem | modificar | T-05 |
| `src/landing/landing.js` | inert, foco, `aria-invalid`/`aria-describedby`, foco após 201 | modificar | T-06 |
| `src/landing/landing.css` | drawer fechado invisível | modificar | T-06 |
| `src/app/view/landing/landing.html` | drawer com `inert`, cache-busters | modificar | T-06 |
| `src/landing/translations.js` | en/es de textos novos | modificar | T-06 |
| `src/tests/Unit/LandingPageTest.php` | marcadores de cache-buster | modificar | T-06 |
| `src/tests/Unit/LandingA11yTest.php` | marcadores de a11y do template e do JS | criar | T-06 |
| `src/tests/Support/FakeRedis.php` | `set` com TTL int | modificar | T-07 |
| `src/tests/Unit/LeadFormTokenTest.php` | semântica phpredis do `set` int | modificar | T-07 |
| `src/app/Core/Landing/LeadCsvExport.php` | `failurePage()` | modificar | T-08 |
| `src/tests/Unit/LeadCsvExportTest.php` | teste de `failurePage()` | modificar | T-08 |
| `src/app/control/admin/LandingLeadList.php` | `onExport` em falha com página legível | modificar | T-08 |
| `src/app/config/translations.json` | mensagem de falha do CSV en → pt | modificar | T-08 |
| `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql` | limpeza de leads de teste (não executado) | criar | T-09 |
| `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/reports/T-10.md` | relatório da validação final | criar | T-10 |

Nenhum arquivo é tocado por mais de uma task: não há ⚠. `LeadSubmissionHandler.php` (T-02) e `LeadSubmissionHandlerTest.php` (T-01) estão na mesma onda em arquivos distintos; a SUITE de um pode ver o outro pela metade (ruído, regra 3 do caminho exclusivo).

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| `LeadSubmissionHandler::precheck()` estático com os passos 1–4 (método, tamanho, content-type, origem), chamado por `lead.php` antes de abrir Redis e por `handle()` no início | construtor com closures para limitador e token; checar método só no `lead.php` | não muda o contrato de `handle()` nem dos testes existentes; mantém uma só ordem de regras; os passos 1–4 não leem estado |
| `LazyLeadStore` (closure → `LeadStoreInterface`, memoizada) no lugar do `LeadRepository` direto | abrir PDO antes do `insert` dentro do handler; PDO com `ATTR_PERSISTENT` | 429/403/422 não tocam MySQL; a conexão só abre no caminho do 201 |
| nginx: `set_real_ip_from` 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 e 127.0.0.1 no `server`, `real_ip_header X-Forwarded-For`, `real_ip_recursive on` | fixar subnet com `ipam` no compose; `X-Real-IP` | a rede docker não tem subnet fixa e o compose fica fora do escopo; `X-Forwarded-For` recursivo pega o primeiro IP não confiável da direita; a porta só escuta em `127.0.0.1` |
| `/lead.php` fora de `POST` → `return 405` JSON `{"accepted":false,"error":"method_not_allowed"}` com `Allow: POST` no nginx | `limit_except POST { deny all; }` | `limit_except` responde 403 HTML; o 405 JSON repete o contrato do PHP |
| `absolute_redirect off` no `server` | `port_in_redirect off` | `Location` relativo (`/landing/`) vale atrás de qualquer porta ou proxy |
| Apache: `<IfModule mod_rewrite.c>` com `RewriteCond %{REQUEST_METHOD} !^POST$` + `RewriteRule ^lead\.php$ - [R=405,L]`; IP real só por `mod_remoteip` do provedor (diretiva de servidor, não de `.htaccess`) | `<LimitExcept POST>` (403); ler `X-Forwarded-For` no PHP | o PHP já responde 405; o `.htaccess` só antecipa; `X-Forwarded-For` é falsificável pelo cliente |
| Drawer fechado com `inert` (atributo no template e propriedade no JS) e, aberto, `inert` em `header.top`, `main#inicio` e `footer`; sem `aria-hidden` no `#cart` | trap de foco por `keydown` Tab; manter `aria-hidden` | `inert` tira do foco e da árvore de acessibilidade de uma vez, sem lista de focáveis; `aria-hidden` com focáveis é o defeito apontado |
| Erros do formulário: `aria-describedby="<id>-err"` fixo no campo, `aria-invalid="true"` só com mensagem; após 201, foco no `h3#lead-done-title` (`tabindex="-1"`) | `aria-errormessage`; foco no botão Fechar | `aria-describedby` tem suporte amplo; o título anuncia o resultado |
| `onExport` em falha: `500` + `Content-Type: text/html; charset=UTF-8` + `LeadCsvExport::failurePage(_t('Could not export the leads. Close this tab and try again.'))` | 500 vazio (atual); redirect para a lista | aba `_blank` mostra a mensagem; a página é montada no Core e testável |
| Limpeza por `WHERE (name LIKE 'LP teste%' OR name = 'R3 landing Ana') AND created_at >= '2026-10-01'`, com SELECT antes/depois e COMMIT só se o DELETE afetar o número de linhas do SELECT anterior | `WHERE id IN (1,2,3)` | os gates desta rodada criam novos `LP teste`; o predicado cobre todos e não toca lead real |

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-04  T-07  T-09
T-04 → T-05
Onda 2: T-03  T-05  T-06  T-08
T-01..T-09 → T-10 (Onda 3)
[depois da Onda 3: orquestrador executa sql/T-09-cleanup-landing-lead.sql com aprovação SQL]
```

## Estratégia de execução
- Branch de trabalho: `task/landing-publica-carrinho`
- Branch base: `task/landing-publica-carrinho` (HEAD `939b541`; a rodada continua na mesma branch)
- Commits da onda: cada implementador commita os próprios caminhos (`git -C /var/www/html/centralvet add <caminhos>` + `git -C /var/www/html/centralvet commit -m "<assunto>" -m "Task: T-xx" -- <caminhos>`); o commit do teste falhando (só os arquivos do bloco Teste RED) leva `Task: T-xx (RED)` e vem antes da implementação. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task no checkout compartilhado `/var/www/html/centralvet`; nenhum arquivo dividido entre tasks; rebuild, restart do nginx, Playwright e SQL de escrita são estado global — só o orquestrador reconstrói, reinicia e executa SQL, só o validador navega)
- Gate de cada onda: o validador confere trailer, escopo e ordem RED por `git -C /var/www/html/centralvet log <BASE da onda>..HEAD`, roda LINT dos PHP da onda, SUITE sozinha, NGINX-T e, depois do rebuild + restart pelo orquestrador, os `curl` e o Playwright das tasks.
- Depois da Onda 3: o orquestrador mostra `sql/T-09-cleanup-landing-lead.sql` ao usuário (objetos, efeito, risco), e só com aprovação SQL explícita (skill `sql-write-approval`) roda o backup (`./scripts/backup.sh` + `gzip -t`) e o SQL; registra em `notes.md § Decisões tomadas`.

## Ondas de execução

### Onda 1
- T-01, T-02, T-04, T-07, T-09

### Onda 2
- T-03, T-05, T-06, T-08

### Onda 3
- T-10

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Arquimedes | general-purpose | inherit | T-01 |
| Platão | general-purpose | inherit | T-02 |
| Saitama | general-purpose | inherit | T-03 |
| Yoda | general-purpose | inherit | T-04 |
| Gandalf | geduc:documentador | sonnet | T-05 |
| Aang | general-purpose | inherit | T-06 |
| Levi | general-purpose | inherit | T-07 |
| Naruto | general-purpose | inherit | T-08 |
| Darwin | general-purpose | inherit | T-09 |
| Spock — validador | geduc:validador | sonnet | T-10, gates das ondas 1–3 |

## Review Focus
- Abrir o carrinho pelo botão "Assinar" de um plano (`[data-add]`, recriado por `renderPlans()`) e fechar com Esc → foco volta a um elemento conectado (`#open-cart`), nunca ao `body` → T-06
- Carrinho fechado e Tab a partir do último link do rodapé → nenhum elemento de `#cart` recebe foco e `#cart` não aparece na árvore de acessibilidade; carrinho aberto e 20 Tabs → o foco nunca sai de `#cart` → T-06
- Idioma `en`, envio com e-mail inválido (422) → mensagem em inglês em `#lead-email-err`, `aria-invalid="true"` em `#lead-email`; corrigido e reenviado → `aria-invalid` removido → T-06
- `POST /lead.php` com `X-Forwarded-For: 203.0.113.9, 10.1.2.3` → bucket `lead|203.0.113.9` no Redis (o IP privado da direita é pulado), nunca `lead|10.1.2.3` → T-04
- `OPTIONS` ou `PUT` em `/lead.php` → `405` JSON com `Allow: POST` e os 5 headers de segurança da location (CSP `default-src 'none'`) → T-04

## Critérios gerais de aceite
- SUITE termina com `Failed: 0` e `Total` ≥ o da BASE da Onda 1 + os testes novos de cada onda, em cada gate.
- LINT de todo PHP tocado imprime `No syntax errors detected` (nenhum erro novo em relação a `baseline/php-lint.txt`, 0 linhas); NGINX-T imprime as 2 linhas de `baseline/nginx-t.txt`.
- Dados: `SELECT COUNT(*) FROM landing_lead` em `centralvet` só cresce pelos envios `LP teste` do validador até a execução do T-09; `SELECT COUNT(*)` de `tenant`, `patient`, `tutor`, `system_program`, `system_group_program` iguais à BASE da Onda 1 em cada gate.
- Commits: `git -C /var/www/html/centralvet log --format='%h %s%n%b' <BASE da onda>..HEAD` só com trailer `Task: T-xx` de tasks da onda (ou `chore(tasks)` restrito a `.claude/tasks/`); `git show --stat` de cada um só com caminhos de "Arquivos prováveis" da task; RED antes da implementação.
- `git -C /var/www/html/centralvet diff --stat 939b541..HEAD` sem `src/lib/adianti`, `src/app/config/framework_hashes.php`, `src/index.php`, `src/engine.php`, `src/init.php`, `src/app/templates/` nem `src/app/database/migrations/`.
- Gate de navegador (`http://127.0.0.1:8081`, contexto anônimo, ondas 2–3): landing em pt/en/es, carrinho e envio `LP teste` com sucesso na tela; `browser_console_messages` (nível `error`) 0 e `browser_network_requests` ≥ 400 só os esperados (422 provocado).
