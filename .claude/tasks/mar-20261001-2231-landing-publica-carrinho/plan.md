# Plano: Landing pública com carrinho de planos e captura de leads

## Objetivo
Servir em `/`, para visitante sem sessão, a landing do Central Vet Pro (base: `reference/landing-draft.html`), com recursos por módulo, 4 planos, comparativo, FAQ, carrinho de um plano e formulário de lead gravado no MySQL por um endpoint PHP público e protegido. Usuário logado continua indo ao sistema; administrador da plataforma lista e exporta os leads atrás do login.

## Premissas
- Branch de trabalho `task/landing-publica-carrinho`, criada pelo orquestrador a partir do HEAD de `feat/rodada-3-divida-tecnica` (`cb4b613`), antes da onda 1. Checkout compartilhado, caminho exclusivo, RED antes da implementação, trailers `Task: T-xx` / `Task: T-xx (RED)`.
- Preços mensais definidos pelo usuário: Starter R$ 57, Pro R$ 97, Business R$ 137, Enterprise R$ 197; sem desconto anual, trial ou add-ons. O conteúdo Disponível/Em breve do rascunho já foi conferido contra o código e o PRD e é copiado como está.
- O lead é pré-venda do operador da plataforma: tabela **sem** `tenant_id` (decisão em § Decisões de arquitetura e `notes.md`).
- Mesmas convenções e ambiente das rodadas 2 e 3 (`.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/notes.md`), comandos rodados de `/var/www/html/centralvet`:
  - **LINT** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>`;
  - **SUITE** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`. Não filtra por arquivo: leia as linhas `PASS/FAIL  <Suite>\<Classe>::` (use `| grep <Classe>`) e o resumo `Failed:`. Roda no `centralvet_test` (grava e faz rollback); não interromper no meio;
  - **NGINX-T** = `docker compose exec -T nginx nginx -t` (o `default.conf` é bind-mount `:ro` no container nginx: a edição é vista na hora pelo `-t`, mas só vale depois do restart);
  - **GATE**: o orquestrador reconstrói (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`) — o PHP vai copiado na imagem (`docker/php/Dockerfile:64`, autoload `--classmap-authoritative`), os estáticos de `src/` o nginx serve do bind-mount. Só então o validador usa `curl` e o Playwright MCP, **sempre** em `http://127.0.0.1:8081` (nunca `localhost`): primeiro anônimo (contexto sem cookie), depois com a sessão admin logada pelo orquestrador. A credencial não fica gravada.
- Controllers Adianti não são carregados pela suíte: a regra testável vive em `src/app/Core/Landing` (sem Adianti, travado por `CoreLayerDependencyTest`), e os entrypoints (`landing.php`, `lead.php`) e a tela ficam finos, provados por `php -l`, `curl` e gate.
- **Bloqueio entre as ondas 1 e 2** (aprovação SQL do usuário, skill `sql-write-approval`, `docs/runbooks/migrations.md`): o orquestrador mostra objetos, efeito e risco; roda `./scripts/backup.sh` + `gzip -t`; `sha256sum` da 0009 e troca do checksum de zeros; aplica `20261001_0009_landing_lead.sql` em `centralvet` com o usuário de migration e roda o `.verify.sql`; aplica `sql/T-02-programs.sql` em `centralvet`; com aprovação própria, aplica a 0009 em `centralvet_test`; registra tudo em `notes.md § Bloqueios`. Sem aprovação, T-04 e T-08 ficam `[!]` (a tabela não existe) e T-05, T-06, T-07 seguem.
- Registros criados nos gates levam o prefixo `LP teste` no nome. Rebuild, restart do nginx e login admin no Playwright ficam com o orquestrador.

## Escopo

### Incluso
- Contrato único de planos, preços (centavos), comparativo, opções do formulário, texto e versão do consentimento LGPD e contrato HTTP do endpoint (`LandingCatalog`, `LeadEndpoint`), e o value object validado do lead (`LeadSubmission`) com a interface de gravação → T-01.
- Migration `0009` (`landing_lead`, sem tenant) com `.verify.sql`, runbook `docs/runbooks/landing-leads.md`, `provision.sh`/`tests.md` do `centralvet_test` e o SQL de registro do programa admin → T-02.
- Token de formulário de uso único em Redis, com idade mínima (anti-robô) e TTL, e as operações do `FakeRedis` que o limitador e o token usam → T-03.
- Repositório PDO dos leads (gravação com plano e preço do momento, consentimento com data/hora e IP; busca por plano e período) → T-04.
- Endpoint público `POST /lead.php`: validação no servidor, limite por IP (`LoginRateLimiter` com prefixo próprio), honeypot, token, checagem de origem, tamanho máximo do corpo, JSON sem erro interno → T-05.
- Landing em `/` (template + CSS + JS próprios, sem inline, dados do `LandingCatalog`), decisão sessão → sistema / anônimo → landing, botão "Entrar" para o `LoginForm` → T-06.
- nginx: `/` sem query string → `landing.php`; CSP e headers de segurança na landing, nos assets `/landing/` e no endpoint; nenhuma rota interna nova exposta → T-07.
- Tela interna `LandingLeadList` (só grupo 1 "Template - Admin"), filtro por plano e período, paginação e exportação CSV com `CsvCell::safe` → T-08 (programa registrado pelo SQL de T-02).
- Testes unit e de integração no `centralvet_test` (T-01, T-03, T-04, T-05, T-06, T-08) e gate de navegador anônimo e admin → T-09.

### Excluído
- Pagamento online (gateway), criação automática de conta/tenant a partir do lead, e-mail/WhatsApp de confirmação (Fase 7), desconto anual, trial e add-ons (pedido do usuário).
- `src/lib/adianti`, `src/app/config/framework_hashes.php` e todo arquivo listado nele (inclui `src/index.php`, `engine.php`, `init.php` e `app/templates/adminbs5`): o mecanismo escolhido não os edita.
- Fontes locais da Bricolage Grotesque / Instrument Sans / JetBrains Mono: a landing usa o Google Fonts, permitido pelo usuário, liberado na CSP só para `fonts.googleapis.com` e `fonts.gstatic.com` (proposta: auto-hospedar numa rodada futura, por LGPD — o Google recebe o IP do visitante).
- Retenção e expurgo de leads (LGPD: prazo e pedido de exclusão) e edição/status do lead na tela interna (proposta para a próxima rodada; hoje a tela só lista e exporta).
- Migrar `FinancialOverview::csvSafe` (privado) para o `CsvCell::safe` novo: fica como está, para não tocar tela fora do escopo (proposta registrada em `notes.md`).
- Variáveis `LEAD_RATE_LIMIT_*` no `docker-compose.yml`: o código usa os padrões (10 envios por IP por hora); só o `.env.example` documenta. IP real atrás de proxy (`X-Forwarded-For`) não é confiado: usa-se `REMOTE_ADDR`.
- Registro de outros grupos no programa `LandingLeadList` (só o grupo 1).

## Contexto técnico
- Camadas envolvidas: backend (`src/app/Core/Landing`, `src/app/Core/Persistence`, `src/app/Core/Support`, entrypoints `src/landing.php` e `src/lead.php`), frontend (`src/app/view/landing/landing.html`, `src/landing/*.css|js`, tela Adianti `src/app/control/admin/LandingLeadList.php`, `menu.xml`, `translations.json`), database (migration 0009, SQL de programa, `scripts/test-db/provision.sh`), infra (`docker/nginx/default.conf`), docs (`docs/runbooks/landing-leads.md`, `docs/runbooks/tests.md`), qa (`src/tests` Unit/Integration/Support, gate Playwright).
- Projeto/base analisada: `/var/www/html/centralvet` (repositório git único; `git -C /var/www/html/centralvet rev-parse --show-toplevel` = `/var/www/html/centralvet`), código em `src/`, base `feat/rodada-3-divida-tecnica` @ `cb4b613` (rodada 3 encerrada).
- Integrações: MySQL `centralvet` e `centralvet_test` (0001…0008 aplicadas), Redis (sessão no DB 0, limitador e token com prefixo `centralvet:`), Google Fonts (CSS/fontes, sem script).

## Baseline
- php-lint: `php -l` em todo `.php` de `app/control`, `app/lib/widget`, `app/Core`, `app/service`, `app/model`, `tests` e em `index.php`, `engine.php`, `init.php`, `download.php` (528 arquivos, via LINT com `sh -c` no container, só as linhas diferentes de `No syntax errors detected`) em raiz, no HEAD `cb4b613` → baseline/php-lint.txt (0 linhas). Sem erros prévios: "nenhum erro novo em relação a `baseline/php-lint.txt`" equivale a `No syntax errors detected` em cada arquivo tocado.
- nginx-t: `docker compose exec -T nginx nginx -t` em raiz → baseline/nginx-t.txt (2 linhas: `syntax is ok` e `test is successful`).

## Exploração read-only
- Caminhos relevantes:
  - `src/index.php` (health :6-11, `new TSession(SessionHandlerFactory::createFromEnvironment())` :22, `logged` :26, `login.html` :52, `loadPage('LoginForm')` :80) — listado em `framework_hashes.php`, não se edita;
  - `docker/nginx/default.conf` (headers do server :11-13, `location /` :15-17, bloqueios :39-49, `\.php$` :51-62 com `fastcgi_hide_header X-Content-Type-Options`); nginx monta `./src:ro` e o conf `:ro` (`docker-compose.yml:71-73`);
  - `src/config/health.php` (padrão de handler sem Adianti, PDO por `config/environment.php`);
  - `src/app/Core/Security/LoginRateLimiter.php` (construtor com `$prefix`, `identifierFor`, `tooManyAttempts`, `hit`, `secondsUntilAvailable`), `CsrfToken.php` (só sessão), `src/app/Core/Redis/RedisConnectionFactory.php` (`fromEnvironment()`), `src/app/Core/Session/SessionHandlerFactory.php`;
  - `src/app/control/clinic/FinancialOverview.php` (`onExport` :111, `fputcsv(..., ';', '"', '')` :171, `csvSafe` privado :208), `FinancialEntryList.php` (`TTransaction::open('permission')` + repositório com `TTransaction::get()` :176, :324);
  - `src/app/database/migrations/` (`YYYYMMDD_NNNN_slug.sql` + `.verify.sql`, cabeçalho PREPARED ONLY, checksum de zeros, `utf8mb4_0900_ai_ci`, `timestamp(6)`), `scripts/test-db/provision.sh:40-53` (lista fixa até 0008), `docs/runbooks/tests.md:189,226`;
  - `src/menu.xml:112-156` (Administration), `src/app/config/application.php:50` (`public_classes`), `.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/sql/T-01-programs.sql` (modelo de registro de programa; ids fixos, sem AUTO_INCREMENT);
  - `src/tests/run.php` (descoberta `*Test.php` em Unit/Integration), `src/tests/Support/{Assert,FakeRedis,MysqlIntegrationTestCase,TestDatabase}.php`.
- Padrões identificados:
  - Core em `CentralVet\` → `app/Core/` (PSR-4), sem Adianti; services e repositórios recebem `PDO`; controllers usam `TTransaction::get()`;
  - catch de controller: `error_log(__METHOD__ . ': ' . $e->getMessage()); new TMessage('error', CvFormat::userError($e));` (travado por `ControllerRawExceptionMessageTest`);
  - testes sem PHPUnit: classes `CentralVet\Tests\{Unit,Integration}\XTest` com `test*`, `Assert::*`; MySQL com transação e rollback por teste;
  - permissão Adianti por `system_program` + `system_group_program` (SQL manual aprovado); grupo 1 = "Template - Admin".
- Scripts úteis: LINT, SUITE, NGINX-T e GATE (em Premissas); `./scripts/backup.sh`; leitura só com `SELECT` via `docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" centralvet -N -e "<SELECT>"'`.
- Riscos identificados:
  - CSP no `server` do nginx quebraria o Adianti (scripts inline): a CSP fica só nas `location` da landing, do endpoint e de `/landing/`, e cada `location` com `add_header` repete os 3 headers do server (o nginx não herda);
  - `/` é a mesma URL para anônimo e logado: só o `location = /` sem query string vai para `landing.php`; `/?class=...` e `/live` seguem para `index.php`;
  - lead vem de formulário público: nome, clínica e cidade sem `<`/`>`; a tela interna escapa tudo e o CSV neutraliza fórmula;
  - `system_program` MAX(id)=108 e `system_group_program` MAX(id)=110 lidos em 2026-10-01 (SELECT): se mudarem antes do bloqueio, o SQL de T-02 para;
  - rebuild e Playwright são estado global (só o orquestrador reconstrói, só o validador navega); SUITEs simultâneas podem dar falso FAIL em testes Redis de outros arquivos.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/Core/Landing/LandingCatalog.php` | planos, preços, comparativo, opções, consentimento (fonte única) | criar | T-01 |
| `src/app/Core/Landing/LeadEndpoint.php` | contrato HTTP do endpoint (rota, header, limites) | criar | T-01 |
| `src/app/Core/Landing/LeadSubmission.php` | value object validado do lead | criar | T-01 |
| `src/app/Core/Landing/LeadValidationException.php` | erros por campo | criar | T-01 |
| `src/app/Core/Landing/Contract/LeadStoreInterface.php` | gravação do lead | criar | T-01 |
| `src/tests/Unit/LandingCatalogTest.php` | teste do catálogo e da validação | criar | T-01 |
| `src/app/database/migrations/20261001_0009_landing_lead.sql` | tabela `landing_lead` | criar | T-02 |
| `src/app/database/migrations/20261001_0009_landing_lead.verify.sql` | conferência só SELECT | criar | T-02 |
| `scripts/test-db/provision.sh` | lista de migrations do `centralvet_test` | modificar | T-02 |
| `docs/runbooks/tests.md` | runbook da suíte (0001…0009) | modificar | T-02 |
| `docs/runbooks/landing-leads.md` | runbook da landing e dos leads | criar | T-02 |
| `.claude/tasks/mar-20261001-2231-landing-publica-carrinho/sql/T-02-programs.sql` | registro do programa `LandingLeadList` (aplicado pelo orquestrador) | criar | T-02 |
| `src/app/Core/Landing/LeadFormToken.php` | token de uso único em Redis | criar | T-03 |
| `src/tests/Support/FakeRedis.php` | dublê do Redis (`incr`, `expire`, `ttl`, `del`) | modificar | T-03 |
| `src/tests/Unit/LeadFormTokenTest.php` | teste do token | criar | T-03 |
| `src/app/Core/Persistence/LeadRepository.php` | gravação e busca de leads (PDO) | criar | T-04 |
| `src/tests/Integration/LeadRepositoryIntegrationTest.php` | integração no `centralvet_test` | criar | T-04 |
| `src/app/Core/Landing/LeadResponse.php` | resposta HTTP do endpoint | criar | T-05 |
| `src/app/Core/Landing/LeadSubmissionHandler.php` | regras do endpoint | criar | T-05 |
| `src/lead.php` | entrypoint público do endpoint | criar | T-05 |
| `src/tests/Support/FakeLeadStore.php` | dublê de `LeadStoreInterface` | criar | T-05 |
| `src/tests/Unit/LeadSubmissionHandlerTest.php` | teste do endpoint | criar | T-05 |
| `.env.example` | documentação de `LEAD_RATE_LIMIT_*` | modificar | T-05 |
| `src/app/Core/Landing/LandingPage.php` | decisão de entrada e render do template | criar | T-06 |
| `src/landing.php` | entrypoint da landing em `/` | criar | T-06 |
| `src/app/view/landing/landing.html` | template da landing (sem inline) | criar | T-06 |
| `src/landing/landing.css` | estilos da landing | criar | T-06 |
| `src/landing/landing.js` | carrinho, render dos planos e envio do lead | criar | T-06 |
| `src/tests/Unit/LandingPageTest.php` | teste da decisão e do template | criar | T-06 |
| `docker/nginx/default.conf` | rota de `/`, CSP e headers da landing e do endpoint | modificar | T-07 |
| `src/app/Core/Support/CsvCell.php` | neutralização de fórmula em CSV | criar | T-08 |
| `src/app/Core/Landing/LeadCsvExport.php` | cabeçalho e linhas do CSV de leads | criar | T-08 |
| `src/tests/Unit/LeadCsvExportTest.php` | teste do CSV | criar | T-08 |
| `src/app/control/admin/LandingLeadList.php` | tela interna de leads (filtro, paginação, CSV) | criar | T-08 |
| `src/menu.xml` | item "Leads da landing" em Administration | modificar | T-08 |
| `src/app/config/translations.json` | chaves en → pt da tela | modificar | T-08 |
| `.claude/tasks/mar-20261001-2231-landing-publica-carrinho/reports/T-09.md` | relatório da validação final | criar | T-09 |

Nenhum arquivo é tocado por mais de uma task: não há ⚠. `translations.json`, `menu.xml`, `FakeRedis.php` e `docker/nginx/default.conf` têm escritor único.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| `location = /` do nginx sem query string → `src/landing.php` (entrypoint novo, fino); com query string → `index.php` como hoje. `landing.php` só abre sessão se o cookie de sessão existe; logado → `302 /index.php`; anônimo → template renderizado | classe pública do Adianti (`public_view`/`public_classes`); hook no `src/index.php`; página 100% estática com token buscado por `GET` | não edita nenhum arquivo de `framework_hashes.php`; a página fica fora do `login.html`/`public.html`, que têm scripts inline incompatíveis com CSP estrita; anônimo sem cookie não cria sessão no Redis |
| CSP e headers no nginx, por `location` (`= /landing.php`, `= /lead.php`, `^~ /landing/`), repetindo os 3 headers do server | CSP no `server`; CSP enviada pelo PHP | atende ao pedido (headers no nginx) sem atingir o Adianti; `add_header` em `location` anula a herança, por isso a repetição |
| Template em `src/app/view/landing/landing.html` (bloqueado pelo nginx) e assets em `src/landing/` (só extensões estáticas); dados do catálogo num `<script type="application/json" id="cv-landing-data">`, sem script inline executável | preços no JS como no rascunho; render dos cards no PHP | uma fonte de preço (`LandingCatalog`), CSP `script-src 'self'` sem `unsafe-inline` nem nonce; o JS do rascunho é portado quase sem mudança |
| Token de formulário: 32 bytes aleatórios por visualização, guardado no Redis (`centralvet:lead-token:` + sha256) com o instante de emissão, TTL 2 h, idade mínima 3 s, consumido com `del() === 1` só no envio aceito | CSRF de sessão (`CsrfToken`); HMAC sem estado | página pública não tem sessão (e não deve criar uma por visitante); HMAC exigiria segredo novo no `.env`; uso único + idade mínima também barram robô simples |
| Limite por IP com o próprio `LoginRateLimiter`, prefixo `centralvet:lead-throttle:`, 10 tentativas por hora (`LEAD_RATE_LIMIT_MAX_ATTEMPTS`/`LEAD_RATE_LIMIT_DECAY_SECONDS`) contadas a cada POST | classe nova; contar só envio aceito | a classe já é genérica (prefixo no construtor) e tem semântica de janela fixa; contar toda tentativa trava robô que erra de propósito |
| Honeypot `website` (campo fora da tela): preenchido → `200 {"accepted":true}` sem gravar e token consumido | `422`; captcha de terceiro | não sinaliza ao robô; captcha exigiria script de terceiro, vetado pela CSP |
| `landing_lead` sem `tenant_id`, lida só pelo `LeadRepository` (não estende `AbstractTenantRepository`) | lead por tenant | o lead é pré-venda do operador da plataforma, anterior a qualquer tenant (ADR 0002 trata dados de clínica) |
| Consentimento: `consent_version` + `consent_at` + `consent_ip` (texto do IP em claro, `varchar(45)`) | só hash SHA-256 do IP | prova de consentimento verificável; SHA-256 de IPv4 é reversível por força bruta (2³² candidatos), então o hash não protegeria e impediria a prova. O texto vive em `LandingCatalog::CONSENT_TEXT` com a versão: mudar o texto exige nova versão |
| Mensagens de validação do lead em pt direto no Core (`LeadSubmission`) | inglês no Core + `UserMessage`/`_t` | a landing é pública, só pt e fora do Adianti (o `_t` não existe no `lead.php`); a tela interna continua com `_t` |
| Tela `LandingLeadList` em `app/control/admin`, só grupo 1, `TTransaction::open('permission')` + `LeadRepository`; CSV por `onExport` estático com `CsvCell::safe` em todo campo de texto | sem tela (só SQL); `TStandardList` com `TRecord` | cabe no escopo com 1 controller e 1 classe de CSV testável; segue `FinancialEntryList`/`FinancialOverview::onExport`; não precisa de model `TRecord` |

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03
[bloqueio: 0009 + T-02-programs.sql em centralvet; 0009 em centralvet_test — aprovação SQL]
T-01, T-02 → T-04
T-01, T-03 → T-05
T-01, T-03 → T-06
T-01 → T-07
T-01, T-02, T-04 → T-08
T-04, T-05, T-06, T-07, T-08 → T-09
```

## Estratégia de execução
- Branch de trabalho: `task/landing-publica-carrinho`
- Branch base: `feat/rodada-3-divida-tecnica`
- Nota de branch: o orquestrador cria `task/landing-publica-carrinho` a partir de `feat/rodada-3-divida-tecnica` @ `cb4b613` antes da onda 1; todos os agentes trabalham nela, no checkout compartilhado `/var/www/html/centralvet`, sem trocar de branch.
- Commits da onda: cada implementador commita os próprios caminhos (`git -C /var/www/html/centralvet add <caminhos>` + `git -C /var/www/html/centralvet commit -m "<assunto>" -m "Task: T-xx" -- <caminhos>`). Nas tasks com teste, o commit do teste falhando (só os arquivos do bloco Teste RED) leva `Task: T-xx (RED)` e vem antes da implementação. Proibidos: `git add -A`/`.`, `commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`. Commit que falhar por `index.lock` é repetido após alguns segundos (nunca apagar o lock). O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task no checkout compartilhado; nenhum arquivo é dividido entre tasks; rebuild, restart do nginx e Playwright são estado global — só o orquestrador reconstrói e reinicia, só o validador navega; mutação de prova só em worktree isolada em `/tmp/claude-1000/wt-T-xx`, removida ao fim)
- Gate de cada onda: o validador confere trailer, escopo e ordem RED por `git -C /var/www/html/centralvet log <BASE da onda>..HEAD`, roda LINT dos arquivos PHP da onda, SUITE (sozinha), os passos de Validação das tasks e, das ondas 2 em diante, rebuild + restart do nginx pelo orquestrador antes de `curl` e Playwright.
- Bloqueio entre as ondas 1 e 2: ver § Premissas (0009 + `sql/T-02-programs.sql` em `centralvet`, 0009 em `centralvet_test`, cada um com aprovação SQL específica, backup, checksum e `.verify.sql`, registro em `notes.md § Bloqueios`).

