# Board — mar-20261006-1347-fase-7b-documentos

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-03] LocalFilesystemStorage/DocumentStorageFactory prontos (commit 25f9d54). StoredObjectMetadata do driver local: storageProvider='local', bucket='local', objectKey='cv/<env>/tenant/<id>/objects/<chave>' (relativo ao root), versionId null. get de objeto ausente lança StorageException('Stored object not found').
- [T-03] i18n-domínio: Local storage root must be outside the web root
- [T-03] i18n-domínio: Local storage root is not a directory
- [T-03] i18n-domínio: Stored object not found
- [T-03] i18n-domínio: Presigned URLs are not supported by the local storage driver
- [T-03] i18n-domínio: Unknown document storage driver
- [T-03] i18n-domínio: Could not create local storage directory / Resolved storage path escapes the local storage root / Could not create temporary file in local storage / Could not write object to local storage / Could not read object from local storage / Could not delete object from local storage (mensagens internas de falha de I/O, além do plano)
- [T-02] i18n-domínio: Unknown document kind "<kind>"
- [T-02] i18n-domínio: Document generation failed: <code>
- [T-02] i18n-domínio: Document not found
- [T-02] i18n-domínio: Document source not found
- [T-02] i18n-domínio: Document body must be between 1 and 20000 characters
- [T-02] i18n-domínio: Document kind "<kind>" does not accept a body
- [T-02] i18n-domínio: Document kind "<kind>" does not use templates
- [T-02] i18n-domínio: Document kind "<kind>" has no default template
- [T-02] i18n-domínio: Unknown placeholder "<name>" in template
- [T-02] i18n-domínio: name must be between 1 and 120 characters / body must be between 1 and 20000 characters (DocumentTemplate; mesmos textos da 7A)
- [T-02] Contratos commitados em cecca44 sem renomear tokens. Extras públicos (não quebram contrato): DocumentKind::SOURCE_PATIENT/SOURCE_PRESCRIPTION/SOURCE_SURGERY, DocumentKind::requiresBodyText(), GeneratedDocument::BODY_TEXT_MAX_LENGTH, DocumentTemplate::assignId(int), tenantId(), createdBySystemUserId(), updatedBySystemUserId(), createdAt(), updatedAt(). As interfaces de repositório NÃO estendem TenantRepositoryInterface (assinaturas literais do plano; findById(int)).
- [T-02] DocumentTemplate::create aceita só kind com usesTemplate (medical_certificate) e recusa placeholder fora de DocumentTemplateRenderer::PLACEHOLDERS; render() mantém {{nome}} quando o valor é null/ausente.
- [T-08] DocumentHtmlBuilder/DompdfDocumentRenderer prontos (namespace CentralVet\Document, src/app/Core/Document/). Extras públicos: DompdfDocumentRenderer::__construct(DocumentHtmlBuilder $htmlBuilder = new DocumentHtmlBuilder()) e options(): Dompdf\Options (opções exatas do render). Linha do sujeito "Rótulo: valor" vira rótulo em <strong> até o primeiro ':'. Nenhuma mensagem i18n nova.
- [T-09] PendingItem::TYPE_DOCUMENT_FAILED='document_failed' (9º e último em TYPES), DEEP_LINK_CLASSES/KEYS ganham 'DocumentList' => ['patient_id']; prioridade sempre high; PendingItemQuery::listForUnit inclui failedDocuments (assunto '<DocumentKind::titleFor> v<versão>', due_at=failed_at, mais novo primeiro).
- [T-09] Arquivo travado fora do escopo: CommunicationReadModelIntegrationTest::testEachOfTheEightTypesAppearsWithTheDeepLinkOfTheDomain (linha 94) exige tipos presentes == PendingItem::TYPES; sem documento falho na fixture, falha agora. Correção sugerida: comparar com array_values(array_diff(PendingItem::TYPES, [PendingItem::TYPE_DOCUMENT_FAILED])) ou semear um generated_document failed. As contagens 9/8 do mesmo arquivo continuam válidas.
- [T-09] i18n: Failed documents → Documentos com falha (rótulo do card em PendingCenter::TYPE_META)
- [T-05] Fakes commitados (RED baa5fe1, impl 993a5f6). failWith de FakeDocumentRenderer/FakeDocumentContentFactory é de uma chamada só e a chamada que falha entra em calls(); FakeDocumentContentFactory::calls() extra (list<GeneratedDocument>). simulateConcurrentClaim só grava claimed_at=agora (attempt_count intacto); claim incrementa attempt_count, releaseClaim não. insertNextVersion lança InvalidArgumentException para outro tenant ou documento já com id; seed() aceita qualquer tenant. FakeDocumentSourceQuery sem tenant: não semeado → null/[].
- [T-06] GeneratedDocumentRepository/DocumentTemplateRepository prontos; não estendem AbstractTenantRepository (findById(int) do contrato T-02). claim incrementa attempt_count (releaseClaim não); markFailed exige só status queued (sem claim). Template com nome duplicado no tenant sobe como PDOException 1062 (o service traduz).
- [T-06] i18n-domínio: Could not allocate document version
- [T-09] Ruling aplicado: CommunicationReadModelIntegrationTest ganhou um generated_document failed por tenant na unidade A (contagens 9→10 e 8→9; teste renomeado para testEachTypeAppearsWithTheDeepLinkOfTheDomain). SUITE Failed: 0.
