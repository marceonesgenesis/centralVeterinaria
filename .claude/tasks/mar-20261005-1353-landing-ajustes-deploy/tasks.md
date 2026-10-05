# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | backend | LeadSubmission: trim unicode, `\p{Cf}` e phone bruto; testes de regras e de preço forjado | — | sim | média | Arquimedes | [x] |
| T-02 | backend | lead.php: precheck (405/413/400/403) antes de Redis/PDO e LazyLeadStore | — | sim | média | Platão | [x] |
| T-03 | backend | HEAD em landing.php não emite nem grava token | — | sim | simples | Saitama | [ ] |
| T-04 | infra | nginx: real_ip, absolute_redirect off, 405 do /lead.php; .htaccess com limite de método | — | sim | média | Yoda | [x] |
| T-05 | docs | Runbooks: IP real e limite por IP no nginx local e na hospedagem compartilhada | T-04 | sim | simples | Gandalf | [ ] |
| T-06 | frontend | a11y do drawer do carrinho e do formulário de lead (pt/en/es) | — | sim | alta | Aang | [ ] |
| T-07 | backend | FakeRedis::set respeita TTL int no 3º argumento | — | sim | simples | Levi | [x] |
| T-08 | frontend | LandingLeadList::onExport em falha com página de erro legível | — | sim | simples | Naruto | [ ] |
| T-09 | database | SQL de limpeza dos leads de teste e roteiro dos resíduos Redis (sem executar) | — | sim | simples | Darwin | [x] |
| T-10 | qa | Validação final: suíte, lint, nginx, curl, navegador, contagens e escopo | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09 | não | média | Spock | [ ] |

## Detalhamento

### T-01 — LeadSubmission: trim unicode, `\p{Cf}`, phone bruto e testes de regras e preço forjado

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

Pendências de `reviews/final.md` da rodada anterior (T-01 e T-05): preço forjado sem teste, regras sem teste, `name`/`clinic` só com caracteres invisíveis passando no `trim()` (`LeadSubmission.php:106-132`) e `phone` sem limite no texto bruto (`:52-58`).

Mudanças em `src/app/Core/Landing/LeadSubmission.php` (mensagens de erro e chaves continuam as mesmas):
- `text()` troca `trim($value)` por `preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $value)` (NBSP U+00A0 é `\p{Zs}`; ZWSP U+200B e WORD JOINER U+2060 são `\p{Cf}`);
- `plainText()` passa a recusar `\p{Cf}` em qualquer posição: `preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 0`;
- nova constante `public const PHONE_RAW_MAX = 20;` e `phone` com `mb_strlen($phoneRaw) > self::PHONE_RAW_MAX` → `Informe o WhatsApp com DDD.` (o JS envia só dígitos; o limite só barra payload forjado).

Testes novos em `src/tests/Unit/LandingCatalogTest.php` (usar `validPayload()` e `errorsFor()` existentes, `:82-108`):
- `testForgedPriceInPayloadIsIgnored`: `validPayload()` + `['price_cents' => 1, 'plan_price_cents' => 1, 'planPriceCents' => 1, 'plan_name' => 'Grátis']` → `planPriceCents === 9700` e `planName === 'Pro'`;
- `testInvisibleOnlyNameAndClinicAreRejected`: `name` = `"\u{00A0}\u{200B}\u{00A0}"`, `clinic` = `"\u{200B}\u{2060}"` → `sortedKeys(errors) === ['clinic', 'name']`;
- `testFormatCharacterInsideNameIsRejected`: `name` = `"An\u{200B}a Ribeiro"` → erro em `name`;
- `testPhoneRawTextAboveLimitIsRejected`: `phone` = `'(11) 91234-5678' . str_repeat(' -', 5)` (25 caracteres, 11 dígitos) → erro em `phone`;
- `testUncoveredRulesAreEnforced` (um `errorsFor` por caso, cada um com a chave esperada): phone `'119123456'` (9 dígitos) e `'11912345678901'` (14 dígitos) → `phone`; email `str_repeat('a', 149) . '@exemplo.com'` (161 caracteres) → `email`; clinic `'A'` e `str_repeat('a', 161)` → `clinic`; vets `'x'` → `vets`; city `str_repeat('a', 81)` e `'Campinas <b>'` → `city`; `name` = `123`, `email` = `['a@b.com']`, `plan` = `true` → `name`, `email`, `plan`; consent `'true'` e `1` → `consent`.

Teste novo em `src/tests/Unit/LeadSubmissionHandlerTest.php` (helpers `post()`, `validPayload()`, `:224-255`): `testForgedPriceInPayloadIsStoredWithCatalogPrice` → `validPayload()` + `['plan_price_cents' => 1, 'price_cents' => 1]` com token emitido em `ISSUED_AT` → `201` e `$this->store->inserted[0]['lead']->planPriceCents === 9700`.

