# Notas de execução

## Decisões tomadas
- 2026-10-01 · plano · onda 0 — Fontes: `notes.md` da rodada 2 (§ Pendências, § Descobertas, § Decisões), `reviews/final.md` (duas triagens, itens `[aberta]`, e Achados), sugestões abertas de `reviews/T-62.md`, `T-63.md` e `T-64.md`, e a seção T-65 de `tasks.md` (proposta de defesa na entrada). Repositório único, checkout compartilhado, isolamento `caminho exclusivo`.
- 2026-10-01 · plano · onda 0 — Branch `feat/rodada-3-divida-tecnica` (pedido do usuário, que prevalece sobre o padrão `task/<contexto>`), a partir do HEAD de `feat/rodada-2-cadastros-schema-acoes` depois do fechamento da rodada 2; o orquestrador a cria antes da onda 1.
- 2026-10-01 · plano · onda 0 — A contagem real de `new TMessage(..., $e->getMessage())` é 173 (não ~40): 70 fora do template (68 em `control/clinic`, `SearchBox.php:64`, `log/SystemRequestLogView.php:27`) e 103 em `control/admin`/`control/communication`. A rodada cobre os 70 (T-10, T-11, T-12) e deixa os 103 do template fora (decisão do usuário). Nenhum desses arquivos está em `framework_hashes.php`.
- 2026-10-01 · plano · onda 0 — Mensagens de domínio continuam em inglês no Core; a tradução é pelo catálogo `UserMessage` (T-01) + `translations.json` (T-16). Os padrões genéricos `^1 is required` e `^1 must have at most ^2 characters` mostram o nome técnico do campo (ex.: "Campo obrigatório: scheduled_at"). É melhor que a frase inteira em inglês; trocar por rótulo amigável exigiria um mapa campo → rótulo por tela, fora desta rodada.
- 2026-10-01 · plano · onda 0 — `EncounterDocumentService::download` nega quando não recebe o `EncounterRepository` (4º argumento). O `ExamResultForm` só usa `attach`, então não precisa passá-lo.
- 2026-10-01 · plano (revisão) · onda 0 — Respostas do usuário:
  - (1) T-14 aprovada: recusar `<`/`>` nos nomes de Patient, Tutor, Service e Product na criação e na edição; na importação CSV, a linha vira `skipped`. A validação fica no caminho de gravação dos services, nunca no `reconstitute`, para os registros já gravados com `<`/`>` (payloads R2) continuarem listando;
  - (2) os 103 catches do template admin/communication ficam fora; só os 70 do produto;
  - (3) criar o `centralvet_test` com as migrations. T-05 redige `scripts/test-db/provision.sh` e `verify.sql`; o orquestrador os aplica entre as ondas 1 e 2 com aprovação SQL na hora (backup do dev por precaução); T-19 (onda 2) troca o default da SUITE para o banco de teste e faz o `run.php` recusar o banco da aplicação.
- 2026-10-01 · plano · onda 0 — Sem migration no banco da aplicação.
- 2026-10-01 · plano (revisão) · onda 0 — Achados abertos da revisão final das ondas 13–16 da rodada 2, só código do produto:
  - (1) `CvUploaderService:60` sem checagem de extensão quando a URL não traz `extensions`, e o Drive sem MIME → T-20 (onda 1, prioridade alta, arquivos disjuntos). Ela também leva o docblock e o trim de `UploadedTmpFile`, o `unlink` do `SystemProfileForm` e o teste de `\0` nas pontas;
  - (2) `SystemMessageForm` sem `setData` e (3) `$table` sem rollback no `SystemDatabaseExplorer` já estavam em T-15 (linhas atualizadas para o HEAD 39de5ef: 208-212 e 496-501);
  - (4) `CvAvatar::placeholder` → `titleFor` já estava em T-09 (`testPlaceholderTitleGoesThroughTitleFor`);
  - (5) `SystemWikiPagePicker:24-25` é `TDBCombo` com `enableSearch` (select2), e não "select nativo" como diz `reports/T-65.md:60`. O título de wiki é editado por um perfil e exibido a outros, então o combo entra na mitigação do `CvSafeLabelTrait` → T-21. O relatório da rodada 2 não é editado (evidência histórica);
  - framework (TMultiEntry, theme.js, hash do mask) e `CvPage::header`/`cvEscapeTitle` sem teste ficam em `plan.md § Excluído`. Os GATEs `não rodado` da rodada 2 entram na varredura de T-18.

