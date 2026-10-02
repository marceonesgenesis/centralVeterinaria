# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | backend | Contrato da landing: catálogo de planos e preços, contrato HTTP, LeadSubmission validado e LeadStoreInterface | — | sim | média | Platão | [x] |
| T-02 | database | Migration 0009 `landing_lead` + verify, runbook, centralvet_test e SQL do programa admin | — | sim | média | Darwin | [x] |
| T-03 | backend | Token de formulário de uso único em Redis e FakeRedis com incr/expire/ttl/del | — | sim | simples | Arquimedes | [x] |
| T-04 | backend | LeadRepository PDO (insert, search, count) com integração no centralvet_test | T-01, T-02 | sim | média | Darwin | [x] |
| T-05 | backend | Endpoint público POST /lead.php: handler com limite, token, honeypot, origem e JSON | T-01, T-03 | sim | alta | Jaspion | [x] |
| T-06 | frontend | Landing em / (landing.php, template, CSS e JS sem inline) e decisão sessão/anônimo | T-01, T-03 | sim | alta | Tesla | [x] |
| T-07 | infra | nginx: / sem query para landing.php, CSP e headers da landing, assets e endpoint | T-01 | sim | média | Yoda | [x] |
| T-08 | frontend | Tela admin LandingLeadList com filtro, paginação e CSV (CsvCell, LeadCsvExport) | T-01, T-02, T-04 | não | média | Aang | [x] |
| T-09 | qa | Validação final: suíte, lint, nginx, curl, gate anônimo e admin, contagens | T-04, T-05, T-06, T-07, T-08 | não | média | Spock | [ ] |

## Detalhamento

### T-01 — Contrato da landing: catálogo, contrato HTTP, LeadSubmission e LeadStoreInterface

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

Fonte única de preço e texto de plano: nada de preço fora de `LandingCatalog` (o JS, o endpoint e a tela leem dele). Copie planos, itens (com a marca "em breve") e o comparativo de `reference/landing-draft.html:496-523`, as opções de veterinários de `:588`, o texto do consentimento de `:591` e as mensagens de `:631-637`. Core sem Adianti (`CoreLayerDependencyTest`).

Regras de `LeadSubmission::fromPayload` (todo campo `trim`; tipo diferente de string — ou de `bool` em `consent` — é inválido; comprimento com `mb_strlen`; acima do máximo é erro, nunca truncamento):
- `name` 3–120, sem `<`, `>` nem caractere de controle → `Informe seu nome.`
- `clinic` 2–160, mesma regra → `Informe o nome da clínica.`
- `email` `FILTER_VALIDATE_EMAIL` e ≤ 160 → `Informe um e-mail válido.`
- `phone` só os dígitos, 10–13 → `Informe o WhatsApp com DDD.` (o valor guardado é só dígitos)
- `vets` chave de `VETS_OPTIONS` → `Escolha o número de veterinários.`
- `city` 0–80, sem `<`, `>` nem controle → `Informe a cidade com até 80 caracteres, sem < ou >.`
- `uf` vazio ou um de `UFS` → `Escolha uma UF válida.`
- `plan` id de `LandingCatalog::plan()` → `Escolha um plano.`
- `consent` `=== true` → `Marque a autorização de contato para enviar.`
Todos os erros juntos numa só `LeadValidationException`.

**Arquivos prováveis**
- `src/app/Core/Landing/LandingCatalog.php`
- `src/app/Core/Landing/LeadEndpoint.php`
- `src/app/Core/Landing/LeadSubmission.php`
- `src/app/Core/Landing/LeadValidationException.php`
- `src/app/Core/Landing/Contract/LeadStoreInterface.php`
- `src/tests/Unit/LandingCatalogTest.php`

**Interface**
- Produz: `final class CentralVet\Landing\LandingCatalog` com `LandingCatalog::plans(): array` (lista na ordem starter, pro, business, enterprise; cada item `['id' => string, 'name' => string, 'price_cents' => int, 'for_who' => string, 'featured' => bool, 'items' => list<array{label: string, soon: bool}>]`; `price_cents` 5700, 9700, 13700, 19700; `featured` só no `pro`), `LandingCatalog::plan(string $id): ?array` (mesmo formato), `LandingCatalog::compareRows(): array` (11 linhas `['label' => string, 'plans' => array<string, bool>, 'soon' => bool]`), `LandingCatalog::publicJson(): string` (objeto com as chaves `plans`, `compare`, `vets`, `ufs`, `consent`, codificado com `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`), `LandingCatalog::CONSENT_VERSION = 'lgpd-contato-2026-10'`, `LandingCatalog::CONSENT_TEXT = 'Concordo que a equipe Central Vet Pro entre em contato sobre a assinatura, conforme a LGPD.'`, `LandingCatalog::VETS_OPTIONS = ['1' => 'Só eu', '2-4' => '2 a 4', '5-10' => '5 a 10', '11+' => 'Mais de 10']`, `LandingCatalog::UFS` (as 27 siglas, em ordem alfabética)
- Produz: `final class CentralVet\Landing\LeadEndpoint` com `PATH = '/lead.php'`, `TOKEN_HEADER = 'X-CV-Lead-Token'`, `MAX_BODY_BYTES = 8192`, `HONEYPOT_FIELD = 'website'`, `FIELDS = ['name', 'clinic', 'email', 'phone', 'vets', 'city', 'uf', 'plan', 'consent', 'website']`; respostas JSON do endpoint: `201 {"accepted":true}`, `200 {"accepted":true}` (honeypot), `400 {"accepted":false,"error":"invalid_request"}`, `403 {"accepted":false,"error":"invalid_token"}`, `403 {"accepted":false,"error":"forbidden_origin"}`, `405 {"accepted":false,"error":"method_not_allowed"}`, `413 {"accepted":false,"error":"payload_too_large"}`, `422 {"accepted":false,"error":"validation","fields":{"email":"Informe um e-mail válido."}}`, `429 {"accepted":false,"error":"rate_limited","retry_after":60}`, `503 {"accepted":false,"error":"unavailable"}`
- Produz: `final class CentralVet\Landing\LeadSubmission` com `LeadSubmission::fromPayload(array $payload): self` (lança `LeadValidationException`) e propriedades `public readonly` `string $name`, `string $clinic`, `string $email`, `string $phone`, `string $vets`, `string $city`, `string $uf`, `string $planId`, `string $planName`, `int $planPriceCents`, `string $consentVersion` (preço, nome do plano e versão vêm do catálogo, nunca do payload)
- Produz: `final class CentralVet\Landing\LeadValidationException extends \DomainException` com `errors(): array` (campo do payload → mensagem pt das regras acima)
- Produz: `interface CentralVet\Landing\Contract\LeadStoreInterface` com `LeadStoreInterface::insert(LeadSubmission $lead, string $consentIp, \DateTimeImmutable $consentAt): int` (id gravado)
- Consome: nada

