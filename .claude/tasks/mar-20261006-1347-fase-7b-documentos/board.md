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