## Ondas de execução

### Onda 1
- T-01, T-02, T-03

### Onda 2
- T-04, T-05, T-06, T-07

### Onda 3
- T-08

### Onda 4
- T-09

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Platão | general-purpose | inherit | T-01 |
| Darwin | general-purpose | inherit | T-02, T-04 |
| Arquimedes | general-purpose | inherit | T-03 |
| Jaspion | general-purpose | inherit | T-05 |
| Tesla | general-purpose | inherit | T-06 |
| Yoda | general-purpose | inherit | T-07 |
| Aang | general-purpose | inherit | T-08 |
| Spock — validador | geduc:validador | sonnet | T-09, gates das ondas 1–4 |

## Review Focus
- Visitante com cookie de sessão expirado ou de anônimo (já passou pelo `LoginForm`) abrindo `http://127.0.0.1:8081/` → recebe a landing (`200`, `<meta name="cv-lead-token">`), não o `LoginForm`; admin logado abrindo `/` → `302` para `/index.php` e o layout do sistema → T-06
- Redis fora do ar ao abrir a landing → página `200` sem erro PHP na resposta (token vazio) e o envio responde JSON `403 invalid_token`, nunca stack trace → T-06
- Landing anônima no Chromium → 0 violações de CSP no console (fontes do Google carregam, nenhum `style=`/script inline bloqueado), `/landing/landing.js` com os 4 headers + CSP, `/app/view/landing/landing.html` → `404` → T-07
- Lead com clínica `Patas & Cia "LP teste"` e e-mail com `+` na tela `LandingLeadList` → texto literal na grade (sem HTML interpretado, 0 dialogs) e no CSV com acentos legíveis no Excel (BOM UTF-8 ou `;`) → T-08
- Mesmo token reenviado depois de um `201` (duplo clique) → segundo POST `403 invalid_token` e só 1 linha nova em `landing_lead` → T-05

