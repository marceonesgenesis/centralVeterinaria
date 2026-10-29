# Eficiência — mar-20261019-1612-fase-8b-pendencias

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261019-1612-fase-8b-pendencias` · projeto `/var/www/html/centralvet` · versão 2.7.0? · gerado em 2026-10-19T17:29:02-03:00 · última onda medida 4
- Sessões: 661d3ed0-1c79-4f72-8f12-5a960468f359 (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 18.802 | sem base |
| Contexto máximo | 239.799 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,07 | sem base |
| Retornos acima do limite (%) | 6 | sem base |
| Validador acima da meta (%) | 0 | sem base |
| Aprovadas de primeira (%) | 96 | sem base |
| Precisão do mapa de arquivos (%) | 100 | sem base |
| Rodadas de fix loop | 1 | sem base |
| Tasks de correção pós-Fase 5 (%) | 14 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/1 | 0 | 0 | — |
| T-02 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 0 | — |
| T-03 | 1 | plano | média | [x] | ok | 0 | 0 | 0/1 | 0 | 0 | — |
| T-04 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/1 | 0 | 0 | — |
| T-05 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-06 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-07 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/2 | 0 | 1 | — |
| T-08 | 1 | plano | média | [x] | ok | 0 | 0 | 0/1 | 0 | 0 | — |
| T-09 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 0 | — |
| T-10 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 0 | — |
| T-11 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/1 | 0 | 0 | — |
| T-12 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-13 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-14 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-15 | 1 | plano | média | [x] | ok | 0 | 0 | 0/1 | 0 | 0 | — |
| T-16 | 1 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 5 | — |
| T-17 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/1 | 0 | 0 | — |
| T-18 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-19 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-20 | 2 | plano | média | [ ] | ok | 0 | 0 | 0/3 | 0 | 0 | — |
| T-21 | 2 | plano | média | [ ] | ok | 0 | 0 | 0/2 | 0 | 3 | — |
| T-22 | 2 | plano | média | [ ] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-23 | 3 | plano | simples | [x] | sem teste | 0 | 0 | 0/2 | 0 | 1 | — |
| T-24 | 3 | plano | simples | [x] | sem teste | 0 | 1 | 1/2 | 0 | 0 | — |
| T-25 | 4 | correcao | média | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-26 | 4 | correcao | média | [x] | ok | 0 | 0 | 0/3 | 0 | 2 | — |
| T-27 | 4 | correcao | simples | [x] | ok | 0 | 0 | 0/1 | 0 | 0 | — |
| T-28 | 4 | correcao | simples | [x] | ok | 0 | 0 | 0/1 | 0 | 1 | — |

## Causas

- T-05: Asserts existentes de limit passaram a limit+1 (11 e 6) por contrato da Interface; testObterHistoricoOutsideUnit já passava (lacuna)
- T-06: Sem ClinicalToolsetTest (filtro casa AiCopilotServiceTest); imports de exceção removidos das 3 ferramentas, só a trait os usa
- T-07: Nome das unidades leva token aleatório para contagem fora da transação não colidir com execuções concorrentes
- T-12: correlation_id lido da mcp_tool_call gravada, pois a interação não é gravada quando o insert falha
- T-14: Fixture gravava chamada de V com contexto de U; passou a repositório com contexto de V; conversa de outro usuário do tenant aceita
- T-15: Testes de lote com relógio de 2001 (retenção é cross-tenant); teste extra da trava com flock e subprocesso sem banco
- T-16: RuntimeException na montagem vira ai_provider_error; $fallbackReason removido; motivo com html_entity_decode contra escape duplo
- T-17: helper shapeViolations público só para a prova de sensibilidade com cópia temporária
- T-21: chave Record not found já existia (6 novas) e o teste de XSS precisou de vínculo tenant_user
- T-22: teste reforçado com foco no JS; pergunta em branco não marca foco
- T-24: FK RESTRICT descrita como barrando anonimização; corrigido para só exclusão física
- T-25: contextFor dentro do try da auditoria para que registry sem unidade não vire error
- T-26: load voltou a aceitar fallbackReason: motivo da 8A tem precedência sobre o da exceção
- T-28: @fopen para não duplicar o warning do PHP no log
