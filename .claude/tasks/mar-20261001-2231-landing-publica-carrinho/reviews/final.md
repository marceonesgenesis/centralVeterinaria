# Revisão final
Branch task/landing-publica-carrinho contra feat/rodada-3-divida-tecnica (diff 7e4e33a..HEAD, HEAD 92d838e).
SUITE (uma vez, árvore parada): `Total: 485, Passed: 485, Failed: 0, Skipped: 0`.
Escopo: o diffstat só toca arquivos do Mapa de arquivos, mais a extensão da T-09 (default.conf/landing.html, ruling da onda 4); nenhum arquivo de src/lib/adianti, framework_hashes.php, index.php, engine.php, init.php nem app/templates.

## Triagem
- [aberta] T-01: falta teste de preço forjado (price_cents/planPriceCents no payload). O código não lê preço do payload (LeadSubmission.php:96-98, LeadRepository.php:50), mas nenhum teste trava isso → LandingCatalogTest.php:42-52
- [aberta] T-01: regras sem teste (phone, email >160, clinic, vets, city, tipos não-string, consent não-bool) → LandingCatalogTest.php (só 6 testes)
- [aberta] T-01: name/clinic só com NBSP/ZWSP/\p{Cf} passam no trim → LeadSubmission.php:119-131
- [aberta] T-01: phone sem limite no texto bruto; o MAX_BODY_BYTES de 8 KiB contém → LeadSubmission.php:55
- [aberta] T-01: plan com espaços em volta é aceito depois do trim; só registrar → LeadSubmission.php:77
- [aberta] T-02: a guarda de sql/T-02-programs.sql é só um comentário mais SELECT MAX (já aplicado; vale para reaplicação) → sql/T-02-programs.sql:15-21
- [aberta] T-02: verify.sql não conta system_group_program (grep sem ocorrência) → 20261001_0009_landing_lead.verify.sql
- [resolvida] T-03: consume() não reconfere idade/TTL. O handler roda isUsable() antes e só grava se consume() === true (DEL atômico) → LeadSubmissionHandler.php:62,85-88
- [aberta] T-03: FakeRedis::set só lê EX vindo de array e ignora o 3º argumento int; o TTL não expira → tests/Support/FakeRedis.php:50-59
- [aberta] T-04: faltam teste do limite superior do período, created_at === consent_at e clamp superior do limit → LeadRepositoryIntegrationTest.php (2 testes)
- [aberta] T-05: lead.php abre PDO e Redis antes de checar o método; com MySQL/Redis fora, GET responde 503 em vez de 405. Também abre conexão MySQL para qualquer pedido, até os 400/403 → src/lead.php:37-50
- [aberta] T-05: sem real_ip, todos os visitantes dividem o bucket do gateway docker (10 POST/h no total); um único cliente esgota o limite de todos. Configurar antes do deploy → src/lead.php:72, docker/nginx/default.conf (sem set_real_ip_from)
- [aberta] T-05: falta teste de preço forjado no endpoint (plan_price_cents no payload) → LeadSubmissionHandlerTest.php:51-56
- [aberta] T-06: a11y do drawer (aria-hidden com focáveis, sem trap/inert) e do formulário (aria-describedby/aria-invalid, foco após 201) → landing.js:96-118
- [aberta] T-06: HEAD emite e grava token no Redis → src/landing.php:47-58
- [aberta] T-07: `/landing` sem barra redireciona para a porta interna (sem absolute_redirect off); /lead.php limita método só no PHP → docker/nginx/default.conf
- [aberta] Onda 2: resíduos de gate (sessão deadbeef até expirar, lead `R3 landing Ana`, contador do IP 192.168.16.1) → notes.md § Pendências
- [aberta] T-08: permissão de usuário sem o programa 109 conferida só por código (engine.php:24-35 → SystemPermission::checkPermission), sem prova em runtime → src/engine.php:24-35
- [resolvida] T-08: markup no nome do lead. A T-09 mostrou `Patas & Cia "LP teste"` literal na grade, 0 dialogs, CSV legível; `<`/`>` já são recusados na entrada (LeadSubmission.php:129) → reports/T-09.md:40-43
- [aberta] T-08: ControllerRawExceptionMessageTest não varre app/control/admin; conferido à mão → LandingLeadList.php:85-86,167
- [aberta] T-08: onExport em falha responde 500 sem corpo numa aba _blank → LandingLeadList.php:163-178
- [aberta] T-09: Redis fora do ar não exercitado (landing com cookie e lead.php) → src/landing.php:27-32,47-51
- [aberta] T-09: 2 leads `LP teste` + 1 `R3 landing Ana` em landing_lead (centralvet); limpar manualmente com aprovação SQL → notes.md
- [aberta] T-09: reports/T-09.md "Arquivos tocados" não lista default.conf/landing.html → reports/T-09.md
- [aberta] T-09: location `= /favicon.ico` sem teste automatizado (coberta por curl) → docker/nginx/default.conf:78-82

