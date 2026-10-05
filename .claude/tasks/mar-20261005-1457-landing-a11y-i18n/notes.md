# Notas de execução

## Decisões tomadas
- 2026-10-05 · plano · onda 1 — Branch de trabalho `task/landing-a11y-i18n` a partir de `task/landing-publica-carrinho` @ `5b632bf`; uma onda de implementação (T-01..T-04, caminho exclusivo, arquivos disjuntos) e uma de validação (T-05).
- 2026-10-05 · plano · onda 1 — Borda de erro com foco por regra nova `.field [aria-invalid="true"]:focus` (0,3,0) com `box-shadow`, sem mexer no literal `.field [aria-invalid="true"]` que `testStyleHidesClosedDrawerAndMarksInvalidFields` exige; a sombra repõe o indicador de foco que `outline: none` tirava.
- 2026-10-05 · plano · onda 1 — Após "Remover", foco em `#go-plans` (link já existente no estado vazio, traduzido em pt/en/es); nenhum texto visível novo, `translations.js` intocado. Alternativa `#close-cart` descartada por estar longe do conteúdo removido.
- 2026-10-05 · plano · onda 1 — Marcador do wrap: literais já existentes e exclusivos do `wrapTab` (`ev.preventDefault(); last.focus();`, `ev.preventDefault(); first.focus();`, `if (ev.key === "Tab") wrapTab(ev);`); o JS do wrap não muda, por isso esse método passa antes e depois — a T-01 prova a discriminação numa cópia em `/tmp`.
- 2026-10-05 · plano · onda 1 — `failurePage(string $message, string $language = 'pt')`: o Core não chama Adianti; o controller passa `(string) ApplicationTranslator::getLanguage()`. Mapa `pt→pt-BR`, `en→en-US`, `es→es` (igual a `src/landing/i18n.js:5`), outro → `pt-BR`; o `lang` nunca é o argumento cru.
- 2026-10-05 · plano · onda 1 — `$phoneRaw === null ||` só sai com reordenação (`strlen($phone) < 10` primeiro): apagar a cláusula sem reordenar levaria `mb_strlen(null)` a `TypeError` sob `strict_types=1`. Sem RED (refatoração sem mudança de comportamento); teste de caracterização commitado antes da refatoração.
- 2026-10-05 · plano · onda 1 — SQL da T-09 anterior: só comentários, instruções idênticas (diff sem comentários contra `5b632bf`); o COMMIT continua incondicional e o cabeçalho passa a proibir reexecução.
- 2026-10-05 · plano · onda 1 — Cache-buster novo `?v=20261005-a11y2` só em `landing.css`/`landing.js`; `translations.js?v=20261005-a11y` e `i18n.js?v=20261002-i18n` ficam.
- 2026-10-05 · T-04 · onda 1 — Edição dos comentários do SQL histórico negada pelo classificador de permissões do modo automático; não contornada; usuário retirou a T-04 do escopo (cancelada). Comentários desatualizados do SQL viram pendência (a execução real está no notes.md da rodada anterior). T-05 deixa de depender da T-04.
- 2026-10-05 · T-01 · onda 1 — Gate de navegador (foco em #go-plans após Remover, Tab, borda de erro focada) fica para a T-05.
- 2026-10-05 · T-05 · onda 1 — Validador: "Total = BASE + 4" confirmado por referência (rodada anterior fechou com SUITE 514/514; onda deu 518/518); COUNT de landing_lead não se aplica na onda 1 (sem envio de lead).

## Bloqueios
- nenhum

## Descobertas
- [T-04] Bloqueada: o classificador do modo automático negou a edição de T-09-cleanup-landing-lead.sql; arquivo igual a 5b632bf, sem commit da T-04.

## Pendências
- T-04: comentários do SQL histórico .claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql seguem desatualizados (cancelada; execução real registrada no notes.md da rodada anterior).
- T-03: `Failed: 0` global confirmado no gate da onda (518/518); sem pendência residual.
- Validador: gate de navegador da T-01 (foco após Remover, Tab, borda de erro focada) não rodado; fica para a T-05.

## Riscos
- 4 implementadores no checkout compartilhado: SUITEs simultâneas dão falso FAIL em testes Redis alheios — cada um lê só as linhas da própria classe; o gate roda a SUITE sozinha.
- `mb_strlen($phoneRaw)` com `?string` depende do curto-circuito da condição do phone; reordenar de novo no futuro reintroduz o `TypeError` — o teste de caracterização da T-03 cobre.
- Navegador com `landing.css`/`landing.js` antigos em cache: não acontece, porque o template muda a URL (`?v=20261005-a11y2`) junto.
- O gate depende do rebuild (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`) feito pelo orquestrador; sem ele o PHP do container fica com a versão anterior.

## Retomada
- Pasta: `.claude/tasks/mar-20261005-1457-landing-a11y-i18n/`
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c
- Branch de trabalho: task/landing-a11y-i18n (base: task/landing-publica-carrinho)
- BASE da onda 1: 5b632bf
- Commits por onda:
  - Onda 1: BASE 5b632bf → HEAD b1e8ba7 (bde7dc7, 0c0d806, e17e748, 42ebc17, 58593d2, b1e8ba7)
- Último status conhecido: onda 1 concluída: T-01, T-02, T-03 [x]; T-04 cancelada (fora do escopo); SUITE 518/518.
- Próxima onda recomendada: onda 2 — T-05 (validação final, após rebuild + restart do nginx)
