# Board — mar-20261001-1520-rodada-3-divida-tecnica

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-01] i18n: Photo must be a JPEG, PNG or WEBP image → A foto deve ser uma imagem JPEG, PNG ou WEBP
- [T-01] i18n: Photo must be at most 2 MB → A foto deve ter no máximo 2 MB
- [T-01] i18n: Encounter ^1 is already finished → O atendimento ^1 já foi finalizado
- [T-01] i18n: Bank account must belong to the current unit → A conta bancária deve pertencer à unidade atual
- [T-01] i18n: Record not found → Registro não encontrado
- [T-01] i18n: Fill in ^1 on every item → Preencha o campo ^1 em todos os itens
- [T-01] i18n: ^1 must have at most ^2 characters → O campo ^1 aceita no máximo ^2 caracteres
- [T-01] i18n: ^1 is required → Campo obrigatório: ^1
- [T-01] O `grep` do PATH neste host é ugrep 7.8.4, que trata `$` como âncora em qualquer posição: `grep -c "\$/D'" UserMessage.php` imprime 0; use `/usr/bin/grep -c "\$/D'"` ou `grep -cF "\$/D'"` (ambos 20).
- [T-04] TutorService::__construct agora exige (TutorRepositoryInterface, TenantContext); create() ignora $data['tenant_id']. Além dos 4 controllers do plano, PatientList.php:150 e PendingReceivableList.php:302 também construíam TutorService e foram ajustados (só a construção) — T-10/T-12 encontram esses arquivos já alterados.
- [T-02] Parser de data e hora agora é `CentralVet\Support\DateTimeInput` (src/app/Core/Support/); `CentralVet\Presentation\DateTimeInput::parse` só delega e perdeu as constantes MIN_YEAR/MAX_YEAR (ficam em Support).
- [T-21] SystemWikiPage usa CvSafeLabelTrait e expõe {title_safe}; SystemWikiPagePicker usa safeSearchMask('title_safe') (ordem 'title', enableSearch mantido) — commit 6d57324. Banco local tem 0 páginas de wiki: a página R3 fica para o validador no gate.
- [T-04] TutorService::__construct agora exige (TutorRepositoryInterface, TenantContext); create() ignora $data['tenant_id'] (mensagem "tenant_id is required..." removida). Além dos 4 controllers do plano, PatientList.php:150 e PendingReceivableList.php:302 também construíam TutorService e foram ajustados (só a construção, commit 5e058b0) — T-10/T-12 encontram esses arquivos já alterados.
- [T-20] CvUploaderService agora confere a extensão sempre: TFile/TMultiFile sem setAllowedExtensions (SystemMessageForm, SystemSupportForm, EncounterView docFile) passam a aceitar só UploadedTmpFile::DEFAULT_EXTENSIONS (pdf, jpg, jpeg, png, webp, txt, csv); o gate deve conferir anexos dessas telas.
- [T-20] UploadedTmpFile::resolve/resolveForSession aparam só " \t\n\r\x0B" (o byte nulo não é mais aparado e é recusado); resolveForSession apara o nome e a lista da sessão uma vez.
- [T-03] EncounterDocumentService::__construct ganhou o 4º argumento opcional ?EncounterRepositoryInterface $encounters; sem ele (e sem unidade selecionada) download() devolve null. EncounterView::makeEncounterDocumentService já o passa (commit 317dac1); ExamResultForm (só attach) segue com 3 argumentos. O 404 de onDownloadDocument escreve o corpo "Attachment not found" (não vem vazio).
- [T-05] MysqlIntegrationTestCase::tearDown() agora lança RuntimeException se o teste encerrou a transação (COMMIT/DDL); subclasse que sobrescreve tearDown deve chamar parent::tearDown(). DSN vem de TestDatabase::resolveName(getenv()) (TEST_DB_DATABASE; DEFAULT_NAME = null até T-19).
- [T-06] medição com 4 SUITEs simultâneas em andamento
- [T-06] medição com 4 SUITEs simultâneas concluída; RedisQueue::recoverDue passa a usar sprintf('%.17g', microtime(true)) como limite do zRangeByScore (assinatura inalterada)
- [T-20] Correção 1: Drive usa SystemDriveDocumentUploadForm::MIMES_BY_EXTENSION + UploadedTmpFile::mimeMatchesExtension (finfo tem de casar com a extensão); ALLOWED_MIMES foi removida.
- [T-20] Correção 2 (ruling): SystemMessageForm e SystemSupportForm declaram ATTACHMENT_EXTENSIONS (DEFAULT_EXTENSIONS + doc, docx, xls, xlsx, odt, ods, gif, zip) via setAllowedExtensions; EncounterView segue com DEFAULT_EXTENSIONS.
- [T-11] i18n: ^1 days → ^1 dias
- [T-19] SUITE passa a usar centralvet_test a partir de 946709c (TestDatabase::DEFAULT_NAME = 'centralvet_test'; run.php sai com 1 se o nome resolvido = DB_DATABASE ou se o banco não existir)
- [T-10] commit a928701: 10 controllers de financeiro/vendas com error_log + CvFormat::userError; CashSessionForm.php também tinha `new TAlert('danger', $e->getMessage())` (fora do regex de TMessage), corrigido — T-17 pode incluir TAlert na trava. PaymentForm monta o combo a partir de Payment::METHODS.
- [T-14] i18n: Name must not contain < or > → O nome não pode conter < ou >
- [T-14] NameText::assertNoMarkup (CentralVet\Domain) chamado em PatientService/TutorService/ProductService create+update e ServiceCatalogService create+update (duplicate e importCsv passam por create; linha de CSV com < ou > vira skipped com reason 'Name must not contain < or >'). UserMessage::STATIC tem 18 entradas.
