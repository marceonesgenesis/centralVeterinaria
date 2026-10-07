# Eficiência — mar-20261005-1457-landing-a11y-i18n

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261005-1457-landing-a11y-i18n` · projeto `/var/www/html/centralvet` · versão 2.5.1? · gerado em 2026-10-05T15:17:28-03:00 · última onda medida 2
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 22.003 | sem base |
| Contexto máximo | 256.159 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,14 | sem base |
| Retornos acima do limite (%) | 13 | sem base |
| Validador acima da meta (%) | 0 | sem base |
| Aprovadas de primeira (%) | 100 | sem base |
| Precisão do mapa de arquivos (%) | 100 | sem base |
| Rodadas de fix loop | 0 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | média | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-02 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-03 | 1 | plano | — | simples | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-04 | 1 | plano | simples | [-] cancelada (fora do escopo) | sem teste | n/d | 0 | n/d/n/d | 0 | 0 | — |
| T-05 | 2 | plano | média | [x] | sem teste | 0 | 0 | 0/3 | 0 | 2 | — |

## Causas

- T-04: Edição do SQL histórico negada pelo classificador de permissões; usuário retirou a task do escopo
- T-05: Perfil Playwright persistente (sem contexto anônimo) e select #lead-vets nunca inválido no cliente exigiram limpar storage e aria-invalid sintético
