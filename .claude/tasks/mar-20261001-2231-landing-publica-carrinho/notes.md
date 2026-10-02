# Notas de execução

## Decisões tomadas
- 2026-10-01 · plano · onda 1 — Mecanismo da landing: `location = /` do nginx sem query string → `src/landing.php` (novo, fora de `framework_hashes.php`); com query → `index.php` como hoje. O `src/index.php` está na baseline de `framework_hashes.php` e não é editado, nem `login.html`/`public.html` (scripts inline incompatíveis com CSP estrita). Classe pública do Adianti foi descartada pelo mesmo motivo.
- 2026-10-01 · plano · onda 1 — `landing.php` só abre sessão quando o cookie `session_name()` existe: visitante anônimo sem cookie não cria sessão no Redis. Logado → `302 /index.php` (o sistema abre como hoje); cookie de sessão expirado → landing.
- 2026-10-01 · plano · onda 1 — Lead sem tenant (`landing_lead` sem `tenant_id`): é pré-venda do operador da plataforma, anterior a qualquer clínica; o `LeadRepository` recebe só `PDO` e não estende `AbstractTenantRepository`. A tela é do grupo 1 ("Template - Admin").
- 2026-10-01 · plano · onda 1 — CSP no nginx por `location` (`= /landing.php`, `= /lead.php`, `^~ /landing/`), nunca no `server` (quebraria o Adianti). Cada `location` com `add_header` repete os 3 headers do `server`, porque o nginx não herda `add_header` quando a `location` declara o seu.
- 2026-10-01 · plano · onda 1 — Proteção do formulário sem sessão: token aleatório por visualização no Redis (sha256 como chave, emissão como valor, TTL 2 h, idade mínima 3 s, uso único por `del() === 1`), checagem de `Origin`/`Sec-Fetch-Site`, `Content-Type: application/json` obrigatório, honeypot `website` e limite de 10 POST por IP por hora com o próprio `LoginRateLimiter` (prefixo `centralvet:lead-throttle:`). HMAC sem estado foi descartado por exigir segredo novo no `.env`; `CsrfToken` depende de sessão.
- 2026-10-01 · plano · onda 1 — Consentimento LGPD: `consent_version` (`lgpd-contato-2026-10`), `consent_at` e `consent_ip` em claro. Hash SHA-256 de IPv4 se reverte por força bruta (2³² candidatos) e impediria a prova do consentimento. Mudar `LandingCatalog::CONSENT_TEXT` exige nova versão.
- 2026-10-01 · plano · onda 1 — Mensagens de validação do lead em pt no Core (`LeadSubmission`), exceção à convenção "inglês no Core + `UserMessage`": a landing é pública, só pt, e o `lead.php` não carrega o Adianti (`_t` não existe ali). A tela interna segue com `_t` + `translations.json`.
- 2026-10-01 · plano · onda 1 — Fonte única de preços e textos de plano: `CentralVet\Landing\LandingCatalog` (centavos). A página recebe o catálogo por `<script type="application/json" id="cv-landing-data">` (não executável, aceito pela CSP); o endpoint grava nome e preço do plano lidos do catálogo, nunca do payload; a tela e o CSV leem o preço gravado (o do momento do pedido).
- 2026-10-01 · plano · onda 1 — Google Fonts mantido (permitido pelo usuário), liberado na CSP só para `fonts.googleapis.com`/`fonts.gstatic.com`. Proposta para rodada futura: auto-hospedar as 3 famílias (o Google recebe o IP do visitante).
- 2026-10-01 · plano · onda 1 — Tela interna entra no escopo (`LandingLeadList`: filtro por plano e período, 20 por página, CSV com `CsvCell::safe`). `FinancialOverview::csvSafe` continua privado e intocado; migrar para `CsvCell::safe` fica como proposta.
- 2026-10-01 · plano · onda 1 — Ids do programa: `system_program.id = 109`, `system_group_program.id = 111`, lidos por SELECT em 2026-10-01 (MAX 108 e 110). O SQL de T-02 para sem `COMMIT` se os máximos mudarem.
- 2026-10-01 · plano · onda 1 — Resposta do usuário (1): IP do consentimento LGPD guardado em claro (`consent_ip` varchar(45)).
- 2026-10-01 · plano · onda 1 — Resposta do usuário (2): limite de 10 envios por IP por hora, contados a cada POST.
- 2026-10-01 · plano · onda 1 — Resposta do usuário (3): o usuário aprovou executar o plano agora.