## Rulings
- plano · onda 1 — `location = /` sem query → src/landing.php; com query → index.php; index.php/login.html/public.html intocados; classe pública Adianti descartada
- plano · onda 1 — landing.php só abre sessão se o cookie session_name() existir; logado → 302 /index.php; cookie expirado → landing
- plano · onda 1 — landing_lead sem tenant_id; LeadRepository só com PDO; tela do grupo 1
- plano · onda 1 — CSP por location (= /landing.php, = /lead.php, ^~ /landing/), nunca no server; 3 headers repetidos por location
- plano · onda 1 — proteção sem sessão: token Redis (sha256, TTL 2 h, idade mínima 3 s, uso único por del()===1), Origin/Sec-Fetch-Site, JSON obrigatório, honeypot website, 10 POST/IP/h via LoginRateLimiter
- plano · onda 1 — consentimento LGPD com consent_version, consent_at e consent_ip em claro; mudar CONSENT_TEXT exige nova versão
- plano · onda 1 — mensagens de validação em pt no Core (LeadSubmission), exceção à convenção
- plano · onda 1 — LandingCatalog é a fonte única de preço (centavos); a página recebe JSON não executável; o endpoint grava preço do catálogo, nunca do payload
- plano · onda 1 — Google Fonts mantido, CSP restrita a fonts.googleapis.com/fonts.gstatic.com; auto-hospedar fica para depois
- plano · onda 1 — LandingLeadList no escopo; FinancialOverview::csvSafe intocado
- plano · onda 1 — ids system_program 109 / system_group_program 111
- plano · onda 1 — usuário: IP do consentimento em claro (varchar(45))
- plano · onda 1 — usuário: 10 envios por IP por hora, contados a cada POST
- plano · onda 1 — usuário: aprovou executar o plano
- T-01 · onda 1 — consent em publicJson() = {"version","text"}; vets como objeto
- T-01 · onda 1 — UFS em ordem alfabética estrita
- T-01 · onda 1 — city/uf ausentes contam como ''; demais ausentes inválidos; controle = \p{Cc}
- T-02 · onda 1 — T-02 sem teste; guarda do SQL de programa manual
- orquestrador · entre ondas 1 e 2 — SQL aplicado com aprovação: backup, 0009 em centralvet e centralvet_test (checksum 679202dc…), verify ok, programa 109/111
- T-07 · onda 2 — `location ~ /\.` 404 dentro de ^~ /landing/ aceito
- T-04 · onda 2 — created_at = consentAt no fuso APP_TIMEZONE aceito
- T-05 · onda 2 — GET com MySQL/Redis fora → 503 em vez de 405 aceito como pendência
- T-06 · onda 2 — fix (plano-mandou): landing só lê a sessão em read_and_close e só com cookie (393f172, 162b8aa); redirect de logado mantendo a query aceito
- orquestrador · onda 2 — `/` admin → 302 /index.php conferido por curl e navegador
- orquestrador · onda 2 — validação cruzada pós-correção: SUITE 480/480
- orquestrador · onda 2 — rebuild app + restart nginx antes do gate
- T-08 · onda 3 — menu.xml validado com simplexml (xmllint ausente)
- T-08 · onda 3 — ReferenceError da máscara TDate fora do shell index.php aceito
- orquestrador · onda 3 — rebuild app antes do gate
- T-09 · onda 4 — correção 1: location = /favicon.ico + `<link rel="icon">` (28cc5fa); extensão de escopo da T-09, sem RED, verificada por curl e navegador
- orquestrador · onda 4 — rebuild app + restart nginx antes do gate

