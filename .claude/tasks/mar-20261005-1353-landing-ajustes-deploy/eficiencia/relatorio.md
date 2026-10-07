# Eficiência — mar-20261005-1353-landing-ajustes-deploy

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261005-1353-landing-ajustes-deploy` · projeto `/var/www/html/centralvet` · versão 2.5.1? · gerado em 2026-10-05T14:47:04-03:00 · última onda medida 3
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 28.232 | sem base |
| Contexto máximo | 200.117 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,22 | sem base |
| Retornos acima do limite (%) | 16 | sem base |
| Validador acima da meta (%) | 0 | sem base |
| Aprovadas de primeira (%) | 80 | sem base |
| Precisão do mapa de arquivos (%) | 100 | sem base |
| Rodadas de fix loop | 3 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | média | [x] | ok | 0 | 1 | 1/2 | 0 | 0 | — |
| T-02 | 1 | plano | média | [x] | ok | 0 | 0 | 0/0 | 0 | 2 | — |
| T-03 | 2 | plano | simples | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-04 | 1 | plano | média | [x] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-05 | 2 | plano | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-06 | 2 | plano | alta | [x] | ok | 1 | 1 | 0/3 | 0 | 3 | — |
| T-07 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-08 | 2 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-09 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/2 | 0 | 0 | — |
| T-10 | 3 | plano | média | [x] | sem teste | 0 | 1 | 0/5 | 0 | 2 | — |

## Causas

- T-01: Teste do strip unicode de text() não discriminava (passava com trim()); corrigido com caso NBSP/U+3000
- T-02: Critério citava 12 testes no LeadSubmissionHandlerTest; correto são 13 após T-01
- T-06: Gate reprovou: Tab/Shift+Tab escapavam de #cart para body; faltava wrap de Tab no keydown.
- T-08: Desvio menor: failurePage inclui meta viewport; SUITE com falhas alheias da T-06 em andamento.
- T-10: Bucket lead-throttle do gateway esgotado e falta de sessão admin exigiram XFF e login pelo orquestrador; correção 1 fechou o gate admin
