# Notas de execução

## Decisões tomadas
- 2026-10-05 · plano · onda 1 — Rodada de ajustes de deploy continua na branch `task/landing-publica-carrinho` (HEAD `939b541`), sem branch nova, conforme premissa da Fase 1.
- 2026-10-05 · plano · onda 1 — `lead.php`: `LeadSubmissionHandler::precheck()` estático (método, tamanho, content-type, origem) roda antes de abrir Redis; `LazyLeadStore` abre o PDO só no primeiro `insert`. Contrato de `handle()` e dos testes existentes inalterado.
- 2026-10-05 · plano · onda 1 — nginx local confia em `X-Forwarded-For` vindo das faixas RFC1918 e de 127.0.0.1 (`real_ip_recursive on`): a rede docker não tem subnet fixa e o compose fica fora do escopo. Risco aceito: quem fala com o gateway pode forjar o header, mas a porta só escuta em `127.0.0.1`. Produção (Apache) não confia no header; IP real só por `mod_remoteip` do provedor.
- 2026-10-05 · plano · onda 1 — 405 do `/lead.php` no nginx com o mesmo JSON do PHP (`{"accepted":false,"error":"method_not_allowed"}`) e `Allow: POST` por `map` (valor vazio em POST não envia o header); `limit_except` descartado por responder 403 HTML.
- 2026-10-05 · plano · onda 1 — a11y do carrinho por `inert` (drawer fechado; irmãos `header.top`, `main#inicio`, `footer` com o drawer aberto) em vez de trap por Tab; `aria-hidden` sai do `#cart`. Foco devolvido a `#open-cart` quando o `lastFocus` foi recriado por `renderPlans()`.
- 2026-10-05 · plano · onda 1 — a11y sem harness de JS no projeto (sem `package.json`; módulo Playwright ausente para `scripts/test-landing-i18n.cjs`): RED da T-06 é marcador estático em PHP (`LandingA11yTest`) e o comportamento é provado pelo Playwright MCP no gate.
- 2026-10-05 · plano · onda 1 — `onExport` em falha devolve página HTML mínima montada em `LeadCsvExport::failurePage()` (Core, testável), com texto via `_t` + `translations.json`.
- 2026-10-05 · plano · onda 1 — SQL de limpeza por predicado (`name LIKE 'LP teste%' OR name = 'R3 landing Ana'`, `created_at >= '2026-10-01'`) e não por ids: os gates desta rodada criam novos `LP teste`. Execução só pelo orquestrador, depois da Onda 3, com aprovação SQL do usuário. Resíduos Redis expiram por TTL (deadbeef já expirou; nenhum contador `lead-throttle` em 2026-10-05); nenhum `DEL`.
- 2026-10-05 · plano · onda 1 — Resposta do usuário (1): manter a confiança do nginx local nas faixas RFC1918 inteiras (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16) e em 127.0.0.1, sem tocar `docker-compose.yml` (sem `ipam`).
- 2026-10-05 · plano · onda 1 — Resposta do usuário (2): predicado do DELETE da T-09 confirmado (`name LIKE 'LP teste%' OR name = 'R3 landing Ana'`, com `created_at >= '2026-10-01'`); nenhum lead real terá nome nesse padrão.
- 2026-10-05 · T-02 · onda 1 — o critério cita 12 testes em LeadSubmissionHandlerTest; o correto é 13 (T-01 adicionou um na mesma onda).
- 2026-10-05 · T-01 · onda 1 — achado plano-mandou da revisão rodada 1 (teste não discriminava o strip unicode de text()) aceito e corrigido no fix loop rodada 1 (c0f19ff); re-revisão rodada 2 aprovada; validação cruzada pós-fix: SUITE 504/504.
- 2026-10-05 · T-06 · onda 2 — gate reprovou (Tab/Shift+Tab escapavam de #cart via body); fix loop rodada 1 (1a4c70f RED + 937df30) re-validado e aprovado (reviews/T-06.md § Re-validação 1); revisão rodada 1 aprovada com sugestões; validação cruzada pós-fix: SUITE 514/514.
- 2026-10-05 · T-08 · onda 2 — gate do CSV com sessão admin (onExport) não rodado nesta onda (sem sessão admin); passa para o gate admin da T-10.

## Bloqueios
- Depois da Onda 3 (aprovação SQL do usuário): execução de `sql/T-09-cleanup-landing-lead.sql` em `centralvet` pelo orquestrador, com backup (`./scripts/backup.sh` + `gzip -t`) antes. Sem aprovação, os leads de teste ficam e a pendência é registrada.

## Descobertas
- [T-04] nginx (9b5a8e0): real_ip X-Forwarded-For (RFC1918 + 127.0.0.1, recursive) e absolute_redirect off; /lead.php não-POST responde 405 JSON com Allow: POST no nginx (não chega ao PHP local).
- [T-04] Neste zsh `grep` é função de shell e devolve 0; usar /usr/bin/grep nas validações do .htaccess.
- [T-02] LeadSubmissionHandler::precheck(string $method, array $headers, string $body): ?LeadResponse e LazyLeadStore (closure no 1º insert) prontos (f008b84); lead.php só abre Redis/PDO depois do precheck; handle() inalterado.
- [T-06] landing.html: cache-busters landing.css/translations.js/landing.js agora `?v=20261005-a11y` (i18n.js inalterado) e `#cart` com `inert` sem `aria-hidden` (c0117ea RED, aaefa40); LandingPageTest:67-68 atualizado. Quem mexer nesses arquivos deve partir desses valores.

## Pendências
- T-01: [sugestão] Interface promete recusar `\p{Cf}` também em `city`, mas só `name` tem teste de `\p{Cf}` no meio do texto (LeadSubmission.php:75, LandingCatalogTest.php:105-110).
- T-01: [sugestão] `$phoneRaw === null ||` é redundante (LeadSubmission.php:60).
- T-09: [sugestão] A guarda "N linhas afetadas, senão ROLLBACK" é só comentário; deixar explícito no cabeçalho que a execução é interativa e o COMMIT só após conferir N (sql/T-09-cleanup-landing-lead.sql:12-14,37).
- T-09: [sugestão] Comentário "lead-throttle: nenhum encontrado em 2026-10-05" já está vencido; dizer "no momento da leitura" (sql:42-43).
- Validador: contagens de tenant/patient/tutor/system_program/system_group_program não comparadas na onda 1 (sem BASE no prompt); ficam para T-10. Gate de navegador é das ondas 2-3.
- SQL da T-09 só é executado pelo orquestrador após a onda 3, com backup e aprovação SQL do usuário.
- T-06: [sugestão] marcador `ev.preventDefault()` de testScriptWrapsTabInsideOpenCart já existia (submitLead); o wrap só é provado pelo gate Playwright (LandingA11yTest.php:65).
- T-06: [sugestão] `.field input:focus`/`select:focus` sobrepõe a borda de `[aria-invalid="true"]`; campo inválido focado perde o indicador vermelho (landing.css:223-225).
- T-06: [sugestão] "Remover" (`#remove-plan`) chama renderCart() e o foco cai em body dentro do diálogo aberto (landing.js:253).
- T-06: cache-buster `?v=` da landing não mudou após a correção do wrap de Tab (937df30); revisar antes do deploy (ver reviews/T-06.md).
- T-08: [sugestão] `<html lang="pt-BR">` fixo em failurePage, mas a mensagem sai por `_t()` e pode ser em inglês (LeadCsvExport.php:90).
- T-08: [sugestão] gate do CSV 200 / `text/csv` com sessão admin não rodou na onda 2; fica para o gate admin da T-10 (LandingLeadList.php:184-186).
- Validador (onda 2): leads de teste criados nos gates: ids 4 `LP teste pt`, 5 `LP teste en`, 6 `LP teste es`, 7 `LP teste pt wrap2`, todos cobertos pelo predicado da T-09. Contagens observadas: landing_lead 7, tenant 1, patient 7, tutor 7, system_program 109, system_group_program 111 (comparar com a BASE na T-10).

## Riscos
- 9 tasks em 2 ondas no checkout compartilhado: SUITEs simultâneas dão falso FAIL em testes Redis alheios — cada implementador lê só as linhas da própria classe; o gate roda a SUITE sozinha.
- `X-Forwarded-For` confiável no nginx local: só seguro porque a porta escuta em `127.0.0.1`; publicar a porta em `0.0.0.0` exige rever `set_real_ip_from`.
- `.htaccess` com `RewriteEngine On` na raiz de `src/`: hospedagem sem `mod_rewrite` ignora o bloco (`IfModule`) e o PHP continua respondendo 405.
- Cache-busters novos (`20261005-a11y`): navegador com o JS antigo em cache e template novo não acontece, porque o template muda a URL junto.

## Retomada
- Pasta: `.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/`
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c
- Branch de trabalho: task/landing-publica-carrinho (base: task/landing-publica-carrinho @ 939b541)
- BASE da onda 1: 939b541
- Commits por onda:
  - Onda 1: BASE 939b541 → HEAD c0f19ff (e36cf11, 75081e0, 9b5a8e0, 50e60d9, be5c9ac, 50abbe9, b7414ff, f008b84, c0f19ff)
  - Onda 2: BASE f65e9cc → HEAD 937df30 (dff3aeb, 1a31d45, a35c9bd, 6f83a3e, c0117ea, 6d3fc4b, aaefa40, 1a4c70f, 937df30)
- Último status conhecido: onda 2 concluída (T-03, T-05, T-06, T-08 [x]); só T-10 pendente
- Próxima onda recomendada: 3 — T-10
