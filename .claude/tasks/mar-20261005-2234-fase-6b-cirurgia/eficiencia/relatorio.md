# Eficiência — mar-20261005-2234-fase-6b-cirurgia

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia` · projeto `/var/www/html/centralvet` · versão 2.5.1? · gerado em 2026-10-06T08:40:26-03:00 · última onda medida 6
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)
- Ondas fechadas sem registro: 7 (rode com `--retroativo`)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 28.106 | sem base |
| Contexto máximo | 719.725 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,17 | sem base |
| Retornos acima do limite (%) | 10 | sem base |
| Validador acima da meta (%) | 19 | sem base |
| Aprovadas de primeira (%) | 81 | sem base |
| Precisão do mapa de arquivos (%) | 92 | sem base |
| Rodadas de fix loop | 4 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | alta | [x] | sem teste | 0 | 0 | 0/3 | 0 | 0 | — |
| T-02 | 1 | plano | alta | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-03 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-04 | 1 | plano | média | [x] | sem teste | 0 | 0 | 0/3 | 0 | 0 | — |
| T-05 | 2 | plano | média | [x] | ok | 0 | 1 | 0/3 | 0 | 2 | src/app/Core/Domain/Contract/SurgeryRepositoryInterface.php |
| T-06 | 2 | plano | alta | [x] | ok | 0 | 0 | 1/7 | 0 | 3 | — |
| T-07 | 3 | plano | simples | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-08 | 3 | plano | alta | [x] | ausente | 0 | 1 | 1/5 | 0 | 3 | — |
| T-09 | 3 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 0 | — |
| T-10 | 3 | plano | média | [x] | ok | 0 | 1 | 1/5 | 0 | 3 | src/app/Core/Domain/Contract/SurgeryMaterialRepositoryInterface.php, src/app/Core/Persistence/SurgeryMaterialRepository.php, src/tests/Integration/SurgeryRepositoryIntegrationTest.php, src/tests/Support/FakeSurgeryMaterialRepository.php, src/tests/Unit/SurgeryFakesTest.php |
| T-11 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 0 | — |
| T-12 | 4 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-13 | 4 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 4 | — |
| T-14 | 4 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 5 | — |
| T-15 | 4 | plano | média | [x] | ok | 0 | 1 | 1/3 | 0 | 3 | — |
| T-16 | 4 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 2 | — |
| T-17 | 4 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 3 | — |
| T-18 | 4 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 0 | — |
| T-19 | 5 | plano | média | [ ] | ok | 0 | 0 | 0/3 | 0 | 7 | — |
| T-20 | 5 | plano | simples | [ ] | sem teste | 0 | 0 | 0/2 | 0 | 2 | — |
| T-21 | 6 | plano | média | [x] | sem teste | 0 | 0 | 0/4 | 0 | 3 | — |

## Causas

- T-02: Acréscimo aditivo: getters extras em Surgery e reconstitute para a T-06, sem mudar a Interface
- T-03: Acréscimo público assertItemOfPhase reutilizado pela entidade e pela T-09; contrato intacto
- T-05: Fake do save condicional divergia do PDO (ordem loadedStatus vs status gravado pela instância); corrigido por ruling
- T-06: Revisão apontou divergência Fake x PDO no save condicional; ruling alinhou o Fake e o docblock ao PDO
- T-08: Autorização por unidade sem teste discriminante; corrigido só com testes
- T-09: confirmPhase sem lockStatus; toque duplo fica com o UNIQUE
- T-10: Remoção dupla concorrente gerava evento fantasma; delete() com rowCount
- T-12: Badge da sala precisou de chaves próprias (Active traduz Ativo, não Ativa); teste ampliado
- T-13: Extras além do contrato: onChangeProcedure e ACTION_LOAD
- T-14: Teste extra de ações por status; postedReason privado lido por reflexão
- T-15: XSS armazenado: nome do procedimento sem escape no título do SurgeryEventForm, corrigido com CvFormat::e
- T-16: Extras além do contrato: onAskRemove e classes CSS auxiliares cv-checklist; voltar para SurgeryView
- T-17: Nomes em lote por SELECT IN no controller e contagem por status em SurgeryAgendaView
- T-19: Board e plano divergiam nas traducoes e nas mensagens com id interno; mensagens sem id e chaves novas, Circulante no lugar de Volante.
- T-20: Regex do criterio devolve 8 por nao casar SurgeryList/SurgeryView; 9 controllers conferidos um a um.
- T-21: Ordem de DELETE ajustada por FK surgery.followup_appointment_id e @gate_start fixado pré-gate no SQL de limpeza