**Teste RED**
- `src/tests/Unit/LandingCatalogTest.php` — falha porque `LandingCatalog` e `LeadSubmission` não existem: `plan('pro')['price_cents']` = 9700 e os ids `starter, pro, business, enterprise` (`landing-draft.html:496-505`); `publicJson()` sem `<` cru; payload válido com `phone` `(11) 91234-5678` → `phone` `11912345678`, `planPriceCents` 9700 e `consentVersion` `lgpd-contato-2026-10`; `consent` false → `errors()['consent']` = `Marque a autorização de contato para enviar.` (`:637`); `name` `<b>Ana</b>`, `email` `ana@`, `plan` `gold`, `uf` `XX` e `name` de 121 caracteres → erro nas chaves `name`, `email`, `plan`, `uf` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LandingCatalogTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LandingCatalogTest::` para cada método, `Failed: 0`, e `grep -rn "5700\|9700\|13700\|19700" src/app/Core` só encontra `src/app/Core/Landing/LandingCatalog.php`.

**Validação**
- SUITE com `| grep -E 'LandingCatalogTest|CoreLayerDependencyTest|Failed:'` (evidência: linhas `PASS` de `LandingCatalogTest` e `CoreLayerDependencyTest`, `Failed: 0`)
- LINT dos 5 arquivos de `src/app/Core/Landing` (evidência: `No syntax errors detected` em cada um)
- `grep -rn "5700\|9700\|13700\|19700" /var/www/html/centralvet/src/app/Core` (evidência: só `LandingCatalog.php`)

### T-02 — Migration 0009 `landing_lead`, verify, runbook, centralvet_test e SQL do programa

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

Siga o cabeçalho de `20260930_0008_queue_entry_appointment_unique.sql` (Migration, Status PREPARED ONLY, Target after 0008, Effects, Data, Risk, Rollback, aviso de DDL não transacional, checksum de zeros no `INSERT INTO schema_migrations`). Não execute SQL algum: a aplicação é do orquestrador no bloqueio entre as ondas 1 e 2.

**Arquivos prováveis**
- `src/app/database/migrations/20261001_0009_landing_lead.sql`
- `src/app/database/migrations/20261001_0009_landing_lead.verify.sql`
- `scripts/test-db/provision.sh`
- `docs/runbooks/tests.md`
- `docs/runbooks/landing-leads.md`
- `.claude/tasks/mar-20261001-2231-landing-publica-carrinho/sql/T-02-programs.sql`

**Interface**
- Produz: tabela `landing_lead` (`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`, sem `tenant_id`) com `id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY`, `name varchar(120) NOT NULL`, `clinic_name varchar(160) NOT NULL`, `email varchar(160) NOT NULL`, `phone varchar(13) NOT NULL`, `vets_range varchar(8) NOT NULL`, `city varchar(80) NOT NULL DEFAULT ''`, `uf char(2) NOT NULL DEFAULT ''`, `plan_id varchar(20) NOT NULL`, `plan_name varchar(40) NOT NULL`, `plan_price_cents int unsigned NOT NULL`, `consent_version varchar(40) NOT NULL`, `consent_at timestamp(6) NOT NULL`, `consent_ip varchar(45) NOT NULL`, `created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`; chaves `landing_lead_created_idx (created_at)`, `landing_lead_plan_idx (plan_id, created_at)`; checks `landing_lead_price_ck CHECK (plan_price_cents > 0)`, `landing_lead_vets_ck CHECK (vets_range IN ('1','2-4','5-10','11+'))`; linha `schema_migrations.version = '20261001_0009_landing_lead'`
- Produz: programa `LandingLeadList` (`system_program.id = 109`, nome `Leads da landing`) e `system_group_program.id = 111` (grupo 1 → programa 109) em `sql/T-02-programs.sql`, com `START TRANSACTION`, os `SELECT MAX(id)` de guarda (esperado 108 e 110 em 2026-10-01; diferente → parar sem `COMMIT`), os 2 `INSERT`, `SELECT` de conferência e `COMMIT`
- Consome: nada

**Teste RED**
- sem teste: migration, SQL de permissão e documentação; a prova é estática nesta onda e o `.verify.sql` roda no bloqueio, com aprovação SQL

**Critério de aceite**
- A migration contém `CREATE TABLE landing_lead` com as 15 colunas da Interface e nenhuma `tenant_id`; o `.verify.sql` só tem `SELECT` e confere tabela, 15 colunas, os 2 índices, os 2 checks, a linha de `schema_migrations` e `COUNT(*)` de `tenant`, `patient`, `tutor` e `system_program` (para comparar com os valores anotados antes); `provision.sh` lista a 0009 logo depois da 0008 e `bash -n` sai com código 0; `docs/runbooks/tests.md` diz `0001`…`0009` e "9 linhas"; `docs/runbooks/landing-leads.md` tem as seções Aplicação, Verificação, Rollback, Programa admin e Operação (limite por IP, token, onde ver os leads).

**Validação**
- `grep -c "tenant_id" /var/www/html/centralvet/src/app/database/migrations/20261001_0009_landing_lead.sql` (evidência: `0`) e `grep -n "CREATE TABLE landing_lead\|landing_lead_plan_idx\|landing_lead_vets_ck\|20261001_0009_landing_lead" /var/www/html/centralvet/src/app/database/migrations/20261001_0009_landing_lead.sql` (evidência: as 4 linhas)
- `grep -Eiv "^\s*(--|$)" /var/www/html/centralvet/src/app/database/migrations/20261001_0009_landing_lead.verify.sql | grep -Eic "\b(insert|update|delete|alter|drop|create|grant|truncate)\b"` (evidência: `0`)
- `bash -n /var/www/html/centralvet/scripts/test-db/provision.sh && grep -n "0008\|0009" /var/www/html/centralvet/scripts/test-db/provision.sh` (evidência: código 0 e a 0009 na linha seguinte à 0008)
- `grep -n "0009" /var/www/html/centralvet/docs/runbooks/tests.md` e `grep -n "^## " /var/www/html/centralvet/docs/runbooks/landing-leads.md` (evidência: `0001`…`0009` e as 5 seções)
- `grep -n "MAX(id)\|INSERT INTO\|COMMIT" /var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/sql/T-02-programs.sql` (evidência: 2 guardas, 2 `INSERT` com ids 109 e 111, `COMMIT`)
- No bloqueio (orquestrador, depois da aprovação): `.verify.sql` em `centralvet` com `COUNT(*)` de `tenant`, `patient`, `tutor` iguais aos de antes e `system_program` +1 depois do SQL de programa (evidência em `notes.md § Bloqueios`)

### T-03 — Token de formulário de uso único e FakeRedis

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Arquimedes

A chave guarda `sha256` do token, nunca o token cru; o valor é o instante de emissão (segundos). `consume` usa `del()` e só devolve true quando `del()` retorna 1 (corrida de dois envios: um ganha). `FakeRedis` passa a lembrar o TTL do `set` com `['EX' => n]`/`['ex' => n]` e ganha `incr`, `expire`, `ttl` e `del` com a semântica do phpredis (`ttl` de chave inexistente = -2, sem TTL = -1).

**Arquivos prováveis**
- `src/app/Core/Landing/LeadFormToken.php`
- `src/tests/Support/FakeRedis.php`
- `src/tests/Unit/LeadFormTokenTest.php`

**Interface**
- Produz: `final class CentralVet\Landing\LeadFormToken` com `__construct(\Redis $redis, string $prefix = 'centralvet:lead-token:')`, `TTL_SECONDS = 7200`, `MIN_AGE_SECONDS = 3`, `issue(int $now): string` (64 caracteres hex; `set(prefix . hash('sha256', $token), (string) $now, ['EX' => 7200])`), `isUsable(string $token, int $now): bool` (formato `/^[0-9a-f]{64}$/`, chave existe e `3 <= $now - emissão <= 7200`), `consume(string $token): bool`
- Produz: `FakeRedis::incr($key)`, `FakeRedis::expire($key, $ttl)`, `FakeRedis::ttl($key)`, `FakeRedis::del(...$keys)`
- Consome: nada

**Teste RED**
- `src/tests/Unit/LeadFormTokenTest.php` — falha porque `LeadFormToken` e os métodos novos do `FakeRedis` não existem: token emitido em 1000 → `isUsable` false em 1002, true em 1003 e em 8200, false em 8201; `consume` true e depois false; `isUsable('abc', 1003)` false; o `FakeRedis` não guarda o token cru (só `centralvet:lead-token:` + sha256); `LoginRateLimiter(new FakeRedis(), 10, 3600, 'centralvet:lead-throttle:')` com 10 `hit('lead|203.0.113.7')` → `tooManyAttempts` true e `secondsUntilAvailable` 3600 (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LeadFormTokenTest|RedisLockTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LeadFormTokenTest::` em cada método e mantém `PASS` em `Unit\RedisLockTest::` (outro usuário do `FakeRedis`), com `Failed: 0`.

