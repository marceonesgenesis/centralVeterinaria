# Eficiência — mar-20261021-1145-etapa-2-dinheiro-estoque

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261021-1145-etapa-2-dinheiro-estoque` · projeto `/var/www/html/centralvet` · versão 2.7.0? · gerado em 2026-10-21T15:34:21-03:00 · última onda medida 6
- Sessões: fc92cc27-9f66-4ad7-bfe3-efed43d88248 (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 32.141 | sem base |
| Contexto máximo | 305.393 | sem base |
| Compactações | 2 | sem base |
| Correções por implementador | 0,26 | sem base |
| Retornos acima do limite (%) | 14 | sem base |
| Validador acima da meta (%) | 14 | sem base |
| Aprovadas de primeira (%) | 82 | sem base |
| Precisão do mapa de arquivos (%) | 98 | sem base |
| Rodadas de fix loop | 4 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/0 | 0 | 1 | src/tests/Unit/UserMessageTest.php |
| T-02 | 1 | plano | média | [x] | sem teste | 0 | 0 | 0/2 | 0 | 3 | src/tests/Integration/StockSalesOverviewIntegrationTest.php |
| T-03 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 0 | — |
| T-04 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-05 | 2 | plano | simples | [x] | ok | 0 | 1 | 0/0 | 0 | 1 | — |
| T-06 | 2 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 3 | — |
| T-07 | 2 | plano | média | [x] | inválido | 0 | 0 | 0/3 | 0 | 3 | — |
| T-08 | 2 | plano | alta | [x] | inválido | 0 | 0 | 0/6 | 0 | 3 | — |
| T-09 | 2 | plano | média | [x] | inválido | 0 | 0 | 0/3 | 0 | 3 | — |
| T-10 | 2 | plano | média | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-11 | 3 | plano | alta | [x] | ok | 0 | 1 | 1/2 | 0 | 0 | — |
| T-12 | 3 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 2 | — |
| T-13 | 4 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 2 | — |
| T-14 | 4 | plano | alta | [x] | ok | 0 | 0 | 0/2 | 2 | 2 | — |
| T-15 | 5 | plano | alta | [x] | inválido | 1 | 1 | 1/4 | 0 | 5 | — |
| T-16 | 5 | plano | média | [x] | ok | 0 | 1 | 1/4 | 0 | 4 | — |
| T-17 | 6 | plano | alta | [x] | sem teste | 0 | 0 | 0/4 | 0 | 1 | — |

## Causas

- T-01: Teste fixa o tamanho do catálogo (82→89); ajuste fora dos arquivos prováveis aceito por ruling
- T-02: Índices extras por FK e ALTER TABLE separado; fixture alheia quebrou com sale_cancellation_ck (cruzada 69e1122)
- T-04: Teste monta o nome removido por concatenação para manter o grep do critério vazio
- T-05: Implementador sem credencial para o curl autenticado; cookie-jar do orquestrador e host localhost corrigiram a verificação.
- T-06: Placeholder :quantity_floor e helper refuseNextDecrease no fake; teste 1205 sem isolar o lock de A (sugestão).
- T-07: Tenant commitado no teste de 1205 e attach de token não reivindicado fora da Interface; RED tocou teste não declarado.
- T-08: remove() append-only e auxiliares linkPayment/all no fake fora do contrato; RED tocou teste não declarado.
- T-09: Fake sem guarda de transação e ordem de validação do cancel; RED tocou testes não declarados.
- T-11: Teste 1205 com token falhava por lock do claim/FK de tenant e não isolava o FOR UPDATE; B passou a chamar register sem token
- T-12: Commit de implementação ajustou ACTION do teste RED (hífen rejeitado por AuthorizationRequest::ACTION_PATTERN)
- T-13: Desvios declarados: assertTenant antes do claim e forma de pagamento desconhecida sem chave da T-01.
- T-14: RED com ACTION de formato inválido (hífen) ajustado na implementação; sem caixa lança após gravar estoque.
- T-15: Grid do carrinho com colunas Qty, Unit price e Total fora da esquerda, contra o CLAUDE.md; corrigido em fix loop por ruling.
- T-16: Runbook com contagem fixa de 47 programas, divergente do seed (80 INSERT); corrigido por ruling com grep e SELECT.
- T-17: Cenário extra de cancelamento sem caixa + rollback acrescentado no mesmo arquivo
