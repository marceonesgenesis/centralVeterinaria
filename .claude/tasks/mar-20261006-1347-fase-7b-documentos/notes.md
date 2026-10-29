# Notas de execução

## Decisões tomadas
- 2026-10-06 · plano · onda 0 — **Escopo e tipos.** Fase 7B = PRD §8.21 (Documentos) + item "documentos" da §8.23 (Central de Pendências), MVP.
  - Tipos no MVP: `vaccination_card`, `prescription`, `medical_certificate` e `surgery_consent`.
  - Fora: orçamento (não há tabela), recibo de recebimento, laudo gerado e relatório de alta.
  - O PDF síncrono existente (`PrescriptionForm::onGeneratePdf`, `SaleForm::onGenerateReceiptPdf`, `ProductList::onReport`) fica intocado. A receita ganha o caminho assíncrono e arquivado em paralelo (link "Archive PDF").
- 2026-10-06 · plano · onda 0 — **Storage.** Driver local novo (`LocalFilesystemStorage`) sobre a `StorageInterface` existente, com `DocumentStorageFactory` por `DOCUMENT_STORAGE_DRIVER` (`local` padrão, ou `s3`).
  - Pasta `/var/www/html/var/documents` (fora de `src/`, o webroot), em volume nomeado novo `app_documents` e criada no `Dockerfile` com dono `www-data`. O container é `read_only`; o único gravável persistente era `src/files`, que fica dentro do webroot.
  - O driver recusa root dentro do webroot.
  - `STORAGE_DRIVER` e os anexos existentes (S3) não mudam, para não quebrar `PatientForm`/`EncounterView`/`ExamResultForm`. Migrar os anexos para a fábrica fica como pendência da hospedagem sem S3.
- 2026-10-06 · plano · onda 0 — **Download.** Só pelo controller (`DocumentList::onDownload&id=`), com RBAC + tenant + unidade ativa.
  - Documento de outra unidade, `queued` ou inexistente → 404 com o mesmo texto (sem oráculo; lição da T-23 da 7A).
  - Sem URL pré-assinada nem token público: não há usuário tutor, e o driver local não tem presign (`presignedUrl` lança).
  - Nome do arquivo `<kind>-<id>-v<versão>.pdf`, sem dado pessoal.
- 2026-10-06 · plano · onda 0 — **Aviso `document_ready`.** Automático só com `notify_tutor` marcado no pedido e `opted_in` explícito no canal (base `consent`, como a 7A fixou). O texto é o template/padrão da 7A, sem link e sem anexo.
  - Dedupe por `dedupe_key` `document_ready:document:<id>:<canal>` com `source_type`/`source_id` NULL.
  - **Não** se amplia `communication_message_source_ck` nem `OutboundMessage::SOURCE_TYPES`: DROP/ADD CHECK não passa pelo preparador 5.7 (CHECK → triggers).
  - Custo aceito: a mensagem não aponta a origem por coluna, só pela chave.
- 2026-10-06 · plano · onda 0 — **Assíncrono.** Job `document.generate` na fila `default` (`MAX_ATTEMPTS = 3`), publicado pelo controller depois do commit.
  - O worker faz claim condicional, render, `put` e, numa transação injetada (`?Closure $transaction`), `stored_object` + `markReady` condicional + avisos. Se a transação falha, o objeto é apagado.
  - `source_not_found` falha na hora; as demais falhas são retentadas e, na última tentativa, ficam `failed`.
  - Varredor `DocumentSweeper` (comando + tick do worker, `DOCUMENT_SWEEP_INTERVAL_SECONDS` 600) republica `queued` presos há mais de 10 min.