- 2026-10-01 · T-01 · onda 1 — `consent` no `publicJson()` é `{"version","text"}`; `vets` = VETS_OPTIONS como objeto.
- 2026-10-01 · T-01 · onda 1 — `UFS` em ordem alfabética estrita (o rascunho tinha AP antes de AM).
- 2026-10-01 · T-01 · onda 1 — `city`/`uf` ausentes no payload contam como `''`; demais campos ausentes são inválidos; caractere de controle = `\p{Cc}`.
- 2026-10-01 · T-02 · onda 1 — T-02 sem teste, conforme tasks.md; guarda do SQL de programa é manual (comentário + SELECT MAX(id)).
- 2026-10-01 · orquestrador · entre as ondas 1 e 2 — SQL aplicado com aprovação explícita do usuário: backup var/backups/centralvet-20261002T020418Z.sql.gz (gzip -t ok); contagens antes tenant 1, patient 7, tutor 7, system_program 108, system_group_program 110; migration 20261001_0009_landing_lead aplicada em centralvet (checksum 679202dc3c31b1323a9ee08fe04f4f63d65775850bebcf3dffb99cb74474651b trocado numa cópia temporária, como o runbook); verify.sql ok (15 colunas, sem tenant_id, 2 índices, 2 checks, contagens iguais); sql/T-02-programs.sql aplicado (system_program 109 'Leads da landing' LandingLeadList; system_group_program 111 → grupo 1); 0009 aplicada em centralvet_test com o mesmo checksum (15 colunas).
- 2026-10-01 · T-07 · onda 2 — `location ~ /\. { return 404; }` dentro de `^~ /landing/` aceito (mais restritivo).
- 2026-10-01 · T-04 · onda 2 — created_at = consentAt no fuso APP_TIMEZONE aceito.
- 2026-10-01 · T-05 · onda 2 — com MySQL ou Redis fora, GET em /lead.php responde 503 em vez de 405: aceito como pendência (ordem de checagens), sem impacto de segurança.
- 2026-10-01 · T-06 · onda 2 — Fix loop rodada 1 (plano-mandou): a landing criava sessão vazia no Redis com cookie forjado; ruling: só lê a sessão em read_and_close e só se o cookie vier (393f172 RED, 162b8aa). Re-validação e re-revisão aprovadas. Redirect de usuário logado mantendo a query aceito.
- 2026-10-01 · orquestrador · onda 2 — `/` com sessão admin → 302 /index.php conferido por curl (login admin com autorização do usuário) e depois no navegador pela re-validação.
- 2026-10-01 · orquestrador · onda 2 — Validação cruzada pós-correção: SUITE da Re-validação 1 (T-06) no HEAD final, árvore parada: 480/480.
- 2026-10-01 · orquestrador · onda 2 — Rebuild do centralvet-app-1 e restart do nginx antes do gate.
- 2026-10-01 · T-08 · onda 3 — menu.xml validado com simplexml (xmllint ausente no ambiente), aceito.
- 2026-10-01 · T-08 · onda 3 — ReferenceError da máscara TDate só ao abrir engine.php?class=LandingLeadList fora do shell index.php; pelo index.php, 0 erros: aceito (efeito do framework fora do shell).
- 2026-10-01 · orquestrador · onda 3 — Rebuild do centralvet-app-1 antes do gate.
- 2026-10-01 · T-09 · onda 4 — Correção 1 pedida pelo orquestrador (cruzada achada pela T-09): `/favicon.ico` caía no index.php e criava sessão para o anônimo → location própria no nginx servindo src/favicon.png e `<link rel="icon">` na landing (28cc5fa). docker/nginx/default.conf e src/app/view/landing/landing.html entram como extensão de escopo da T-09. Sem RED por ser config de nginx; verificação por curl (200 image/png, sem Set-Cookie) e por navegador anônimo (0 cookies).
- 2026-10-01 · orquestrador · onda 4 — Rebuild do centralvet-app-1 e restart do nginx antes do gate.

