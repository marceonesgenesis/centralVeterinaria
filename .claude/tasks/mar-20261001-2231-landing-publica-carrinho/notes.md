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

## Bloqueios
- Entre as ondas 1 e 2 (aprovação SQL do usuário): `./scripts/backup.sh` + `gzip -t`; anotar antes `COUNT(*)` de `tenant`, `patient`, `tutor`, `system_program`, `system_group_program`; `sha256sum` da 0009 e troca do checksum de zeros; aplicar `src/app/database/migrations/20261001_0009_landing_lead.sql` em `centralvet` com o usuário de migration; rodar o `.verify.sql`; aplicar `sql/T-02-programs.sql` em `centralvet`; com aprovação própria, aplicar a 0009 em `centralvet_test`. Desbloqueia T-04 e T-08. Sem aprovação: T-04 e T-08 `[!]`, T-05/T-06/T-07 seguem.

## Descobertas
- nenhuma

## Pendências
- nenhuma

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
- BASE da onda 1: {hash7}
- Commits por onda: {Onda N: BASE <hash7> → HEAD <hash7> (<hashes dos commits>)}
- Último status conhecido: plano aprovado, nenhuma task executada (T-01..T-09 [ ])
- Próxima onda recomendada: onda 1