**Arquivos prováveis**
- `src/app/Core/Landing/LeadSubmission.php`
- `src/tests/Unit/LandingCatalogTest.php`
- `src/tests/Unit/LeadSubmissionHandlerTest.php`

**Interface**
- Produz: `LeadSubmission::PHONE_RAW_MAX = 20`, `LeadSubmission::fromPayload(array $payload): self` (assinatura inalterada) recusando `\p{Cf}` em `name`/`clinic`/`city` e removendo `[\s\p{Z}\p{Cf}]` das pontas de todo campo texto
- Consome: nada

**Teste RED**
- `src/tests/Unit/LandingCatalogTest.php`, `src/tests/Unit/LeadSubmissionHandlerTest.php` — `testInvisibleOnlyNameAndClinicAreRejected`, `testFormatCharacterInsideNameIsRejected` e `testPhoneRawTextAboveLimitIsRejected` falham porque hoje `trim()` não remove NBSP/ZWSP, `\p{Cf}` passa e o phone bruto não tem limite (`LeadSubmission.php:106-132`, `:52-58`); os demais testes novos já podem passar (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LandingCatalogTest|LeadSubmissionHandlerTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LandingCatalogTest::` para os 5 testes novos e `PASS  Unit\LeadSubmissionHandlerTest::testForgedPriceInPayloadIsStoredWithCatalogPrice`, com `Failed: 0`; LINT dos 3 arquivos imprime `No syntax errors detected`.

**Validação**
- SUITE com `| grep -E 'LandingCatalogTest|LeadSubmissionHandlerTest|Failed:'` (evidência: `PASS` em cada método novo e nos 6 + 12 antigos, `Failed: 0`)
- LINT de `app/Core/Landing/LeadSubmission.php`, `tests/Unit/LandingCatalogTest.php`, `tests/Unit/LeadSubmissionHandlerTest.php` (evidência: `No syntax errors detected`)

### T-02 — lead.php: precheck antes de Redis/PDO e LazyLeadStore

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

Hoje `src/lead.php:37-51` abre PDO e Redis antes de `handle()`: com MySQL ou Redis fora, `GET` responde 503 em vez de 405, e todo 400/403 abre conexão MySQL.

- `LeadSubmissionHandler::precheck()` estático reúne os passos 1–4 de `handle()` (`LeadSubmissionHandler.php:36-50`, mesmas respostas: 405 `method_not_allowed` com `Allow: POST`; 413 `payload_too_large`; 400 `invalid_request`; 403 `forbidden_origin`) e devolve `null` quando o pedido segue. `handle()` começa com `$early = self::precheck($method, $headers, $body); if ($early !== null) { return $early; }` — ordem e respostas de `handle()` inalteradas.
- `LazyLeadStore` em `src/app/Core/Landing/LazyLeadStore.php`: guarda a closure, chama-a no primeiro `insert` (uma vez só, memoiza o store) e delega.
- `src/lead.php`, nesta ordem: `vendor/autoload.php`, `config/environment.php`, fuso, headers de `$_SERVER` (bloco atual `:53-63`), corpo (`:65`), `$early = LeadSubmissionHandler::precheck(<método>, $headers, $body)` → se não for `null`, `centralvet_lead_emit(...)` e fim; só então `RedisConnectionFactory::fromEnvironment()`, limitador (inalterado) e `new LeadSubmissionHandler($limiter, new LeadFormToken($redis), new LazyLeadStore(static fn (): LeadStoreInterface => new LeadRepository(new PDO(<mesmos argumentos de :38-43>))))`. O `catch (Throwable)` com 503 continua.

**Arquivos prováveis**
- `src/app/Core/Landing/LeadSubmissionHandler.php`
- `src/app/Core/Landing/LazyLeadStore.php`
- `src/lead.php`
- `src/tests/Unit/LeadEndpointPrecheckTest.php`

**Interface**
- Produz: `public static function precheck(string $method, array $headers, string $body): ?LeadResponse` em `CentralVet\Landing\LeadSubmissionHandler` (`null` = segue para limitador/token)
- Produz: `final class CentralVet\Landing\LazyLeadStore implements LeadStoreInterface` com `__construct(\Closure $factory)` (closure `fn (): LeadStoreInterface`, chamada no máximo uma vez, só no primeiro `insert`)
- Consome: nada

**Teste RED**
- `src/tests/Unit/LeadEndpointPrecheckTest.php` — falha porque `precheck` e `LazyLeadStore` não existem: `precheck('GET', [], '')` → 405, `body` `['accepted' => false, 'error' => 'method_not_allowed']` e `headers['Allow'] === 'POST'`; `precheck('POST', <headers JSON same-origin de host 127.0.0.1:8081>, '{}')` → `null`; corpo de 8193 bytes → 413; `content-type` `text/plain` → 400; `origin` `https://evil.example` → 403 `forbidden_origin`; handler com `FakeRedis`, `LeadFormToken` e `LazyLeadStore` cuja closure conta chamadas e devolve um `FakeLeadStore`: POST sem token → 403 e 0 chamadas; payload válido do plano `pro` com token emitido 10 s antes → 201 e 1 chamada; segundo envio válido com token novo → 201 e ainda 1 chamada (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LeadEndpointPrecheckTest|LeadSubmissionHandlerTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LeadEndpointPrecheckTest::` em cada método, os 12 `PASS  Unit\LeadSubmissionHandlerTest::` antigos e `Failed: 0`; com MySQL e Redis inalcançáveis (`-e DB_HOST=db-fora.invalid -e REDIS_HOST=redis-fora.invalid`), `php -r` simulando `GET` em `lead.php` imprime `{"accepted":false,"error":"method_not_allowed"}` e simulando `POST` com `CONTENT_TYPE=text/plain` imprime `{"accepted":false,"error":"invalid_request"}` (nunca `unavailable`).

**Validação**
- SUITE com `| grep -E 'LeadEndpointPrecheckTest|LeadSubmissionHandlerTest|Failed:'` (evidência: `PASS` em cada método, `Failed: 0`)
- LINT de `lead.php`, `app/Core/Landing/LeadSubmissionHandler.php`, `app/Core/Landing/LazyLeadStore.php`, `tests/Unit/LeadEndpointPrecheckTest.php` (evidência: `No syntax errors detected`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -e DB_HOST=db-fora.invalid -e REDIS_HOST=redis-fora.invalid app php -r '$_SERVER["REQUEST_METHOD"]="GET"; require "lead.php";'` (evidência: `{"accepted":false,"error":"method_not_allowed"}`)
- O mesmo com `'$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["CONTENT_TYPE"]="text/plain"; require "lead.php";'` (evidência: `{"accepted":false,"error":"invalid_request"}`)
- Gate (depois do rebuild): `curl -s -X POST -H 'Content-Type: application/json' -d '{}' http://127.0.0.1:8081/lead.php` (evidência: `{"accepted":false,"error":"invalid_token"}`)

### T-03 — HEAD em landing.php não emite nem grava token

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Saitama

`src/landing.php:48-53` emite o token (`LeadFormToken::issue(time())`, grava `centralvet:lead-token:<sha256>` com TTL 2 h) antes da saída do `HEAD` em `:58-60`. Novo método `LandingPage::issuesToken()` (`true` só para `GET`, sem diferenciar caixa); `landing.php` só entra no bloco `try { ... issue(time()) }` quando `$page->issuesToken($method)` é `true`. Headers e status do `HEAD` (200, `Content-Type: text/html; charset=utf-8`, `Cache-Control: no-store`, sem corpo) continuam iguais.

**Arquivos prováveis**
- `src/app/Core/Landing/LandingPage.php`
- `src/landing.php`
- `src/tests/Unit/LandingPageHeadTest.php`

**Interface**
- Produz: `public function issuesToken(string $method): bool` em `CentralVet\Landing\LandingPage`
- Consome: nada

**Teste RED**
- `src/tests/Unit/LandingPageHeadTest.php` — falha porque `issuesToken` não existe: `issuesToken('GET')` e `issuesToken('get')` → `true`; `issuesToken('HEAD')`, `issuesToken('head')` e `issuesToken('POST')` → `false` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LandingPageHeadTest|LandingPageTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LandingPageHeadTest::` em cada método e `Failed: 0`; depois do rebuild, `curl -sI http://127.0.0.1:8081/` imprime `HTTP/1.1 200` e o número de chaves `centralvet:lead-token:*` no Redis não aumenta; um `GET` aumenta em 1.

**Validação**
- SUITE com `| grep -E 'LandingPageHeadTest|LandingPageTest|Failed:'` (evidência: `PASS` em cada método, `Failed: 0`)
- LINT de `landing.php`, `app/Core/Landing/LandingPage.php`, `tests/Unit/LandingPageHeadTest.php` (evidência: `No syntax errors detected`)
- Gate: `docker compose exec -T redis redis-cli --scan --pattern 'centralvet:lead-token:*' | wc -l` antes e depois de `curl -sI http://127.0.0.1:8081/` (evidência: mesmo número ou menor, e `HTTP/1.1 200` no curl); depois de `curl -s -o /dev/null http://127.0.0.1:8081/` (evidência: +1)

### T-04 — nginx: real_ip, absolute_redirect off, 405 do /lead.php; .htaccess

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

`docker/nginx/default.conf` (imagem `nginx:1.27.5-alpine`, com `--with-http_realip_module`; bind-mount `:ro`):
- Fora do `server` (o arquivo é incluído no contexto `http`): `map $request_method $lead_allow { POST ""; default "POST"; }` (`add_header` com valor vazio não envia o header).
- No `server`, depois de `server_tokens off;`: `set_real_ip_from 10.0.0.0/8;`, `set_real_ip_from 172.16.0.0/12;`, `set_real_ip_from 192.168.0.0/16;`, `set_real_ip_from 127.0.0.1;`, `real_ip_header X-Forwarded-For;`, `real_ip_recursive on;` e `absolute_redirect off;`, com comentário: a rede docker não tem subnet fixa (`docker-compose.yml:212-215`), nada na frente do nginx local define `X-Forwarded-For` e a porta só escuta em `127.0.0.1` (`docker-compose.yml:70`); em produção (Apache) isso não vale (ver T-05).
- Em `location = /lead.php`: `default_type application/json;`, `add_header Allow $lead_allow always;` junto dos 5 headers já repetidos, e `if ($request_method != POST) { return 405 '{"accepted":false,"error":"method_not_allowed"}'; }` (o `if` não declara `add_header`, então herda os 6 da location).

`src/.htaccess` (mantendo as 2 linhas atuais): bloco com comentário em pt dizendo que o `lead.php` aceita só `POST` (o PHP também responde 405) e que o IP real do visitante não se configura no `.htaccess` (é `mod_remoteip` no servidor, ver runbook), e
`<IfModule mod_rewrite.c>` / `RewriteEngine On` / `RewriteCond %{REQUEST_METHOD} !^POST$` / `RewriteRule ^lead\.php$ - [R=405,L]` / `</IfModule>`.

**Arquivos prováveis**
- `docker/nginx/default.conf`
- `src/.htaccess`

**Interface**
- Produz: `real_ip_header X-Forwarded-For;` com `set_real_ip_from 10.0.0.0/8; 172.16.0.0/12; 192.168.0.0/16; 127.0.0.1` e `real_ip_recursive on;`, `absolute_redirect off;`, `GET|HEAD|OPTIONS|PUT /lead.php` → `405` `{"accepted":false,"error":"method_not_allowed"}` com `Allow: POST`, `RewriteRule ^lead\.php$ - [R=405,L]`
- Consome: nada

**Teste RED**
- sem teste: configuração de nginx e Apache, sem suíte que a leia; provada por NGINX-T e `curl` no gate (Apache não existe no ambiente local)

**Critério de aceite**
- NGINX-T imprime as 2 linhas de `baseline/nginx-t.txt`; depois do restart: `curl -sI http://127.0.0.1:8081/landing` → `301` com `Location: /landing/` (sem `:8080`); `curl -si -X OPTIONS http://127.0.0.1:8081/lead.php` → `405`, `Allow: POST`, `Content-Security-Policy: default-src 'none'` e corpo `{"accepted":false,"error":"method_not_allowed"}`; POST com `X-Forwarded-For: 203.0.113.9, 10.1.2.3` cria a chave do bucket `lead|203.0.113.9` no Redis e não a de `lead|10.1.2.3`.

**Validação**
- `docker compose exec -T nginx nginx -t` (evidência: `syntax is ok` e `test is successful`, iguais a `baseline/nginx-t.txt`)
- Gate, depois do `docker compose restart nginx` do orquestrador: `curl -sI http://127.0.0.1:8081/landing` (evidência: `301` e `Location: /landing/`)
- `curl -si -X OPTIONS http://127.0.0.1:8081/lead.php`, `curl -si http://127.0.0.1:8081/lead.php` e `curl -si -X PUT http://127.0.0.1:8081/lead.php` (evidência: `405`, `Allow: POST`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'`, `X-Content-Type-Options: nosniff`, corpo `{"accepted":false,"error":"method_not_allowed"}`) — Review Focus
- `curl -si -X POST -H 'Content-Type: application/json' -d '{}' http://127.0.0.1:8081/lead.php` (evidência: `403`, sem header `Allow`)
- `curl -s -o /dev/null -X POST -H 'Content-Type: application/json' -H 'X-Forwarded-For: 203.0.113.9, 10.1.2.3' -d '{}' http://127.0.0.1:8081/lead.php` e depois `docker compose exec -T redis redis-cli EXISTS "centralvet:lead-throttle:$(printf 'lead|203.0.113.9' | sha256sum | cut -d' ' -f1)"` (evidência: `1`) e o mesmo com `lead|10.1.2.3` (evidência: `0`) — Review Focus
- Sem regressão: `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8081/` → `200` e `curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:8081/index.php?class=LoginForm'` → `200`
- `grep -c 'RewriteRule ^lead\\.php\$ - \[R=405,L\]' src/.htaccess` (evidência: `1`) e `grep -c 'DirectoryIndex landing.php index.php' src/.htaccess` (evidência: `1`)

### T-05 — Runbooks: IP real e limite por IP no nginx local e na hospedagem compartilhada

**Camada:** docs
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

- `docs/runbooks/shared-hosting-mysql57.md`: nova subseção `### IP do visitante e limite de envios da landing` dentro de `## Configuração da aplicação` (depois do parágrafo do `.htaccess`, `:52-57`): o `lead.php` usa só `REMOTE_ADDR` e conta 10 envios por IP por hora; atrás de proxy/CDN do provedor (ex.: Cloudflare) `REMOTE_ADDR` vira o IP do proxy e todos os visitantes dividem o mesmo limite; a correção é no servidor, com `mod_remoteip` (`RemoteIPHeader` + `RemoteIPTrustedProxy` só com as faixas do proxy), pedida ao provedor, porque essas diretivas não valem no `.htaccess`; o PHP nunca lê `X-Forwarded-For` (falsificável pelo cliente); como conferir: `consent_ip` de um lead de teste igual ao IP público de quem enviou; o `.htaccess` recusa métodos diferentes de `POST` no `lead.php` com 405 (`RewriteRule ^lead\.php$ - [R=405,L]`, só com `mod_rewrite`).
- `docs/runbooks/landing-leads.md` § Operação (`:66-72`): trocar "configure `real_ip` no nginx" pelo que T-04 configurou (faixas confiáveis, `real_ip_header X-Forwarded-For`, `real_ip_recursive on`, risco aceito pela porta em `127.0.0.1`), o comando de conferência do bucket por `redis-cli EXISTS` e o apontamento para a subseção da hospedagem.

**Arquivos prováveis**
- `docs/runbooks/shared-hosting-mysql57.md`
- `docs/runbooks/landing-leads.md`

**Interface**
- Produz: nada
- Consome: T-04 `real_ip_header X-Forwarded-For;`, T-04 `RewriteRule ^lead\.php$ - [R=405,L]`

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `grep -n '### IP do visitante e limite de envios da landing' docs/runbooks/shared-hosting-mysql57.md` imprime 1 linha; o mesmo arquivo contém `mod_remoteip`, `RemoteIPTrustedProxy` e `REMOTE_ADDR`; `docs/runbooks/landing-leads.md` contém `real_ip_recursive on` e não contém mais `configure \`real_ip\` no nginx`.

**Validação**
- `grep -c 'mod_remoteip\|RemoteIPTrustedProxy\|REMOTE_ADDR' docs/runbooks/shared-hosting-mysql57.md` (evidência: ≥ 3)
- `grep -n 'real_ip_recursive on\|X-Forwarded-For' docs/runbooks/landing-leads.md` (evidência: ≥ 2 linhas) e `grep -c 'configure `real_ip` no nginx' docs/runbooks/landing-leads.md` (evidência: `0`)
- `git -C /var/www/html/centralvet diff --stat <BASE da onda>..HEAD -- docs/` (evidência: só os 2 runbooks)

### T-06 — a11y do drawer do carrinho e do formulário de lead

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

Hoje o `#cart` fica `aria-hidden="true"` com focáveis e sem `inert` (`landing.html:302-318`, `landing.js:146-157`), o drawer fechado continua focável (`landing.css:199-201`, só `transform`), os erros do formulário não têm `aria-invalid`/`aria-describedby` (`landing.js:130-137`, `:159-170`) e o foco não se move depois do 201 (`:212-215`, `.done-box` em `:94-97`).

- `src/app/view/landing/landing.html`: `<aside class="drawer" id="cart" role="dialog" aria-modal="true" aria-labelledby="cart-title" inert>` (sem `aria-hidden`); cache-busters `landing/landing.css?v=20261005-a11y`, `landing/translations.js?v=20261005-a11y` e `landing/landing.js?v=20261005-a11y` (`i18n.js` inalterado).
- `src/landing/landing.js`:
  - `openCart()`: guarda `lastFocus`; `$("cart").inert = false`; `inert = true` em `document.querySelector("header.top")`, `$("inicio")` e `document.querySelector("body > footer")`; mostra `#scrim`, `.open`, foco em `#close-cart` (como hoje);
  - `closeCart()`: reverte (`#cart` `inert = true`, os 3 irmãos `inert = false`), esconde `#scrim`; foco em `lastFocus` se `lastFocus && lastFocus.isConnected`, senão em `$("open-cart")`;
  - nenhum `setAttribute("aria-hidden", ...)` em `#cart`; Esc (`:243`) continua fechando;
  - `field()`/`selectField()`: `aria-describedby="<id>-err"` no `input`/`select`; `#lead-consent` com `aria-describedby="consent-err"`;
  - `setErr(id, msg)`: no controle (`$(id === "consent" ? "lead-consent" : id)`) `setAttribute("aria-invalid", "true")` com mensagem e `removeAttribute("aria-invalid")` sem mensagem;
  - `.done-box`: `<h3 id="lead-done-title" tabindex="-1">Pedido recebido</h3>`; no 201/200, depois de `renderCart()`, `$("lead-done-title").focus()`.
- `src/landing/landing.css`: `.drawer` com `visibility: hidden` e `transition: transform .25s ease, visibility 0s linear .25s`; `.drawer.open` com `visibility: visible` e `transition: transform .25s ease`; `.field [aria-invalid="true"]` com borda `#B3412E` (mesma cor de `.field .err`, `:224`).
- Texto visível ou `aria-label` novo, se houver: entrada em `en` e `es` de `src/landing/translations.js` (chave = texto pt). Não está previsto texto novo.
- `src/tests/Unit/LandingPageTest.php:67-68`: marcadores com os novos cache-busters.

**Arquivos prováveis**
- `src/landing/landing.js`
- `src/landing/landing.css`
- `src/app/view/landing/landing.html`
- `src/landing/translations.js`
- `src/tests/Unit/LandingPageTest.php`
- `src/tests/Unit/LandingA11yTest.php`

**Interface**
- Produz: `<aside class="drawer" id="cart" role="dialog" aria-modal="true" aria-labelledby="cart-title" inert>`, `aria-describedby="<id>-err"` e `aria-invalid="true"` nos campos `lead-*`, `h3#lead-done-title[tabindex="-1"]` focado após 201, `landing/landing.js?v=20261005-a11y`, `landing/landing.css?v=20261005-a11y`
- Consome: nada

**Teste RED**
- `src/tests/Unit/LandingA11yTest.php`, `src/tests/Unit/LandingPageTest.php` — falha porque o template ainda tem `aria-hidden="true"` no `#cart`, sem `inert`, e com os cache-busters `20261002-*`, e o `landing.js` não contém `aria-describedby`, `aria-invalid`, `.inert = `, `isConnected` nem `lead-done-title` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LandingA11yTest|LandingPageTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LandingA11yTest::` em cada método, os 7 `PASS  Unit\LandingPageTest::` e `Failed: 0`; no Chromium (Playwright MCP, anônimo, `http://127.0.0.1:8081/`): com o carrinho fechado, `document.getElementById('cart').inert === true` e `#cart` sem `aria-hidden`; aberto pelo botão de um plano, 20 `Tab` mantêm `document.activeElement` dentro de `#cart`; `Escape` fecha e `document.activeElement.id === 'open-cart'` (ou o botão do plano, se conectado); envio vazio → `#lead-name` com `aria-invalid="true"` e `aria-describedby="lead-name-err"`; envio `LP teste` válido → `document.activeElement.id === 'lead-done-title'`; 0 erros de console.

**Validação**
- SUITE com `| grep -E 'LandingA11yTest|LandingPageTest|Failed:'` (evidência: `PASS` em cada método, `Failed: 0`)
- LINT de `tests/Unit/LandingA11yTest.php` e `tests/Unit/LandingPageTest.php` (evidência: `No syntax errors detected`)
- `node --check src/landing/landing.js` e `node --check src/landing/translations.js` (evidência: saída vazia, exit 0)
- Gate Playwright (anônimo): `browser_evaluate` dos estados do critério (inert, `activeElement` depois de 20 `Tab`, Esc, `aria-invalid`/`aria-describedby`, `lead-done-title`) e `browser_console_messages` nível `error` (evidência: valores do critério e 0 erros)
- Review Focus: carrinho aberto pelo botão "Assinar" de um plano, Esc → `document.activeElement` conectado e diferente de `document.body`; carrinho fechado, Tab a partir do último link do rodapé → `#cart.contains(document.activeElement) === false`; idioma `en`, e-mail inválido → texto em inglês em `#lead-email-err` e `aria-invalid="true"`, corrigido e reenviado → sem `aria-invalid` (evidência: valores do `browser_evaluate`)

### T-07 — FakeRedis::set respeita TTL int no 3º argumento

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

`src/tests/Support/FakeRedis.php:45-77` só lê `EX` vindo de array; no phpredis, `set($k, $v, 30)` (int) equivale a `SETEX` com 30 s. Acrescentar: `is_int($options)` → `$ttl = $options` (o resto do método igual; sem relógio, como hoje). Teste novo em `src/tests/Unit/LeadFormTokenTest.php` ao lado de `testFakeRedisFollowsPhpredisSemantics` (`:79-101`).

**Arquivos prováveis**
- `src/tests/Support/FakeRedis.php`
- `src/tests/Unit/LeadFormTokenTest.php`

**Interface**
- Produz: `FakeRedis::set($key, $value, $options = null)` com `$options` int = TTL em segundos (`ttl()` devolve o int)
- Consome: nada

**Teste RED**
- `src/tests/Unit/LeadFormTokenTest.php` — `testFakeRedisSetWithIntTtlBehavesLikeSetex` falha porque `set('k', 'v', 30)` hoje deixa `ttl('k') === -1`: espera `get('k') === 'v'`, `ttl('k') === 30` e, depois de `set('k', 'w')`, `ttl('k') === -1` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LeadFormTokenTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LeadFormTokenTest::testFakeRedisSetWithIntTtlBehavesLikeSetex`, os testes antigos de `LeadFormTokenTest` em `PASS` e `Failed: 0` no total da suíte.

**Validação**
- SUITE completa com `| grep -E 'LeadFormTokenTest|Failed:'` (evidência: `PASS` em cada método e `Failed: 0` na suíte inteira, que usa o `FakeRedis` em outros testes)
- LINT de `tests/Support/FakeRedis.php` e `tests/Unit/LeadFormTokenTest.php` (evidência: `No syntax errors detected`)

### T-08 — LandingLeadList::onExport em falha com página de erro legível

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Naruto

`src/app/control/admin/LandingLeadList.php:174-178` responde `http_response_code(500); exit` sem corpo numa aba `_blank`. Novo `LeadCsvExport::failurePage()` (Core, sem Adianti): `<!doctype html>` + `<html lang="pt-BR">` + `<meta charset="utf-8">` + `<title>` e `<p>` com a mensagem escapada por `htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. No ramo de falha do `onExport` (depois do `ob_end_clean`, como hoje): `http_response_code(500)`, `header('Content-Type: text/html; charset=UTF-8')`, `header('Cache-Control: private, no-store')`, `echo \CentralVet\Landing\LeadCsvExport::failurePage(_t('Could not export the leads. Close this tab and try again.'))`, `exit`. O `error_log` atual fica. `src/app/config/translations.json`: entrada `{"en": "Could not export the leads. Close this tab and try again.", "pt": "Não foi possível exportar os leads. Feche esta aba e tente de novo."}` na ordem alfabética do `en` (lista `{en, pt}`).

**Arquivos prováveis**
- `src/app/Core/Landing/LeadCsvExport.php`
- `src/tests/Unit/LeadCsvExportTest.php`
- `src/app/control/admin/LandingLeadList.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `public static function failurePage(string $message): string` em `CentralVet\Landing\LeadCsvExport`
- Consome: nada

**Teste RED**
- `src/tests/Unit/LeadCsvExportTest.php` — `testFailurePageEscapesTheMessage` falha porque `failurePage` não existe: `failurePage('Falha <b>"x"</b>')` começa com `<!doctype html>`, contém `<meta charset="utf-8">` e `Falha &lt;b&gt;&quot;x&quot;&lt;/b&gt;` e não contém `<b>` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LeadCsvExportTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LeadCsvExportTest::testFailurePageEscapesTheMessage` e `Failed: 0`; LINT de `LandingLeadList.php` e `LeadCsvExport.php` imprime `No syntax errors detected`; `python3 -c "import json; json.load(open('src/app/config/translations.json'))"` sai com 0; com a sessão admin, o CSV continua `200` com `Content-Type: text/csv; charset=UTF-8`.

**Validação**
- SUITE com `| grep -E 'LeadCsvExportTest|Failed:'` (evidência: `PASS` em cada método, `Failed: 0`)
- LINT de `app/control/admin/LandingLeadList.php`, `app/Core/Landing/LeadCsvExport.php`, `tests/Unit/LeadCsvExportTest.php` (evidência: `No syntax errors detected`)
- `python3 -c "import json; d=json.load(open('/var/www/html/centralvet/src/app/config/translations.json')); print([e['pt'] for e in d if e['en']=='Could not export the leads. Close this tab and try again.'])"` (evidência: a frase pt, 1 item)
- `grep -n 'failurePage' src/app/control/admin/LandingLeadList.php` (evidência: 1 linha no ramo `$leads === null`)
- Gate (sessão admin logada pelo orquestrador): `engine.php?class=LandingLeadList&method=onExport&static=1` (evidência: `200`, `text/csv; charset=UTF-8`, cabeçalho de 12 colunas)

### T-09 — SQL de limpeza dos leads de teste e roteiro dos resíduos Redis

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Darwin

Prepara, sem executar, `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql` no estilo de `.claude/tasks/mar-20261001-2231-landing-publica-carrinho/sql/T-02-programs.sql` (cabeçalho em pt com o estado lido, aviso de aprovação explícita, `START TRANSACTION`, guarda, DML, conferência, `COMMIT`). Colunas reais de `landing_lead`: `id, name, clinic_name, email, phone, vets_range, city, uf, plan_id, plan_name, plan_price_cents, consent_version, consent_at, consent_ip, created_at` (não existe `clinic`). Estado lido em 2026-10-05: 3 linhas (1 `R3 landing Ana`, 2 `LP teste Ana`, 3 `LP teste Replay`); os gates desta rodada acrescentam leads `LP teste`.
- `-- antes`: `SELECT COUNT(*) FROM landing_lead;` e `SELECT id, name, clinic_name, email, created_at FROM landing_lead WHERE (name LIKE 'LP teste%' OR name = 'R3 landing Ana') AND created_at >= '2026-10-01' ORDER BY id;`
- guarda: o número de linhas do SELECT acima é anotado (`-- esperado: N`); se o `DELETE` informar outro número de linhas afetadas, `ROLLBACK` (nunca `COMMIT`);
- `DELETE FROM landing_lead WHERE (name LIKE 'LP teste%' OR name = 'R3 landing Ana') AND created_at >= '2026-10-01';`
- `-- depois`: o mesmo SELECT (esperado 0 linhas) e `SELECT COUNT(*) FROM landing_lead;` (esperado: antes − N);
- seção final só de comentários sobre Redis: `centralvet:session:deadbeef` já expirou (EXISTS 0 em 2026-10-05); contadores `centralvet:lead-throttle:<sha256('lead|<ip>')>` expiram em ≤ 3600 s e tokens `centralvet:lead-token:*` em ≤ 7200 s; comandos de conferência só leitura (`redis-cli --scan --pattern 'centralvet:lead-throttle:*'`, `redis-cli TTL <chave>`); nenhum `DEL`.

**Arquivos prováveis**
- `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql`

**Interface**
- Produz: `DELETE FROM landing_lead WHERE (name LIKE 'LP teste%' OR name = 'R3 landing Ana') AND created_at >= '2026-10-01';` em `sql/T-09-cleanup-landing-lead.sql`, executado só pelo orquestrador com aprovação SQL
- Consome: nada

**Teste RED**
- sem teste: SQL preparado e não executado; conferido por grep e pelos SELECTs só leitura

**Critério de aceite**
- O arquivo tem 1 `DELETE FROM landing_lead WHERE` e nenhum `TRUNCATE`, `DROP`, `UPDATE` nem `DEL `; o SELECT `-- antes` rodado só leitura em `centralvet` lista as linhas `R3 landing Ana`, `LP teste Ana`, `LP teste Replay` (e os `LP teste` dos gates) e nenhuma outra; `SELECT COUNT(*) FROM landing_lead` não muda pela task.

**Validação**
- `grep -c 'DELETE FROM landing_lead WHERE' <DIR>/sql/T-09-cleanup-landing-lead.sql` (evidência: `1`) e `grep -ciE 'truncate|drop |update |^del ' <DIR>/sql/T-09-cleanup-landing-lead.sql` (evidência: `0`)
- `docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" centralvet -N -e "SELECT id, name FROM landing_lead WHERE (name LIKE '"'"'LP teste%'"'"' OR name = '"'"'R3 landing Ana'"'"') AND created_at >= '"'"'2026-10-01'"'"' ORDER BY id; SELECT COUNT(*) FROM landing_lead"'` (evidência: ids 1, 2, 3 com os nomes de teste e o COUNT igual ao total listado — sem lead real fora do predicado)

### T-10 — Validação final

**Camada:** qa
**Dependências:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09
**Paralelizável:** não
**Complexidade:** média
**Agente:** Spock

Depois do rebuild + restart do nginx pelo orquestrador: SUITE uma vez (árvore parada), LINT de todo PHP tocado na rodada, NGINX-T, os `curl` de T-02, T-03 e T-04, o gate Playwright de T-06 em pt, en e es (contexto anônimo), o CSV de T-08 com a sessão admin, as contagens e o escopo. Envios de lead com nome `LP teste`. Grava `reports/T-10.md`.

**Arquivos prováveis**
- `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/reports/T-10.md`

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: validação final, sem código

**Critério de aceite**
- `reports/T-10.md` registra: SUITE com `Failed: 0` e `Total` ≥ o da BASE da Onda 1 + testes novos; `No syntax errors detected` em cada PHP tocado; NGINX-T com as 2 linhas da baseline; os valores de evidência das Validações de T-02, T-03, T-04, T-06 e T-08; `git diff --stat 939b541..HEAD` sem caminhos proibidos (§ Critérios gerais); tabela `Tela | Fluxos | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` com 0 erros de console e só o 422 provocado na rede.

**Validação**
- SUITE (evidência: `Total:` e `Failed: 0`)
- LINT dos PHP de `git -C /var/www/html/centralvet diff --name-only 939b541..HEAD -- '*.php'` (evidência: `No syntax errors detected` em cada um)
- `docker compose exec -T nginx nginx -t` (evidência: iguais a `baseline/nginx-t.txt`)
- `git -C /var/www/html/centralvet diff --stat 939b541..HEAD -- src/lib/adianti src/app/config/framework_hashes.php src/index.php src/engine.php src/init.php src/app/templates src/app/database/migrations` (evidência: saída vazia)
- `SELECT COUNT(*)` de `tenant`, `patient`, `tutor`, `system_program`, `system_group_program` e `landing_lead` em `centralvet` (evidência: os 5 primeiros iguais à BASE da Onda 1; `landing_lead` = BASE + envios `LP teste` do gate)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
