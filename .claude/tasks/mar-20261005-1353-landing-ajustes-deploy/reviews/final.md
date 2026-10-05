# Revisão final
Escopo: task/landing-publica-carrinho, 939b541..4c96665 (21 commits; 25 arquivos de código/docs fora de .claude/). Todos os caminhos estão no Mapa de arquivos; nenhum caminho proibido (adianti, framework_hashes, index/engine/init, templates, migrations); nada de Excluído implementado. Interfaces conferidas literalmente: precheck(string,array,string): ?LeadResponse, LazyLeadStore(\Closure), issuesToken(string): bool, failurePage(string): string, PHONE_RAW_MAX = 20, FakeRedis::set com int, aside#cart com inert e sem aria-hidden, ?v=20261005-a11y, map $lead_allow + return 405 JSON, RewriteRule ^lead\.php$ - [R=405,L]. Leitura só SELECT: landing_lead = 13, fora do predicado da T-09 = 0. git status limpo.
## Triagem
- [resolvida] T-01: `\p{Cf}` no meio de `city` sem teste → src/tests/Unit/LandingCatalogTest.php:118 (testFormatCharacterInsideCityIsRejected, c0f19ff)
- [aberta] T-01: `$phoneRaw === null ||` redundante → src/app/Core/Landing/LeadSubmission.php:60
- [aberta] T-09: guarda "N linhas afetadas, senão ROLLBACK" só em comentário; o arquivo termina em `COMMIT;` incondicional, então rodar com `mysql < arquivo` não tem guarda. Hoje N = 13 (não 3, como diz o cabeçalho) e 0 linhas fora do predicado; executar interativamente e conferir 13 antes do COMMIT → .claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql:12-14,19,37
- [aberta] T-09: comentário "lead-throttle: nenhum encontrado em 2026-10-05" já não vale (os gates da T-10 criaram buckets, ex.: 192.168.16.1 em 10/10 e 203.0.113.NN); só comentário, expiram por TTL → sql/T-09-cleanup-landing-lead.sql:42-43
- [aberta] Validador (onda 1): contagens tenant/patient/tutor/system_program/system_group_program sem BASE da onda 1; T-10 comparou com a onda 2 → reports/T-10.md § Contagens
- [aberta] Execução do SQL da T-09 depois da onda 3, com backup e aprovação SQL do usuário → notes.md § Bloqueios
- [aberta] T-06: marcador `ev.preventDefault()` de testScriptWrapsTabInsideOpenCart já existia (submitLead) e não prova o wrap; o wrap só é provado pelo gate Playwright → src/tests/Unit/LandingA11yTest.php:65, src/landing/landing.js:191
- [aberta] T-06: `.field input:focus` (0,2,1) vence `.field [aria-invalid="true"]` (0,2,0); campo inválido focado perde a borda vermelha → src/landing/landing.css:223-225
- [aberta] T-06: "Remover" (`#remove-plan`) recria o corpo do carrinho e o foco cai em body com o diálogo aberto → src/landing/landing.js:253
- [resolvida] T-06: cache-buster não mudou após 937df30 → src/app/view/landing/landing.html:383 (o diff líquido da branch leva landing.js de `20261002-cart` a `20261005-a11y`, versão que já contém o wrap; a branch não tem upstream e a versão intermediária nunca foi publicada)
- [aberta] T-08: `<html lang="pt-BR">` fixo com mensagem via `_t()` que pode sair em inglês → src/app/Core/Landing/LeadCsvExport.php:90
- [resolvida] T-08: gate do CSV 200 / text/csv com sessão admin → reports/T-10.md (CSV admin 200 text/csv, 12 colunas, 13 linhas; ruling T-10/T-08 · onda 3)
- [resolvida] Validador (onda 2): leads de gate ids 4-7 cobertos pelo predicado → SELECT só leitura: 13 linhas, 0 fora de `(name LIKE 'LP teste%' OR name = 'R3 landing Ana') AND created_at >= '2026-10-01'`
- [aberta] T-10: BASE numérica da onda 1 nunca registrada (mesma da pendência do validador da onda 1) → reports/T-10.md § Contagens
- [aberta] T-10: nenhum 422 provocado no navegador (validação no cliente barra antes) → reports/T-10.md § Tabela do gate
- [aberta] T-10: curls de T-02/T-04 com XFF 203.0.113.NN por ruling; 403 sem XFF não exercido → reports/T-10.md § curl
- [aberta] T-10: caminho de falha do CSV (failurePage) só no unitário LeadCsvExportTest → src/tests/Unit/LeadCsvExportTest.php:72
- [resolvida] Validador (onda 3): escopo não verificável por commit → 4c96665 commita reports/T-10.md e reviews/T-10.md só em .claude/tasks/; git status limpo
- [aberta] landing_lead com 13 linhas, todas no predicado; SQL da T-09 ainda não executado → aguarda aprovação SQL do usuário
## Rulings
- plano · onda 1 — rodada continua na branch task/landing-publica-carrinho @ 939b541, sem branch nova
- plano (T-02) · onda 1 — precheck() estático antes de Redis; LazyLeadStore abre o PDO só no primeiro insert; contrato de handle() inalterado
- plano (T-04) · onda 1 — nginx local confia em X-Forwarded-For das faixas RFC1918 e 127.0.0.1 (recursive); risco aceito pela porta em 127.0.0.1; produção só via mod_remoteip
- plano (T-04) · onda 1 — 405 do /lead.php no nginx com o JSON do PHP e Allow: POST por map; limit_except descartado
- plano (T-06) · onda 1 — a11y do carrinho por inert (drawer fechado; irmãos com o drawer aberto), sem aria-hidden; foco volta a #open-cart quando lastFocus foi recriado
- plano (T-06) · onda 1 — sem harness de JS: RED da T-06 por marcador estático em PHP; comportamento provado pelo Playwright MCP no gate
- plano (T-08) · onda 1 — onExport em falha devolve página mínima de LeadCsvExport::failurePage() com texto via _t
- plano (T-09) · onda 1 — limpeza por predicado de nome e data, não por ids; execução só pelo orquestrador após a onda 3 com aprovação; Redis expira por TTL, sem DEL
- plano (T-04) · onda 1 — usuário (1): manter RFC1918 inteiras + 127.0.0.1, sem tocar docker-compose.yml
- plano (T-09) · onda 1 — usuário (2): predicado do DELETE confirmado; nenhum lead real nesse padrão
- T-02 · onda 1 — critério cita 12 testes em LeadSubmissionHandlerTest; o correto é 13 (T-01 adicionou um)
- T-01 · onda 1 — achado plano-mandou (teste não discriminava o strip unicode de text()) aceito e corrigido em c0f19ff; re-revisão aprovada; SUITE 504/504
- T-06 · onda 2 — gate reprovou (Tab escapava de #cart); fix 1a4c70f (RED) + 937df30 re-validado; SUITE 514/514
- T-08 · onda 2 — gate do CSV com sessão admin adiado para o gate admin da T-10
- T-10 · onda 3 — bucket do gateway 192.168.16.1 esgotado: gates com XFF 203.0.113.NN, sem apagar chave nem esperar TTL
- T-10/T-08 · onda 3 — login admin no Playwright feito pelo orquestrador com autorização do usuário; CSV 200 text/csv; falha do CSV só pelo unitário
## Achados
- [sugestão] `set_real_ip_from`/`real_ip_header` ficam no `server`, então valem para todo pedido, não só para o throttle do lead: o throttle de login (`$_SERVER['REMOTE_ADDR']` em LoginForm.php:163) e os logs de acesso/auditoria (SystemAccessLogService.php:38, EncounterView.php:1740) também passam a aceitar X-Forwarded-For forjado por quem alcança 127.0.0.1:8081 (o ruling da T-10 usou exatamente isso para contornar o bucket esgotado). O comentário e o runbook só citam o lead.php; registrar o efeito no login e nos logs → docker/nginx/default.conf:21-33, docs/runbooks/landing-leads.md:71-78
- [sugestão] O runbook diz que sem mod_rewrite "o PHP responde o mesmo 405", mas o `[R=405]` do Apache devolve a página de erro HTML padrão, sem o JSON `{"accepted":false,"error":"method_not_allowed"}` e sem `Allow: POST`; os dois 405 não são iguais → docs/runbooks/shared-hosting-mysql57.md:107-109, src/.htaccess:129