## Bloqueios
- (resolvido) Pré-requisito da rodada 2 cumprido: T-62..T-65 `[x]`, HEAD `39de5ef` de `feat/rodada-2-cadastros-schema-acoes` e revisão final das ondas 13–16 aprovada. O orquestrador cria `feat/rodada-3-divida-tecnica` a partir de `39de5ef`.
- Entre as ondas 1 e 2: provisionamento do `centralvet_test` (`scripts/test-db/provision.sh` + `verify.sql`, de T-05). Desbloqueio: aprovação SQL específica do usuário no momento; o orquestrador registra aqui comandos, contagens e `schema_migrations`. Sem ela, T-19 fica `[!]` e a SUITE segue no banco de dev.

## Descobertas
- Já resolvidos na rodada 2 e confirmados na exploração (sem task): `json_encode` com `JSON_INVALID_UTF8_SUBSTITUTE` (`AuthorizationRequest.php:43`); N+1 de `PrescriptionTemplateService::listAll` e validação de item vazio e tamanhos (`PrescriptionTemplate.php:84,91-92,175-176`); `MAX_DATABASE` constante (`RedisConnectionFactory.php:29`); `ms-1` em `span.agenda-block-time` (`AgendaView.php:404`); docblock do `ServiceImportForm`; `FinancialEntryForm` já itera `FinancialEntry::PAYMENT_METHODS` (a lista à mão está em `PaymentForm.php:278-284`).
- Lacunas de teste já cobertas: `payment_method` (FinancialEntryRepositoryIntegrationTest:42,76), INSERT e `findByCode` de produto (ProductRepositoryIntegrationTest:36,90), `paused_*` (EncounterRepositoryIntegrationTest:51,63,97), `CvFormat::userError` (CvFormatUserErrorTest), clamp de `accumulatePause` (EncounterServiceTest:185), `ClinicalSummary` (ClinicalSummaryIntegrationTest:279,306), reschedule de outro tenant e AuthorizationDenied (AppointmentServiceTest:434,456,482).
- As chaves "%s days" e "This entry cannot advance right now: ^1" existem em `translations.json`; o defeito real é que `_t` só troca `^1..^4` (`lib/util/ApplicationTranslator.php:148`), então "%s days" sai literal (T-11 troca para `^1 days`).
- Chaves órfãs: `Uploaded file was not found` está órfã (T-16 remove); `Selected tutor was not found for your account` já não existe; `Attachment not found` é usada (`EncounterView.php:1954`).
- Os testes MySQL já fazem transação + rollback (`MysqlIntegrationTestCase.php:51,54-58`) no banco de dev; não existe banco de teste. `AppointmentFormPostIntegrationTest` não grava (subprocesso só lê o form).
- `RedisQueueIntegrationTest` já usa fila única (commit 73600c2); o que sobra são timeouts curtos e `recoverDue` com backoff 0.
- `EncounterView.php` tem 32 catches e `EncounterAccountForm.php` 22; nenhum outro arquivo é tocado por duas tasks na mesma onda.

## Pendências
- nenhuma

## Riscos
- A rodada 2 pode mudar arquivos deste plano ao fechar (T-65 mexe em `PatientForm`, `AppointmentForm`, `EncounterView`, `SaleForm` e models). Mitigação: o orquestrador confere `git -C /var/www/html/centralvet diff --stat <HEAD de hoje>..<HEAD de partida>` antes da onda 1 e, se um arquivo do Mapa mudou, as linhas citadas nas tasks são conferidas pelo implementador antes de editar.
- SUITEs simultâneas de vários agentes na onda 1 (9 tasks) podem dar falso FAIL nos testes Redis. Mitigação: o gate roda a SUITE sozinho; T-06 mede e corrige.
- Padrões genéricos de `UserMessage` podem capturar mensagens que hoje caem no fallback e mudar o texto de telas não listadas. Mitigação: ficam por último em `PATTERNS`, e STATIC é consultado antes; a varredura da onda 3 confere as telas.
- O `centralvet_test` nasce dos arquivos em disco. Migrations com checksum de zeros no arquivo (ex.: 0006) gravam zeros em `centralvet_test.schema_migrations`, e algum teste pode depender de dado que só existe no dev. Mitigação: o `verify.sql` compara as tabelas, e T-19 compara `Total`/`Skipped` com a SUITE da BASE; diferença vira pendência com a classe de teste.
- Download negado quando a unidade selecionada difere da unidade do anexo (tenant com 2 unidades). É o comportamento pretendido; o gate de T-03 roda com a unidade do atendimento.

## Retomada
- Pasta: `.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/`
- Sessões: ce4d9a4f-5d35-46ec-a771-8ef03d42a254
- Branch de trabalho: feat/rodada-3-divida-tecnica (base: feat/rodada-2-cadastros-schema-acoes)
- BASE da onda 1: {hash7}
- Commits por onda: {Onda N: BASE <hash7> → HEAD <hash7> (<hashes dos commits>)}
- Último status conhecido: {resumo}
- Próxima onda recomendada: {onda}