## Bloqueios
- Entre as ondas 1 e 2 (aprovação SQL do usuário): `./scripts/backup.sh` + `gzip -t`; anotar antes `COUNT(*)` de `tenant`, `patient`, `tutor`, `system_program`, `system_group_program`; `sha256sum` da 0009 e troca do checksum de zeros; aplicar `src/app/database/migrations/20261001_0009_landing_lead.sql` em `centralvet` com o usuário de migration; rodar o `.verify.sql`; aplicar `sql/T-02-programs.sql` em `centralvet`; com aprovação própria, aplicar a 0009 em `centralvet_test`. Desbloqueia T-04 e T-08. Sem aprovação: T-04 e T-08 `[!]`, T-05/T-06/T-07 seguem.
  - RESOLVIDO na onda 2: usuário aprovou; backup, 0009, verify, programa e centralvet_test aplicados (ver Decisões, entre as ondas 1 e 2).

## Descobertas
- T-01: contrato pronto (2023e96); chave de erro de `LeadSubmission` é o nome do campo do payload (`plan`, não `planId`).
- [T-04] LeadRepository pronto (a0f40da): `insert` grava created_at = consentAt; `search` devolve id/plan_price_cents int, demais colunas string; limit 1..500, offset < 0 vira 0.
- [T-05] POST /lead.php pronto (9c20c9d): header do token `x-cv-lead-token`; bucket `lead|<REMOTE_ADDR>` com prefixo `centralvet:lead-throttle:`; 422 devolve `{"accepted":false,"error":"validation","fields":{...}}` sem consumir o token.
- [T-06] Cookie de sessão do app é `PHPSESSID_centralvet`; landing em src/landing.php, assets em /landing/landing.css e /landing/landing.js (b28043f); a landing só lê a sessão (read_and_close), nunca grava (162b8aa).
- [T-08] LandingLeadList pronto (a903dff RED, 9a16203): filtros por parâmetro de requisição (plan_id, from/to em d/m/Y no POST ou Y-m-d na paginação/CSV); CSV em engine.php?class=LandingLeadList&method=onExport&static=1; xmllint ausente no host e no container.
- [T-09] Validação final ok (485/485, lint 23 arquivos, nginx, curl, navegador anônimo/admin, contagens, escopo vazio); /favicon.ico caía em index.php e criava sessão para anônimo (corrigido em 28cc5fa).