**Validação**
- SUITE com `| grep -E 'LeadFormTokenTest|RedisLockTest|Failed:'` (evidência: as linhas `PASS` e `Failed: 0`)
- LINT de `app/Core/Landing/LeadFormToken.php` e `tests/Support/FakeRedis.php` (evidência: `No syntax errors detected`)

### T-04 — LeadRepository PDO com integração no centralvet_test

**Camada:** backend
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

Só começa depois do bloqueio (0009 aplicada em `centralvet_test`; ver `notes.md § Bloqueios`). Repositório de plataforma: recebe só `PDO`, não usa `TenantContext`. `search`/`count` filtram por `plan_id` exato quando não nulo e por `created_at >= :from 00:00:00` e `created_at < :to + 1 dia` quando não nulos; ordem `id DESC`; `limit` limitado a 1..500. Datas gravadas no fuso de `APP_TIMEZONE` (o PDO grava a string `Y-m-d H:i:s.u` do `DateTimeImmutable` recebido).

**Arquivos prováveis**
- `src/app/Core/Persistence/LeadRepository.php`
- `src/tests/Integration/LeadRepositoryIntegrationTest.php`

**Interface**
- Produz: `final class CentralVet\Persistence\LeadRepository implements LeadStoreInterface` com `__construct(PDO $connection)`, `insert(LeadSubmission $lead, string $consentIp, \DateTimeImmutable $consentAt): int`, `search(?string $planId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit, int $offset): array` (lista de linhas associativas com as chaves `id`, `created_at`, `name`, `clinic_name`, `email`, `phone`, `vets_range`, `city`, `uf`, `plan_id`, `plan_name`, `plan_price_cents`, `consent_version`, `consent_at`, `consent_ip`; `id` e `plan_price_cents` como `int`), `count(?string $planId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int`
- Consome: T-01 `LeadStoreInterface::insert(LeadSubmission $lead, string $consentIp, \DateTimeImmutable $consentAt): int`, T-01 `LeadSubmission::fromPayload(array $payload): self`, T-02 `landing_lead`

