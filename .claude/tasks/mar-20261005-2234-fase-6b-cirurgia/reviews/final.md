# Revisão final
Branch feat/fase-6b-cirurgia (be11483) contra feat/fase-6a-internacao (09ce2d5). 74 arquivos, +14559/-12 (fora de .claude). Escopo priorizado: tenant/unidade, RBAC, concorrência, atomicidade da conclusão, XSS, texto clínico em URL, MySQL 5.7 da 0011.
Verificações read-only: `SELECT @@GLOBAL.transaction_isolation` → REPEATABLE-READ (MySQL 8.0.43); `src/app/config/database.php` não define isolamento (init só sql_mode); `ls =` na raiz → inexistente; `git status --short` limpo.

## Triagem
- [resolvida] T-08/T-01: nada prova a ordem trava → checagem de sobreposição de sala → a ordem existe, mas a checagem é leitura não travante sob REPEATABLE READ e usa o snapshot aberto antes da trava (ver Achado 1) → src/app/Core/Application/SurgeryService.php:99,135,153; src/app/Core/Persistence/SurgeryRepository.php:105-111
- [resolvida] T-11: atomicidade com 2+ produtos depende do TTransaction → SurgeryView::onComplete abre um TTransaction('permission') único e todos os repositórios/serviços usam TTransaction::get() → src/app/control/clinic/SurgeryView.php:234-239,1102-1158
- [resolvida] T-11: complete() sem leitura prévia na transação → onComplete chama complete() logo após o open e lockAndLoad faz lockStatus antes de findById → SurgeryCompletionService.php lockAndLoad
- [aberta] T-11: testCompletionUsesLockedStatusNotStaleRead não distingue trava → só teste
- [aberta] T-11/6A: conclusão x fechamento da conta não serializam (openOrGet + status sem FOR UPDATE na conta; herdado da 6A) → src/app/Core/Application/SurgeryCompletionService.php:88
- [resolvida] T-19: i18n "Material <id> was already removed" → src/app/Core/Presentation/UserMessage.php:127 + translations.json:3955
- [resolvida] T-19: chaves i18n da onda 4 / "Message not found" tolerado → chaves em translations.json; gate pt da T-21 aprovado (relatório, não reproduzido aqui)
- [resolvida] T-19: gate de tela em pt transferido → executado no gate final T-21 (relatório)
- [resolvida] T-21: fluxos em navegador da onda 4 adiados → roteiro B do gate final (relatório; RF2 por teste unitário, decisão registrada)
- [resolvida] T-21: arquivo vazio `=` na raiz → `ls =` não encontra
- [aberta] T-21: executar sql/T-21-cleanup.sql (backup, ensaio com ROLLBACK, aprovação SQL explícita) → ainda não executado
- [aberta] T-21: cabeçalho do SQL descreve só o estado pré-gate → sql/T-21-cleanup.sql:15-22
- [aberta] T-21: queue_entry 818 / appointment 832 sem contagem antes/depois → sql/T-21-cleanup.sql:76-88,300-327
- [aberta] T-21: tabela do gate por tela não registrada → relatório
- [aberta] T-21: QueueEntryView::onAdvance não avança a fila 818 → fora do escopo
- [aberta] T-09/T-16: toque duplo concorrente vira mensagem genérica → confirmado: SurgeryChecklistRepository.php:89-95 encadeia a PDOException e CvFormat::userError (lib/widget/CvFormat.php) devolve o texto genérico ao achar PDOException na cadeia; integridade preservada pelo UNIQUE surgery_checklist_item_uq, só a mensagem do RF4 falha em corrida real
- [aberta] T-09: confirmPhase sem lockStatus (cancelamento concorrente pode gravar itens) → src/app/Core/Application/SurgeryChecklistService.php:72
- [aberta] T-08: replaceTeam cai para status carregado quando lockStatus é null → SurgeryService.php:194
- [aberta] T-08: recordClinicalEvent confere cancelled sem trava; sem teste de corrida cancel x start → SurgeryService.php recordClinicalEvent
- [aberta] T-01: FKs de coluna única não garantem tenant/unidade; surgery_cancelled_ck não exige autor; sobreposição sem limite inferior de janela → migration 0011:153-158
- [aberta] T-02: consent_text sem limite; SurgeryRoom::rename sem teste
- [aberta] T-03: reconstitute com código fora do catálogo; assertComplete com Assert::true(true)
- [aberta] T-04: rollback só nos grupos 1/5; lacunas frontpage_id/tenant_group; nome sem COLLATE utf8mb4_bin
- [aberta] T-05: ordenação do Fake de checklist; exceção divergente Fake x PDO ao regravar item
- [aberta] T-06: WeakMap sem teste de integração e sobrevivendo a rollback; round trip sem campos de cancelamento; replaceForSurgery repetido e corrida de código de sala sobem PDOException crua; docblock Surgery.php:21-23
- [aberta] T-07: corrida de código de sala (duplicata da T-06)
- [aberta] T-10: lockStatus x leitura velha não distinguidos no teste; AuthorizationDenied em remove/list sem teste; Fake devolve 0 para outro tenant
- [aberta] T-12: sem teste de tela para ativar/desativar/editar sala
- [aberta] T-13: onSave/onSaveTeam capturam só Exception; onChangeProcedure sobrescreve duração; patient_id da rota ignorado
- [aberta] T-14: ficha concluída sem itens lançados; RF3 sem teste automatizado de tela; onAskComplete sem validar id > 0 (onComplete valida)
- [aberta] T-15: sem teste de query string ignorada; helpers duplicados nas telas
- [aberta] T-16: link Remover sem static=1; segundo Remover com texto enganoso
- [aberta] T-17: SELECT IN diretos no controller (conferidos: filtram tenant_id e ids intval) → SurgeryList.php:288-327; badges pre_op/in_progress no mesmo tom
- [aberta] T-18: teste de menu não prova posição; docblock de onInlineAction
- [aberta] T-19: gêneros misturados nos badges; mensagens com nome técnico; "Hospitalize post-op" não criada
- [aberta] T-20: runbook trata SurgeryAgendaView como tela; contagens fixas no passo 2
- [aberta] 6A herdadas: Central de Pendências; $MIGRATION_USER no runbook; admissões simultâneas sem guarda