## Pendências
- T-01: sem teste de preço forjado (price_cents/planPriceCents no payload) → LandingCatalogTest.php:42
- T-01: regras sem teste (phone, email >160, clinic, vets, city, tipos não-string, consent não-bool)
- T-01: name/clinic só com caracteres invisíveis (NBSP/ZWSP/\p{Cf}) passam o trim → LeadSubmission.php:131
- T-01: phone sem limite no texto bruto (contido por MAX_BODY_BYTES do T-05) → LeadSubmission.php:55
- T-01: plan com espaços aceito após trim; só registrar → LeadSubmission.php:77
- T-02: guarda de sql/T-02-programs.sql é só instrução; tornar autoabortável com INSERT … SELECT … WHERE MAX(id)=108/110
- T-02: verify.sql não conta system_group_program (runbook e critérios esperam +1)
- T-03: consume() não reconfere idade/TTL; T-05 deve rodar isUsable() antes e inserir o lead só após consume() === true
- T-03: FakeRedis::set ignora o 3º argumento int (EX) do phpredis e não expira TTL
- T-04: teste do limite superior do período (`created_at < :to`), asserção created_at === consent_at e clamp superior do limit vácuo → LeadRepositoryIntegrationTest.php:62-92
- T-05: ordem de checagens em src/lead.php (método antes de abrir PDO/Redis; PDO preguiçoso); com MySQL/Redis fora, GET responde 503 em vez de 405 → src/lead.php:37-50
- T-05: sem `real_ip` no nginx todos os visitantes dividem o bucket do gateway (192.168.16.1); configurar antes do deploy → src/lead.php:72
- T-05: teste de preço forjado no endpoint (plan_price_cents: 1 no payload) → LeadSubmissionHandlerTest.php:51-56
- T-06: acessibilidade do drawer do carrinho (aria-hidden com focáveis, sem trap de foco/inert) e do formulário (aria-describedby/aria-invalid, foco após o 201) → landing.css:195, landing.js:121-205; levar à T-09
- T-06: HEAD emite e grava token no Redis → src/landing.php:47-58
- T-07: `/landing` sem barra redireciona para a porta interna 8080 (considerar `absolute_redirect off`); /lead.php limita método só no PHP
- Onda 2: chave de sessão vazia `centralvet:session:deadbeef` do gate anterior no Redis até expirar; lead de teste `R3 landing Ana` em landing_lead (centralvet); contador de rate limit do IP 192.168.16.1 com ~6 POSTs de teste; token só vale após 3 s (MIN_AGE_SECONDS)
- T-08: permissão de usuário logado sem o programa 109 verificada só pelo código/banco → src/engine.php:24-35
- T-08: markup no nome do lead verificado só pelo código (CvFormat::e), sem lead com markup no banco; avaliar na T-09 (clínica `Patas & Cia "LP teste"` e e-mail com `+`)
- T-08: ControllerRawExceptionMessageTest não varre app/control/admin; conferido à mão → LandingLeadList.php:85-86,167
- T-08: onExport em falha responde 500 sem corpo numa aba _blank (mesmo padrão do FinancialOverview::onExport) → LandingLeadList.php:163-178
- T-09: Redis fora do ar não exercitado (parar container é proibido ao validador); coberto só por código/testes
- T-09: 2 leads `LP teste` e 1 `R3 landing Ana` em landing_lead (centralvet); limpar manualmente
- T-09: relatório lista só reports/T-09.md em "Arquivos tocados"; default.conf e landing.html aparecem apenas em "Correção 1" → reports/T-09.md
- T-09: location `= /favicon.ico` sem teste automatizado (coberta por curl) → docker/nginx/default.conf:75-82

## Riscos
- `REMOTE_ADDR` atrás de proxy/CDN vira o IP do proxy e o limite por IP passa a valer para todos os visitantes: hoje o nginx é a borda; em produção com proxy, configurar `real_ip` no nginx antes de abrir o tráfego.
- Escritório com muitas clínicas atrás do mesmo IP (NAT) bate no limite de 10/h: o `.env.example` documenta `LEAD_RATE_LIMIT_*`, mas o `docker-compose.yml` não os repassa (Excluído); ajustar exige mudança no compose.
- Redis fora do ar: a landing abre sem token e o envio responde `403`; o lead se perde até o Redis voltar (mensagem pede para recarregar).
- `docker compose restart nginx` derruba conexões por um instante: só o orquestrador reinicia, no gate.
- A tabela guarda dados pessoais sem prazo de retenção (Excluído): registrar no runbook `docs/runbooks/landing-leads.md` que o expurgo é manual até a próxima rodada.

## Retomada
- Pasta: `.claude/tasks/mar-20261001-2231-landing-publica-carrinho/`
- Sessões: ce4d9a4f-5d35-46ec-a771-8ef03d42a254
- Branch de trabalho: task/landing-publica-carrinho (base: feat/rodada-3-divida-tecnica)
- BASE da onda 1: 7e4e33a
- Commits por onda:
  - Onda 1: BASE 7e4e33a → HEAD 0a79b54 (edab8f2, c3175b4, c534b57, 2023e96, 0a79b54)
  - Onda 2: BASE 41569bc → HEAD 162b8aa (4b74566, da3345a, 370c4f6, 7593b2c, a0f40da, 9c20c9d, b28043f, 393f172, 162b8aa)
  - Onda 3: BASE 53ed7eb → HEAD 9a16203 (a903dff, 9a16203)
  - Onda 4: BASE e033e85 → HEAD 28cc5fa (8aab833, 28cc5fa)
- Último status conhecido: todas as ondas concluídas (T-01 a T-09 [x]). SQL aplicado, bloqueio resolvido
- Próxima onda recomendada: nenhuma; próximo passo: revisão final
