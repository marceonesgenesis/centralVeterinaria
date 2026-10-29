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
- [T-11] DocumentContentFactory pronta (RED e6577fd, impl no commit seguinte). subjectLines são strings "Rótulo: valor" (Paciente, Espécie "Cão (Raça)", Tutor; termo + Procedimento, Data prevista d/m/Y H:i). Texto livre vira uma linha não vazia por parágrafo. surgery_consent: 1ª linha do snapshot = signatureName e fica FORA de paragraphs (só o consent_text). Fonte ausente lança DocumentGenerationFailed(source_not_found) (o docblock de DocumentContentFactoryInterface cita DocumentSourceNotFoundException; vale o plano). Sem mensagens i18n novas.
- [T-13] DocumentTemplateService pronto (RED 3d00204). save: id inexistente/alheio → CrossTenantReferenceException; nome duplicado checado sem diferenciar maiúsculas (listAll) e também via PDOException 1062. mergeForPatient: template 0 → padrão; template ausente/alheio/inativo → InvalidArgumentException('Document template is not available'); sem unidade ativa, unit_name/clinic_name ficam null e o renderer mantém {{unit_name}}/{{clinic_name}}; variável null fica como {{nome}}.
- [T-13] i18n-domínio: Unknown placeholder: {{<nome>}}
- [T-13] i18n-domínio: A document template with this name already exists
- [T-13] i18n-domínio: Document template is not available
- [T-13] i18n-domínio: Unknown document template status "<status>" (mesmo texto da entidade T-02)
- [T-10] DocumentRequestService/DocumentJobPublisher prontos (RED 49faf9f). download/retry: inexistente, outra unidade ou estado errado → DocumentNotAvailableException antes de autorizar e sem ler o storage; só StorageException do get vira DocumentNotAvailableException. Autorização de download/retry com entityId = id e metadata {document_id, kind, version}; listForUnit autoriza sem metadata. medical_certificate sem template_id é aceito (texto livre); bodyText nulo/vazio cai no 'Document body must be between 1 and 20000 characters' do domínio. Publisher valida ids > 0.
- [T-10] i18n-domínio: Patient has no vaccinations to print
- [T-10] i18n-domínio: Surgery consent has not been recorded
- [T-10] i18n-domínio: Document text has unresolved placeholders
- [T-10] i18n-domínio: Document template is not available
- [T-10] i18n-domínio: Document <id> can no longer be retried
- [T-10] i18n-domínio: Tenant id and document id must be positive
- [T-12] DocumentGenerationService/DocumentReadyNotifier/DocumentGenerationResult prontos (RED b7f46cd, impl fa09830). Passo 3 aceita DocumentSourceNotFoundException (contrato de DocumentContentFactoryInterface) e DocumentGenerationFailed(SOURCE_NOT_FOUND) → markFailed na hora; outra exceção de build → render_failed. Transação padrão: static fn (Closure $work) => $work() — o handler da T-14 deve passar um Closure que recebe o trabalho e devolve o resultado dele. DocumentGenerationResult tem fábricas extras ready()/skipped()/failed(). Nenhuma mensagem i18n nova.
- [T-18] Navegação pronta (RED c3f1c9c, impl 6c0b820): menu Documents (fas:file-pdf) após CRM / Communication com Documents → DocumentList e Document templates → DocumentTemplateList; CvNav::group('documents') com abas documents/templates; links por href: PatientForm (edição) DocumentList&patient_id= e DocumentRequestForm&kind=medical_certificate&source_id=<patient_id>; VaccinationCardView kind=vaccination_card&source_id=<patient_id>; SurgeryView kind=surgery_consent&source_id=<surgery_id>; PrescriptionForm "Archive PDF" kind=prescription&source_id=<prescription_id> (onGeneratePdf intacto).
- [T-18] UserMessageTest::testEverySurgeryScreenKeyHasATranslation falha até a T-19 traduzir 'Consent PDF' (varre _t() de Surgery*.php).
- [T-18] i18n: Documents → Documentos (rótulo de menu/aba/ação; já existe no AdiantiTemplateTranslator, conferir pt)
- [T-18] i18n: Document templates → Modelos de documento
- [T-18] i18n: Medical certificate → Atestado
- [T-18] i18n: Consent PDF → PDF do termo
- [T-18] i18n: Archive PDF → Arquivar PDF
- [T-17] DocumentTemplateList/DocumentTemplateForm prontos (RED d20f911). Edição carrega pelo listAll() do service (sem find no contrato); id ausente/alheio → CrossTenantReferenceException → "The selected record does not belong to this clinic". Extra público: DocumentTemplateForm::kindLabel(string). Programas ACTION_*: DocumentTemplateList::onReload, DocumentTemplateForm::onSave/onEdit.
- [T-17] i18n: Kind → Tipo
- [T-17] i18n: Actions → Ações
- [T-17] i18n: Document templates → Templates de documento
- [T-17] i18n: Documents → Documentos
- [T-17] i18n: Medical certificate → Atestado
- [T-17] i18n: No document templates registered → Nenhum template de documento cadastrado
- [T-17] i18n: You are not allowed to manage document templates → Você não tem permissão para gerenciar templates de documento
- [T-17] i18n: Edit document template → Editar template de documento
- [T-17] i18n: New document template → Novo template de documento
- [T-17] i18n: Text → Texto
- [T-17] i18n: Available placeholders → Variáveis disponíveis
- [T-16] DocumentList pronto (RED fd34d69). Rotas: DocumentList[&patient_id=], onDownload&id=&static=1 (404 único com _t('Document not found') para inexistente/outra unidade/queued/sem sessão/sem permissão; error_log só com a classe), onAskRetry&id= (TQuestion) → onRetry&id= (retry + publish; recarrega DocumentList sem patient_id). Subtítulo: nome do paciente (patientSummary) ou TSession userunitname.
- [T-16] i18n: Documents → Documentos (já existe) / Refresh / Document / Version / Requested at / Actions / Download / Try again / Processing… / Ready / Queued / Failed / No documents yet / Document not found (texto do 404; hoje sai "Message not found: Document not found") / Try to generate this document again? / Document requeued / You are not allowed to view the documents / You are not allowed to change this document — pt a definir na T-19 (sugestões: Atualizar, Documento, Versão, Pedido em, Ações, Baixar, Tentar de novo, Processando…, Pronto, Na fila, Falhou, Nenhum documento ainda, Documento não encontrado, Gerar este documento novamente?, Documento reenfileirado, Você não tem permissão para ver os documentos, Você não tem permissão para alterar este documento)
- [T-14] DocumentJobHandler/DocumentSweeper prontos (namespace CentralVet\Document; RED 6820f0f, impl 0b2852a). Extra público DocumentSweeper::forConnection(PDO, DocumentJobPublisher, LoggerInterface, int $systemUserId). worker.php: despacho document.generate (handler lazy com a fila do loop), tick do varredor DOCUMENT_SWEEP_INTERVAL_SECONDS (600, 0 desliga) só no contínuo, DOCUMENT_SYSTEM_USER_ID (1). Logs: document.job.<ready|skipped|failed|invalid>, document.sweep.completed/tenant_failed/failed. Nenhuma mensagem i18n nova.
- [T-15] DocumentRequestForm pronto (RED 0896446). Campos: hidden kind/source_id, kind_label (só leitura), template_id (0 = Default text) e body_text só no atestado, notify_tutor (TCheckButton índice '1'), botão onSave. Ganchos protegidos estáticos (loadTemplateOptions/loadInitialBody/loadConsentSummary/mergeTemplate). Voltar: PatientForm&method=onEdit (paciente), SurgeryView&id (termo), DocumentList (receita). Redireciona para DocumentList&patient_id=<GeneratedDocument::patientId()>.
- [T-15] i18n: New document → Novo documento
- [T-15] i18n: Invalid document request → Pedido de documento inválido
- [T-15] i18n: Document type → Tipo de documento
- [T-15] i18n: Default text → Texto padrão
- [T-15] i18n: Text → Texto
- [T-15] i18n: The consent text recorded on the surgery will be used → Será usado o texto do termo registrado na cirurgia
- [T-15] i18n: Notify the tutor when ready → Avisar o tutor quando estiver pronto
- [T-15] i18n: Communication consent unavailable → Consentimento de comunicação indisponível
- [T-15] i18n: authorized → autorizado
- [T-15] i18n: not authorized → não autorizado
- [T-15] i18n: Document requested. It will be available in the list in a few moments. → Documento solicitado. Ele estará disponível na lista em instantes.
- [T-15] i18n: You are not allowed to request documents → Você não tem permissão para solicitar documentos
- [T-15] i18n: Vaccination card / Prescription / Medical certificate / Surgery consent → Carteira de vacinação / Receita / Atestado / Termo de consentimento cirúrgico (conferir se já existem; Generate PDF já traduz para Gerar PDF)
- [T-19] i18n pronto (RED de25718): 49 chaves novas em translations.json (Document templates → "Modelos de documento", alinhado a "Modelos de mensagem" da 7A, não "Templates de documento"); UserMessage STATIC 62→72 e PATTERNS 86→92. "Unknown document kind" virou padrão (a mensagem real traz o kind entre aspas); "Document generation failed: <code>" → "The document could not be generated" sem expor o código. Mensagens de I/O do storage (T-03) e "Tenant id and document id must be positive" ficam fora do catálogo (internas, não chegam à tela). DocumentKind::titleFor segue em pt fixo (fora do escopo).
- [T-21] sql/T-21-cleanup.sql pronto (96938c1, não executado): deriva tudo do prefixo 'F7B teste' + guardas de id pré-Onda 4; seção 2.7 tem placeholders '<ids roteiro A/B>' a completar com os ids criados pelos validadores. Tutor da Onda 4 usa e-mail f7b@example.invalid (não f7b.teste@). Roteiros A/B (Playwright, worker --once, republicação no Redis) ficam com o validador.
- [T-23] Domínio/Aplicação (RED b859f1e, fix eb348e1): GeneratedDocument::fileName() sem id lança LogicException e reconstitute recusa source_type ≠ DocumentKind::sourceTypeFor(kind); TOKEN_PATTERN de DocumentTemplateRenderer agora casa `{{[^{}\s]+}}` (também DocumentTemplate) e `breed` é opcional (OPTIONAL_PLACEHOLDERS → "—"); DocumentRequestService resolve {{breed}} no pedido e recusa tokens desconhecidos com a mensagem existente; markReady=false vira skipped (DocumentClaimLostException interna); fake novo src/tests/Support/CountingDocumentSourceQuery.php. i18n-domínio (internas, não chegam à tela): "Generated document has no id yet", "Document source type \"<x>\" does not match kind \"<y>\"".
- [T-25] Onda 8 pronta (RED d91bf90/c458ef7, fix 9ca61a9/316737b): consentSummaryFor com unidade+RBAC (DocumentRequestForm::onLoad), onSave/onRetry com ganchos testados, retry volta com patient_id, título da lista por CvDocumentKind::title (_t), Ações à esquerda, topo 44 px em cv-components.css (custom.css/layout.html protegidos por hash), runbook/SQL; i18n: Prescription document → Receita, Surgical consent form → Termo de consentimento cirúrgico, + 2 mensagens da T-23 no UserMessage (73/93). Pendente: find por id no DocumentTemplateService.
- [T-24] Persistence/Storage/Document pronto (RED 4e56f6d, fix e18c4ca): varredor usa updated_at p/ linha sem claim (sem migration); deadlock 1213 retentado só fora de transação; storage resolve realpath; DompdfDocumentRenderer::renderHtml público; fakes com seed(colunas)/row()/simulateConcurrentClaim($at). Nenhuma mensagem i18n nova. Docblock da interface (Domain:63) ainda cita created_at.
- [T-23] Docblock de listStaleQueuedIds na interface alinhado ao updated_at da T-24 (e18c4ca).
