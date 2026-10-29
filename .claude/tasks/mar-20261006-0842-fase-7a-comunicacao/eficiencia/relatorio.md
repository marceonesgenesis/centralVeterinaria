# Eficiência — mar-20261006-0842-fase-7a-comunicacao

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261006-0842-fase-7a-comunicacao` · projeto `/var/www/html/centralvet` · versão 2.5.1? · gerado em 2026-10-06T11:46:25-03:00 · última onda medida 6
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)
- Ondas fechadas sem registro: 7, 8 (rode com `--retroativo`)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 28.106 | sem base |
| Contexto máximo | 909.468 | sem base |
| Compactações | 2 | sem base |
| Correções por implementador | 0,21 | sem base |
| Retornos acima do limite (%) | 11 | sem base |
| Validador acima da meta (%) | 26 | sem base |
| Aprovadas de primeira (%) | 83 | sem base |
| Precisão do mapa de arquivos (%) | 96 | sem base |
| Rodadas de fix loop | 4 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | alta | [x] | sem teste | 0 | 0 | 0/5 | 0 | 2 | — |
| T-02 | 1 | plano | média | [x] | ok | 0 | 0 | 0/4 | 0 | 2 | — |
| T-03 | 1 | plano | média | [x] | ok | 0 | 1 | 1/2 | 0 | 2 | — |
| T-04 | 1 | plano | média | [x] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-05 | 1 | plano | média | [x] | ok | 0 | 0 | 0/6 | 0 | 6 | — |
| T-06 | 2 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 3 | — |
| T-07 | 2 | plano | alta | [x] | ok | 0 | 0 | 0/7 | 0 | 5 | — |
| T-08 | 2 | plano | alta | [x] | ok | 0 | 0 | 0/5 | 0 | 4 | — |
| T-09 | 3 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 4 | — |
| T-10 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 1 | — |
| T-11 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 0 | — |
| T-12 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 1 | — |
| T-13 | 3 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-14 | 3 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-15 | 4 | plano | alta | [x] | ok | 0 | 1 | 1/3 | 0 | 5 | — |
| T-16 | 4 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 5 | — |
| T-17 | 4 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 5 | — |
| T-18 | 4 | plano | média | [x] | ok | 0 | 0 | 0/4 | 0 | 7 | — |
| T-19 | 4 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 5 | — |
| T-20 | 4 | plano | simples | [x] | ok | 0 | 0 | 0/1 | 0 | 3 | — |
| T-21 | 5 | plano | média | [x] | ok | 0 | 1 | 0/0 | 0 | 6 | src/app/control/clinic/CommunicationMessageView.php, src/app/control/clinic/PendingCenter.php, src/tests/Integration/CommunicationMessageScreensIntegrationTest.php, src/tests/Integration/PendingCenterIntegrationTest.php |
| T-22 | 5 | plano | simples | [x] | sem teste | 0 | 1 | 1/2 | 0 | 0 | — |
| T-23 | 6 | plano | média | [x] | sem teste | 1 | 0 | 2/4 | 0 | 5 | — |

## Causas

- T-01: Critério dizia 3 UNIQUEs, Interface define 4
- T-02: Desvio: dois contratos de repositório não estendem TenantRepositoryInterface
- T-03: Deep-link aceitava qualquer chave (telefone/CPF numéricos na URL); corrigido com allowlist fechada por destino
- T-05: RED com body_length errado (12 em vez de 13) corrigido no verde
- T-06: Fakes divergem do PDO em bordas (claim 10 min, insertIfNew com id); desvios de extras e sem filtro de data
- T-07: Repositórios PDO só inserem e usam transições; fake T-06 regrava/remove, gerando 4 diferenças aceitas
- T-08: Consultas sem AbstractTenantRepository por exigir stubs; fuso convertido ao padrão da aplicação
- T-09: preferencesFor também autoriza e template inexistente lança CrossTenantReferenceException (mensagens novas), sem bloqueante
- T-10: Decisões locais de not-found, motivo discarded e filtros do listForUnit; só sugestões
- T-12: Extras RESULT_*/CODE_* e skipped em corrida de cancel; só sugestões
- T-13: Desempate por ordem de PendingItem::TYPES e now único por chamada; só sugestões
- T-14: Teste extra de atendimento inexistente e mensagem nova de not-found; só sugestões
- T-15: Varredura de presas e publicação no mesmo try do generate: candidato envenenado travava o tenant; corrigido isolando as etapas
- T-16: Desvios: error_log só com classe, status em THidden e onToggle único conforme a Interface
- T-17: Desvios: confirmações via TQuestion, ficha por MessageService::find e filtros só por POST
- T-18: Desvios: onChangeChannel extra, onChangeTemplate ajusta finalidade e patient_id como combo
- T-19: Desvios: seam buildContent para teste, consulta memoizada por render e responsável via system_users
- T-20: Desvios: href em vez de method no TutorForm, ícones escolhidos e SUITE verde inatingível com RED aberto
- T-21: Textos visíveis errados nas telas (status Em aberto, Enviada, assunto cru) por colisão de chaves i18n existentes
- T-22: Runbook mandava rodar o worker por cron sem modo one-shot inexistente em worker.php
- T-23: Gate E2E achou alvo de toque 19 px, ficha sem recarga, marcadores vazios e SenderNamesQuery sem filtro de tenant