## Critérios gerais de aceite
- SUITE termina com `Failed: 0` e `Total` ≥ o da BASE da onda 1 + os testes novos de cada onda, no gate de cada onda.
- LINT de todo arquivo PHP tocado imprime `No syntax errors detected` (nenhum erro novo em relação a `baseline/php-lint.txt`, 0 linhas); NGINX-T imprime as 2 linhas de `baseline/nginx-t.txt`.
- Dados: `SELECT COUNT(*)` de `system_program`, `system_group_program`, `tenant`, `patient` e `tutor` em `centralvet` na BASE da onda 1 e em cada gate: só os acréscimos do SQL de T-02 (+1 e +1); `landing_lead` só cresce com os envios `LP teste` que o validador registrou; `schema_migrations` com 9 linhas depois do bloqueio.
- Commits (todo gate): `git -C /var/www/html/centralvet log --format='%h %s%n%b' <BASE da onda>..HEAD` mostra só commits com trailer `Task: T-xx` de tasks da onda (ou `chore(tasks)` restrito a `.claude/tasks/`); `git show --stat` de cada um lista só caminhos de "Arquivos prováveis" da task; nas tasks com teste o commit `Task: T-xx (RED)` toca só os arquivos do bloco Teste RED e vem antes de todo commit `Task: T-xx` da implementação.
- Nenhum arquivo de `src/lib/adianti`, de `src/app/config/framework_hashes.php` nem `src/index.php`, `src/engine.php`, `src/init.php`, `src/app/templates/` aparece em `git -C /var/www/html/centralvet diff --stat cb4b613..HEAD`.
- Gate de navegador (`http://127.0.0.1:8081`, ondas 2–4): anônimo (contexto sem cookie) → `/` com hero, recursos, 4 planos (R$ 57, R$ 97, R$ 137, R$ 197), comparativo, FAQ, carrinho e envio de lead `LP teste` com resposta de sucesso na tela; "Entrar" → `LoginForm`; admin → `/` cai no sistema e (onda 3+) `LandingLeadList` lista e exporta. Em cada tela, `browser_console_messages` (nível `error`) e `browser_network_requests` (≥ 400, exceto `favicon`): tabela `Tela | Fluxos | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` com 0/0/nenhum em cada linha.
