# Eficiência — mar-20261005-1521-fase-6a-internacao

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261005-1521-fase-6a-internacao` · projeto `/var/www/html/centralvet` · versão 2.5.1? · gerado em 2026-10-05T22:30:16-03:00 · última onda medida 6
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 28.232 | sem base |
| Contexto máximo | 520.016 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,22 | sem base |
| Retornos acima do limite (%) | 12 | sem base |
| Validador acima da meta (%) | 20 | sem base |
| Aprovadas de primeira (%) | 80 | sem base |
| Precisão do mapa de arquivos (%) | 99 | sem base |
| Rodadas de fix loop | 4 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | alta | [x] | sem teste | 0 | 0 | 0/4 | 0 | 3 | — |
| T-02 | 1 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 1 | — |
| T-03 | 1 | plano | alta | [x] | ok | 0 | 0 | 0/2 | 0 | 4 | — |
| T-04 | 1 | plano | alta | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-05 | 1 | plano | média | [x] | sem teste | 0 | 0 | 0/3 | 0 | 3 | — |
| T-06 | 2 | plano | média | [ ] | ok | 0 | 0 | 0/3 | 0 | 0 | — |
| T-07 | 2 | plano | alta | [ ] | ok | 0 | 0 | 0/3 | 0 | 5 | — |
| T-08 | 3 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 3 | — |
| T-09 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/5 | 0 | 2 | — |
| T-10 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 3 | — |
| T-11 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 4 | — |
| T-12 | 4 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 3 | — |
| T-13 | 4 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 3 | — |
| T-14 | 4 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 2 | — |
| T-15 | 4 | plano | alta | [x] | ok | 1 | 1 | 0/2 | 0 | 3 | — |
| T-16 | 4 | plano | alta | [x] | ok | 1 | 1 | 1/2 | 0 | 4 | src/tests/Integration/HospitalizationBoardIntegrationTest.php |
| T-17 | 4 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-18 | 5 | plano | média | [x] | ok | 0 | 1 | 1/4 | 0 | 5 | — |
| T-19 | 5 | plano | simples | [x] | sem teste | 0 | 0 | 0/1 | 0 | 0 | — |
| T-20 | 6 | plano | média | [x] | sem teste | 1 | 1 | 2/3 | 0 | 4 | — |

## Causas

- T-01: Índices extras por FK, updated_at ON UPDATE e timestamps com DEFAULT, por padrão da 0006 e notas 5.7
- T-02: verification_query extraída como função pública para ser testável
- T-03: Extras aditivos (occupied, getters de autoria) e Bed sem occupy/release
- T-04: reconstitute/assignId e assertValid públicos além da Interface, para repositório e fakes
- T-05: SET NAMES no seed, FROM DUAL para 5.7 e SELECT prévio no rollback
- T-06: Fakes com extras aditivos (saveCount, occupy/release reconstituindo Bed) por Bed sem mutador de ocupação
- T-07: CASE no save de leito para proteger bed_occupancy_ck; eventos append-only; board exclui cancelled
- T-08: Suíte inteira não zerava falhas por RED alheio da T-10; ordem autoriza antes de checar duplicidade
- T-09: Leito ocupado também recusado antes de salvar; extras de validação de transferência e data prevista
- T-10: Suspend sem exigir admitted e extras de outcome/window não previstos na Interface
- T-11: Status e referências validados antes de escrever; release falso lança exceção para o TTransaction desfazer
- T-12: Teste RED ampliado e rota de edição sem method; sem retrabalho
- T-13: Filtro de tenant no responsável em vez de TDBUniqueSearch; tipo do campo no teste corrigido
- T-14: Combo de transferência via listByUnit; ações extras onAskSuspendOrder/onReload/tab aditivas
- T-15: ACTION_VIEW sem ::método violava ACTION_PATTERN; corrigido em d29e899 após reprovação no gate
- T-16: Board não capturava AuthorizationDenied/MissingTenantContext; corrigido em f6cef53/3bc672a após revisão
- T-17: xmllint ausente (minidom aceito); Beds no nível de Configurações
- T-18: Mensagens cannot be negative de HospitalizationEvent e recusas alcançáveis sem entrada no catálogo; varredura incompleta na 1ª rodada
- T-20: Conta 371 criada pela alta do gate sem limpeza no SQL e atraso do flowboard sem evidência E2E