- 2026-10-06 · plano · onda 0 — **Versionamento.** Cada pedido é uma linha com `version = MAX+1` por fonte (UNIQUE + nova tentativa em 1062). O retry reaproveita a linha `failed`. O termo cirúrgico e o atestado congelam o texto em `generated_document.body_text` no pedido.
- 2026-10-06 · plano · onda 0 — **Templates.** Por tenant, só para `medical_certificate` (CHECK `kind IN ('medical_certificate')`, extensível), com placeholders fechados e texto padrão em código. O termo usa o texto já registrado na 6B.
- 2026-10-06 · plano · onda 0 — **RBAC.** 4 programas (`DocumentList`, `DocumentRequestForm`, `DocumentTemplateList`, `DocumentTemplateForm`) para os grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia`, mantendo a decisão da 7A (16 concessões; grupo 3 nada). A demanda pediu para confirmar os grupos "1,2,4,5": o planejador manteve a regra da 7A (1 e 2 por id, 4 e 5 pelo nome).
- 2026-10-06 · plano · onda 0 — **Branch e base.** Branch de trabalho `feat/fase-7b-documentos` (nome dado pelo orquestrador; o padrão da skill seria `task/fase-7b-documentos`), base `feat/fase-7a-comunicacao` @ `83029c3`.
- 2026-10-06 · plano · onda 0 — **Gates.** Mesmo esquema econômico da 7A.
  - Ondas 1–3: LINT + SUITE.
  - Onda 4: rebuild (cria o volume), PDF real pelo worker `--once`, varredor, grep de dado pessoal no log e smoke desktop das 4 telas.
  - Onda 5: fetch em pt.
  - Onda 6: E2E em dois disparos.
- 2026-10-06 · plano · onda 0 — **Baseline.** php-lint 0 linhas (593 PHP sem erro) e test-prepare-mysql57 com `Ran 8 tests` / `OK`, gravados em `baseline/`.
- 2026-10-06 · T-01 · onda 1 — Sete índices nomeados nas colunas de FK além do plano (padrão da 0012); contrato inalterado: aceito.
- 2026-10-06 · T-02 · onda 1 — Adições públicas extras (`DocumentTemplate::assignId`, `DocumentKind::requiresBodyText`) e interfaces de repositório sem estender `TenantRepositoryInterface`: aceito (registrado no board).
- 2026-10-06 · T-04 · onda 1 — RBAC: usuário confirmou os grupos 1, 2, 4 e 5 (mesmos da 7A).
- 2026-10-06 · T-09 · onda 2 — Autorizada a alterar `CommunicationReadModelIntegrationTest` (54183c2): fixture ganhou documento `failed`, sem excluir o tipo da comparação.
- 2026-10-06 · T-06 · onda 2 — Repositórios sem estender `AbstractTenantRepository` (escopo via `TenantQuery`) e retry após 1062 sem teste: aceito (revisor aprovou).
- 2026-10-06 · T-08 · onda 2 — Extras públicos `options()` e construtor com `DocumentHtmlBuilder` opcional: aceito.
- 2026-10-06 · onda 2 — Pré-condição: migration 0013 aplicada em `centralvet` e `centralvet_test` (sha e5d9f164f91ff5bd1cddecd77e51f177c40de87e3805d083496fe00fe7dc5adf, backup `var/backups/centralvet-20261006T171637Z.sql.gz`); DML T-04 aplicada em `centralvet` (system_program 133→137, system_group_program 173→189; grupos 1,2,4,5 com 4 cada, grupo 3 com 0).
- 2026-10-06 · T-10 · onda 3 — Teste ajustado após o RED (spy de storage lança StorageException como os drivers reais): aceito, sem enfraquecer asserção; caminho `requeueFailed() === false` sem teste (fake final).
- 2026-10-06 · T-11 · onda 3 — Signatário do snapshot em `signatureName`, `subjectLines` "Rótulo: valor", `DocumentGenerationFailed` conforme o plano com docblock da interface desatualizado: aceito.
- 2026-10-06 · T-12 · onda 3 — Passo 3 aceita também `DocumentSourceNotFoundException` (contrato T-02); teste de transação não prova rollback (fake não transacional): aceito, pendência para o gate E2E/integração.
- 2026-10-06 · T-13 · onda 3 — Template ausente/inativo no merge → `InvalidArgumentException('Document template is not available')`: aceito.
- 2026-10-06 · T-18 · onda 4 — UserMessageTest "Missing translation: Consent PDF" é a única falha da SUITE (1108/1109); fica para a T-19 (escritor único de i18n). Textos "Message not found: …" nas telas até a T-19.
- 2026-10-06 · plano · onda 4 — Critério "curl /var/documents/ ≠ 200" (plan.md:386): o 200 é o fallback HTML do app no nginx e nem o caminho real do PDF devolve %PDF; aceito. Revisor sugere reescrever o critério.
- 2026-10-06 · T-15/T-17 · onda 4 — Ajustes de teste após o RED (comparar com _t(); nome curto da classe) aceitos.
- 2026-10-06 · T-14 · onda 4 — `DocumentSweeper::forConnection` público, fora do plano, aceito.
- 2026-10-06 · T-17 · onda 4 — Edição filtra `listAll()` por id (T-13 sem `find`), aceito.
- 2026-10-06 · T-19 · onda 5 — Desvios aceitos: "Unknown document kind" como padrão com aspas; código de falha não exibido na tela; "Modelos de documento" segue o termo da 7A; mensagens internas de storage ficam fora do catálogo.
- 2026-10-06 · T-21 · onda 6 — "Tentar novamente"/document_failed e usuário sem permissão não exercitados em E2E: cobertos por T-09/T-12/testes de RBAC.
- 2026-10-06 · T-21 · onda 6 — RF1 outro tenant: ambiente com 1 tenant; cobertura por testes de integração (T-06/T-07) + 404 único E2E (outra unidade, queued, inexistente); pendência para ambiente com 2 tenants.
- 2026-10-06 · T-21 · onda 6 — Critério plan.md:386 "curl /var/documents/ ≠ 200" substituído por "nenhum caminho sob /var/documents serve %PDF" (front controller devolve shell HTML).
- 2026-10-06 · T-21 · onda 6 — Ações de estado no gate (local): worker parado/religado, DEL da fila Redis pending para simular job perdido, listener php -S 127.0.0.1:9 no worker (encerrado, sem processo restante).
- 2026-10-06 · T-16 · onda 6 — Fix do gate (RED bc70e62, fix 1087d3c): "Baixar" passa por engine.php?class=DocumentList&method=onDownload&id=<id>&static=1 com target _blank (index.php devolvia o shell HTML); substitui a rota de download da Interface da T-16.
- 2026-10-06 · T-21 · onda 7 — Limpeza SQL F7B autorizada pelo usuário e executada pelo orquestrador: backup var/backups/centralvet-20261006T183746Z.sql.gz (gzip -t ok), dry run com ROLLBACK (contagens batem com o pré-gate), COMMIT sem erro. Estado final: generated_document 0, document_template 0, stored_object 9/2131, tutor 7/13876, patient 7/9180, surgery 0, surgery_room 0, communication_message 0, communication_preference 0, encounter 7, appointment 15, payment 3; os 9 PDFs de teste removidos do volume app_documents (0 restantes).
- 2026-10-06 · T-22 · onda 7 — Correção pós-revisão final aprovada pelo usuário (RED 25a3a7f, fix d24e252, runbook 31009a4; SUITE 1112/1112): "Gerar PDF" também ganhou cv-touch-target (irmão do "Arquivar PDF"); voltar da DocumentTemplateForm sem teste próprio; orquestrador mediu no navegador todos os botões da DocumentTemplateForm ≥44 px.
- 2026-10-06 · T-23/T-24/T-25 · onda 8 — Correção das pendências da revisão final (RED b859f1e/4e56f6d/d91bf90/c458ef7; fix eb348e1, db3de1e, e18c4ca, 9ca61a9, 316737b): SUITE 1157/1157, PYTEST57, lint 37 PHP, cabeçalho 44x44 em 820/1366, document_failed + "Tentar novamente" e usuário sem acesso negado exercitados, {{breed}} → "—"; revisões aprovadas com sugestões.
- 2026-10-06 · T-26 · onda 8 — Usuário autorizou e o orquestrador executou a limpeza do gate da onda 8 (1d8bf7d): backup centralvet-20261006T191904Z.sql.gz, dry run, COMMIT, PDF removido; system_access_log 62/63 do usuário de teste ficam (sem FK).
- 2026-10-06 · T-27 · onda 8 — Usuário autorizou e o orquestrador executou a criação do 2º tenant de teste (ff14b07): backup centralvet-20261006T192246Z.sql.gz, dry run, COMMIT (tenant 41190, unidade 3, usuário 2). E2E cross-tenant aprovado (404 único nos dois sentidos, formulários/busca/listas/pendências isolados, chaves por tenant, logs sem PII); RF1 cross-tenant resolvido.
- 2026-10-06 · T-27 · onda 8 — Usuário autorizou a limpeza completa do 2º tenant incluindo a variante 3.8-OPCIONAL (16 linhas de audit_log do tenant 41190 apagadas; f3997a4): backup centralvet-20261006T193219Z.sql.gz, dry run (audit_outro_tenant 0, resíduos vazios), COMMIT; 2 PDFs removidos. Estado final: tenant 1 único, system_unit 2, system_users 1, generated_document 0, stored_object 9/2131, tutor 7/13876, patient 7/9180, volume app_documents sem arquivos.

## Bloqueios
- **Bloqueio entre a Onda 1 e a Onda 2** (orquestrador, com aprovação SQL explícita do usuário pela skill `sql-write-approval`):
  1. `./scripts/backup.sh` e `gzip -t`.
  2. SHA-256 da 0013 numa cópia temporária (o arquivo commitado mantém o placeholder).
  3. Aplicar em `centralvet` e em `centralvet_test` com `MIGRATION_DB_USER` e rodar o `.verify.sql` nos dois.
  4. Registrar `COUNT(*)`/`MAX(id)` de `system_program` e `system_group_program` (última referência da 7A: 133 e 173) e aplicar `sql/T-04-programs.sql` (4 programas + 16 concessões) em `centralvet` com `--default-character-set=utf8mb4`; depois, `sql/T-04-programs.verify.sql`. Rollback preparado em `sql/T-04-programs.rollback.sql`, com nova aprovação.
  5. Registrar contagens antes/depois de `patient`, `vaccination`, `prescription`, `surgery`, `stored_object` e `communication_message`.

  Critério de desbloqueio: o `.verify.sql` lista as 2 tabelas e os 9 CHECKs nos dois bancos, e o verify da T-04 mostra 4 concessões em cada um dos grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia` e 0 no grupo 3.