## Rulings
- plano · onda 0 — cirurgia só a partir de atendimento; sala como cadastro surgery_room com trava + sobreposição; procedimento qualquer ativo com cópia de nome/preço; consentimento em colunas de surgery; checklist fixo 5+4+4 com UNIQUE; materiais baixados/cobrados só na conclusão em TTransaction único; save condicional + lockStatus; internação pós-op pela tela da 6A fora da transação; cancelamento só scheduled/pre_op por POST; 9 programas, grupo novo "Clínico – Cirurgia" + grupo 1; migration 0011 compatível com 5.7; gates econômicos; respostas (1)-(4) do usuário; login admin pelo orquestrador; branch feat/fase-6b-cirurgia
- T-01..T-04 · onda 1 — SurgeryChecklist::assertItemOfPhase público (contrato intacto)
- T-05/T-06 · onda 2 — semântica oficial do save condicional = PDO (WeakMap → loadedStatus → scheduled); Fake e docblock corrigidos (701e4cb, f1dc5d8)
- T-08 · onda 3 — correção só de testes (21dbc10), ausência de RED aceita
- T-10 · onda 3 — delete(): int com rowCount; "Material <id> was already removed" sem evento (4678b75, 6929b48)
- T-09 · onda 3 — confirmPhase sem lockStatus; toque duplo fica com o UNIQUE
- T-12..T-18 · onda 4 — fluxos do Review Focus no navegador adiados para a T-21
- T-15 · onda 4 — XSS no título do SurgeryEventForm corrigido (2d05dd9, 78aa2e0)
- T-19 · onda 5 — gate pt transferido à T-21; "Circulante"; "Campo obrigatório: <rótulo>"
- T-20 · onda 5 — regex do critério devolve 8; 9 controllers conferidos um a um
- T-21 · onda 6 — fluxo completo só em desktop; RF2 por SurgeryMaterialServiceTest; login refeito pelo orquestrador

## Achados
- [resolvida] Agendamento simultâneo na mesma sala pode gravar duas cirurgias sobrepostas (Review Focus 1). SurgeryService::schedule lê o atendimento (SurgeryService.php:99, leitura consistente que abre o snapshot InnoDB) antes de travar a sala (:135); hasOverlapInRoom (:153 → SurgeryRepository.php:105-111) é SELECT sem FOR UPDATE/LOCK IN SHARE MODE e, em REPEATABLE-READ (confirmado no servidor; database.php não muda o isolamento), lê o snapshot antigo. Cenário: A e B leem o atendimento; A trava a sala, não vê sobreposição, insere e commita; B obtém a trava, consulta no snapshot anterior ao commit de A, não vê a cirurgia de A e insere. A trava da sala serializa, mas a checagem não enxerga o último commit. Correção esperada: leitura travante (ou current read) na checagem de sobreposição, mais teste de integração com duas conexões → src/app/Core/Persistence/SurgeryRepository.php:105-111; src/app/Core/Application/SurgeryService.php:99,135,153
- [sugestão] RF4 em corrida real: o segundo "Confirmar" concorrente recebe "Não foi possível concluir a operação" em vez de "fase já confirmada", porque CvFormat::userError prioriza a PDOException encadeada. Basta não encadear o previous (ou tratar InvalidStatusTransitionException antes do laço) → src/app/Core/Persistence/SurgeryChecklistRepository.php:91-95
- [sugestão] O mesmo padrão de snapshot vale para qualquer checagem futura "trava e depois SELECT simples" com leitura consistente anterior. lockAndLoad (conclusão/retorno) e lockedInProgressSurgery (material) estão corretos porque decidem pelo status travado e a primeira leitura consistente vem depois da trava → SurgeryCompletionService.php lockAndLoad; SurgeryMaterialService.php:161
- Conferidos sem achado: queries novas com tenantQuery/tenant_id (inclusive SELECT IN do SurgeryList com intval e join tenant_user); autorização por resourceUnitId da entidade persistida (cirurgia, sala, material); RBAC por Controller::método nos 9 programas; atomicidade da conclusão (estoque/conta/status/evento numa transação e numa conexão); conclusão x cancelamento (estados disjuntos + save condicional); conclusão x material e remoção dupla (lockStatus + rowCount); checklist duplo com integridade pelo UNIQUE; escape com CvFormat::e nas listas/fichas e htmlspecialchars do TCombo; textos clínicos (notes, consentimento, motivo) lidos só de $_POST; 0011 no mesmo padrão das migrations anteriores (collation 0900 e DROP CHECK tratados pelo preparador, PYTEST57 ok).
- [resolvida] (re-revisão) Achado 1 / pendência promovida T-08/T-01 resolvidos: 219f1e2 (RED, duas conexões, falhava com "answered: free"), aeeb85d (hasOverlapInRoom com LIMIT 1 FOR UPDATE, current read) e f1c0688 (teste-guarda da ordem trava → checagem → insert) → src/app/Core/Persistence/SurgeryRepository.php:90-118; src/tests/Integration/SurgeryRepositoryIntegrationTest.php:189-229; src/tests/Unit/SurgeryServiceTest.php:252-380
