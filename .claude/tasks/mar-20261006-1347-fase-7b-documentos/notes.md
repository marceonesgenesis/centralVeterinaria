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

## Pendências
- Anexos existentes (`PatientForm`, `EncounterView`, `ExamResultForm`) continuam presos a `S3CompatibleStorage::fromEnvironment`. Numa hospedagem sem S3, eles precisam migrar para uma fábrica com driver local (fora do escopo 7B).
- Retenção e expurgo de PDFs e de `generated_document.body_text` não definidos (registrar no runbook T-20).
- T-02: lacunas de teste (ready sem storage_key, limite exato de 20000, readonly de DocumentContent); `fileName()` com id null devolve `<kind>-0-v0.pdf`; `reconstitute` não confere source_type contra o kind; `TOKEN_PATTERN` ignora `{{a.b}}`/`{{cpf-x}}`; board omite mensagens de validação novas.
- T-03: get/exists/delete validam o caminho só lexicamente (symlink no root); testes de root fora do webroot/inexistente só conferem o tipo da exceção.
- Onda 1: aplicar a 0013 (`centralvet` e `centralvet_test`) e a DML da T-04 no bloqueio entre as ondas 1 e 2, com aprovação SQL do usuário.
- Onda 1: teste "writable" do storage fica para o gate da onda 4.

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
- Último status conhecido: onda 1 concluída (T-01..T-04 [x]); gate SUITE 998/998, LINT, PYTEST57, preparador 5.7 ok; 0013 e DML T-04 ainda não aplicadas
- Próxima onda recomendada: bloqueio SQL (0013 + T-04) e depois onda 2 (T-05..T-13 conforme dependências)