- **Rebuild no gate da Onda 4** (orquestrador): `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`. O `up` cria o volume `app_documents`. Sem rebuild, o worker não enxerga as classes novas (classmap autoritativo) nem a pasta.

## Descobertas
- **Exploração.**
  - Todo PDF de hoje é síncrono com dompdf 3.1.5 e HTML inline no controller, sem arquivo gravado. FPDF e pdfdesigner estão instalados e sem uso.
  - Já existem `StorageInterface`/`S3CompatibleStorage` (SigV4 própria), `stored_object` e `StoredObjectRepository`, além do download seguro de anexo em `EncounterView::onDownloadDocument`. `src/download.php` não tem escopo de tenant e não deve ser usado.
  - O MinIO está no profile `minio`, sem bucket criado.
  - `surgery` já guarda `consent_signer_name`/`consent_text`/`consent_recorded_at` (6B).
  - `patient` não tem unidade; `vaccination` e `prescription` pegam a unidade pelo `encounter`.
- [T-03] Local storage: StoredObjectMetadata com provider/bucket 'local', objectKey relativo ao root, get ausente lança StorageException; mensagens i18n-domínio de I/O listadas no board.
- [T-02] Contratos de documentos commitados em cecca44 com extras públicos aditivos; `DocumentTemplate::create` só aceita kind com template (medical_certificate) e placeholders fechados; `render()` mantém `{{nome}}` quando o valor é null.
- [T-05] Fakes: `failWith` de uma chamada só (a que falha entra em `calls()`); `claim` incrementa `attempt_count`, `releaseClaim` não; `simulateConcurrentClaim` só grava `claimed_at`; `FakeDocumentSourceQuery` não semeado devolve null/[].
- [T-06] Repositórios PDO não estendem `AbstractTenantRepository`; `markFailed` exige só `queued`; template com nome duplicado sobe PDOException 1062 (o service traduz); i18n-domínio: Could not allocate document version.
- [T-07] `system_users` sem `tenant_id` (LEFT JOIN por id, nome ausente → ''); itens da receita por id; datas TIMESTAMP(6) truncadas a `Y-m-d H:i:s`.
- [T-08] `DocumentHtmlBuilder`/`DompdfDocumentRenderer` em `src/app/Core/Document/`; sujeito "Rótulo: valor" com rótulo em `<strong>`; saída sem `%PDF-` vira RENDER_FAILED.
- [T-09] `PendingItem::TYPE_DOCUMENT_FAILED` (9º tipo), prioridade sempre high, deep-link `DocumentList&patient_id`; i18n: "Failed documents" → "Documentos com falha" (T-19).
- [T-10] DocumentRequestService/DocumentJobPublisher: download/retry fora do estado ou de outra unidade → DocumentNotAvailableException sem ler o storage; auditoria com metadata {document_id, kind, version}; 6 mensagens i18n-domínio no board para a T-19.
- [T-11] DocumentContentFactory: subjectLines "Rótulo: valor"; surgery_consent com signatário em signatureName e só o consent_text em paragraphs; fonte ausente → DocumentGenerationFailed(source_not_found); sem i18n novo.
- [T-12] DocumentGenerationService: transação padrão `static fn (Closure $work) => $work()` (o handler da T-14 deve injetar Closure que recebe o trabalho e devolve o resultado); DocumentGenerationResult com fábricas ready/skipped/failed.
- [T-13] DocumentTemplateService: nome duplicado case-insensitive (listAll) e via PDOException 1062; sem unidade ativa unit_name/clinic_name ficam como {{nome}}; 4 mensagens i18n-domínio no board para a T-19.
- [T-18] Menu Documents após CRM/Communication; CvNav grupo documents (abas documents/templates); links por href em PatientForm (DocumentList e atestado), VaccinationCardView, SurgeryView e PrescriptionForm ("Archive PDF", onGeneratePdf intacto). i18n para a T-19: Documents, Document templates, Medical certificate, Consent PDF, Archive PDF.
- [T-17] DocumentTemplateList/Form prontos; extra público `DocumentTemplateForm::kindLabel`. i18n para a T-19: Kind, Actions, Document templates, Documents, Medical certificate, No document templates registered, Edit/New document template, Text, Available placeholders, mensagem de permissão.
- [T-16] DocumentList: onDownload&id=&static=1 com 404 único (`Document not found`), onAskRetry→onRetry (recarrega sem patient_id). i18n para a T-19: Refresh, Document, Version, Requested at, Download, Try again, Processing…, Ready, Queued, Failed, No documents yet, Document not found.
- [T-14] DocumentJobHandler/DocumentSweeper em `CentralVet\Document`; `worker.php` despacha document.generate; `bin/document-sweep.php` roda o varredor.
- [T-15] DocumentRequestForm: kind/source_id, template_id (0 = Default text), body_text só no atestado, notify_tutor; redireciona a DocumentList&patient_id. i18n para a T-19: New document, Invalid document request, Document type, Default text, Notify the tutor when ready, Communication consent unavailable, authorized/not authorized, Document requested…, permissão, nomes dos 4 tipos.
- [gate onda 4] Volume app_documents gravável; PDF real ready (19757 bytes); download 200 %PDF / 404 sem oráculo / anônimo negado; document-sweep exit 0; logs sem PII; smoke 4 telas 1366x768; alvos 44–48 px.
- [gate onda 4] Dados criados para o SQL de limpeza da T-21: tutor 15447, patient 13321, encounter 11105, vaccination 2983, stored_object 2132, generated_document 1, arquivo var/documents/cv/development/tenant/1/objects/documents/1/5ce0f90572413f32.pdf; contagens pré-gate: generated_document 0, tutor 7/13876, patient 7/9180, vaccination 0, stored_object 9/2131. A fixture da T-09 usa título "F7A teste documento" (fora do prefixo F7B).
- [gate onda 6] Ids criados para o SQL de limpeza (sql/T-21-cleanup.sql, NÃO executado; exige aprovação SQL e backup): tutor 15447, patient 13321, encounter 11105, vaccination 2983, prescription 6124, prescription_item 9108, surgery 4, surgery_room 2, document_template 1-2, generated_document 1-9, stored_object 2132-2140 (+ arquivos no volume, 9 rm comentados), communication_preference 8, communication_message 53.
- [T-23] Domínio/Aplicação (RED b859f1e, fix eb348e1): GeneratedDocument::fileName() sem id lança LogicException e reconstitute recusa source_type ≠ DocumentKind::sourceTypeFor(kind); TOKEN_PATTERN de DocumentTemplateRenderer agora casa `{{[^{}\s]+}}` (também DocumentTemplate) e `breed` é opcional (OPTIONAL_PLACEHOLDERS → "—"); DocumentRequestService resolve {{breed}} no pedido e recusa tokens desconhecidos com a mensagem existente; markReady=false vira skipped (DocumentClaimLostException interna); fake novo src/tests/Support/CountingDocumentSourceQuery.php. i18n-domínio (internas, não cheg...
- [T-25] Onda 8 pronta (RED d91bf90/c458ef7, fix 9ca61a9/316737b): consentSummaryFor com unidade+RBAC (DocumentRequestForm::onLoad), onSave/onRetry com ganchos testados, retry volta com patient_id, título da lista por CvDocumentKind::title (_t), Ações à esquerda, topo 44 px em cv-components.css (custom.css/layout.html protegidos por hash), runbook/SQL; i18n: Prescription document → Receita, Surgical consent form → Termo de consentimento cirúrgico, + 2 mensagens da T-23 no UserMessage (73/93). Pendente: find por id no DocumentTemplateService.
- [T-24] Persistence/Storage/Document pronto (RED 4e56f6d, fix e18c4ca): varredor usa updated_at p/ linha sem claim (sem migration); deadlock 1213 retentado só fora de transação; storage resolve realpath; DompdfDocumentRenderer::renderHtml público; fakes com seed(colunas)/row()/simulateConcurrentClaim($at). Nenhuma mensagem i18n nova. Docblock da interface (Domain:63) ainda cita created_at.
- [T-23] Docblock de listStaleQueuedIds na interface alinhado ao updated_at da T-24 (e18c4ca).

## Pendências
- Anexos existentes (`PatientForm`, `EncounterView`, `ExamResultForm`) continuam presos a `S3CompatibleStorage::fromEnvironment`. Numa hospedagem sem S3, eles precisam migrar para uma fábrica com driver local (fora do escopo 7B).
- Retenção e expurgo de PDFs e de `generated_document.body_text` não definidos (registrar no runbook T-20).
- T-02: lacunas de teste (ready sem storage_key, limite exato de 20000, readonly de DocumentContent); `fileName()` com id null devolve `<kind>-0-v0.pdf`; `reconstitute` não confere source_type contra o kind; `TOKEN_PATTERN` ignora `{{a.b}}`/`{{cpf-x}}`; board omite mensagens de validação novas.
- T-03: get/exists/delete validam o caminho só lexicamente (symlink no root); testes de root fora do webroot/inexistente só conferem o tipo da exceção.
- Onda 1: aplicar a 0013 (`centralvet` e `centralvet_test`) e a DML da T-04 no bloqueio entre as ondas 1 e 2, com aprovação SQL do usuário.
- Onda 1: teste "writable" do storage fica para o gate da onda 4.
- T-05: `testTemplateRepositoryFiltersTenantAndKind` não exercita filtro por kind/status; sem caso semeado de prescription/surgery; `simulateConcurrentClaim` usa relógio real; `seed()` descarta size_bytes/sha256/claimed_at/failed_at.
- T-06: retry em 1062 e RuntimeException na 4ª colisão sem teste (testável com PDO stub); avaliar retentar também deadlock 1213 no INSERT ... SELECT.
- T-07: regra "nome de profissional ausente → ''" sem teste.
- T-08: teste confere `options()` e não as opções efetivas do render; ordem das seções do HTML não afirmada em teste.
- T-09: docblock de `PendingCenterService::totalsByType` ainda diz "8 types"; assunto `Receita v1` fixo em pt (`DocumentKind::titleFor`), avaliar na T-19; fixture usa 'F7A teste documento' (fora do prefixo `F7B teste`); relatório diz 24 testes, são 23.
- T-10: `requeueFailed() === false` sem teste; `medical_certificate` sem bodyText sem teste próprio; `patientSummary` consultado 2x por pedido; metadata de auditoria de download/retry não afirmada no teste.
- T-11: kind desconhecido devolve conteúdo vazio em silêncio (switch sem default); docblock de DocumentContentFactoryInterface cita DocumentSourceNotFoundException.
- T-12: ramo `opted_out` do notifier sem teste; dedupe via `insertIfNew` não exercitado; contagem de objetos no FakeStorage não afirmada; teste de transação não prova rollback (fake não transacional), cobrir no gate E2E/integração.
- T-13: retry em 1062 sem teste; sem unidade ativa o texto sai com {{unit_name}}/{{clinic_name}} literais (tratar na T-10/T-15); edição com template de outro kind lança CrossTenantReferenceException.
- Onda 3: mensagens i18n-domínio do board de T-10 (6) e T-13 (4) para a T-19.
- Onda 4 (gate): aviso notify_tutor (document_ready) não exercitado; 404 cross-tenant não exercitado (só 1 tenant); transação real de forEnvironment sem teste unitário.
- T-14: janela de 10 min e limite 100 do varredor sem teste (fake grava created_at null); SUITE Failed: 1 alheio (Consent PDF, T-19).
- T-15: caminho onSave sem teste automatizado; loadConsentSummary lê preferências sem RbacAuthorizationService.
- T-16: critério curl /var/documents/ (plan.md:386) a reescrever; outra unidade/queued → 404 só pelo roteiro B da T-21; onRetry recarrega sem patient_id.
- T-17: cabeçalho Actions alinhado à direita; onEdit varre listAll(); DocumentTemplateList depende de DocumentTemplateForm::kindLabel (mover para helper/DocumentKind).
- T-18: testes de telas clínicas só conferem o prefixo da rota, não o id concatenado; link Archive PDF sem cv-touch-target (PrescriptionForm:165).
- Onda 4: mensagens i18n das telas (T-15..T-18, ver Descobertas) para a T-19.
- T-20: runbook documentos.md:76 escreve a rota como `DocumentList::onDownload&id=`; a real é `engine.php?class=DocumentList&method=onDownload&id=<id>&static=1` (corrigir o runbook).
- Onda 5: botão "PDF do termo" na SurgeryView não visto em tela (sem cirurgia no banco) → verificar no E2E da T-21.
- Onda 5: critério do plano "curl /var/documents/ ≠ 200" (plan.md:386) a reescrever: o front controller devolve 200 para qualquer caminho e nenhum arquivo é servido.
- T-21: SQL sql/T-21-cleanup.sql preparado e NÃO executado (aprovação SQL explícita + backup; os 9 `rm` de arquivos também).
- T-21: RF1 outro tenant só por testes (T-06/T-07) + 404 único E2E; repetir em ambiente com 2 tenants.
- T-21: "Tentar novamente"/document_failed e usuário sem permissão fora do E2E (cobertos por T-09/T-12/RBAC).
- T-21: interface da T-16 (tasks.md:937) ainda cita index.php para o download; a real é engine.php (ver Decisões).
- T-21: cabeçalho do SQL diz que erro interrompe o script, mas no `mysql> source` interativo o cliente segue; ler os erros antes do COMMIT ou rodar em lote.
- T-21: DEL da chave Redis cv:development:queue_default:pending no roteiro B: conteúdo apagado (só o job do doc 7) não verificável.
- T-21: atestado a partir do template "F7B teste Atestado" não exercitado E2E (template só criado).
- Controles globais do cabeçalho (menu, notificações, ajuda) com 40 px: pré-existentes, fora da 7B, violam a regra de 44 px do CLAUDE.md.
- T-22: voltar da DocumentTemplateForm sem teste próprio; linha reflowada longa em docs/runbooks/documentos.md:79; sugestões em reviews/final.md e reviews/T-22.md.
- T-22: aviso do eficiencia.py "onda 7 sem tasks em plan.md" (onda de correção pós-final; T-22 registrada só em tasks.md).
- T-23: signatário decidido pela fonte viva e não pelo snapshot do pedido (termo legado com signatário em branco); TOKEN_PATTERN não detecta token com espaço interno; fixture 'F7A teste documento' (T-09) sem tratamento.
- T-24: retry de deadlock só fora de transação (no controller o 1213 sobe); "link dentro do root" aceito entre tenants e delete() de link apaga só o link; teste de render depende de proc_open.
- T-25: resumo de consentimento audita por abertura (duas linhas com listActive no atestado); onEdit do DocumentTemplateForm varre listAll() (DocumentTemplateService sem find por id); Interface da T-16 em tasks.md ainda cita index.php.
- Onda 8: carteira de vacinação exige vacinas (restante).
- Onda 8: aviso do eficiencia.py "onda 8 sem tasks em plan.md" (onda de correção registrada só em tasks.md); nada gravado em eficiencia/tasks.jsonl.

## Riscos
- **Container `read_only`**: sem a pasta criada na imagem com dono `www-data`, o volume nomeado nasce com dono root e o `put` falha. Mitigação: T-03 no `Dockerfile` e PDF real no gate da Onda 4.
- **dompdf no `read_only`**: o cache de fontes fica em `vendor`. Mitigação: só fontes empacotadas, `tempDir`/`chroot` em `/tmp` (tmpfs de 32 MB) e teste de render no container da SUITE (T-08). PDFs grandes (carteira com muitas doses) cabem com folga.
- **Volume de memória e tempo do worker**: um PDF por job; o tick do varredor é leve (só ids). O heartbeat do worker contínuo segue o padrão da 7A.
- **HTML injection no PDF**: o escape fica centralizado em `DocumentHtmlBuilder`, com `isRemoteEnabled=false` (Review Focus 4).
- **Corrida de versão e redelivery**: UNIQUE + nova tentativa, claim condicional, `markReady` condicional e dedupe do aviso (Review Focus 2).
- **`EncounterView`/`PrescriptionForm`/`PatientForm`/`SurgeryView`/`VaccinationCardView`** de fases anteriores ganham só um link de cabeçalho (T-18). Mitigação: diff restrito e testes de navegação de 6A/6B/7A rodados juntos.
- **`translations.json` e `UserMessage.php`** com um escritor só (T-19, Onda 5). Até lá, "Message not found" é tolerado no smoke da Onda 4.
- **Ondas 2 a 4 com 4 ou 5 escritores e SUITEs simultâneas** (falso FAIL em Redis/deadlock). Mitigação: cada agente filtra a própria classe, e o validador roda a SUITE inteira sozinho no gate.

## Retomada
- Pasta: `.claude/tasks/mar-20261006-1347-fase-7b-documentos/`
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c
- Branch de trabalho: feat/fase-7b-documentos (base: feat/fase-7a-comunicacao)
- BASE da onda 1: 2ead438
- Commits por onda:
  - Onda 1: BASE 2ead438 → HEAD cecca44 (649d988, 78b7b13, 9586797, efb9bbb, 25f9d54, cecca44)
  - Onda 2: BASE f2b8947 → HEAD 54183c2 (15fc70a, baa5fe1, 18b2438, c7d5652, 5b5470a, fe64748, 54f4273, 993a5f6, cf7e01b, e743d30, 54183c2)
  - Onda 3: BASE 0ccd300 → HEAD 12c8479 (e6577fd, 3d00204, b7f46cd, 49faf9f, a41359a, 66446c0, fa09830, 12c8479)
  - Onda 4: BASE e412e11 → HEAD e378577 (c3f1c9c, d20f911, 6c0b820, 0896446, 6820f0f, fd34d69, 265520d, 0b2852a, c85c010, e378577)
  - Onda 5: BASE 6f259c1 → HEAD 098f9c1 (eac4852, de25718, 098f9c1)
  - Onda 6: BASE 4c29344 → HEAD 893e10a (96938c1, bc70e62, 1087d3c, 0f0f10f, 4e6df97, 893e10a)
  - Onda 7: BASE 3bb514d → HEAD 31009a4 (25a3a7f, d24e252, 31009a4)
  - Onda 8: BASE 820fdc4 → HEAD f3997a4 (d91bf90, b859f1e, 4e56f6d, eb348e1, c458ef7, 9ca61a9, 316737b, e18c4ca, db3de1e, 1d8bf7d, ff14b07, f3997a4)
- Último status conhecido: onda 8 concluída (T-23..T-27 [x]); SUITE 1157/1157; E2E cross-tenant aprovado; limpezas SQL do gate e do 2º tenant executadas e verificadas
- Próxima onda recomendada: nenhuma
