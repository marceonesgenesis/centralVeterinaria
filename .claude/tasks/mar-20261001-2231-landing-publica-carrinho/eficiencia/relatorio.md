# Eficiência — mar-20261001-2231-landing-publica-carrinho

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho` · projeto `/var/www/html/centralvet` · versão 2.5.1? · gerado em 2026-10-01T23:41:43-03:00 · última onda medida 4
- Sessões: ce4d9a4f-5d35-46ec-a771-8ef03d42a254 (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 23.647 | sem base |
| Contexto máximo | 601.417 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,26 | sem base |
| Retornos acima do limite (%) | 14 | sem base |
| Validador acima da meta (%) | 27 | sem base |
| Aprovadas de primeira (%) | 89 | sem base |
| Precisão do mapa de arquivos (%) | 95 | sem base |
| Rodadas de fix loop | 2 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | média | [x] | ok | 0 | 0 | 0/5 | 0 | 1 | — |
| T-02 | 1 | plano | média | [x] | sem teste | 0 | 0 | 0/2 | 0 | 0 | — |
| T-03 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/3 | 0 | 0 | — |
| T-04 | 2 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 2 | — |
| T-05 | 2 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 4 | — |
| T-06 | 2 | plano | alta | [x] | ok | 0 | 1 | 1/4 | 0 | 5 | — |
| T-07 | 2 | plano | média | [x] | sem teste | 0 | 0 | 0/2 | 0 | 0 | — |
| T-08 | 3 | plano | média | [x] | ok | 0 | 0 | 0/4 | 0 | 6 | — |
| T-09 | 4 | plano | média | [x] | sem teste | 0 | 1 | 0/2 | 0 | 2 | docker/nginx/default.conf, src/app/view/landing/landing.html |

## Causas

- T-01: Desvios de contrato: ordem das UFs, forma do consent e city/uf ausentes decididos na implementação
- T-02: Guarda do SQL de programa manual, pois SQL puro não aborta condicionalmente
- T-04: created_at gravado igual a consentAt para o filtro de período casar com o fuso APP_TIMEZONE
- T-05: Entrypoint usa LeadRepository do T-04 e testes extras de origem, token e corpo; plano não nomeava o store
- T-06: Landing criava sessão vazia no Redis com cookie forjado; corrigida com leitura em read_and_close
- T-07: Regra de dotfiles recolocada em ^~ /landing/ porque o ^~ anula a regra global
- T-08: xmllint ausente: menu.xml validado com simplexml; tela em TPage para o CSV refletir filtros; CSV em blocos de 500.
- T-09: /favicon.ico caía no index.php e criava sessão para o anônimo; location própria no nginx e link icon na landing