**Teste RED**
- `src/tests/Integration/LeadRepositoryIntegrationTest.php` — falha porque `LeadRepository` não existe: grava um lead `pro` (`LP teste Ana`, IP `203.0.113.7`) e um `starter`; `search('pro', null, null, 50, 0)` devolve 1 linha com `plan_price_cents` 9700, `plan_name` `Pro`, `consent_version` `lgpd-contato-2026-10` e `consent_ip` `203.0.113.7`; `count(null, null, null)` cresce 2 em relação ao início do teste; `search(null, amanhã, amanhã, 50, 0)` vazio (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LeadRepositoryIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Integration\LeadRepositoryIntegrationTest::` em cada método (nenhum `SKIP`) e `Failed: 0`; `SELECT COUNT(*) FROM landing_lead` em `centralvet_test` é o mesmo antes e depois da SUITE (rollback).

**Validação**
- SUITE com `| grep -E 'LeadRepositoryIntegrationTest|Failed:'` (evidência: `PASS` em cada método, `Failed: 0`)
- `docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" centralvet_test -N -e "SELECT COUNT(*) FROM landing_lead"'` antes e depois da SUITE (evidência: o mesmo número)
- LINT de `app/Core/Persistence/LeadRepository.php` e do teste (evidência: `No syntax errors detected`)

### T-05 — Endpoint público POST /lead.php

**Camada:** backend
**Dependências:** T-01, T-03
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Jaspion

Ordem de `handle` (a primeira regra que falha responde):
1. método diferente de `POST` → 405 com header `Allow: POST`;
2. `strlen($body) > LeadEndpoint::MAX_BODY_BYTES` → 413;
3. `content-type` que não começa com `application/json` → 400 `invalid_request`;
4. `origin` presente com host:porta diferente do header `host`, ou `sec-fetch-site` presente e fora de `same-origin`/`none` → 403 `forbidden_origin`;
5. `tooManyAttempts('lead|' . $ip)` → 429 com `retry_after` = `secondsUntilAvailable` e header `Retry-After`; senão `hit('lead|' . $ip)`;
6. `x-cv-lead-token` ausente ou `isUsable` false → 403 `invalid_token`;
7. corpo que não decodifica em objeto JSON → 400 `invalid_request`;
8. `website` não vazio → `consume` do token e 200 `{"accepted":true}`, sem gravar;
9. `LeadSubmission::fromPayload` lança → 422 com `fields` = `errors()` (token não é consumido: o visitante corrige e reenvia);
10. `consume` false → 403 `invalid_token`; senão `insert($lead, $ip, $now)` → 201.
Exceção de `insert` → `error_log` com a classe e a mensagem e 503 `unavailable`; o corpo nunca leva mensagem de exceção. Todas as respostas: `Content-Type: application/json; charset=utf-8` e `Cache-Control: no-store`.
`src/lead.php` (sem `init.php`): `chdir(__DIR__)`, `vendor/autoload.php`, `config/environment.php` (fuso e PDO `utf8mb4` com `ERRMODE_EXCEPTION`), `RedisConnectionFactory::fromEnvironment()`, limitador `new LoginRateLimiter($redis, (int) (getenv('LEAD_RATE_LIMIT_MAX_ATTEMPTS') ?: 10), (int) (getenv('LEAD_RATE_LIMIT_DECAY_SECONDS') ?: 3600), 'centralvet:lead-throttle:')`, headers de `$_SERVER` em minúsculas, IP de `REMOTE_ADDR`, corpo lido de `php://input` com no máximo `MAX_BODY_BYTES + 1` bytes; qualquer `Throwable` fora do handler → 503 `unavailable` com `error_log`.

**Arquivos prováveis**
- `src/app/Core/Landing/LeadResponse.php`
- `src/app/Core/Landing/LeadSubmissionHandler.php`
- `src/lead.php`
- `src/tests/Support/FakeLeadStore.php`
- `src/tests/Unit/LeadSubmissionHandlerTest.php`
- `.env.example`

**Interface**
- Produz: `final class CentralVet\Landing\LeadSubmissionHandler` com `__construct(LoginRateLimiter $limiter, LeadFormToken $tokens, LeadStoreInterface $store)` e `handle(string $method, array $headers, string $body, string $ip, \DateTimeImmutable $now): LeadResponse` (`$headers` com nomes em minúsculas: `content-type`, `origin`, `host`, `sec-fetch-site`, `x-cv-lead-token`)
- Produz: `final class CentralVet\Landing\LeadResponse` com `public readonly int $status`, `public readonly array $body`, `public readonly array $headers`
- Produz: `POST /lead.php` (entrypoint `src/lead.php`), `.env.example` com `LEAD_RATE_LIMIT_MAX_ATTEMPTS=10` e `LEAD_RATE_LIMIT_DECAY_SECONDS=3600` comentados
- Consome: T-01 `LeadSubmission::fromPayload(array $payload): self`, T-01 `LeadStoreInterface::insert(LeadSubmission $lead, string $consentIp, \DateTimeImmutable $consentAt): int`, T-01 `MAX_BODY_BYTES = 8192`, T-01 `HONEYPOT_FIELD = 'website'`, T-01 `TOKEN_HEADER = 'X-CV-Lead-Token'`, T-03 `isUsable(string $token, int $now): bool`, T-03 `consume(string $token): bool`, T-03 `FakeRedis::incr($key)`

**Teste RED**
- `src/tests/Unit/LeadSubmissionHandlerTest.php`, `src/tests/Support/FakeLeadStore.php` — falha porque `LeadSubmissionHandler` não existe: com `FakeRedis`, token emitido 10 s antes e payload válido do plano `pro` → 201 e 1 gravação com `planPriceCents` 9700 e IP `203.0.113.7`; o mesmo token de novo → 403 `invalid_token`; `website` preenchido → 200 e 0 gravações; `GET` → 405 com `Allow: POST`; corpo de 8193 bytes → 413; `origin` `https://evil.example` → 403 `forbidden_origin`; 11º POST do mesmo IP → 429 com `Retry-After`; `email` inválido → 422 com `fields.email` e, depois, o mesmo token com e-mail válido → 201; store que lança `RuntimeException('SQLSTATE[HY000] segredo')` → 503 e `json_encode($response->body)` sem `SQLSTATE` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LeadSubmissionHandlerTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LeadSubmissionHandlerTest::` em cada método e `Failed: 0`; LINT de `src/lead.php` imprime `No syntax errors detected`; depois do rebuild, `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8081/lead.php` (GET) imprime `405` e um POST sem token imprime `403` com corpo `{"accepted":false,"error":"invalid_token"}`.

**Validação**
- SUITE com `| grep -E 'LeadSubmissionHandlerTest|Failed:'` (evidência: `PASS` em cada método, `Failed: 0`)
- LINT de `lead.php`, `app/Core/Landing/LeadSubmissionHandler.php`, `app/Core/Landing/LeadResponse.php` e dos 2 arquivos de teste (evidência: `No syntax errors detected`)
- Gate (validador, depois do rebuild): `curl -s -i http://127.0.0.1:8081/lead.php` → `405` e `Allow: POST`; `curl -s -i -X POST -H 'Content-Type: application/json' -d '{}' http://127.0.0.1:8081/lead.php` → `403` e `invalid_token`; envio com token reaproveitado (Review Focus) → segundo POST `403` e `SELECT COUNT(*) FROM landing_lead WHERE name LIKE 'LP teste%'` em `centralvet` +1 só

### T-06 — Landing em / e decisão sessão/anônimo

**Camada:** frontend
**Dependências:** T-01, T-03
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

Porte `reference/landing-draft.html` (hero com a ficha de exemplo, faixa do fluxo, recursos por módulo Disponível/Em breve, planos, comparativo, FAQ, carrinho lateral de um plano com `localStorage` `cvp-cart-plan`, formulário de lead) para: template sem `<style>`, sem `style=`, sem `on*=` e sem script executável inline; CSS em `src/landing/landing.css`; JS em `src/landing/landing.js`, que lê planos, comparativo, opções de veterinários, UFs e texto do consentimento de `#cv-landing-data` (sem cópia de preço no JS ou no HTML) e o token de `<meta name="cv-lead-token">`. O envio troca o `claude.use("db")` do rascunho (`:527`, `:650`) por `fetch('/lead.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CV-Lead-Token': token}, body: JSON.stringify(payload)})` com as chaves de `LeadEndpoint::FIELDS` (`website` vem do honeypot: input fora da tela por classe CSS, `tabindex="-1"`, `autocomplete="off"`, `aria-hidden="true"`); 201/200 → tela de sucesso do rascunho; 422 → mensagem de `fields` no `-err` de cada campo; 429 → "Muitos envios a partir desta rede. Tente de novo em alguns minutos."; 403 → "Sua sessão do formulário expirou. Recarregue a página e envie de novo."; outro → mensagem genérica do rascunho. Cabeçalho ganha "Entrar" → `/index.php?class=LoginForm`. Fontes: o mesmo `<link>` do Google Fonts do rascunho (`:2-4`).
`src/landing.php`: `chdir(__DIR__)`, `init.php`; abre sessão só se `isset($_COOKIE[session_name()])`, com `new TSession(\CentralVet\Session\SessionHandlerFactory::createFromEnvironment())` e `logged = (bool) TSession::getValue('logged')`, depois `session_write_close()`; `entryFor` → `system`: `302 Location: /index.php`; `method_not_allowed`: 405 `Allow: GET, HEAD`; `landing`: token por `LeadFormToken::issue(time())` (Redis fora → `error_log` e token vazio, página continua 200), `Content-Type: text/html; charset=utf-8`, `Cache-Control: no-store`, corpo de `render()` (sem corpo em `HEAD`).

**Arquivos prováveis**
- `src/app/Core/Landing/LandingPage.php`
- `src/landing.php`
- `src/app/view/landing/landing.html`
- `src/landing/landing.css`
- `src/landing/landing.js`
- `src/tests/Unit/LandingPageTest.php`

**Interface**
- Produz: `final class CentralVet\Landing\LandingPage` com `entryFor(string $method, string $queryString, bool $logged): string` (`'landing'` para `GET`/`HEAD` sem query e sem sessão; `'system'` com sessão ou query não vazia; `'method_not_allowed'` para outro método) e `render(string $template, string $leadToken): string` (troca `{{LEAD_TOKEN}}` pelo token com `htmlspecialchars(ENT_QUOTES)` e `{{LANDING_DATA}}` por `LandingCatalog::publicJson()`)
- Produz: marcadores do template `<meta name="cv-lead-token" content="{{LEAD_TOKEN}}">`, `<script type="application/json" id="cv-landing-data">{{LANDING_DATA}}</script>`, `<link rel="stylesheet" href="/landing/landing.css">`, `<script src="/landing/landing.js" defer></script>` e `<a href="/index.php?class=LoginForm">Entrar</a>`
- Consome: T-01 `LandingCatalog::publicJson(): string`, T-01 `PATH = '/lead.php'`, T-01 `TOKEN_HEADER = 'X-CV-Lead-Token'`, T-01 `HONEYPOT_FIELD = 'website'`, T-03 `issue(int $now): string`

**Teste RED**
- `src/tests/Unit/LandingPageTest.php` — falha porque `LandingPage` e o template não existem: `entryFor('GET', '', false)` = `landing`, `('HEAD', '', false)` = `landing`, `('GET', '', true)` = `system`, `('GET', 'class=LoginForm', false)` = `system`, `('POST', '', false)` = `method_not_allowed`; `render()` do arquivo real `app/view/landing/landing.html` com token `t"<` contém `content="t&quot;&lt;"` e `"price_cents":9700` e não contém `{{`; o template não tem `<style`, ` style=`, atributo `on[a-z]+=` nem `<script>` sem `src` além do `application/json`, e tem `/index.php?class=LoginForm`; `landing/landing.js` não contém `claude.` nem `9700`/`R$ 97` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LandingPageTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LandingPageTest::` em cada método e `Failed: 0`; depois do rebuild e do T-07, `curl -s http://127.0.0.1:8081/` sem cookie devolve 200 com `cv-lead-token` de 64 caracteres hex e o Playwright anônimo mostra os 4 planos com R$ 57, R$ 97, R$ 137 e R$ 197, abre o carrinho e envia o lead `LP teste` com a tela de sucesso; com a sessão admin, `curl`/navegador em `/` recebe `302` → `/index.php` com o layout do sistema.

**Validação**
- SUITE com `| grep -E 'LandingPageTest|CoreLayerDependencyTest|Failed:'` (evidência: `PASS` em cada método, `Failed: 0`)
- LINT de `landing.php` e `app/Core/Landing/LandingPage.php` (evidência: `No syntax errors detected`)
- Gate (validador): `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8081/` → `200` e `curl -s http://127.0.0.1:8081/ | grep -oE 'cv-lead-token" content="[0-9a-f]{64}"'` → 1 linha; `curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b 'PHPSESSID=<cookie da sessão admin>' http://127.0.0.1:8081/` → `302 http://127.0.0.1:8081/index.php`; cookie de sessão inexistente (`-b 'PHPSESSID=deadbeef'`, nome de `session_name()`) → `200` com a landing (Review Focus); Redis inacessível sem parar o serviço: `docker compose run --rm --no-deps -T -e REDIS_PORT=1 app php -r '$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["QUERY_STRING"] = ""; require "landing.php";' | grep -cE 'cv-lead-token|Fatal|Stack trace'` → `1` (só o meta, sem `Fatal`/`Stack trace`) (Review Focus)
- Playwright anônimo: snapshot com os 4 preços, carrinho aberto, envio `LP teste` → sucesso; 0 erros de console, 0 respostas ≥ 400

### T-07 — nginx: rota de / e CSP da landing

**Camada:** infra
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

Mudanças em `docker/nginx/default.conf` (o resto fica igual):
- `location = / { if ($args = "") { rewrite ^ /landing.php last; } rewrite ^ /index.php last; }` (o `rewrite` preserva a query string);
- `location = /landing.php` e `location = /lead.php`: cópia do bloco `fastcgi` de `location ~ \.php$` (inclusive `fastcgi_hide_header X-Content-Type-Options`), os 3 headers do `server` repetidos (`add_header` na `location` anula a herança), `Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"` e a CSP abaixo, todos com `always`; em `/lead.php` também `client_max_body_size 16k`;
- `location ^~ /landing/`: só `css|js|svg|png|webp|woff2?` com `try_files $uri =404`, o resto `return 404`; mesmos headers e a CSP da página;
- CSP da página e dos assets: `default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'`;
- CSP do endpoint: `default-src 'none'; frame-ancestors 'none'; base-uri 'none'`.
`/app/view/` já é 404 pela regra existente (:39): não mexer. O restart do nginx é do orquestrador.

**Arquivos prováveis**
- `docker/nginx/default.conf`

**Interface**
- Produz: `location = /` (sem query → `/landing.php`; com query → `/index.php`), `location = /landing.php`, `location = /lead.php`, `location ^~ /landing/` e o header `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'`
- Consome: T-01 `PATH = '/lead.php'`

**Teste RED**
- sem teste: configuração do nginx, fora do `tests/run.php`; a prova é `nginx -t` nesta task e a matriz de `curl -sI` no gate

**Critério de aceite**
- NGINX-T imprime as 2 linhas de `baseline/nginx-t.txt`; depois do restart, `curl -sI http://127.0.0.1:8081/` traz `Content-Security-Policy` com `script-src 'self'` e `X-Content-Type-Options: nosniff` uma vez só; `curl -sI 'http://127.0.0.1:8081/?class=LoginForm'` e `curl -sI http://127.0.0.1:8081/index.php` vêm sem `Content-Security-Policy`; `/live` → 200; `/landing/landing.css` → 200 com a CSP; `/landing/nada.php` e `/app/view/landing/landing.html` → 404; `/lead.php` traz `default-src 'none'`.

**Validação**
- `docker compose exec -T nginx nginx -t` (evidência: as 2 linhas de `baseline/nginx-t.txt`)
- Gate (validador, depois do restart): `for u in / '/?class=LoginForm' /index.php /live /landing/landing.css /landing/landing.js /landing/nada.php /app/view/landing/landing.html /lead.php; do printf '%s ' "$u"; curl -s -o /dev/null -D - "http://127.0.0.1:8081$u" | grep -iE '^HTTP|content-security|x-content-type|x-frame' | tr '\r\n' '  '; echo; done` (evidência: a matriz do critério)
- Playwright anônimo em `/`: `browser_console_messages` sem `Content Security Policy` e fontes `fonts.gstatic.com` com status 200 em `browser_network_requests` (Review Focus)

### T-08 — Tela admin LandingLeadList com filtro, paginação e CSV

**Camada:** frontend
**Dependências:** T-01, T-02, T-04
**Paralelizável:** não
**Complexidade:** média
**Agente:** Aang

Controller em `app/control/admin` seguindo `FinancialEntryList` (filtro + `TTransaction::open('permission')` + repositório com `TTransaction::get()`) e `FinancialOverview::onExport` (estático, `static=1`). Filtros: plano (`TCombo` de `LandingCatalog::plans()`, vazio = todos), de/até (`TDate`, `d/m/Y`); grade com Data, Nome, Clínica, E-mail, WhatsApp, Veterinários, Cidade/UF, Plano, Preço mensal; 20 por página com `TPageNavigation`; todo texto do lead escapado com `CvFormat::e`. Catches no padrão `error_log(__METHOD__ . ': ' . $e->getMessage()); new TMessage('error', CvFormat::userError($e));` (trava `ControllerRawExceptionMessageTest`). CSV: BOM UTF-8, `fputcsv($out, ..., ';', '"', '')`, nome `leads-landing-YYYYMMDD.csv`, mesmos filtros da tela, até 5000 linhas. Menu: item em Administration depois de "Users", `<menuitem label='_t{Landing leads}'>` com ícone `fas:bullhorn fa-fw` e `<action>LandingLeadList</action>`. `translations.json`: só as chaves novas que a tela usa (`Landing leads` → `Leads da landing` e as que faltarem), na ordem casefold, JSON válido.

**Arquivos prováveis**
- `src/app/Core/Support/CsvCell.php`
- `src/app/Core/Landing/LeadCsvExport.php`
- `src/tests/Unit/LeadCsvExportTest.php`
- `src/app/control/admin/LandingLeadList.php`
- `src/menu.xml`
- `src/app/config/translations.json`

**Interface**
- Produz: `final class CentralVet\Support\CsvCell` com `safe(string $value): string` (prefixa `'` quando o primeiro caractere é `=`, `+`, `-`, `@`, tab ou CR)
- Produz: `final class CentralVet\Landing\LeadCsvExport` com `header(): array` (`['Data', 'Nome', 'Clínica', 'E-mail', 'WhatsApp', 'Veterinários', 'Cidade', 'UF', 'Plano', 'Preço mensal (R$)', 'Consentimento em', 'IP do consentimento']`) e `row(array $lead): array` (linha de `search`; datas `d/m/Y H:i`, `vets_range` pelo rótulo de `VETS_OPTIONS`, preço `number_format($cents / 100, 2, ',', '.')`, `CsvCell::safe` em todo texto)
- Produz: `LandingLeadList` com `onExport` em `engine.php?class=LandingLeadList&method=onExport&static=1&plan_id=<id>&from=<Y-m-d>&to=<Y-m-d>`
- Consome: T-04 `search(?string $planId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit, int $offset): array`, T-04 `count(?string $planId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int`, T-01 `LandingCatalog::plans(): array`, T-01 `LandingCatalog::VETS_OPTIONS`, T-02 `LandingLeadList`

**Teste RED**
- `src/tests/Unit/LeadCsvExportTest.php` — falha porque `CsvCell` e `LeadCsvExport` não existem: `CsvCell::safe('=HYPERLINK("x")')` = `'=HYPERLINK("x")` com apóstrofo, `safe('-1')` = `'-1`, `safe('')` = `''`, `safe('Ana')` = `Ana`; `row()` de uma linha com `name` `=cmd`, `plan_price_cents` 13700 e `vets_range` `2-4` → `'=cmd`, `137,00` e `2 a 4`; `header()` com 12 colunas e `Preço mensal (R$)` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | grep -E 'LeadCsvExportTest|ControllerRawExceptionMessageTest|Failed:'`)

**Critério de aceite**
- A SUITE imprime `PASS  Unit\LeadCsvExportTest::` em cada método e `PASS` em `Unit\ControllerRawExceptionMessageTest::`, com `Failed: 0`; no gate admin, Administration → "Leads da landing" abre a grade com o lead `LP teste` de T-06, o filtro por plano `Pro` e por data de hoje mostra só ele, e "Exportar CSV" baixa um arquivo cuja primeira linha é o `header()` e cuja linha do lead tem `97,00`.

**Validação**
- SUITE com `| grep -E 'LeadCsvExportTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT de `app/control/admin/LandingLeadList.php`, `app/Core/Support/CsvCell.php`, `app/Core/Landing/LeadCsvExport.php` e do teste (evidência: `No syntax errors detected`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'json_decode(file_get_contents("app/config/translations.json"), true, 512, JSON_THROW_ON_ERROR); echo "json valido\n";'` e `xmllint --noout /var/www/html/centralvet/src/menu.xml` (evidência: `json valido` e nenhuma saída do xmllint)
- Gate admin (validador): lead `LP teste` com clínica `Patas & Cia "LP teste"` e e-mail `ana+lp@exemplo.com.br` enviado pela landing → na grade o texto aparece literal (snapshot), 0 dialogs; CSV aberto com `file`/`head -c 3 | xxd` mostra BOM `efbbbf` e `;` (Review Focus)

### T-09 — Validação final da landing

**Camada:** qa
**Dependências:** T-04, T-05, T-06, T-07, T-08
**Paralelizável:** não
**Complexidade:** média
**Agente:** Spock

Validador da onda 4, depois do rebuild e do restart do nginx pelo orquestrador. Grava `reports/T-09.md` com cada comando e o trecho da saída.

**Arquivos prováveis**
- `.claude/tasks/mar-20261001-2231-landing-publica-carrinho/reports/T-09.md`

**Interface**
- Produz: `reports/T-09.md` com as seções Suíte, Lint, nginx, curl, Navegador anônimo, Navegador admin, Dados e Escopo
- Consome: T-07 `location = /landing.php`, T-05 `POST /lead.php`, T-08 `LandingLeadList`

**Teste RED**
- sem teste: task de validação; não produz código

**Critério de aceite**
- `reports/T-09.md` registra: SUITE com `Failed: 0` e `Total` ≥ BASE da onda 1 + os testes novos; LINT `No syntax errors detected` em todo PHP tocado (0 linhas novas sobre `baseline/php-lint.txt`); NGINX-T com as 2 linhas; a matriz de `curl` de T-07 e os códigos de T-05; a tabela `Tela | Fluxos | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` com 0/0/nenhum para Landing anônima, Carrinho, Envio de lead, Entrar → LoginForm, `/` logado e LandingLeadList (filtro e CSV); `COUNT(*)` de `tenant`, `patient`, `tutor` iguais à BASE e `system_program` +1; `git -C /var/www/html/centralvet diff --stat cb4b613..HEAD -- src/lib/adianti src/index.php src/engine.php src/init.php src/app/templates src/app/config/framework_hashes.php` vazio.

**Validação**
- SUITE completa (evidência: `Failed: 0` e `Total`)
- LINT de todo `.php` do `git -C /var/www/html/centralvet diff --name-only cb4b613..HEAD` (evidência: `No syntax errors detected` em cada um)
- `docker compose exec -T nginx nginx -t` e a matriz de `curl` de T-07 (evidência: 2 linhas e a matriz do critério de T-07)
- Playwright anônimo e admin dos critérios de T-06, T-07 e T-08, com os 5 itens de `plan.md § Review Focus` (evidência: a tabela e os snapshots citados)
- `SELECT COUNT(*)` de `tenant`, `patient`, `tutor`, `system_program`, `landing_lead` em `centralvet` (evidência: comparação com a BASE da onda 1 anotada em `notes.md`)
- `git -C /var/www/html/centralvet diff --stat cb4b613..HEAD -- src/lib/adianti src/index.php src/engine.php src/init.php src/app/templates src/app/config/framework_hashes.php` (evidência: saída vazia)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
