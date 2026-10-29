# Revisão final
Branch `task/landing-a11y-i18n` (HEAD 89b264c) contra `task/landing-publica-carrinho` (5b632bf). Código: 10 arquivos em `src/`, todos em "Arquivos prováveis" de T-01..T-03; resto em `.claude/tasks/mar-20261005-1457-landing-a11y-i18n/`. Nenhum caminho proibido (adianti, framework_hashes, index/engine/init, templates, migrations, translations.js, i18n.js). `T-09-cleanup-landing-lead.sql` sem diff (`git diff --stat 5b632bf..HEAD -- .claude/tasks/mar-20261005-1353-landing-ajustes-deploy/` → vazio), coerente com a T-04 cancelada.
Verificado read-only: diff de src/ bate literalmente com Produz de T-01/T-02 e com a condição de T-03; trailers com RED (e17e748, 0c0d806) antes da implementação e caracterização da T-03 (bde7dc7) antes da refatoração (42ebc17); `curl /` → `landing.css?v=20261005-a11y2`, `translations.js?v=20261005-a11y`, `i18n.js?v=20261002-i18n`, `landing.js?v=20261005-a11y2`; JS servido contém `renderCart(); $("go-plans").focus(); return; }` (1) e CSS servido contém `aria-invalid="true"]:focus` (1). SUITE e Playwright não rodados por mim (evidência do validador em reports/T-05.md e reviews/T-0x.md § Gate).
## Triagem
- [aberta] T-04: comentários do SQL histórico T-09-cleanup-landing-lead.sql seguem desatualizados → arquivo idêntico a 5b632bf; T-04 cancelada pelo usuário; execução real registrada no notes.md da rodada anterior
- [resolvida] T-03: `Failed: 0` global no gate da onda → reviews/T-03.md § Gate e reports/T-05.md (Total 518, Failed 0); LeadSubmission.php:59-63
- [resolvida] Validador: gate de navegador da T-01 (foco após Remover, Tab, borda focada) não rodado → feito na T-05 em pt/en/es (reports/T-05.md, tabela Playwright: go-plans, 20/20 Tab em #cart, rgb(179, 65, 46), console 0)
- [aberta] T-05: `#lead-vets` nunca fica inválido no cliente; borda do select provada só com aria-invalid sintético → src/landing/landing.js:202 (`setErr("lead-vets", "")`); o select só fica inválido por `showServerErrors` (landing.js:180-188); regra CSS cobre select (landing.css:226); critério do plano pressupunha estado que o cliente não produz (plano-mandou, já aceito por ruling)
- [aberta] T-05: relatório com "Pendências: nenhuma" apesar do desvio do select; contexto Playwright persistente, não anônimo → reports/T-05.md § Pendências / § Desvios; só documental, sem efeito no resultado
## Rulings
- plano · onda 1 — Branch `task/landing-a11y-i18n` a partir de `task/landing-publica-carrinho` @ 5b632bf; onda de implementação T-01..T-04 com caminho exclusivo e onda de validação T-05.
- plano · onda 1 — Borda de erro focada por regra nova `.field [aria-invalid="true"]:focus` (0,3,0) com box-shadow, preservando o literal exigido pelo teste existente.
- plano · onda 1 — Após "Remover", foco em `#go-plans` (link existente, traduzido); sem texto novo; `#close-cart` descartado.
- plano · onda 1 — Marcadores do wrap com literais exclusivos de `wrapTab`; discriminação provada em cópia em /tmp.
- plano · onda 1 — `failurePage(string $message, string $language = 'pt')`; Core sem Adianti; mapa pt/en/es → pt-BR/en-US/es, outro → pt-BR; `lang` nunca é o argumento cru.
- plano · onda 1 — `$phoneRaw === null ||` removido só com reordenação (`strlen($phone) < 10` primeiro); sem RED, caracterização commitada antes.
- plano · onda 1 — SQL da T-09 anterior: só comentários, instruções idênticas, COMMIT incondicional (não executado: T-04 cancelada).
- plano · onda 1 — Cache-buster `?v=20261005-a11y2` só em landing.css/landing.js; translations.js e i18n.js mantêm o `?v=` atual.
- T-04 · onda 1 — Edição do SQL histórico negada pelo classificador; não contornada; usuário cancelou a T-04; comentários desatualizados viram pendência; T-05 deixa de depender da T-04.
- T-01 · onda 1 — Gate de navegador da T-01 adiado para a T-05.
- T-05 · onda 1 — "Total = BASE + 4" aceito por referência (514 → 518); COUNT de landing_lead não se aplica na onda 1.
- T-05 · onda 2 — Limpeza de localStorage/sessionStorage por língua no lugar de contexto anônimo novo; aceito.
- T-05 · onda 2 — Borda do select `#lead-vets` conferida com aria-invalid sintético; aceito.
## Achados
- [sugestão] Comportamento de navegador (foco em #go-plans após Remover, wrap de Tab, borda vermelha focada) não reproduzido nesta revisão: o plano reserva a navegação ao validador; a evidência é a tabela de reports/T-05.md, e só os marcadores servidos foram confirmados por curl → reports/T-05.md § Evidência
- [sugestão] plan.md continua listando a T-04 em § Incluso e o critério de escopo da T-05 cita "T-01..T-04" sem registrar o cancelamento; quem ler só o plano espera o SQL comentado → .claude/tasks/mar-20261005-1457-landing-a11y-i18n/plan.md § Escopo / Incluso
