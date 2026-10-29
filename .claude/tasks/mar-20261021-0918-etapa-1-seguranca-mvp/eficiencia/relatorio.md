# Eficiência — mar-20261021-0918-etapa-1-seguranca-mvp

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261021-0918-etapa-1-seguranca-mvp` · projeto `/var/www/html/centralvet` · versão 2.7.0? · gerado em 2026-10-21T11:36:14-03:00 · última onda medida 4
- Sessões: fc92cc27-9f66-4ad7-bfe3-efed43d88248 (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)
- Ondas fechadas sem registro: 5 (rode com `--retroativo`)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 31463,50 | sem base |
| Contexto máximo | 238.451 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,50 | sem base |
| Retornos acima do limite (%) | 15 | sem base |
| Validador acima da meta (%) | 20 | sem base |
| Aprovadas de primeira (%) | 55 | sem base |
| Precisão do mapa de arquivos (%) | 96 | sem base |
| Rodadas de fix loop | 0 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/0 | 0 | 1 | src/tests/Unit/UserMessageTest.php |
| T-02 | 1 | plano | alta | [x] | ok | 0 | 0 | 5/6 | 0 | 3 | — |
| T-03 | 1 | plano | alta | [x] | ok | 0 | 0 | 2/4 | 0 | 0 | — |
| T-04 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-05 | 2 | plano | alta | [ ] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-06 | 3 | plano | alta | [ ] | ok | 1 | 0 | 0/8 | 0 | 2 | — |
| T-07 | 2 | plano | média | [ ] | ok | 0 | 0 | 0/3 | 0 | 3 | — |
| T-08 | 3 | plano | média | [ ] | ok | 1 | 0 | 0/3 | 0 | 0 | — |
| T-09 | 4 | plano | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-10 | 4 | plano | média | [x] | sem teste | 0 | 0 | 0/3 | 0 | 1 | — |
| T-11 | 2 | plano | alta | [ ] | inválido | 0 | 0 | 1/3 | 0 | 3 | src/tests/Support/Fixtures/multisearch-tenant-7-active-criteria.txt |

## Causas

- T-01: Contador de STATIC em UserMessageTest fixava 78 entradas; teste fora dos arquivos prováveis precisou ajuste
- T-02: Caixa de classe/método, divergência query×corpo e SQL panel tratados como leitura; 2 rodadas de correção
- T-03: Limite de reset por conta não valia para qualquer IP; ajustado para limite duplo conta/IP
- T-04: Validação por curl feita em servidor embutido, pois :8081 serve o código da imagem
- T-05: Testes extras de tenant e checagem de tenantId da entidade além do bloco RED, por ruling
- T-06: Busca de tutor (class_alias do Adianti) negada pelo guard e cv_csrf vazando para URLs via $_REQUEST; corrigido na rodada 1
- T-07: .env.example com APP_PUBLIC_URL ativo e onReset recusando usuário inexistente; timing do SMTP aceito
- T-08: Bloqueante do validador veio do guard de T-06 (403 na busca de tutor), nao do SaleForm; sem correcao propria
- T-10: Política de autorização Fake no teste de integração, pois RbacAuthorizationService depende da sessão Adianti
- T-11: Guard lia só chaves manguladas do criteria; contornável, exigiu forma canônica e propriedades exatas