## Achados
- [sugestão] GET/HEAD `/` grava 1 chave no Redis por pedido (token, TTL 2 h), sem limite de taxa. O Redis é o mesmo das sessões e roda com maxmemory 0 / noeviction (`redis-cli CONFIG GET 'maxmemory*'`): uma enxurrada anônima cresce a memória sem teto por até 2 h. Avaliar limit_req no nginx para `= /` / `= /landing.php` antes do deploy (plano-mandou: token por visualização) → src/landing.php:47-50, docker/nginx/default.conf:21-41
- [sugestão] Corpo entre 16 KiB e acima recebe 413 HTML do nginx, fora do contrato JSON de LeadEndpoint (8 KiB → 413 JSON só entre 8 e 16 KiB). Considerar `error_page 413` JSON ou alinhar os limites → docker/nginx/default.conf:44
- [sugestão] sameOrigin compara Origin com o header Host; atrás de proxy TLS que reescreva Host/porta, os envios legítimos viram 403 forbidden_origin. Documentar no runbook junto do real_ip → LeadSubmissionHandler.php:99-121
- [sugestão] LGPD: sem prazo de retenção (Excluído, documentado como expurgo manual) e Google Fonts recebe o IP do visitante sem aviso na landing. Registrar o prazo e a menção no texto de privacidade na próxima rodada → docs/runbooks/landing-leads.md:80-85
- Verificado sem achado: ordem do handler igual a tasks.md:170-181 (método → tamanho → content-type → origem → rate limit → token → JSON → honeypot → validação → consume → insert); token de 64 hex, chave sha256 e uso único por DEL atômico; IP só de REMOTE_ADDR, sem X-Forwarded-For (não falsificável pelo cliente); honeypot consome o token e responde 200 sem gravar; corpo lido com MAX+1 bytes; 503 sem mensagem de exceção (lead.php:76-81); SQL 100% parametrizado (LeadRepository, LIMIT/OFFSET com PARAM_INT); JSON do catálogo com HEX_TAG/AMP/APOS/QUOT; JS escapa todo dado com esc() antes de innerHTML; template sem inline (só script type=application/json); CSP só nas 3 locations da landing; CvFormat::e em todas as colunas da grade; CsvCell::safe em todo texto do CSV, BOM, `;`; permissão via engine.php checkPermission também para onExport static; migration 0009 só CREATE TABLE + linha de auditoria, sem DML em tabela existente.

## Não revisado (limite de turnos)
- src/landing/landing.css e landing.js por inteiro (só os sinks innerHTML/fetch e esc()); src/app/view/landing/landing.html por inteiro
- src/app/config/translations.json, .env.example, scripts/test-db/provision.sh, docs/runbooks/tests.md, docs/runbooks/landing-leads.md (só a seção de dados pessoais)
- 20261001_0009_landing_lead.verify.sql em detalhe; LeadValidationException, LeadStoreInterface, FakeLeadStore
- Testes novos em profundidade (LandingPageTest, LeadFormTokenTest, LeadSubmissionHandlerTest, LeadCsvExportTest), além dos pontos citados na Triagem
