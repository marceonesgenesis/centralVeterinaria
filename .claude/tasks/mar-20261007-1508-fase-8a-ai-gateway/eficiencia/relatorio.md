# Eficiência — mar-20261007-1508-fase-8a-ai-gateway

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway` · projeto `/var/www/html/centralvet` · versão 2.7.0? · gerado em 2026-10-07T17:00:46-03:00 · última onda medida 6
- Sessões: c2da22ef-8644-4fa0-aa7c-cb2020291aaf (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)
- Ondas fechadas sem registro: 7 (rode com `--retroativo`)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 20.630 | sem base |
| Contexto máximo | 400.796 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,08 | sem base |
| Retornos acima do limite (%) | 10 | sem base |
| Validador acima da meta (%) | 0 | sem base |
| Aprovadas de primeira (%) | 79 | sem base |
| Precisão do mapa de arquivos (%) | 95 | sem base |
| Rodadas de fix loop | 6 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | alta | [x] | sem teste | 0 | 0 | 0/1 | 0 | 3 | — |
| T-02 | 1 | plano | alta | [x] | ok | 0 | 0 | 0/2 | 0 | 3 | — |
| T-03 | 1 | plano | simples | [x] | ok | 1 | 1 | 0/3 | 0 | 1 | — |
| T-04 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-05 | 2 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-06 | 2 | plano | alta | [x] | ok | 0 | 0 | 0/2 | 0 | 4 | — |
| T-07 | 2 | plano | alta | [x] | ok | 0 | 1 | 1/2 | 0 | 5 | — |
| T-08 | 2 | plano | média | [x] | ok | 0 | 0 | 0/6 | 0 | 4 | — |
| T-09 | 2 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 2 | — |
| T-10 | 2 | plano | simples | [x] | ok | 0 | 1 | 2/5 | 0 | 0 | — |
| T-11 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 6 | — |
| T-12 | 3 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 4 | — |
| T-13 | 4 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 5 | — |
| T-14 | 4 | plano | alta | [x] | ok | 0 | 1 | 1/7 | 0 | 7 | src/app/Core/Assistant/Contract/AiAssistantInterface.php |
| T-15 | 4 | plano | média | [x] | ok | 0 | 0 | 0/5 | 0 | 5 | — |
| T-16 | 4 | plano | simples | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-17 | 5 | plano | média | [x] | ok | 0 | 1 | 0/2 | 0 | 2 | src/tests/Integration/ExamResultAiIntegrationTest.php |
| T-18 | 5 | plano | simples | [x] | sem teste | 0 | 1 | 0/3 | 0 | 3 | docs/architecture/adr/0006-ai-gateway.md, src/app/database/migrations/20261007_0014_phase8a_ai_gateway.sql |
| T-19 | 6 | plano | média | [x] | sem teste | 0 | 0 | 0/4 | 0 | 4 | — |

## Causas

- T-01: Comando PREP57 do plano não roda (preparador só lê NN-*.sql); usada cópia 18- como na 0013
- T-02: Extras além do contrato e REASONS igual nas duas exceções; docblock de Adianti ajustado
- T-03: Grep cruzado de api.anthropic.com reprovou o host literal no AiConfigTest; corrigido com parse_url em 9a582c2
- T-05: Fakes com extras de teste e semântica de mês/ordem divergente do PDO (sugestão de revisão), sem retrabalho
- T-06: Índice não forçado e mês no fuso da sessão MySQL, desvios declarados; sem correção
- T-07: Reprovada: custo/tokens ignoravam usage.iterations no fallback; somados por tentativa com preço do modelo
- T-08: Namespace CentralVet\Ai e 4º parâmetro opcional $today; texto de entrada de teste ajustado na implementação
- T-09: Histórico exclui prescrições/exames do atendimento atual e formato de items definido por ruling
- T-10: Reprovada: ditado Web Speech sem declarar envio do áudio; aviso data-label-notice adicionado (ruling do usuário)
- T-11: Desvios aceitos: latestDecided restrito à unidade ativa, texto em branco do provedor vira ai_provider_error, falha de auditoria propaga
- T-12: Desvios aceitos: mutação sem efeito não grava nem audita, month_tokens via monthlyTokenUsage, limite recentInteractions 1..200
- T-14: Revisão rodada 1: onAiDecide perdia os campos de rascunho postados e não autosalvos; corrigido com postedDraft e autosave antes de aplicar.
- T-17: Mensagem de IA traduzida quebrou teste da T-15 (linha 188); ajuste autorizado pelo orquestrador; 13 motivos como PATTERNS
- T-18: ADR numerada 0004 duplicada, renumerada para 0006; comentario da migration 0014 aplicada alterado e restaurado byte a byte
- T-19: Telas puladas por ruling do usuário e Roteiro C trocado por smoke de CLI; chave da API ausente no .env.
