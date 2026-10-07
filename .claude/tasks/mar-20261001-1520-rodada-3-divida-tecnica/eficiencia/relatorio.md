# Eficiência — mar-20261001-1520-rodada-3-divida-tecnica

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica` · projeto `/var/www/html/centralvet` · versão 2.5.1? · gerado em 2026-10-01T21:58:20-03:00 · última onda medida 4
- Sessões: ce4d9a4f-5d35-46ec-a771-8ef03d42a254 (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 28.399 | sem base |
| Contexto máximo | 435.447 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,27 | sem base |
| Retornos acima do limite (%) | 15 | sem base |
| Validador acima da meta (%) | 40 | sem base |
| Aprovadas de primeira (%) | 90 | sem base |
| Precisão do mapa de arquivos (%) | 91 | sem base |
| Rodadas de fix loop | 4 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 0 | — |
| T-02 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-03 | 1 | plano | média | [x] | inválido | 1 | 0 | 0/2 | 0 | 2 | — |
| T-04 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | src/app/control/clinic/PatientList.php, src/app/control/clinic/PendingReceivableList.php |
| T-05 | 1 | plano | média | [x] | ok | 0 | 0 | 0/7 | 0 | 3 | — |
| T-06 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/2 | 0 | 1 | — |
| T-07 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/1 | 0 | 1 | — |
| T-08 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-09 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 2 | — |
| T-10 | 2 | plano | média | [ ] | sem teste | 0 | 0 | 0/2 | 0 | 2 | — |
| T-11 | 2 | plano | média | [ ] | sem teste | 0 | 0 | 0/2 | 0 | 0 | — |
| T-12 | 2 | plano | simples | [ ] | sem teste | 0 | 0 | 0/3 | 0 | 0 | — |
| T-13 | 2 | plano | simples | [ ] | sem teste | 0 | 0 | 0/3 | 0 | 0 | — |
| T-14 | 2 | plano | média | [ ] | inválido | 0 | 0 | 0/3 | 0 | 2 | — |
| T-15 | 2 | plano | simples | [ ] | sem teste | 0 | 0 | 0/3 | 0 | 2 | — |
| T-16 | 3 | plano | simples | [x] | sem teste | 0 | 0 | 0/1 | 0 | 2 | — |
| T-17 | 3 | plano | simples | [x] | ok | 0 | 1 | 0/3 | 0 | 0 | src/app/control/clinic/AgendaView.php |
| T-18 | 4 | plano | média | [x] | ok | 0 | 1 | 0/3 | 0 | 2 | src/app/Core/Presentation/UserMessage.php, src/app/config/translations.json, src/tests/Unit/UserMessageTest.php |
| T-19 | 2 | plano | simples | [ ] | ok | 0 | 0 | 0/4 | 0 | 2 | — |
| T-20 | 1 | plano | alta | [x] | ok | 1 | 2 | 1/3 | 0 | 4 | src/app/control/admin/SystemSupportForm.php, src/app/control/communication/messages/SystemMessageForm.php, src/tests/Integration/AttachmentUploadExtensionsIntegrationTest.php |
| T-21 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/1 | 0 | 0 | — |

## Causas

- T-01: grep ugrep do PATH trata $ como âncora; critério vale com grep -F
- T-02: constantes MIN_YEAR/MAX_YEAR saíram da classe Presentation para ficar só em Support
- T-03: gate exigiu 404 sem corpo; corpo genérico idêntico aceito por ruling; RED tocou o Fake
- T-04: PatientList e PendingReceivableList também construíam TutorService; escopo estendido
- T-05: fixture de tutor precisou de phone e tenant; verify.sql conta system_unit além do plano
- T-06: deadlock MySQL 1213 com SUITEs simultâneas no banco de dev, fora do escopo
- T-07: teste extra de caminho feliz com cliente injetado
- T-09: SUITE com falhas alheias e worktree removida com --force
- T-10: CashSessionForm tinha TAlert com getMessage() cru, fora do regex da task
- T-14: teste do catálogo conta entradas, então UserMessageTest.php também mudou no RED
- T-15: catch Exception do SystemDatabaseExplorer segue sem isset em $table e sem rollback
- T-16: BASE de translations.json já fora da ordem casefold; reordenado inteiro
- T-17: Ruling estendeu a trava a TAlert e corrigiu AgendaView.php:94 com getMessage cru
- T-18: Cruzada sem dona: PaymentForm, EncounterAccountForm e SaleForm mostravam mensagem de domínio em inglês; 3 padrões novos no UserMessage.
- T-19: resolveName também recusa DEFAULT_NAME igual a DB_DATABASE
- T-20: Drive aceitava HTML como pdf e DEFAULT_EXTENSIONS bloqueava anexos de Message e Support
